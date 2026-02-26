<?php

declare(strict_types=1);

namespace App\Ranking\Application;

use App\Ranking\Domain\EloRatingModel;
use App\Ranking\Domain\PlayerState;
use App\Ranking\Domain\RatingModelInterface;
use App\Ranking\Domain\WindowDefinition;
use App\Ranking\Infrastructure\GameRecord;
use App\Ranking\Infrastructure\GamesDataSource;

final readonly class CalibrateMinGamesService
{
    public function __construct(
        private GamesDataSource $gamesRepository,
    ) {
    }

    /**
     * @param callable(int, int, WindowDefinition): void|null $onWindowProcessed
     */
    public function calibrate(MinGamesCalibrationConfig $config, ?callable $onWindowProcessed = null): MinGamesCalibrationReport
    {
        mt_srand($config->seed);
        $startedAt = microtime(true);

        $ratingModel = $this->createRatingModel($config);
        $nGrid = $this->normalizeIntList($config->nGrid);
        $sensitivityCheckpoints = $this->normalizeIntList($config->nEarlyCheckpoints);
        $allTargetCheckpoints = $this->normalizeIntList(array_merge($nGrid, $sensitivityCheckpoints));

        $windows = $this->buildWindows($config->start, $config->end, $config->step, $config->window, $config->maxWindows);
        $windowCount = count($windows);

        $acc = [];
        foreach ($nGrid as $n) {
            $acc[$n] = [
                'eligible' => 0,
                'false' => 0,
                'active' => 0,
                'qualified' => 0,
                'windowRates' => [],
                'persistenceDays' => [],
                'windowStats' => [],
            ];
        }

        $sensitivityAcc = [];
        foreach ($sensitivityCheckpoints as $checkpoint) {
            $sensitivityAcc[$checkpoint] = [
                'eligible' => 0,
                'false' => 0,
                'active' => 0,
                'qualified' => 0,
            ];
        }

        $windowIndex = 0;
        foreach ($windows as $window) {
            $windowIndex++;
            $windowData = $this->processWindow($window, $ratingModel, $allTargetCheckpoints, $config);

            foreach ($nGrid as $n) {
                $metrics = $this->evaluateCheckpoint($windowData, $window, $n, $config);
                $acc[$n]['eligible'] += $metrics['eligible'];
                $acc[$n]['false'] += $metrics['false'];
                $acc[$n]['active'] += $metrics['active'];
                $acc[$n]['qualified'] += $metrics['qualified'];

                if ($metrics['eligible'] > 0) {
                    $acc[$n]['windowRates'][] = $metrics['windowRate'];
                    $acc[$n]['windowStats'][] = [
                        'start' => $window->start->format('Y-m-d'),
                        'end' => $window->end->format('Y-m-d'),
                        'rate' => $metrics['windowRate'],
                        'eligible' => $metrics['eligible'],
                        'false' => $metrics['false'],
                    ];
                }

                foreach ($metrics['persistenceDays'] as $days) {
                    $acc[$n]['persistenceDays'][] = $days;
                }
            }

            foreach ($sensitivityCheckpoints as $checkpoint) {
                $metrics = $this->evaluateCheckpoint($windowData, $window, $checkpoint, $config, false);
                $sensitivityAcc[$checkpoint]['eligible'] += $metrics['eligible'];
                $sensitivityAcc[$checkpoint]['false'] += $metrics['false'];
                $sensitivityAcc[$checkpoint]['active'] += $metrics['active'];
                $sensitivityAcc[$checkpoint]['qualified'] += $metrics['qualified'];
            }

            if ($onWindowProcessed !== null) {
                $onWindowProcessed($windowIndex, $windowCount, $window);
            }
        }

        $gridPoints = [];
        $worstWindowsByN = [];
        foreach ($nGrid as $n) {
            $eligible = (int) $acc[$n]['eligible'];
            $false = (int) $acc[$n]['false'];
            $active = (int) $acc[$n]['active'];
            $qualified = (int) $acc[$n]['qualified'];
            $coverage = $active > 0 ? ($qualified / $active) : 0.0;
            $windowRates = $acc[$n]['windowRates'];

            $gridPoints[] = new MinGamesGridPoint(
                nMin: $n,
                falseLeaderRate: $eligible > 0 ? ($false / $eligible) : 0.0,
                p95WindowFalseRate: $this->percentileFloat($windowRates, 95) ?? 0.0,
                windowRateMean: $this->mean($windowRates),
                windowRateMedian: $this->percentileFloat($windowRates, 50) ?? 0.0,
                windowRateP90: $this->percentileFloat($windowRates, 90) ?? 0.0,
                coverage: $coverage,
                excluded: 1.0 - $coverage,
                persistenceP90Days: $this->percentileInt($acc[$n]['persistenceDays'], 90),
                eligibleCount: $eligible,
                falseLeaderCount: $false,
            );

            $windowStats = $acc[$n]['windowStats'];
            usort($windowStats, static function (array $a, array $b): int {
                $cmp = ($b['rate'] <=> $a['rate']);
                if ($cmp !== 0) {
                    return $cmp;
                }

                return ($b['false'] <=> $a['false']);
            });
            $worstWindowsByN[(string) $n] = array_slice($windowStats, 0, 5);
        }

        usort($gridPoints, static fn (MinGamesGridPoint $a, MinGamesGridPoint $b): int => $a->nMin <=> $b->nMin);

        $recommendations = [];
        foreach ($config->alphas as $alpha) {
            $alphaWindow = $config->alphaWindow ?? $alpha;
            $selected = null;
            foreach ($gridPoints as $point) {
                if ($point->eligibleCount <= 0) {
                    continue;
                }
                if ($point->falseLeaderRate <= $alpha && $point->p95WindowFalseRate <= $alphaWindow) {
                    $selected = $point;
                    break;
                }
            }

            if ($selected === null && $gridPoints !== []) {
                $selected = $gridPoints[count($gridPoints) - 1];
            }

            $recommendations[] = new MinGamesRecommendation(
                alpha: $alpha,
                alphaWindow: $alphaWindow,
                recommendedNMin: $selected?->nMin ?? ($nGrid[0] ?? 0),
                metric: $selected,
            );
        }

        $sensitivity = [];
        foreach ($sensitivityAcc as $checkpoint => $row) {
            $eligible = (int) $row['eligible'];
            $active = (int) $row['active'];
            $qualified = (int) $row['qualified'];
            $sensitivity[(string) $checkpoint] = [
                'nEarly' => $checkpoint,
                'eligibleCount' => $eligible,
                'falseLeaderCount' => (int) $row['false'],
                'falseLeaderRate' => $eligible > 0 ? ((int) $row['false'] / $eligible) : 0.0,
                'coverage' => $active > 0 ? ($qualified / $active) : 0.0,
            ];
        }

        $curves = [
            'nToFalseLeaderRate' => array_map(
                static fn (MinGamesGridPoint $p): array => ['nMin' => $p->nMin, 'value' => $p->falseLeaderRate],
                $gridPoints
            ),
            'nToCoverage' => array_map(
                static fn (MinGamesGridPoint $p): array => ['nMin' => $p->nMin, 'value' => $p->coverage],
                $gridPoints
            ),
            'nToP95WindowFalseRate' => array_map(
                static fn (MinGamesGridPoint $p): array => ['nMin' => $p->nMin, 'value' => $p->p95WindowFalseRate],
                $gridPoints
            ),
            'nToPersistenceP90Days' => array_map(
                static fn (MinGamesGridPoint $p): array => ['nMin' => $p->nMin, 'value' => $p->persistenceP90Days],
                $gridPoints
            ),
        ];

        return new MinGamesCalibrationReport(
            config: $config,
            windowCount: $windowCount,
            durationSeconds: microtime(true) - $startedAt,
            gridPoints: $gridPoints,
            recommendations: $recommendations,
            worstWindowsByN: $worstWindowsByN,
            sensitivity: $sensitivity,
            curves: $curves,
        );
    }

    /**
     * @param list<int> $checkpoints
     * @return array{
     *   snapshots: array<int, array<int, float>>,
     *   checkpointDates: array<int, array<int, \DateTimeImmutable>>,
     *   gamesByPlayer: array<int, int>,
     *   finalRatings: array<int, float>,
     *   lastPlayedAtByPlayer: array<int, \DateTimeImmutable>,
     *   rankCache: array<int, array<int, int>>,
     *   topSetCache: array<int, array<int, true>>,
     *   stableRanks: array<int, int>,
     *   stableTop: array<int, true>,
     *   stableRatings: array<int, float>,
     *   finalTop: array<int, true>
     * }
     */
    private function processWindow(
        WindowDefinition $window,
        RatingModelInterface $ratingModel,
        array $checkpoints,
        MinGamesCalibrationConfig $config,
    ): array {
        $checkpoints = $this->normalizeIntList($checkpoints);
        $states = [];
        $snapshots = [];
        $checkpointDates = [];
        $gamesByPlayer = [];
        $lastPlayedAtByPlayer = [];
        $checkpointSet = array_fill_keys($checkpoints, true);

        foreach ($this->gamesRepository->streamWindowGames($window) as $game) {
            [$score1, $score2] = $this->scoresFromGame($game);
            $player1 = $game->player1Id;
            $player2 = $game->player2Id;

            if (!isset($states[$player1])) {
                $states[$player1] = new PlayerState($ratingModel->initialRating());
            }
            if (!isset($states[$player2])) {
                $states[$player2] = new PlayerState($ratingModel->initialRating());
            }

            [$nextRating1, $nextRating2] = $ratingModel->updateRatings(
                $states[$player1]->rating,
                $states[$player2]->rating,
                $score1
            );

            $states[$player1]->rating = $nextRating1;
            $states[$player2]->rating = $nextRating2;

            $states[$player1]->gamesCount++;
            $states[$player2]->gamesCount++;

            $states[$player1]->lastPlayedAt = $game->playedAt;
            $states[$player2]->lastPlayedAt = $game->playedAt;

            $gamesByPlayer[$player1] = $states[$player1]->gamesCount;
            $gamesByPlayer[$player2] = $states[$player2]->gamesCount;
            $lastPlayedAtByPlayer[$player1] = $game->playedAt;
            $lastPlayedAtByPlayer[$player2] = $game->playedAt;

            $this->captureCheckpoint($snapshots, $checkpointDates, $states[$player1], $player1, $game, $checkpointSet);
            $this->captureCheckpoint($snapshots, $checkpointDates, $states[$player2], $player2, $game, $checkpointSet);
        }

        $finalRatings = [];
        foreach ($states as $playerId => $state) {
            $finalRatings[$playerId] = $state->rating;
        }

        $rankCache = [];
        $topSetCache = [];
        foreach ($checkpoints as $checkpoint) {
            if (!isset($snapshots[$checkpoint])) {
                continue;
            }

            $rankCache[$checkpoint] = $this->rankPositions($snapshots[$checkpoint]);
            $topSetCache[$checkpoint] = $this->topSet($rankCache[$checkpoint], $config->topK);
        }

        $stableRatings = [];
        foreach ($finalRatings as $playerId => $rating) {
            if (($gamesByPlayer[$playerId] ?? 0) < $config->minStableGames) {
                continue;
            }
            $stableRatings[$playerId] = $rating;
        }

        $stableRanks = $this->rankPositions($stableRatings);
        $stableTop = $this->topSet($stableRanks, $config->topK);

        $finalEligibleRatings = [];
        foreach ($finalRatings as $playerId => $rating) {
            if (($gamesByPlayer[$playerId] ?? 0) < $config->minGamesForPlayer) {
                continue;
            }
            $finalEligibleRatings[$playerId] = $rating;
        }
        $finalTop = $this->topSet($this->rankPositions($finalEligibleRatings), $config->topK);

        return [
            'snapshots' => $snapshots,
            'checkpointDates' => $checkpointDates,
            'gamesByPlayer' => $gamesByPlayer,
            'finalRatings' => $finalRatings,
            'lastPlayedAtByPlayer' => $lastPlayedAtByPlayer,
            'rankCache' => $rankCache,
            'topSetCache' => $topSetCache,
            'stableRanks' => $stableRanks,
            'stableTop' => $stableTop,
            'stableRatings' => $stableRatings,
            'finalTop' => $finalTop,
        ];
    }

    /**
     * @param array{
     *   snapshots: array<int, array<int, float>>,
     *   checkpointDates: array<int, array<int, \DateTimeImmutable>>,
     *   gamesByPlayer: array<int, int>,
     *   finalRatings: array<int, float>,
     *   lastPlayedAtByPlayer: array<int, \DateTimeImmutable>,
     *   rankCache: array<int, array<int, int>>,
     *   topSetCache: array<int, array<int, true>>,
     *   stableRanks: array<int, int>,
     *   stableTop: array<int, true>,
     *   stableRatings: array<int, float>,
     *   finalTop: array<int, true>
     * } $windowData
     * @return array{
     *   eligible: int,
     *   false: int,
     *   active: int,
     *   qualified: int,
     *   windowRate: float,
     *   persistenceDays: list<int>
     * }
     */
    private function evaluateCheckpoint(
        array $windowData,
        WindowDefinition $window,
        int $checkpoint,
        MinGamesCalibrationConfig $config,
        bool $collectPersistence = true,
    ): array {
        $active = 0;
        $qualified = 0;
        foreach ($windowData['gamesByPlayer'] as $playerId => $gamesCount) {
            if ($gamesCount < $config->minGamesForPlayer) {
                continue;
            }
            $active++;
            if ($gamesCount >= $checkpoint) {
                $qualified++;
            }
        }

        $earlyRanks = $windowData['rankCache'][$checkpoint] ?? [];
        $earlyTop = $windowData['topSetCache'][$checkpoint] ?? [];
        $earlyRatings = $windowData['snapshots'][$checkpoint] ?? [];
        if ($earlyRanks === [] || $windowData['stableRanks'] === []) {
            return [
                'eligible' => 0,
                'false' => 0,
                'active' => $active,
                'qualified' => $qualified,
                'windowRate' => 0.0,
                'persistenceDays' => [],
            ];
        }

        $eligible = 0;
        $false = 0;
        $persistenceDays = [];
        foreach (array_keys($earlyTop) as $playerId) {
            if (!isset($windowData['stableRanks'][$playerId])) {
                continue;
            }

            $eligible++;
            if (isset($windowData['stableTop'][$playerId])) {
                continue;
            }

            $stableRank = $windowData['stableRanks'][$playerId];
            $earlyRank = $earlyRanks[$playerId] ?? $stableRank;
            $rankDrop = $stableRank - $earlyRank;

            $earlyRating = $earlyRatings[$playerId] ?? null;
            $stableRating = $windowData['stableRatings'][$playerId] ?? null;
            $ratingDrop = ($earlyRating !== null && $stableRating !== null) ? ($earlyRating - $stableRating) : 0.0;

            $isFalseByRank = $rankDrop >= $config->deltaRank;
            $isFalseByRating = $config->deltaRating > 0.0 && $ratingDrop >= $config->deltaRating;
            if (!$isFalseByRank && !$isFalseByRating) {
                continue;
            }

            $false++;
            if ($collectPersistence && $config->persistStepGames > 0) {
                $days = $this->estimatePersistenceDays($windowData, $window, $playerId, $checkpoint, $config->persistStepGames);
                if ($days !== null) {
                    $persistenceDays[] = $days;
                }
            }
        }

        return [
            'eligible' => $eligible,
            'false' => $false,
            'active' => $active,
            'qualified' => $qualified,
            'windowRate' => $eligible > 0 ? ($false / $eligible) : 0.0,
            'persistenceDays' => $persistenceDays,
        ];
    }

    /**
     * @param array{
     *   checkpointDates: array<int, array<int, \DateTimeImmutable>>,
     *   gamesByPlayer: array<int, int>,
     *   topSetCache: array<int, array<int, true>>,
     *   finalTop: array<int, true>,
     *   lastPlayedAtByPlayer: array<int, \DateTimeImmutable>
     * } $windowData
     */
    private function estimatePersistenceDays(
        array $windowData,
        WindowDefinition $window,
        int $playerId,
        int $startCheckpoint,
        int $stepGames,
    ): ?int {
        $startDate = $windowData['checkpointDates'][$startCheckpoint][$playerId] ?? null;
        if (!$startDate instanceof \DateTimeImmutable) {
            return null;
        }

        $playerGames = (int) ($windowData['gamesByPlayer'][$playerId] ?? 0);
        if ($playerGames <= $startCheckpoint) {
            return 0;
        }

        for ($checkpoint = $startCheckpoint + $stepGames; $checkpoint <= $playerGames; $checkpoint += $stepGames) {
            if (!isset($windowData['topSetCache'][$checkpoint])) {
                continue;
            }
            if (!isset($windowData['checkpointDates'][$checkpoint][$playerId])) {
                continue;
            }

            if (!isset($windowData['topSetCache'][$checkpoint][$playerId])) {
                $dropDate = $windowData['checkpointDates'][$checkpoint][$playerId];

                return (int) ($startDate->diff($dropDate)->days ?? 0);
            }
        }

        if (!isset($windowData['finalTop'][$playerId])) {
            $dropDate = $windowData['lastPlayedAtByPlayer'][$playerId] ?? $window->end;

            return (int) ($startDate->diff($dropDate)->days ?? 0);
        }

        return (int) ($startDate->diff($window->end)->days ?? 0);
    }

    /**
     * @param array<int, array<int, float>> $snapshots
     * @param array<int, array<int, \DateTimeImmutable>> $checkpointDates
     * @param array<int, true> $checkpointSet
     */
    private function captureCheckpoint(
        array &$snapshots,
        array &$checkpointDates,
        PlayerState $state,
        int $playerId,
        GameRecord $game,
        array $checkpointSet,
    ): void {
        if (!isset($checkpointSet[$state->gamesCount])) {
            return;
        }

        if (!isset($snapshots[$state->gamesCount])) {
            $snapshots[$state->gamesCount] = [];
            $checkpointDates[$state->gamesCount] = [];
        }
        $snapshots[$state->gamesCount][$playerId] = $state->rating;
        $checkpointDates[$state->gamesCount][$playerId] = $game->playedAt;
    }

    /**
     * @param array<int, float> $valuesByPlayer
     * @return array<int, int>
     */
    private function rankPositions(array $valuesByPlayer): array
    {
        uksort($valuesByPlayer, static function (int|string $a, int|string $b) use ($valuesByPlayer): int {
            $ratingCmp = $valuesByPlayer[(int) $b] <=> $valuesByPlayer[(int) $a];
            if ($ratingCmp !== 0) {
                return $ratingCmp;
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

    private function createRatingModel(MinGamesCalibrationConfig $config): RatingModelInterface
    {
        return new EloRatingModel($config->eloK, 0.0);
    }

    /**
     * @param list<int|float|string> $values
     * @return list<int>
     */
    private function normalizeIntList(array $values): array
    {
        $result = [];
        foreach ($values as $value) {
            $int = (int) $value;
            if ($int <= 0) {
                continue;
            }
            $result[] = $int;
        }

        $result = array_values(array_unique($result));
        sort($result, SORT_NUMERIC);

        return $result;
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

    /**
     * @param list<float> $values
     */
    private function mean(array $values): float
    {
        if ($values === []) {
            return 0.0;
        }

        return array_sum($values) / count($values);
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

