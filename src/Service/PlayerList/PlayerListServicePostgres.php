<?php

namespace App\Service\PlayerList;

use App\ApiResource\PlayersList\PlayersList;
use App\ApiResource\PlayersList\PlayersListPlayer;
use App\Repository\UserRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class PlayerListServicePostgres implements PlayerListServiceInterface
{
    private const string ORGANIZATION_CODE = 'PFS';
    private const array UNMAPPED_PLAYER_ID_OVERRIDES = [
        'Alicja Jesionowska|Jesionowska Alicja' => 2565,
        'Alicja Walaszewska-Kempa|Walaszewska-Kempa Alicja' => 2752,
        'Babki z Rodzynkiem|Rodzynkiem Babki z' => 2238,
        'Blank Szczecin|Szczecin Blank' => 2237,
        'Blank.II Szczecin|Szczecin Blank.II' => 2574,
        'Blubry Poznań|Poznań Blubry' => 2571,
        'Blubry.II Poznań|Poznań Blubry.II' => 2575,
        'Dąbek Warszawa|Warszawa Dąbek' => 2576,
        'Elżbieta Świecka|Świecka Elżbieta' => 1991,
        'F-16 Gdańsk|Gdańsk F-16' => 2722,
        'Garden Club1|Club1 Garden' => 1086,
        'Ghost Kraków|Ghost Kraków' => 2373,
        'Grajmyż Piła|Piła Grajmyż' => 2232,
        'Grzegorz Wączkowski|Wączkowski Grzegorz' => 1817,
        'Górnośląski Klub Scrabble|Scrabble Górnośląski Klub' => 1519,
        'Kantor Wisła|Wisła Kantor' => 365,
        'Kwartet Czyli Kwintet|Kwintet Kwartet Czyli' => 2185,
        'MOC Warszawa|Warszawa MOC' => 1101,
        'Macaki Warszawa|Warszawa Macaki' => 1643,
        'Matriks Milanówek|Milanówek Matriks' => 2234,
        'Mikrus MDK|MDK Mikrus' => 1960,
        'Mikrus Rumia|Rumia Mikrus' => 2573,
        'Monika Nowakowska|Nowakowska Monika' => 2172,
        'Niedoczas NML|NML Niedoczas' => 3335,
        'OSPS Anagram|Anagram OSPS' => 2236,
        'Okrutniki|Okrutniki' => 1723,
        'Paranoja Warszawa|Warszawa Paranoja' => 2231,
        'Siódemka Wrocław|Siódemka Wrocław' => 2075,
        'Stacja Wadowice|Wadowice Stacja' => 3333,
        'Start Kołobrzeg|Kołobrzeg Start' => 2577,
        'Szkrab Racibórz|Racibórz Szkrab' => 3334,
        'ŁKMS|ŁKMS' => 2235,
    ];

    public function __construct(
        #[Autowire(service: 'doctrine.dbal.default_connection')]
        private Connection $connection,
        private UserRepository $userRepository,
    ) {
    }

    /**
     * @throws Exception
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
                    p.name_alph
                FROM player_organization po
                INNER JOIN player p ON p.id = po.player_id
                LEFT JOIN mapped_by_player mbp ON mbp.player_id = po.player_id
                WHERE po.organization_id = :organizationId
                  AND p.name_show <> '\"Okrutniki\" -'
                ORDER BY p.name_alph COLLATE \"pl-PL-x-icu\" ASC";
        $result = $this->connection->executeQuery($sql, ['organizationId' => $organizationId]);
        $rows = $result->fetchAllAssociative();
        $photosByPlayerId = $this->loadPhotosByPlayerId($rows);
        $players = [];

        foreach ($rows as $player) {
            $playerId = (int) $player['id'];
            if ($player['mapped_legacy_player_id'] === null) {
                $key = $player['name_show'] . '|' . $player['name_alph'];
                $playerId = self::UNMAPPED_PLAYER_ID_OVERRIDES[$key] ?? $playerId;
            }
            $players[] = new PlayersListPlayer(
                $playerId,
                (string) $player['name_show'],
                (string) $player['name_alph'],
                $photosByPlayerId[$playerId] ?? null,
            );
        }

        return new PlayersList($players);
    }

    /**
     * @param list<array{id: int|string, name_show: string, name_alph: string}> $playerRows
     * @return array<int, string>
     */
    private function loadPhotosByPlayerId(array $playerRows): array
    {
        $playerIds = [];
        foreach ($playerRows as $row) {
            $playerIds[] = (int) $row['id'];
        }

        $playerIds = array_values(array_unique($playerIds));
        if ($playerIds === []) {
            return [];
        }

        $photosByPlayerId = [];
        $users = $this->userRepository->findBy(['playerId' => $playerIds]);

        foreach ($users as $user) {
            $playerId = $user->getPlayerId();
            $photo = $user->getPhoto();

            if ($playerId === null || $photo === null || $photo === '') {
                continue;
            }

            if (!isset($photosByPlayerId[$playerId])) {
                $photosByPlayerId[$playerId] = $photo;
            }
        }

        return $photosByPlayerId;
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
