<?php

declare(strict_types=1);

namespace App\Ranking\Infrastructure;

use App\Ranking\Domain\WindowDefinition;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class GamesRepository implements GamesDataSource {
    public function __construct(
        #[Autowire(service: 'doctrine.dbal.mysql_connection')]
        private Connection $connection,
    ) {
    }

    /**
     * @return array{start: DateTimeImmutable, end: DateTimeImmutable}|null
     */
    public function findDateBounds(): ?array
    {
        $row = $this->connection->fetchAssociative(
            "SELECT MIN(t.dt) AS minDt, MAX(t.dt) AS maxDt
             FROM PFSTOURHH h
             INNER JOIN PFSTOURS t ON t.id = h.turniej"
        );

        if ($row === false || $row['minDt'] === null || $row['maxDt'] === null) {
            return null;
        }

        $start = DateTimeImmutable::createFromFormat('Ymd', (string) $row['minDt']);
        $end = DateTimeImmutable::createFromFormat('Ymd', (string) $row['maxDt']);

        if ($start === false || $end === false) {
            return null;
        }

        return ['start' => $start, 'end' => $end];
    }

    /**
     * @return iterable<GameRecord>
     */
    public function streamWindowGames(WindowDefinition $window): iterable
    {
        $startInt = (int) $window->start->format('Ymd');
        $endInt = (int) $window->end->format('Ymd');

        $result = $this->connection->executeQuery(
            "WITH ranked_games AS (
                SELECT
                    h.turniej,
                    h.runda,
                    h.player1,
                    h.player2,
                    h.result1,
                    h.result2,
                    t.dt,
                    ROW_NUMBER() OVER (
                        PARTITION BY h.turniej, h.runda, LEAST(h.player1, h.player2), GREATEST(h.player1, h.player2)
                        ORDER BY h.player1 ASC
                    ) AS rn
                FROM PFSTOURHH h
                INNER JOIN PFSTOURS t ON t.id = h.turniej
                WHERE t.dt >= :startInt
                  AND t.dt <= :endInt
            )
            SELECT
                turniej,
                runda,
                player1,
                player2,
                result1,
                result2,
                dt
            FROM ranked_games
            WHERE rn = 1
            ORDER BY dt ASC, turniej ASC, runda ASC, player1 ASC, player2 ASC",
            [
                'startInt' => $startInt,
                'endInt' => $endInt,
            ]
        );

        foreach ($result->iterateAssociative() as $row) {
            $playedAt = DateTimeImmutable::createFromFormat('Ymd', (string) $row['dt']);
            if ($playedAt === false) {
                continue;
            }

            yield new GameRecord(
                (int) $row['turniej'],
                $playedAt,
                (int) $row['runda'],
                (int) $row['player1'],
                (int) $row['player2'],
                (int) $row['result1'],
                (int) $row['result2'],
            );
        }
    }
}
