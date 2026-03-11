<?php

namespace App\Service\AnnotatedGames;

use App\ApiResource\AnnotatedGames\AnnotatedGames;
use App\ApiResource\AnnotatedGames\AnnotatedGamesRow;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class AnnotatedGamesServicePostgres implements AnnotatedGamesServiceInterface
{
    private const int PAGE_SIZE = 50;
    private const string ORGANIZATION_CODE = 'PFS';

    public function __construct(
        #[Autowire(service: 'doctrine.dbal.default_connection')]
        private Connection $connection,
    ) {
    }

    /**
     * @throws Exception
     */
    public function getAnnotatedGames(int $page, ?string $playerName = null, ?string $tournamentName = null): AnnotatedGames
    {
        $page = max(1, $page);
        $playerFilter = trim((string) $playerName);
        $tournamentFilter = trim((string) $tournamentName);

        $organizationId = $this->connection->fetchOne(
            'SELECT id FROM organization WHERE code = :code LIMIT 1',
            ['code' => self::ORGANIZATION_CODE],
        );

        if ($organizationId === false || $organizationId === null) {
            return new AnnotatedGames(
                items: [],
                page: $page,
                pageSize: self::PAGE_SIZE,
                totalItems: 0,
                totalPages: 1,
            );
        }

        $whereParts = ['g.organization_id = :organizationId'];
        $params = ['organizationId' => (int) $organizationId];
        $types = ['organizationId' => ParameterType::INTEGER];

        if ($playerFilter !== '') {
            $whereParts[] = '(g.player1_id IN (
                    SELECT po.player_id
                    FROM player_organization po
                    INNER JOIN player p ON p.id = po.player_id
                    WHERE po.organization_id = :organizationId
                      AND p.name_show LIKE :playerFilter
                )
                OR h.player2_id IN (
                    SELECT po.player_id
                    FROM player_organization po
                    INNER JOIN player p ON p.id = po.player_id
                    WHERE po.organization_id = :organizationId
                      AND p.name_show LIKE :playerFilter
                ))';
            $params['playerFilter'] = '%' . $playerFilter . '%';
        }

        if ($tournamentFilter !== '') {
            $whereParts[] = 'COALESCE(t.fullname, t.name) LIKE :tournamentFilter';
            $params['tournamentFilter'] = '%' . $tournamentFilter . '%';
        }

        $whereSql = 'WHERE ' . implode(' AND ', $whereParts);

        $totalItems = (int) $this->connection->fetchOne(
            "SELECT COUNT(*)
             FROM game_record g
             INNER JOIN tournament_game h
                ON h.organization_id = g.organization_id
               AND h.tournament_id = g.tournament_id
               AND h.player1_id = g.player1_id
               AND h.round_no = g.round_no
             INNER JOIN tournament t
                ON t.organization_id = g.organization_id
               AND t.id = g.tournament_id
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
                page.\"tournamentId\",
                page.\"tournamentName\",
                page.\"roundNo\",
                page.\"player1Id\",
                p1.name_show AS \"player1Name\",
                page.\"player2Id\",
                p2.name_show AS \"player2Name\"
             FROM (
                SELECT
                    g.tournament_id AS \"tournamentId\",
                    COALESCE(t.fullname, t.name) AS \"tournamentName\",
                    g.round_no AS \"roundNo\",
                    g.player1_id AS \"player1Id\",
                    h.player2_id AS \"player2Id\",
                    g.player1_id AS \"player1Pk\",
                    h.player2_id AS \"player2Pk\",
                    t.dt AS \"tournamentDate\"
                FROM game_record g
                INNER JOIN tournament_game h
                    ON h.organization_id = g.organization_id
                   AND h.tournament_id = g.tournament_id
                   AND h.player1_id = g.player1_id
                   AND h.round_no = g.round_no
                INNER JOIN tournament t
                    ON t.organization_id = g.organization_id
                   AND t.id = g.tournament_id
                {$whereSql}
                ORDER BY t.dt DESC, g.tournament_id DESC, g.round_no ASC, g.player1_id ASC, h.player2_id ASC
                LIMIT :limit OFFSET :offset
             ) page
             INNER JOIN player p1 ON p1.id = page.\"player1Pk\"
             INNER JOIN player p2 ON p2.id = page.\"player2Pk\"
             ORDER BY page.\"tournamentDate\" DESC, page.\"tournamentId\" DESC, page.\"roundNo\" ASC, page.\"player1Id\" ASC, page.\"player2Id\" ASC",
            $params,
            $types,
        );

        $items = array_map(
            static fn (array $row): AnnotatedGamesRow => new AnnotatedGamesRow(
                tournamentId: (int) $row['tournamentId'],
                tournamentName: (string) $row['tournamentName'],
                round: (int) $row['roundNo'],
                player1Id: (int) $row['player1Id'],
                player1Name: (string) $row['player1Name'],
                player2Id: (int) $row['player2Id'],
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
