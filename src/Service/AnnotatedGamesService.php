<?php

namespace App\Service;

use App\ApiResource\AnnotatedGames\AnnotatedGames;
use App\ApiResource\AnnotatedGames\AnnotatedGamesRow;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class AnnotatedGamesService
{
    private const int PAGE_SIZE = 50;

    public function __construct(
        #[Autowire(service: 'doctrine.dbal.mysql_connection')]
        private Connection $connection,
    ) {
    }

    public function getAnnotatedGames(int $page, ?string $playerName = null, ?string $tournamentName = null): AnnotatedGames
    {
        $page = max(1, $page);
        $playerFilter = trim((string) $playerName);
        $tournamentFilter = trim((string) $tournamentName);

        $whereParts = [];
        $params = [];
        $types = [];

        if ($playerFilter !== '') {
            $whereParts[] = '(g.player1 IN (SELECT p.id FROM PFSPLAYER p WHERE p.name_show LIKE :playerFilter)
                OR h.player2 IN (SELECT p.id FROM PFSPLAYER p WHERE p.name_show LIKE :playerFilter))';
            $params['playerFilter'] = '%' . $playerFilter . '%';
        }

        if ($tournamentFilter !== '') {
            $whereParts[] = 'COALESCE(t.fullname, t.name) LIKE :tournamentFilter';
            $params['tournamentFilter'] = '%' . $tournamentFilter . '%';
        }

        $whereSql = $whereParts === [] ? '' : 'WHERE ' . implode(' AND ', $whereParts);

        $totalItems = (int) $this->connection->fetchOne(
            "SELECT COUNT(*)
            FROM PFSGCG g
            INNER JOIN PFSTOURHH h FORCE INDEX (PFSTOURHH1)
                ON h.turniej = g.tour
               AND h.player1 = g.player1
               AND h.runda = g.`round`
            INNER JOIN PFSTOURS t ON t.id = g.tour
            {$whereSql}",
            $params,
            $types,
        );

        $totalPages = max(1, (int) ceil($totalItems / self::PAGE_SIZE));
        $offset = ($page - 1) * self::PAGE_SIZE;

        $params['limit'] = self::PAGE_SIZE;
        $params['offset'] = $offset;
        $types['limit'] = ParameterType::INTEGER;
        $types['offset'] = ParameterType::INTEGER;

        $rows = $this->connection->fetchAllAssociative(
            "SELECT
                COALESCE(t.fullname, t.name) AS tournamentName,
                page.roundNo,
                p1.name_show AS player1Name,
                p2.name_show AS player2Name
            FROM (
                SELECT
                    g.tour AS tourId,
                    g.`round` AS roundNo,
                    g.player1 AS player1Id,
                    h.player2 AS player2Id,
                    t.dt AS tournamentDate
                FROM PFSGCG g
                INNER JOIN PFSTOURHH h FORCE INDEX (PFSTOURHH1)
                    ON h.turniej = g.tour
                   AND h.player1 = g.player1
                   AND h.runda = g.`round`
                INNER JOIN PFSTOURS t ON t.id = g.tour
                {$whereSql}
                ORDER BY t.dt DESC, g.tour DESC, g.`round` ASC, g.player1 ASC, h.player2 ASC
                LIMIT :limit OFFSET :offset
            ) page
            INNER JOIN PFSTOURS t ON t.id = page.tourId
            INNER JOIN PFSPLAYER p1 ON p1.id = page.player1Id
            INNER JOIN PFSPLAYER p2 ON p2.id = page.player2Id
            ORDER BY page.tournamentDate DESC, page.tourId DESC, page.roundNo ASC, page.player1Id ASC, page.player2Id ASC",
            $params,
            $types,
        );

        $items = array_map(
            static fn (array $row): AnnotatedGamesRow => new AnnotatedGamesRow(
                tournamentName: (string) $row['tournamentName'],
                round: (int) $row['roundNo'],
                player1Name: (string) $row['player1Name'],
                player2Name: (string) $row['player2Name'],
            ),
            $rows
        );

        return new AnnotatedGames(
            items: $items,
            page: $page,
            pageSize: self::PAGE_SIZE,
            totalItems: $totalItems,
            totalPages: $totalPages,
        );
    }
}
