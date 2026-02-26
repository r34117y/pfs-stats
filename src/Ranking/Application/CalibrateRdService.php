<?php

declare(strict_types=1);

namespace App\Ranking\Application;

use App\Ranking\Domain\Glicko2OpponentResult;
use App\Ranking\Domain\Glicko2Service;
use App\Ranking\Domain\PlayerRatingState;
use App\Ranking\Domain\WindowDefinition;
use App\Ranking\Infrastructure\GamesDataSource;
use App\Ranking\Infrastructure\GameRecord;

final readonly class CalibrateRdService
{
    public function __construct(
        private GamesDataSource $gamesRepository,
    ) {
    }

    /**
     * @param callable(int, int, WindowDefinition): void|null $onWindowProcessed
     */
    public function calibrate(CalibrationConfig $config, ?callable $onWindowProcessed = null): CalibrationReport
    {
        mt_srand($config->seed);
        $startedAt = microtime(true);

        $windows = $this->buildWindows($config->start, $config->end, $config->step, $config->window);
        $windowCount = count($windows);

        $rdGrid = $config->rdGrid;
        rsort($rdGrid, SORT_NUMERIC);
        $rdKeys = array_map([$this, 'rdKey'], $rdGrid);

        $mainAcc = [];
        foreach ($rdGrid as $idx => $rdMax) {
            $mainAcc[$rdKeys[$idx]] = [
                'rdMax' => $rdMax,
                'topQualified' => 0,
                'inflated' => 0,
                'population' => 0,
                'qualified' => 0,
                'gamesToQualify' => [],
            ];
        }

        $sensitivityEarly = array_values(array_unique([30, 35, 40]));
        $sensitivityAcc = [];
        foreach ($sensitivityEarly as $earlyGames) {
            $sensitivityAcc[$earlyGames] = [];
            foreach ($rdGrid as $idx => $rdMax) {
                $sensitivityAcc[$earlyGames][$rdKeys[$idx]] = [
                    'rdMax' => $rdMax,
                    'topQualified' => 0,
                    'inflated' => 0,
                ];
            }
        }

        $windowSummaries = [];
        $windowIndex = 0;

        foreach ($windows as $window) {
            $windowIndex++;
            $windowData = $this->processWindow($window, $config, $rdGrid, $sensitivityEarly);

            $this->accumulateForEarlyGames(
                $mainAcc,
                $windowData,
                $config->earlyGames,
                $config->k,
                $config->deltaRank,
                $config->deltaRating,
                true,
            );

            foreach ($sensitivityEarly as $earlyGames) {
                $this->accumulateForEarlyGames(
                    $sensitivityAcc[$earlyGames],
                    $windowData,
                    $earlyGames,
                    $config->k,
                    $config->deltaRank,
                    $config->deltaRating,
                    false,
                );
            }

            $windowSummaries[] = [
                'start' => $window->start->format('Y-m-d'),
                'end' => $window->end->format('Y-m-d'),
                'games' => $windowData['gamesCount'],
                'playersSeen' => $windowData['playersSeen'],
                'playersStable' => $windowData['playersStable'],
            ];

            if ($onWindowProcessed !== null) {
                $onWindowProcessed($windowIndex, $windowCount, $window);
            }
        }

        $metrics = [];
        foreach ($mainAcc as $data) {
            $eligible = (int) $data['topQualified'];
            $inflated = (int) $data['inflated'];
            $population = (int) $data['population'];
            $qualified = (int) $data['qualified'];

            $metrics[] = new CandidateMetric(
                rdMax: (float) $data['rdMax'],
                eligibleCount: $eligible,
                inflatedCount: $inflated,
                inflatedProbability: $eligible > 0 ? ($inflated / $eligible) : 0.0,
                coverage: $population > 0 ? ($qualified / $population) : 0.0,
                gamesToQualifyP50: $this->percentile($data['gamesToQualify'], 50),
                gamesToQualifyP90: $this->percentile($data['gamesToQualify'], 90),
            );
        }

        usort($metrics, static fn (CandidateMetric $a, CandidateMetric $b): int => $b->rdMax <=> $a->rdMax);

        $recommendations = [];
        foreach ($config->alphas as $alpha) {
            $selected = null;
            foreach ($metrics as $metric) {
                if ($metric->inflatedProbability <= $alpha && $metric->eligibleCount > 0) {
                    $selected = $metric;
                }
            }

            if ($selected === null && $metrics !== []) {
                $selected = $metrics[array_key_first($metrics)];
            }

            $recommendations[] = new Recommendation(
                alpha: $alpha,
                recommendedRdMax: $selected?->rdMax ?? $config->rdUpperBound,
                metric: $selected,
            );
        }

        $sensitivity = [];
        foreach ($sensitivityAcc as $earlyGames => $accByRd) {
            $rows = [];
            foreach ($accByRd as $row) {
                $eligible = (int) $row['topQualified'];
                $inflated = (int) $row['inflated'];
                $rows[] = [
                    'rdMax' => (float) $row['rdMax'],
                    'eligible' => $eligible,
                    'inflated' => $inflated,
                    'inflatedProbability' => $eligible > 0 ? ($inflated / $eligible) : 0.0,
                ];
            }
            usort($rows, static fn (array $a, array $b): int => $b['rdMax'] <=> $a['rdMax']);
            $sensitivity[(string) $earlyGames] = $rows;
        }

        $curves = [
            'rdToInflatedProbability' => array_map(
                static fn (CandidateMetric $m): array => ['rdMax' => $m->rdMax, 'value' => $m->inflatedProbability],
                $metrics
            ),
            'rdToCoverage' => array_map(
                static fn (CandidateMetric $m): array => ['rdMax' => $m->rdMax, 'value' => $m->coverage],
                $metrics
            ),
            'rdToGamesP50' => array_map(
                static fn (CandidateMetric $m): array => ['rdMax' => $m->rdMax, 'value' => $m->gamesToQualifyP50],
                $metrics
            ),
            'rdToGamesP90' => array_map(
                static fn (CandidateMetric $m): array => ['rdMax' => $m->rdMax, 'value' => $m->gamesToQualifyP90],
                $metrics
            ),
        ];

        return new CalibrationReport(
            config: $config,
            windowCount: $windowCount,
            durationSeconds: microtime(true) - $startedAt,
            metrics: $metrics,
            recommendations: $recommendations,
            windowSummaries: $windowSummaries,
            sensitivity: $sensitivity,
            curves: $curves,
        );
    }

    /**
     * @param list<float> $rdGrid
     * @param list<int> $earlyCheckpoints
     * @return array{
     *   snapshotsByEarly: array<int, array<int, array{rating: float, rd: float}>>,
     *   stableSnapshots: array<int, array{rating: float, rd: float}>,
     *   totalGames: array<int, int>,
     *   firstCrossByPlayer: array<int, array<string, int>>,
     *   gamesCount: int,
     *   playersSeen: int,
     *   playersStable: int
     * }
     */
    private function processWindow(
        WindowDefinition $window,
        CalibrationConfig $config,
        array $rdGrid,
        array $earlyCheckpoints,
    ): array {
        $glicko = new Glicko2Service(
            tau: $config->tau,
            rdMax: $config->rdUpperBound,
            daysPerRatingPeriod: $config->daysPerRatingPeriod,
        );

        $stableCheckpoint = max($config->stableGames, $config->minStableGames);
        $allCheckpoints = array_values(array_unique(array_merge($earlyCheckpoints, [$stableCheckpoint])));

        $stateByPlayer = [];
        $totalGames = [];
        $firstCrossByPlayer = [];
        $snapshotsByEarly = [];
        foreach ($allCheckpoints as $checkpoint) {
            $snapshotsByEarly[$checkpoint] = [];
        }

        $gamesCount = 0;

        foreach ($this->gamesRepository->streamWindowGames($window) as $game) {
            $gamesCount++;

            $player1 = $game->player1Id;
            $player2 = $game->player2Id;

            if (!isset($stateByPlayer[$player1])) {
                $stateByPlayer[$player1] = PlayerRatingState::initial($config->initialRating, $config->initialRd, $config->initialSigma);
            }
            if (!isset($stateByPlayer[$player2])) {
                $stateByPlayer[$player2] = PlayerRatingState::initial($config->initialRating, $config->initialRd, $config->initialSigma);
            }

            $prepared1 = $this->prepareStateForGame($glicko, $stateByPlayer[$player1], $game);
            $prepared2 = $this->prepareStateForGame($glicko, $stateByPlayer[$player2], $game);

            [$score1, $score2] = $this->scoresFromGame($game);

            $updated1 = $glicko->updateAfterRatingPeriod($prepared1, [
                new Glicko2OpponentResult($prepared2->rating, $prepared2->rd, $score1),
            ]);
            $updated2 = $glicko->updateAfterRatingPeriod($prepared2, [
                new Glicko2OpponentResult($prepared1->rating, $prepared1->rd, $score2),
            ]);

            $stateByPlayer[$player1] = new PlayerRatingState(
                $updated1->rating,
                $updated1->rd,
                $updated1->sigma,
                $game->playedAt,
            );
            $stateByPlayer[$player2] = new PlayerRatingState(
                $updated2->rating,
                $updated2->rd,
                $updated2->sigma,
                $game->playedAt,
            );

            $totalGames[$player1] = ($totalGames[$player1] ?? 0) + 1;
            $totalGames[$player2] = ($totalGames[$player2] ?? 0) + 1;

            $this->captureCrossings($firstCrossByPlayer, $player1, $totalGames[$player1], $stateByPlayer[$player1]->rd, $rdGrid);
            $this->captureCrossings($firstCrossByPlayer, $player2, $totalGames[$player2], $stateByPlayer[$player2]->rd, $rdGrid);

            foreach ($allCheckpoints as $checkpoint) {
                if ($totalGames[$player1] === $checkpoint) {
                    $snapshotsByEarly[$checkpoint][$player1] = [
                        'rating' => $stateByPlayer[$player1]->rating,
                        'rd' => $stateByPlayer[$player1]->rd,
                    ];
                }
                if ($totalGames[$player2] === $checkpoint) {
                    $snapshotsByEarly[$checkpoint][$player2] = [
                        'rating' => $stateByPlayer[$player2]->rating,
                        'rd' => $stateByPlayer[$player2]->rd,
                    ];
                }
            }
        }

        return [
            'snapshotsByEarly' => $snapshotsByEarly,
            'stableSnapshots' => $snapshotsByEarly[$stableCheckpoint],
            'totalGames' => $totalGames,
            'firstCrossByPlayer' => $firstCrossByPlayer,
            'gamesCount' => $gamesCount,
            'playersSeen' => count($stateByPlayer),
            'playersStable' => count($snapshotsByEarly[$stableCheckpoint]),
        ];
    }

    /**
     * @param array<string, array{rdMax: float, topQualified: int, inflated: int, population?: int, qualified?: int, gamesToQualify?: list<int>}> $accumulator
     * @param array{
     *   snapshotsByEarly: array<int, array<int, array{rating: float, rd: float}>>,
     *   stableSnapshots: array<int, array{rating: float, rd: float}>,
     *   totalGames: array<int, int>,
     *   firstCrossByPlayer: array<int, array<string, int>>,
     *   gamesCount: int,
     *   playersSeen: int,
     *   playersStable: int
     * } $windowData
     */
    private function accumulateForEarlyGames(
        array &$accumulator,
        array $windowData,
        int $earlyGames,
        int $topK,
        int $deltaRank,
        float $deltaRating,
        bool $withCoverage,
    ): void {
        $earlySnapshots = $windowData['snapshotsByEarly'][$earlyGames] ?? [];
        if ($earlySnapshots === [] || $windowData['stableSnapshots'] === []) {
            return;
        }

        $stableSnapshots = $windowData['stableSnapshots'];
        $totalGames = $windowData['totalGames'];

        $comparablePlayers = [];
        foreach ($earlySnapshots as $playerId => $earlySnapshot) {
            if (!isset($stableSnapshots[$playerId])) {
                continue;
            }
            if (($totalGames[$playerId] ?? 0) <= 0) {
                continue;
            }

            $comparablePlayers[$playerId] = [
                'early' => $earlySnapshot,
                'stable' => $stableSnapshots[$playerId],
            ];
        }

        if ($comparablePlayers === []) {
            return;
        }

        $earlyRanks = $this->rankPositions(array_map(
            static fn (array $x): float => (float) $x['rating'],
            $earlySnapshots
        ));
        $stableRanks = $this->rankPositions(array_map(
            static fn (array $x): float => (float) $x['rating'],
            $stableSnapshots
        ));

        $earlyTop = $this->topSet($earlyRanks, $topK);
        $stableTop = $this->topSet($stableRanks, $topK);

        $eventByPlayer = [];
        foreach ($comparablePlayers as $playerId => $pair) {
            if (!isset($earlyTop[$playerId])) {
                continue;
            }

            $stableRank = $stableRanks[$playerId] ?? null;
            if ($stableRank === null) {
                continue;
            }

            $isOutsideStableTop = !isset($stableTop[$playerId]);
            $deltaRankValue = $stableRank - ($earlyRanks[$playerId] ?? $stableRank);
            $deltaRatingValue = $pair['early']['rating'] - $pair['stable']['rating'];
            $significantDrop = $deltaRankValue >= $deltaRank || $deltaRatingValue >= $deltaRating;
            $eventByPlayer[$playerId] = $isOutsideStableTop && $significantDrop;
        }

        foreach ($accumulator as $rdKey => &$row) {
            if ($withCoverage) {
                $row['population'] = (int) ($row['population'] ?? 0) + count($comparablePlayers);
            }

            $rdMax = (float) $row['rdMax'];
            foreach ($comparablePlayers as $playerId => $pair) {
                $earlyRd = (float) $pair['early']['rd'];
                if ($earlyRd > $rdMax) {
                    continue;
                }

                if ($withCoverage) {
                    $row['qualified'] = (int) ($row['qualified'] ?? 0) + 1;
                    $firstCross = $windowData['firstCrossByPlayer'][$playerId][$rdKey] ?? null;
                    if (is_int($firstCross) && $firstCross > 0) {
                        $row['gamesToQualify'][] = $firstCross;
                    }
                }

                if (!array_key_exists($playerId, $eventByPlayer)) {
                    continue;
                }

                $row['topQualified']++;
                if ($eventByPlayer[$playerId] === true) {
                    $row['inflated']++;
                }
            }
        }
        unset($row);
    }

    private function prepareStateForGame(Glicko2Service $glicko, PlayerRatingState $state, GameRecord $game): PlayerRatingState
    {
        if ($state->lastPlayedAt === null) {
            return $state;
        }

        $elapsedDays = (int) ($state->lastPlayedAt->diff($game->playedAt)->days ?? 0);
        if ($elapsedDays <= 0) {
            return $state;
        }

        return $glicko->applyInactivity($state, $elapsedDays);
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

    /**
     * @param array<int, array<string, int>> $firstCrossByPlayer
     * @param list<float> $rdGrid
     */
    private function captureCrossings(array &$firstCrossByPlayer, int $playerId, int $gamesCount, float $rd, array $rdGrid): void
    {
        if (!isset($firstCrossByPlayer[$playerId])) {
            $firstCrossByPlayer[$playerId] = [];
        }

        foreach ($rdGrid as $candidateRd) {
            if ($rd > $candidateRd) {
                continue;
            }

            $key = $this->rdKey($candidateRd);
            if (!isset($firstCrossByPlayer[$playerId][$key])) {
                $firstCrossByPlayer[$playerId][$key] = $gamesCount;
            }
        }
    }

    /**
     * @param array<int, float> $valuesByPlayer
     * @return array<int, int>
     */
    private function rankPositions(array $valuesByPlayer): array
    {
        arsort($valuesByPlayer, SORT_NUMERIC);
        $result = [];
        $pos = 1;
        foreach (array_keys($valuesByPlayer) as $playerId) {
            $result[(int) $playerId] = $pos;
            $pos++;
        }

        return $result;
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
     * @param list<int> $values
     */
    private function percentile(array $values, int $percentile): ?float
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
     * @return list<WindowDefinition>
     */
    private function buildWindows(
        \DateTimeImmutable $start,
        \DateTimeImmutable $end,
        \DateInterval $step,
        \DateInterval $window,
    ): array {
        $windows = [];

        for ($cursor = $start; $cursor <= $end; $cursor = $cursor->add($step)) {
            $windowStart = $cursor;
            $windowEnd = $windowStart->add($window)->modify('-1 day');
            if ($windowEnd > $end) {
                break;
            }

            $windows[] = new WindowDefinition($windowStart, $windowEnd);
        }

        return $windows;
    }

    private function rdKey(float $rd): string
    {
        return number_format($rd, 6, '.', '');
    }
}
