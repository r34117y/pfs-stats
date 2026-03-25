<?php

namespace App\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use JsonException;
use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Throwable;

final readonly class TournamentRoundRollbackService
{
    public function __construct(
        #[Autowire(service: 'doctrine.dbal.default_connection')]
        private Connection $connection,
    ) {
    }

    /**
     * @return array{
     *   organizationId:int,
     *   tournamentId:int,
     *   tournamentDbId:int,
     *   tournamentName:string|null,
     *   rankingDeleted:int,
     *   tournamentResultsDeleted:int,
     *   tournamentGamesDeleted:int,
     *   tournamentDeleted:int,
     *   auditDeleted:int,
     *   createdPlayersDeleted:int,
     *   createdPlayerOrganizationsDeleted:int,
     *   skippedCreatedPlayerIds:list<int>
     * }
     * @throws Exception
     * @throws JsonException
     * @throws Throwable
     */
    public function revertMostRecentImport(): array
    {
        $latestAudit = $this->fetchLatestImportAudit();
        if ($latestAudit === null) {
            throw new RuntimeException('No tournament round import audit record was found.');
        }

        return $this->connection->transactional(function (Connection $connection) use ($latestAudit): array {
            $currentLatestAudit = $this->fetchLatestImportAudit($connection);
            if ($currentLatestAudit === null || $currentLatestAudit['textResourceId'] !== $latestAudit['textResourceId']) {
                throw new RuntimeException('Only the most recent tournament round import can be reverted.');
            }

            $deletedRanking = $connection->executeStatement(
                'DELETE FROM ranking WHERE organization_id = :organizationId AND legacy_tournament_id = :tournamentId',
                [
                    'organizationId' => $latestAudit['organizationId'],
                    'tournamentId' => $latestAudit['tournamentId'],
                ],
            );

            $deletedTournamentResults = $connection->executeStatement(
                'DELETE FROM tournament_result WHERE organization_id = :organizationId AND legacy_tournament_id = :tournamentId',
                [
                    'organizationId' => $latestAudit['organizationId'],
                    'tournamentId' => $latestAudit['tournamentId'],
                ],
            );

            $deletedTournamentGames = $connection->executeStatement(
                'DELETE FROM tournament_game WHERE organization_id = :organizationId AND legacy_tournament_id = :tournamentId',
                [
                    'organizationId' => $latestAudit['organizationId'],
                    'tournamentId' => $latestAudit['tournamentId'],
                ],
            );

            $deletedTournament = $connection->executeStatement(
                'DELETE FROM tournament WHERE organization_id = :organizationId AND legacy_id = :tournamentId',
                [
                    'organizationId' => $latestAudit['organizationId'],
                    'tournamentId' => $latestAudit['tournamentId'],
                ],
            );

            if ($deletedTournament !== 1) {
                throw new RuntimeException(sprintf(
                    'Expected to delete exactly one tournament row for import %d, deleted %d.',
                    $latestAudit['tournamentId'],
                    $deletedTournament,
                ));
            }

            $deletedAudit = $connection->executeStatement(
                'DELETE FROM text_resource WHERE id = :id',
                ['id' => $latestAudit['textResourceId']],
            );

            $deletedPlayerOrganizations = 0;
            $deletedPlayers = 0;
            $skippedCreatedPlayerIds = [];

            foreach ($latestAudit['createdPlayerIds'] as $playerId) {
                if ($this->playerHasBlockingReferences($playerId)) {
                    $skippedCreatedPlayerIds[] = $playerId;
                    continue;
                }

                $deletedPlayerOrganizations += $connection->executeStatement(
                    'DELETE FROM player_organization WHERE player_id = :playerId AND organization_id = :organizationId',
                    [
                        'playerId' => $playerId,
                        'organizationId' => $latestAudit['organizationId'],
                    ],
                );

                if ($this->playerHasAnyRemainingReferencesOrAssociations($playerId)) {
                    $skippedCreatedPlayerIds[] = $playerId;
                    continue;
                }

                $deletedPlayers += $connection->executeStatement(
                    'DELETE FROM player WHERE id = :playerId',
                    ['playerId' => $playerId],
                );
            }

            return [
                'organizationId' => $latestAudit['organizationId'],
                'tournamentId' => $latestAudit['tournamentId'],
                'tournamentDbId' => $latestAudit['tournamentDbId'],
                'tournamentName' => $latestAudit['tournamentName'],
                'rankingDeleted' => $deletedRanking,
                'tournamentResultsDeleted' => $deletedTournamentResults,
                'tournamentGamesDeleted' => $deletedTournamentGames,
                'tournamentDeleted' => $deletedTournament,
                'auditDeleted' => $deletedAudit,
                'createdPlayersDeleted' => $deletedPlayers,
                'createdPlayerOrganizationsDeleted' => $deletedPlayerOrganizations,
                'skippedCreatedPlayerIds' => $skippedCreatedPlayerIds,
            ];
        });
    }

    /**
     * @return array{
     *   textResourceId:int,
     *   organizationId:int,
     *   tournamentId:int,
     *   tournamentDbId:int,
     *   tournamentName:string|null,
     *   createdPlayerIds:list<int>
     * }|null
     * @throws JsonException
     * @throws Exception
     */
    private function fetchLatestImportAudit(?Connection $connection = null): ?array
    {
        $connection ??= $this->connection;

        $row = $connection->fetchAssociative(
            'SELECT
                tr.id AS text_resource_id,
                tr.organization_id,
                tr.legacy_id AS tournament_id,
                tr.data,
                t.id AS tournament_db_id,
                COALESCE(t.fullname, t.name) AS tournament_name
             FROM text_resource tr
             LEFT JOIN tournament t
               ON t.organization_id = tr.organization_id
              AND t.legacy_id = tr.legacy_id
             WHERE tr.resource_type = :resourceType
             ORDER BY tr.id DESC
             LIMIT 1',
            ['resourceType' => TournamentRoundImportService::AUDIT_RESOURCE_TYPE],
        );

        if ($row === false) {
            return null;
        }

        $data = json_decode((string) $row['data'], true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new RuntimeException('Latest tournament import audit payload is invalid.');
        }

        $createdPlayerIds = $data['createdPlayerIds'] ?? [];
        if (!is_array($createdPlayerIds)) {
            throw new RuntimeException('Latest tournament import audit createdPlayerIds payload is invalid.');
        }

        return [
            'textResourceId' => (int) $row['text_resource_id'],
            'organizationId' => (int) $row['organization_id'],
            'tournamentId' => (int) $row['tournament_id'],
            'tournamentDbId' => isset($data['tournamentDbId']) ? (int) $data['tournamentDbId'] : (int) ($row['tournament_db_id'] ?? 0),
            'tournamentName' => $row['tournament_name'] !== null ? (string) $row['tournament_name'] : null,
            'createdPlayerIds' => array_values(array_map(static fn (mixed $playerId): int => (int) $playerId, $createdPlayerIds)),
        ];
    }

    /**
     * @throws Exception
     */
    private function playerHasBlockingReferences(int $playerId): bool
    {
        return (bool) $this->connection->fetchOne(
            'SELECT
                EXISTS(SELECT 1 FROM ranking WHERE player_id = :playerId)
                OR EXISTS(SELECT 1 FROM tournament_result WHERE player_id = :playerId)
                OR EXISTS(SELECT 1 FROM tournament_game WHERE player1_id = :playerId OR player2_id = :playerId)
                OR EXISTS(SELECT 1 FROM play_summary WHERE player_id = :playerId)
                OR EXISTS(SELECT 1 FROM tournament WHERE winner_player_id = :playerId)
                OR EXISTS(SELECT 1 FROM app_user WHERE player_id = :playerId)',
            ['playerId' => $playerId],
        );
    }

    /**
     * @throws Exception
     */
    private function playerHasAnyRemainingReferencesOrAssociations(int $playerId): bool
    {
        return (bool) $this->connection->fetchOne(
            'SELECT
                EXISTS(SELECT 1 FROM ranking WHERE player_id = :playerId)
                OR EXISTS(SELECT 1 FROM tournament_result WHERE player_id = :playerId)
                OR EXISTS(SELECT 1 FROM tournament_game WHERE player1_id = :playerId OR player2_id = :playerId)
                OR EXISTS(SELECT 1 FROM play_summary WHERE player_id = :playerId)
                OR EXISTS(SELECT 1 FROM tournament WHERE winner_player_id = :playerId)
                OR EXISTS(SELECT 1 FROM app_user WHERE player_id = :playerId)
                OR EXISTS(SELECT 1 FROM player_organization WHERE player_id = :playerId)',
            ['playerId' => $playerId],
        );
    }
}
