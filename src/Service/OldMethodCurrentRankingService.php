<?php

namespace App\Service;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class OldMethodCurrentRankingService
{
    private const int WINDOW_YEARS = 2;
    private const int MIN_GAMES_FOR_LIST = 30;
    private const int MAX_GAMES_INCLUDED = 200;

    public function __construct(
        #[Autowire(service: 'doctrine.dbal.mysql_connection')]
        private Connection $connection,
    ) {
    }

    /**
     * @return array{
     *   referenceTournamentId: int,
     *   referenceTournamentName: string,
     *   referenceDate: string,
     *   windowStartDate: string,
     *   rows: list<array{
     *      position: int,
     *      playerId: int,
     *      playerName: string,
     *      rankExact: float,
     *      rankRounded: int,
     *      games: int,
     *      tournaments: int
     *   }>
     * }
     */
    public function calculateCurrentRanking(): array
    {
        $referenceTournament = $this->loadReferenceTournament();
        if ($referenceTournament === null) {
            return [
                'referenceTournamentId' => 0,
                'referenceTournamentName' => '',
                'referenceDate' => '',
                'windowStartDate' => '',
                'rows' => [],
            ];
        }

        $referenceDate = DateTimeImmutable::createFromFormat('Ymd', (string) $referenceTournament['dt']);
        if ($referenceDate === false) {
            return [
                'referenceTournamentId' => (int) $referenceTournament['id'],
                'referenceTournamentName' => (string) $referenceTournament['name'],
                'referenceDate' => (string) $referenceTournament['dt'],
                'windowStartDate' => (string) $referenceTournament['dt'],
                'rows' => [],
            ];
        }

        $windowStartDate = $referenceDate->modify(sprintf('-%d years', self::WINDOW_YEARS));

        $tournamentRows = $this->connection->fetchAllAssociative(
            "SELECT
                tw.player AS playerId,
                p.name_show AS playerName,
                p.name_alph AS playerNameSort,
                tw.turniej AS tournamentId,
                t.dt AS tournamentDate,
                tw.games AS games,
                tw.trank AS achievedRank
            FROM PFSTOURWYN tw
            INNER JOIN PFSTOURS t ON t.id = tw.turniej
            INNER JOIN PFSPLAYER p ON p.id = tw.player
            WHERE t.dt >= :windowStartDate
              AND t.dt <= :windowEndDate
            ORDER BY tw.player ASC, t.dt DESC, t.id DESC",
            [
                'windowStartDate' => (int) $windowStartDate->format('Ymd'),
                'windowEndDate' => (int) $referenceDate->format('Ymd'),
            ]
        );

        /** @var array<int, array{name: string, nameSort: string, games: int, weightedRankSum: float, tournaments: int, stopped: bool}> $players */
        $players = [];

        foreach ($tournamentRows as $row) {
            $playerId = (int) $row['playerId'];
            $games = max(0, (int) $row['games']);
            $achievedRank = (float) $row['achievedRank'];

            if (!isset($players[$playerId])) {
                $players[$playerId] = [
                    'name' => (string) $row['playerName'],
                    'nameSort' => (string) $row['playerNameSort'],
                    'games' => 0,
                    'weightedRankSum' => 0.0,
                    'tournaments' => 0,
                    'stopped' => false,
                ];
            }

            if ($players[$playerId]['stopped']) {
                continue;
            }

            if ($games <= 0) {
                continue;
            }

            $candidateGames = $players[$playerId]['games'] + $games;
            if ($candidateGames > self::MAX_GAMES_INCLUDED) {
                $players[$playerId]['stopped'] = true;
                continue;
            }

            $players[$playerId]['games'] = $candidateGames;
            $players[$playerId]['weightedRankSum'] += ($achievedRank * $games);
            $players[$playerId]['tournaments']++;
        }

        $rows = [];
        foreach ($players as $playerId => $player) {
            if ($player['games'] < self::MIN_GAMES_FOR_LIST) {
                continue;
            }

            $rankExact = $player['weightedRankSum'] / $player['games'];

            $rows[] = [
                'position' => 0,
                'playerId' => $playerId,
                'playerName' => $player['name'],
                'playerNameSort' => $player['nameSort'],
                'rankExact' => $rankExact,
                'rankRounded' => (int) round($rankExact, 0, PHP_ROUND_HALF_UP),
                'games' => $player['games'],
                'tournaments' => $player['tournaments'],
            ];
        }

        usort(
            $rows,
            static function (array $a, array $b): int {
                if ($a['rankExact'] !== $b['rankExact']) {
                    return $a['rankExact'] < $b['rankExact'] ? 1 : -1;
                }

                if ($a['games'] !== $b['games']) {
                    return $a['games'] < $b['games'] ? 1 : -1;
                }

                return strcmp($a['playerNameSort'], $b['playerNameSort']);
            }
        );

        foreach ($rows as $index => $row) {
            $rows[$index]['position'] = $index + 1;
            unset($rows[$index]['playerNameSort']);
        }

        return [
            'referenceTournamentId' => (int) $referenceTournament['id'],
            'referenceTournamentName' => (string) $referenceTournament['name'],
            'referenceDate' => $referenceDate->format('Y-m-d'),
            'windowStartDate' => $windowStartDate->format('Y-m-d'),
            'rows' => $rows,
        ];
    }

    /**
     * @return array{id: int|string, dt: int|string, name: string}|null
     */
    private function loadReferenceTournament(): ?array
    {
        $row = $this->connection->fetchAssociative(
            "SELECT
                t.id,
                t.dt,
                COALESCE(t.fullname, t.name) AS name
            FROM PFSTOURS t
            INNER JOIN (
                SELECT MAX(turniej) AS tournamentId
                FROM PFSRANKING
                WHERE rtype = 'f'
            ) latest ON latest.tournamentId = t.id"
        );

        if ($row === false) {
            return null;
        }

        return $row;
    }
}
