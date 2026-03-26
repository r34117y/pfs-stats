<?php

namespace App\Service\PlayerList;

use App\ApiResource\PlayersList\PlayersList;
use App\ApiResource\PlayersList\PlayersListPlayer;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class PlayerListServicePostgres implements PlayerListServiceInterface
{
    private const string ORGANIZATION_CODE = 'PFS';

    public function __construct(
        #[Autowire(service: 'doctrine.dbal.default_connection')]
        private Connection $connection,
    ) {
    }

    /**
     * @throws Exception
     * @throws \Exception
     */
    public function getPlayers(): PlayersList
    {
        $organizationId = $this->fetchOrganizationId();
        if ($organizationId === null) {
            return new PlayersList([]);
        }

        $sql = "WITH mapped AS (
                    SELECT legacy_player_id, player_id
                    FROM ranking
                    WHERE organization_id = :organizationId
                      AND legacy_player_id IS NOT NULL
                      AND player_id IS NOT NULL
                    UNION
                    SELECT legacy_player_id, player_id
                    FROM tournament_result
                    WHERE organization_id = :organizationId
                      AND legacy_player_id IS NOT NULL
                      AND player_id IS NOT NULL
                    UNION
                    SELECT legacy_player_id, player_id
                    FROM play_summary
                    WHERE organization_id = :organizationId
                      AND legacy_player_id IS NOT NULL
                      AND player_id IS NOT NULL
                    UNION
                    SELECT legacy_player1_id AS legacy_player_id, player1_id AS player_id
                    FROM tournament_game
                    WHERE organization_id = :organizationId
                      AND legacy_player1_id IS NOT NULL
                      AND player1_id IS NOT NULL
                    UNION
                    SELECT legacy_player2_id AS legacy_player_id, player2_id AS player_id
                    FROM tournament_game
                    WHERE organization_id = :organizationId
                      AND legacy_player2_id IS NOT NULL
                      AND player2_id IS NOT NULL
                ),
                mapped_by_player AS (
                    SELECT player_id, MIN(legacy_player_id) AS legacy_player_id
                    FROM mapped
                    GROUP BY player_id
                )
                SELECT
                    COALESCE(mbp.legacy_player_id, po.player_id) AS id,
                    mbp.legacy_player_id AS mapped_legacy_player_id,
                    p.name_show,
                    p.name_alph,
                    p.slug,
                    u.photo
                FROM player_organization po
                INNER JOIN player p ON p.id = po.player_id
                LEFT JOIN app_user u ON p.id = u.player_id
                LEFT JOIN mapped_by_player mbp ON mbp.player_id = po.player_id
                WHERE po.organization_id = :organizationId
                  AND p.name_show <> '\"Okrutniki\" -'
                ORDER BY p.name_alph COLLATE \"pl-PL-x-icu\" ASC";
        $result = $this->connection->executeQuery($sql, ['organizationId' => $organizationId]);
        $rows = $result->fetchAllAssociative();

        $players = [];
        foreach ($rows as $player) {
            $playerId = (int) $player['id'];
            if ($player['mapped_legacy_player_id'] === null) {
                // to są z jakiegoś powodu nazwy klubów. do wyczyszczenia potem
                continue;
            }
            $players[] = new PlayersListPlayer(
                $playerId,
                (string) $player['name_show'],
                (string) $player['name_alph'],
                $player['slug'],
                $player['photo'],
            );
        }

        return new PlayersList($players);
    }

    /**
     * @throws Exception
     */
    private function fetchOrganizationId(): ?int
    {
        $value = $this->connection->fetchOne(
            'SELECT id FROM organization WHERE code = :code LIMIT 1',
            ['code' => self::ORGANIZATION_CODE]
        );

        if ($value === false || $value === null) {
            return null;
        }

        return (int) $value;
    }
}
