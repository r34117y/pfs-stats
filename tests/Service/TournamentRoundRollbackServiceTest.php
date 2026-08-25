<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\TournamentRoundImportService;
use App\Service\TournamentRoundRollbackService;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

final class TournamentRoundRollbackServiceTest extends TestCase
{
    public function testRevertDeletesAllRankingRowsForAuditedTournament(): void
    {
        $rankingDeleteSql = null;
        $connection = $this->createStub(Connection::class);
        $connection
            ->method('transactional')
            ->willReturnCallback(static fn (\Closure $callback): mixed => $callback($connection));
        $connection
            ->method('fetchAssociative')
            ->willReturn([
                'text_resource_id' => 99,
                'organization_id' => 21,
                'tournament_id' => 500,
                'data' => json_encode([
                    'tournamentDbId' => 500,
                    'createdPlayerIds' => [],
                ], JSON_THROW_ON_ERROR),
                'tournament_name' => 'Test Tournament',
            ]);
        $connection
            ->method('executeStatement')
            ->willReturnCallback(static function (string $sql, array $params = []) use (&$rankingDeleteSql): int {
                if (str_starts_with($sql, 'DELETE FROM ranking')) {
                    $rankingDeleteSql = $sql;

                    return 4;
                }

                if (str_starts_with($sql, 'DELETE FROM tournament WHERE')) {
                    return 1;
                }

                return 1;
            });

        $summary = (new TournamentRoundRollbackService($connection))->revertMostRecentImport(21);

        self::assertSame(4, $summary['rankingDeleted']);
        self::assertStringContainsString('DELETE FROM ranking WHERE organization_id = :organizationId AND tournament_id = :tournamentId', $rankingDeleteSql);
        self::assertSame(500, $summary['tournamentId']);
    }

    public function testUsesTournamentImportAuditResourceType(): void
    {
        self::assertSame(9001, TournamentRoundImportService::AUDIT_RESOURCE_TYPE);
    }
}
