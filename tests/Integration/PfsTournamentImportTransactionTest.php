<?php

declare (strict_types=1);
namespace App\Tests\Integration;

use App\PfsTournamentImport\CalendarTournament;
use App\Service\{PfsNameNormalizer, PfsTournamentPayloadFactory, PfsTournamentResultsParser, PlayerCatalogService, TournamentRoundImportService};
use App\Service\OldMethodCurrentRanking\OldMethodCurrentRankingServiceInterface;
final class PfsTournamentImportTransactionTest extends PfsImportDatabaseTestCase
{
    private function runImport(bool $fail): void
    {
        $old = new class($fail) implements OldMethodCurrentRankingServiceInterface
        {
            public function __construct(private bool $fail)
            {
            }
            public function calculateCurrentRanking(): array
            {
                return [];
            }
            public function calculateRankingSnapshots(): iterable
            {
                return [];
            }
            public function calculateRankingSnapshotAfterTournament(int $organizationId, int $tournamentId): ?array
            {
                if ($this->fail) {
                    throw new \RuntimeException('deliberate ranking failure');
                }
                return ['rows' => [['playerId' => 1, 'position' => 1, 'rankExact' => 150.0, 'games' => 30]], 'waitingRows' => [['playerId' => 2, 'position' => 1, 'rankExact' => 100.0, 'games' => 1]]];
            }
        };
        $normalizer = new PfsNameNormalizer();
        $service = new TournamentRoundImportService($this->db, $normalizer, new PlayerCatalogService($this->db, $normalizer), $old);
        $calendar = new CalendarTournament(123, 'Test Tournament', 'Warszawa', new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-01-01'));
        $source = (new PfsTournamentResultsParser())->parse(file_get_contents(__DIR__ . '/../Fixtures/pfs_tournament_html/minimal.html'));
        $payload = (new PfsTournamentPayloadFactory())->create($calendar, $source);
        $payload->token = 'never-store-test-token';
        $service->import($payload);
    }
    public function testRollbackIncludesCreatedProfilesAndAllTournamentData(): void
    {
        try {
            $this->runImport(true);
            self::fail('Expected ranking failure');
        } catch (\RuntimeException $e) {
            self::assertSame('deliberate ranking failure', $e->getMessage());
        }
        foreach (['player', 'player_organization', 'tournament', 'tournament_result', 'tournament_game', 'ranking', 'text_resource'] as $table) {
            self::assertSame(0, (int) $this->db->fetchOne('SELECT count(*) FROM ' . $table), $table);
        }
    }
    public function testCommitsPairedGamesResultsAndOfficialLists(): void
    {
        $this->runImport(false);
        self::assertSame(1, (int) $this->db->fetchOne('SELECT count(*) FROM tournament'));
        self::assertSame(2, (int) $this->db->fetchOne('SELECT count(*) FROM tournament_game'));
        self::assertSame(2, (int) $this->db->fetchOne('SELECT count(*) FROM tournament_result'));
        self::assertSame(['f', 'n', 'u', 'w'], $this->db->fetchFirstColumn('SELECT rtype FROM ranking ORDER BY rtype'));
        self::assertSame(1, (int) $this->db->fetchOne('SELECT count(*) FROM text_resource'));
        self::assertStringNotContainsString('never-store-test-token', (string) $this->db->fetchOne('SELECT data FROM text_resource LIMIT 1'));
    }
}
