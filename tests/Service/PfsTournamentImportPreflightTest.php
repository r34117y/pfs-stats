<?php

declare (strict_types=1);
namespace App\Tests\Service;

use App\Tests\Integration\PfsImportDatabaseTestCase;
use App\PfsTournamentImport\{CalendarTournament, PfsTournamentSource};
use App\Service\{PfsTournamentImportPreflight, PfsTournamentResultsParser};
use App\Service\PfsImportVerification\HistoryManifestVerifier;
final class PfsTournamentImportPreflightTest extends PfsImportDatabaseTestCase
{
    public function testAppendRemainsValidAndBaselineMutationBlocks(): void
    {
        $this->seedRankingHistory();
        $path = tempnam(sys_get_temp_dir(), 'pfs-manifest-');
        $htmlPath = realpath(__DIR__ . '/../Fixtures/pfs_tournament_html/minimal.html');
        $hash = hash_file('sha256', $htmlPath);
        $v = new HistoryManifestVerifier($this->db);
        $entry = ['pfsId' => 123, 'date' => 20260101, 'name' => 'Test Tournament', 'city' => 'Warszawa', 'path' => $htmlPath, 'sha256' => $hash];
        $manifest = ['formatVersion' => 1, 'organizationId' => 21, 'baselineDate' => 20251231, 'baselineId' => 40, 'baselineFingerprint' => $v->baselineFingerprint(21, 20251231, 40), 'events' => [$entry]];
        file_put_contents($path, json_encode($manifest, JSON_THROW_ON_ERROR));
        $source = new PfsTournamentSource(new CalendarTournament(123, 'Test Tournament', 'Warszawa', new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-01-01')), $htmlPath, $hash, (new PfsTournamentResultsParser())->parse(file_get_contents($htmlPath)));
        $preflight = new PfsTournamentImportPreflight($this->db, $path);
        self::assertNull($preflight->check($source)->existingTournamentId);
        $r = $this->importFixture();
        self::assertSame($r->tournamentId, $preflight->check($source)->existingTournamentId);
        $this->db->executeStatement("UPDATE tournament SET fullname='modified' WHERE id=777");
        try {
            $preflight->check($source);
            self::fail('Expected stale baseline');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('historical data changed', $e->getMessage());
        } finally {
            unlink($path);
        }
    }
}
