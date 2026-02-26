<?php

declare(strict_types=1);

namespace App\Ranking\Application;

use App\Ranking\Domain\BradleyTerryModel;
use App\Ranking\Domain\SkillEstimate;
use App\Ranking\Domain\SkillModelResult;
use App\Ranking\Domain\WindowDefinition;
use App\Ranking\Infrastructure\GameRecord;
use App\Ranking\Infrastructure\GamesDataSource;

final readonly class CalibrateCiService
{
    public function __construct(
        private GamesDataSource $gamesRepository,
    ) {
    }

    /**
     * @param callable(int, int, WindowDefinition): void|null $onWindowProcessed
     */
    public function calibrate(CiCalibrationConfig $config, ?callable $onWindowProcessed = null): CiCalibrationReport
    {
        mt_srand($config->seed);
        $startedAt = microtime(true);

        $windows = $this->buildWindows($config->start, $config->end, $config->step, $config->window, $config->maxWindows);
        $windowCount = count($windows);

        $nEarlyValues = $this->normalizeIntList($config->nEarly);
        $mainNEarly = $nEarlyValues[0] ?? 35;
        $wGrid = $this->normalizeFloatList($config->wGrid);
        $ciLevels = $this->normalizeCiLevels($config->ciLevels);

        $acc = [];
        foreach ($ciLevels as $ciLevel) {
            foreach ($wGrid as $w) {
                $acc[$this->gridKey($ciLevel, $w)] = [
                    'ciLevel' => $ciLevel,
                    'wMax' => $w,
                    'eligible' => 0,
                    'inflated' => 0,
                    'population' => 0,
                    'qualified' => 0,
                    'gamesToQualify' => [],
                    'windowRates' => [],
                    'windowStats' => [],
                ];
            }
        }

        $sensitivityAcc = [];
        foreach ($nEarlyValues as $nEarly) {
            $sensitivityAcc[$nEarly] = [
                'eligible' => 0,
                'inflated' => 0,
            ];
        }

        $model = new BradleyTerryModel(
            sigmaPrior: $config->sigmaPrior,
            maxIter: $config->maxIter,
            tol: $config->tol,
            maxFullCovariancePlayers: $config->maxFullCovariancePlayers,
        );

        $windowIndex = 0;
        foreach ($windows as $window) {
            $windowIndex++;
            $windowDataByNEarly = $this->processWindow($window, $model, $nEarlyValues, $config);

            $mainData = $windowDataByNEarly[$mainNEarly] ?? null;
            if ($mainData !== null) {
                foreach ($ciLevels as $ciLevel) {
                    $z = $this->zFromCiLevel($ciLevel);
                    foreach ($wGrid as $w) {
                        $this->accumulateForGridPoint(
                            $acc[$this->gridKey($ciLevel, $w)],
                            $mainData,
                            $window,
                            $z,
                            $w,
                            $config->minGamesForPlayer
                        );
                    }
                }
            }

            foreach ($nEarlyValues as $nEarly) {
                $entry = $windowDataByNEarly[$nEarly] ?? null;
                if ($entry === null) {
                    continue;
                }

                $sensitivityAcc[$nEarly]['eligible'] += $entry['topEligibleCount'];
                $sensitivityAcc[$nEarly]['inflated'] += $entry['topInflatedCount'];
            }

            if ($onWindowProcessed !== null) {
                $onWindowProcessed($windowIndex, $windowCount, $window);
            }
        }

        $gridPoints = [];
        foreach ($acc as $row) {
            $eligible = (int) $row['eligible'];
            $inflated = (int) $row['inflated'];
            $population = (int) $row['population'];
            $qualified = (int) $row['qualified'];

            $gridPoints[] = new CiGridPoint(
                ciLevel: (float) $row['ciLevel'],
                wMax: (float) $row['wMax'],
                inflatedProbability: $eligible > 0 ? ($inflated / $eligible) : 0.0,
                p95WindowInflatedProbability: $this->percentileFloat($row['windowRates'], 95) ?? 0.0,
                coverage: $population > 0 ? ($qualified / $population) : 0.0,
                medianGamesToQualify: $this->percentileInt($row['gamesToQualify'], 50),
                p90GamesToQualify: $this->percentileInt($row['gamesToQualify'], 90),
                eligibleCount: $eligible,
                inflatedCount: $inflated,
            );
        }

        usort($gridPoints, static function (CiGridPoint $a, CiGridPoint $b): int {
            $ciCmp = $b->ciLevel <=> $a->ciLevel;
            if ($ciCmp !== 0) {
                return $ciCmp;
            }

            return $a->wMax <=> $b->wMax;
        });

        $recommendations = [];
        foreach ($config->alphas as $alpha) {
            foreach ($ciLevels as $ciLevel) {
                $candidates = array_values(array_filter(
                    $gridPoints,
                    static fn (CiGridPoint $p): bool => abs($p->ciLevel - $ciLevel) < 1e-9
                ));

                $selected = null;
                foreach ($candidates as $point) {
                    if ($point->eligibleCount <= 0) {
                        continue;
                    }
                    if ($point->inflatedProbability <= $alpha && $point->p95WindowInflatedProbability <= $alpha) {
                        $selected = $point;
                        break;
                    }
                }

                if ($selected === null && $candidates !== []) {
                    $selected = $candidates[count($candidates) - 1];
                }

                $recommendations[] = new CiRecommendation(
                    alpha: $alpha,
                    ciLevel: $ciLevel,
                    recommendedWMax: $selected?->wMax ?? ($wGrid[count($wGrid) - 1] ?? 1.0),
                    metric: $selected,
                );
            }
        }

        $worstWindowsByRecommendation = [];
        foreach ($recommendations as $recommendation) {
            $key = $this->gridKey($recommendation->ciLevel, $recommendation->recommendedWMax);
            $windowStats = $acc[$key]['windowStats'] ?? [];
            usort($windowStats, static function (array $a, array $b): int {
                $cmp = ($b['rate'] <=> $a['rate']);
                if ($cmp !== 0) {
                    return $cmp;
                }

                return ($b['inflated'] <=> $a['inflated']);
            });

            $worstWindowsByRecommendation[$this->recommendationKey($recommendation)] = array_slice($windowStats, 0, 5);
        }

        $sensitivity = [];
        foreach ($sensitivityAcc as $nEarly => $row) {
            $eligible = (int) $row['eligible'];
            $inflated = (int) $row['inflated'];
            $sensitivity[(string) $nEarly] = [
                'nEarly' => (int) $nEarly,
                'eligibleCount' => $eligible,
                'inflatedCount' => $inflated,
                'inflatedProbability' => $eligible > 0 ? ($inflated / $eligible) : 0.0,
            ];
        }

        $curves = [];
        foreach ($ciLevels as $ciLevel) {
            $points = array_values(array_filter(
                $gridPoints,
                static fn (CiGridPoint $p): bool => abs($p->ciLevel - $ciLevel) < 1e-9
            ));

            $curves[(string) $ciLevel] = [
                'wToInflatedProbability' => array_map(
                    static fn (CiGridPoint $p): array => ['wMax' => $p->wMax, 'value' => $p->inflatedProbability],
                    $points
                ),
                'wToCoverage' => array_map(
                    static fn (CiGridPoint $p): array => ['wMax' => $p->wMax, 'value' => $p->coverage],
                    $points
                ),
                'wToMedianGames' => array_map(
                    static fn (CiGridPoint $p): array => ['wMax' => $p->wMax, 'value' => $p->medianGamesToQualify],
                    $points
                ),
                'wToP90Games' => array_map(
                    static fn (CiGridPoint $p): array => ['wMax' => $p->wMax, 'value' => $p->p90GamesToQualify],
                    $points
                ),
            ];
        }

        return new CiCalibrationReport(
            config: $config,
            windowCount: $windowCount,
            durationSeconds: microtime(true) - $startedAt,
            gridPoints: $gridPoints,
            recommendations: $recommendations,
            worstWindowsByRecommendation: $worstWindowsByRecommendation,
            sensitivity: $sensitivity,
            curves: $curves,
        );
    }

    /**
     * @param array{
     *   ciData: array<int, array{se: float, games: int}>,
     *   topPlayers: array<int, array{inflated: bool, se: float, games: int}>,
     *   topEligibleCount: int,
     *   topInflatedCount: int
     * } $windowData
     * @param array<string, mixed> $accRow
     */
    private function accumulateForGridPoint(
        array &$accRow,
        array $windowData,
        WindowDefinition $window,
        float $z,
        float $wMax,
        int $minGamesForPlayer,
    ): void {
        $seMax = $wMax / max(2.0 * $z, 1e-12);
        $windowEligible = 0;
        $windowInflated = 0;

        foreach ($windowData['topPlayers'] as $player) {
            $width = 2.0 * $z * $player['se'];
            if ($width > $wMax) {
                continue;
            }

            $windowEligible++;
            $accRow['eligible']++;
            if ($player['inflated']) {
                $windowInflated++;
                $accRow['inflated']++;
            }
        }

        foreach ($windowData['ciData'] as $playerId => $entry) {
            if ($entry['games'] < $minGamesForPlayer) {
                continue;
            }
            $accRow['population']++;

            $width = 2.0 * $z * $entry['se'];
            if ($width <= $wMax) {
                $accRow['qualified']++;
            }

            if ($entry['games'] > 0) {
                $currentSe = max($entry['se'], 1e-12);
                $targetSe = max($seMax, 1e-12);
                $impliedGames = (int) ceil(($currentSe * $currentSe * $entry['games']) / ($targetSe * $targetSe));
                $accRow['gamesToQualify'][] = max(1, $impliedGames);
            }
        }

        if ($windowEligible > 0) {
            $rate = $windowInflated / $windowEligible;
            $accRow['windowRates'][] = $rate;
            $accRow['windowStats'][] = [
                'start' => $window->start->format('Y-m-d'),
                'end' => $window->end->format('Y-m-d'),
                'rate' => $rate,
                'inflated' => $windowInflated,
                'eligible' => $windowEligible,
            ];
        }
    }

    /**
     * @param list<int> $nEarlyValues
     * @return array<int, array{
     *   ciData: array<int, array{se: float, games: int}>,
     *   topPlayers: array<int, array{inflated: bool, se: float, games: int}>,
     *   topEligibleCount: int,
     *   topInflatedCount: int
     * }>
     */
    private function processWindow(
        WindowDefinition $window,
        BradleyTerryModel $model,
        array $nEarlyValues,
        CiCalibrationConfig $config,
    ): array {
        $fullGames = [];
        $fullGamesCountByPlayer = [];

        foreach ($this->gamesRepository->streamWindowGames($window) as $game) {
            [$score1] = $this->scoresFromGame($game);
            $fullGames[] = [
                'player1Id' => $game->player1Id,
                'player2Id' => $game->player2Id,
                'score1' => $score1,
            ];

            $fullGamesCountByPlayer[$game->player1Id] = ($fullGamesCountByPlayer[$game->player1Id] ?? 0) + 1;
            $fullGamesCountByPlayer[$game->player2Id] = ($fullGamesCountByPlayer[$game->player2Id] ?? 0) + 1;
        }

        if ($fullGames === []) {
            return [];
        }

        $fullFit = $model->fit($fullGames, $fullGamesCountByPlayer, 0.95);
        $fullSkills = $fullFit->skillsByPlayer();

        $stableSkills = [];
        foreach ($fullSkills as $playerId => $skill) {
            if (($fullGamesCountByPlayer[$playerId] ?? 0) < $config->stableGames) {
                continue;
            }
            $stableSkills[$playerId] = $skill;
        }

        $stableRanks = $this->rankPositions($stableSkills);
        $stableTop = $this->topSet($stableRanks, $config->topK);

        $result = [];
        foreach ($nEarlyValues as $nEarly) {
            [$earlyGames, $earlyCounts] = $this->buildEarlySlice($fullGames, $nEarly);
            if ($earlyGames === []) {
                $result[$nEarly] = [
                    'ciData' => [],
                    'topPlayers' => [],
                    'topEligibleCount' => 0,
                    'topInflatedCount' => 0,
                ];
                continue;
            }

            $earlyFit = $model->fit($earlyGames, $earlyCounts, 0.95);
            $earlyEstimates = $earlyFit->estimatesByPlayer;
            $earlySkills = $earlyFit->skillsByPlayer();

            $earlyRankingPool = [];
            foreach ($earlySkills as $playerId => $skill) {
                if (($earlyCounts[$playerId] ?? 0) < $nEarly) {
                    continue;
                }
                $earlyRankingPool[$playerId] = $skill;
            }

            $earlyRanks = $this->rankPositions($earlyRankingPool);
            $earlyTop = $this->topSet($earlyRanks, $config->topK);

            $ciData = [];
            foreach ($earlyEstimates as $playerId => $estimate) {
                $ciData[$playerId] = [
                    'se' => $estimate->se,
                    'games' => (int) ($earlyCounts[$playerId] ?? 0),
                ];
            }

            $topPlayers = [];
            $topEligibleCount = 0;
            $topInflatedCount = 0;

            foreach (array_keys($earlyTop) as $playerId) {
                if (!isset($stableRanks[$playerId])) {
                    continue;
                }
                if (!isset($earlyEstimates[$playerId])) {
                    continue;
                }
                $topEligibleCount++;

                $isInflated = false;
                if (!isset($stableTop[$playerId])) {
                    $stableRank = $stableRanks[$playerId];
                    $earlyRank = $earlyRanks[$playerId] ?? $stableRank;
                    $rankDrop = $stableRank - $earlyRank;

                    $earlySkill = $earlySkills[$playerId] ?? null;
                    $stableSkill = $stableSkills[$playerId] ?? null;
                    $skillDrop = ($earlySkill !== null && $stableSkill !== null) ? ($earlySkill - $stableSkill) : 0.0;

                    if ($rankDrop >= $config->deltaRank || ($config->deltaSkill > 0.0 && $skillDrop >= $config->deltaSkill)) {
                        $isInflated = true;
                    }
                }

                if ($isInflated) {
                    $topInflatedCount++;
                }

                /** @var SkillEstimate $estimate */
                $estimate = $earlyEstimates[$playerId];
                $topPlayers[$playerId] = [
                    'inflated' => $isInflated,
                    'se' => $estimate->se,
                    'games' => (int) ($earlyCounts[$playerId] ?? 0),
                ];
            }

            $result[$nEarly] = [
                'ciData' => $ciData,
                'topPlayers' => $topPlayers,
                'topEligibleCount' => $topEligibleCount,
                'topInflatedCount' => $topInflatedCount,
            ];
        }

        return $result;
    }

    /**
     * @param list<array{player1Id: int, player2Id: int, score1: float}> $fullGames
     * @return array{0: list<array{player1Id: int, player2Id: int, score1: float}>, 1: array<int, int>}
     */
    private function buildEarlySlice(array $fullGames, int $nEarly): array
    {
        $seenCounts = [];
        $includedCounts = [];
        $earlyGames = [];

        foreach ($fullGames as $game) {
            $player1 = $game['player1Id'];
            $player2 = $game['player2Id'];

            $seen1 = $seenCounts[$player1] ?? 0;
            $seen2 = $seenCounts[$player2] ?? 0;

            if ($seen1 < $nEarly && $seen2 < $nEarly) {
                $earlyGames[] = $game;
                $includedCounts[$player1] = ($includedCounts[$player1] ?? 0) + 1;
                $includedCounts[$player2] = ($includedCounts[$player2] ?? 0) + 1;
            }

            $seenCounts[$player1] = $seen1 + 1;
            $seenCounts[$player2] = $seen2 + 1;
        }

        return [$earlyGames, $includedCounts];
    }

    /**
     * @param array<int, float> $valuesByPlayer
     * @return array<int, int>
     */
    private function rankPositions(array $valuesByPlayer): array
    {
        uksort($valuesByPlayer, static function (int|string $a, int|string $b) use ($valuesByPlayer): int {
            $skillCmp = $valuesByPlayer[(int) $b] <=> $valuesByPlayer[(int) $a];
            if ($skillCmp !== 0) {
                return $skillCmp;
            }

            return ((int) $a) <=> ((int) $b);
        });

        $ranks = [];
        $position = 1;
        foreach (array_keys($valuesByPlayer) as $playerId) {
            $ranks[(int) $playerId] = $position;
            $position++;
        }

        return $ranks;
    }

    /**
     * @param array<int, int> $ranks
     * @return array<int, true>
     */
    private function topSet(array $ranks, int $k): array
    {
        $set = [];
        foreach ($ranks as $playerId => $position) {
            if ($position > $k) {
                break;
            }
            $set[$playerId] = true;
        }

        return $set;
    }

    /**
     * @return list<WindowDefinition>
     */
    private function buildWindows(
        \DateTimeImmutable $start,
        \DateTimeImmutable $end,
        \DateInterval $step,
        \DateInterval $window,
        int $maxWindows,
    ): array {
        $windows = [];
        $count = 0;

        for ($cursor = $start; $cursor <= $end; $cursor = $cursor->add($step)) {
            $windowStart = $cursor;
            $windowEnd = $windowStart->add($window)->modify('-1 day');
            if ($windowEnd > $end) {
                break;
            }

            $windows[] = new WindowDefinition($windowStart, $windowEnd);
            $count++;
            if ($maxWindows > 0 && $count >= $maxWindows) {
                break;
            }
        }

        return $windows;
    }

    /**
     * @param list<int|float|string> $values
     * @return list<int>
     */
    private function normalizeIntList(array $values): array
    {
        $result = [];
        foreach ($values as $value) {
            $n = (int) $value;
            if ($n <= 0) {
                continue;
            }
            $result[] = $n;
        }
        $result = array_values(array_unique($result));
        sort($result, SORT_NUMERIC);

        return $result;
    }

    /**
     * @param list<float|int|string> $values
     * @return list<float>
     */
    private function normalizeFloatList(array $values): array
    {
        $result = [];
        foreach ($values as $value) {
            $f = (float) $value;
            if ($f <= 0.0) {
                continue;
            }
            $result[] = $f;
        }
        $result = array_values(array_unique($result));
        sort($result, SORT_NUMERIC);

        return $result;
    }

    /**
     * @param list<float|int|string> $ciLevels
     * @return list<float>
     */
    private function normalizeCiLevels(array $ciLevels): array
    {
        $result = [];
        foreach ($ciLevels as $value) {
            $ci = (float) $value;
            if ($ci <= 0.0 || $ci >= 1.0) {
                continue;
            }
            $result[] = round($ci, 6);
        }
        $result = array_values(array_unique($result));
        rsort($result, SORT_NUMERIC);

        if ($result === []) {
            return [0.95];
        }

        return $result;
    }

    private function zFromCiLevel(float $ciLevel): float
    {
        if ($ciLevel >= 0.999) {
            return 3.291;
        }
        if ($ciLevel >= 0.99) {
            return 2.576;
        }
        if ($ciLevel >= 0.975) {
            return 2.241;
        }
        if ($ciLevel >= 0.95) {
            return 1.96;
        }
        if ($ciLevel >= 0.90) {
            return 1.645;
        }

        return 1.96;
    }

    /**
     * @param list<int> $values
     */
    private function percentileInt(array $values, int $percentile): ?float
    {
        if ($values === []) {
            return null;
        }
        sort($values, SORT_NUMERIC);
        $index = (int) ceil((($percentile / 100.0) * count($values))) - 1;
        $index = max(0, min($index, count($values) - 1));

        return (float) $values[$index];
    }

    /**
     * @param list<float> $values
     */
    private function percentileFloat(array $values, int $percentile): ?float
    {
        if ($values === []) {
            return null;
        }
        sort($values, SORT_NUMERIC);
        $index = (int) ceil((($percentile / 100.0) * count($values))) - 1;
        $index = max(0, min($index, count($values) - 1));

        return $values[$index];
    }

    private function gridKey(float $ciLevel, float $wMax): string
    {
        return sprintf('%.6f|%.6f', $ciLevel, $wMax);
    }

    private function recommendationKey(CiRecommendation $recommendation): string
    {
        return sprintf('alpha=%.4f|ci=%.4f|w=%.4f', $recommendation->alpha, $recommendation->ciLevel, $recommendation->recommendedWMax);
    }

    /**
     * @return array{float, float}
     */
    private function scoresFromGame(GameRecord $game): array
    {
        if ($game->result1 > $game->result2) {
            return [1.0, 0.0];
        }
        if ($game->result2 > $game->result1) {
            return [0.0, 1.0];
        }

        return [0.5, 0.5];
    }
}

