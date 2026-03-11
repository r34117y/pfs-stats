<?php

namespace App\Service;

use App\PfsTournamentImport\PfsPlayerImportRow;
use App\PfsTournamentImport\ResolvedPlayer;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class PfsPlayerResolverPostgres implements PfsPlayerResolverInterface
{
    private const string ORGANIZATION_CODE = 'PFS';

    public function __construct(
        #[Autowire(service: 'doctrine.dbal.default_connection')]
        private Connection $connection,
        private PfsNameNormalizer $nameNormalizer,
    ) {
    }

    /**
     * @param array<string, float> $playerRanksByName
     * @return array{resolved: array<string, ResolvedPlayer>, newPlayers: list<PfsPlayerImportRow>, warnings: list<string>}
     */
    public function resolve(array $playerRanksByName, int $tournamentId): array
    {
        $organizationId = $this->loadOrganizationId();
        $catalog = $organizationId !== null ? $this->loadCatalog($organizationId, $tournamentId) : [];
        $nextId = $organizationId !== null ? $this->fetchNextPlayerId($organizationId) : 1;
        $resolved = [];
        $newPlayers = [];
        $warnings = [];

        foreach ($playerRanksByName as $playerName => $tournamentRank) {
            $resolvedPlayer = $this->resolveSinglePlayer($catalog, $playerName, $tournamentRank, $warnings);
            if ($resolvedPlayer === null) {
                $nameAlph = $this->nameNormalizer->toAlphabeticalName($playerName);
                $resolvedPlayer = new ResolvedPlayer(
                    id: $nextId,
                    nameShow: $playerName,
                    nameAlph: $nameAlph,
                    seedRank: 100.0,
                    isNew: true,
                );
                $newPlayers[] = new PfsPlayerImportRow(
                    id: $nextId,
                    nameShow: $playerName,
                    nameAlph: $nameAlph,
                );
                $nextId++;
            }

            $resolved[$playerName] = $resolvedPlayer;
        }

        return [
            'resolved' => $resolved,
            'newPlayers' => $newPlayers,
            'warnings' => $warnings,
        ];
    }

    private function loadOrganizationId(): ?int
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

    /**
     * @return list<array{id:int,nameShow:string,nameAlph:string,normalized:string,latestRank:?float}>
     */
    private function loadCatalog(int $organizationId, int $tournamentId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'WITH player_map AS (
                SELECT DISTINCT legacy_player_id, player_id
                FROM ranking
                WHERE organization_id = :organizationId
                  AND legacy_player_id IS NOT NULL
                  AND player_id IS NOT NULL
                UNION
                SELECT DISTINCT legacy_player_id, player_id
                FROM tournament_result
                WHERE organization_id = :organizationId
                  AND legacy_player_id IS NOT NULL
                  AND player_id IS NOT NULL
                UNION
                SELECT DISTINCT legacy_player_id, player_id
                FROM play_summary
                WHERE organization_id = :organizationId
                  AND legacy_player_id IS NOT NULL
                  AND player_id IS NOT NULL
            ),
            latest AS (
                SELECT tr.legacy_player_id, tr.brank
                FROM tournament_result tr
                INNER JOIN (
                    SELECT legacy_player_id, MAX(legacy_tournament_id) AS last_tournament_id
                    FROM tournament_result
                    WHERE organization_id = :organizationId
                      AND legacy_tournament_id < :tournamentId
                      AND legacy_player_id IS NOT NULL
                    GROUP BY legacy_player_id
                ) latest_ids
                    ON latest_ids.legacy_player_id = tr.legacy_player_id
                   AND latest_ids.last_tournament_id = tr.legacy_tournament_id
                WHERE tr.organization_id = :organizationId
            )
            SELECT
                pm.legacy_player_id AS legacy_player_id,
                p.name_show AS name_show,
                p.name_alph AS name_alph,
                latest.brank AS latest_rank
            FROM player_map pm
            INNER JOIN player p ON p.id = pm.player_id
            LEFT JOIN latest ON latest.legacy_player_id = pm.legacy_player_id',
            [
                'organizationId' => $organizationId,
                'tournamentId' => $tournamentId,
            ],
        );

        return array_map(function (array $row): array {
            return [
                'id' => (int) $row['legacy_player_id'],
                'nameShow' => (string) $row['name_show'],
                'nameAlph' => (string) $row['name_alph'],
                'normalized' => $this->nameNormalizer->normalizeForMatch((string) $row['name_show']),
                'latestRank' => $row['latest_rank'] !== null ? (float) $row['latest_rank'] : null,
            ];
        }, $rows);
    }

    /**
     * @param list<array{id:int,nameShow:string,nameAlph:string,normalized:string,latestRank:?float}> $catalog
     * @param list<string> $warnings
     */
    private function resolveSinglePlayer(array $catalog, string $playerName, float $tournamentRank, array &$warnings): ?ResolvedPlayer
    {
        foreach ($catalog as $candidate) {
            if ($candidate['nameShow'] === $playerName) {
                return new ResolvedPlayer(
                    id: $candidate['id'],
                    nameShow: $candidate['nameShow'],
                    nameAlph: $candidate['nameAlph'],
                    seedRank: $candidate['latestRank'] ?? 100.0,
                    isNew: false,
                );
            }
        }

        $normalizedName = $this->nameNormalizer->normalizeForMatch($playerName);
        $normalizedTournamentRank = max(100.0, $tournamentRank);
        $normalizedMatches = array_values(array_filter(
            $catalog,
            static fn (array $candidate): bool => $candidate['normalized'] === $normalizedName,
        ));

        if (count($normalizedMatches) === 1) {
            $candidate = $normalizedMatches[0];

            return new ResolvedPlayer(
                id: $candidate['id'],
                nameShow: $candidate['nameShow'],
                nameAlph: $candidate['nameAlph'],
                seedRank: $candidate['latestRank'] ?? 100.0,
                isNew: false,
            );
        }

        if ($normalizedMatches !== []) {
            usort($normalizedMatches, static function (array $left, array $right) use ($normalizedTournamentRank): int {
                $leftDistance = abs(($left['latestRank'] ?? 100.0) - $normalizedTournamentRank);
                $rightDistance = abs(($right['latestRank'] ?? 100.0) - $normalizedTournamentRank);

                return $leftDistance <=> $rightDistance;
            });

            $best = $normalizedMatches[0];
            $bestDistance = abs(($best['latestRank'] ?? 100.0) - $normalizedTournamentRank);
            if ($bestDistance <= 1.0) {
                return new ResolvedPlayer(
                    id: $best['id'],
                    nameShow: $best['nameShow'],
                    nameAlph: $best['nameAlph'],
                    seedRank: $best['latestRank'] ?? 100.0,
                    isNew: false,
                );
            }

            $warnings[] = sprintf(
                'Ambiguous player match for "%s" at rank %.2f. Falling back to new player insert.',
                $playerName,
                $normalizedTournamentRank,
            );
        }

        return null;
    }

    private function fetchNextPlayerId(int $organizationId): int
    {
        $value = $this->connection->fetchOne(
            'WITH legacy_ids AS (
                SELECT legacy_player_id FROM ranking WHERE organization_id = :organizationId AND legacy_player_id IS NOT NULL
                UNION ALL
                SELECT legacy_player_id FROM tournament_result WHERE organization_id = :organizationId AND legacy_player_id IS NOT NULL
                UNION ALL
                SELECT legacy_player_id FROM play_summary WHERE organization_id = :organizationId AND legacy_player_id IS NOT NULL
                UNION ALL
                SELECT legacy_player1_id AS legacy_player_id FROM tournament_game WHERE organization_id = :organizationId AND legacy_player1_id IS NOT NULL
                UNION ALL
                SELECT legacy_player2_id AS legacy_player_id FROM tournament_game WHERE organization_id = :organizationId AND legacy_player2_id IS NOT NULL
                UNION ALL
                SELECT legacy_player1_id AS legacy_player_id FROM game_record WHERE organization_id = :organizationId AND legacy_player1_id IS NOT NULL
            )
            SELECT COALESCE(MAX(legacy_player_id), 0) + 1 AS next_id
            FROM legacy_ids',
            ['organizationId' => $organizationId],
        );

        return (int) $value;
    }
}
