<?php

declare (strict_types=1);
namespace App\Tests\Integration;

use App\Command\TournamentImport\ImportPfsTournamentCommand;
use App\Service\{PfsNameNormalizer, PfsPlayerIdentityResolver, PfsTournamentWebsiteClient, PfsTournamentCalendarParser, PfsTournamentResultsParser, PfsTournamentPayloadFactory, PlayerCatalogService, TournamentRoundImportService, PfsTournamentArchive, PfsTournamentImportPreflight, DatasetVersionService, RefreshCacheAfterImportLauncher};
use App\Service\OldMethodCurrentRanking\OldMethodCurrentRankingServicePostgres;
use App\Service\PfsImportVerification\HistoryManifestVerifier;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Console\Tester\CommandTester;
final class PfsTournamentImportRetryTest extends PfsImportDatabaseTestCase
{
    private function command(string $dir, DatasetVersionService $version, int &$downloads): CommandTester
    {
        $html = file_get_contents(__DIR__ . '/../Fixtures/pfs_tournament_html/minimal.html');
        $calendar = "<script>turnieje[123] = '<i>Test Tournament</i><br><b>1 stycznia, Warszawa</b>'; turnieje[124] = '<i>Test Tournament</i><br><b>2 stycznia, Warszawa</b>';</script>";
        $client = new PfsTournamentWebsiteClient(static function (string $url) use ($html, $calendar, &$downloads): string {
            if (str_contains($url, 'kalendarz.php')) {
                return $calendar;
            }
            $downloads++;
            return $html;
        });
        $n = new PfsNameNormalizer();
        $parser = new PfsTournamentResultsParser();
        $archive = new PfsTournamentArchive($dir, $client);
        $entries = [];
        foreach ((new PfsTournamentCalendarParser())->parse($calendar, 2026) as $event) {
            $src = $archive->load($event);
            $entries[] = ['pfsId' => $event->urlId, 'date' => (int) $event->startDate->format('Ymd'), 'name' => 'Test Tournament', 'city' => 'Warszawa', 'path' => $src->path, 'sha256' => $src->sha256];
        }
        $path = $dir . '/manifest.json';
        $v = new HistoryManifestVerifier($this->db);
        file_put_contents($path, json_encode(['formatVersion' => 1, 'organizationId' => 21, 'baselineDate' => 20251231, 'baselineId' => 40, 'baselineFingerprint' => $v->baselineFingerprint(21, 20251231, 40), 'events' => $entries], JSON_THROW_ON_ERROR));
        return new CommandTester(new ImportPfsTournamentCommand($client, new PfsTournamentCalendarParser(), $parser, new PfsTournamentPayloadFactory(), new TournamentRoundImportService($this->db, $n, new PlayerCatalogService($this->db, $n), new OldMethodCurrentRankingServicePostgres($this->db)), new RefreshCacheAfterImportLauncher($dir, new NullLogger()), $archive, new PfsTournamentImportPreflight($this->db, $path), $version, $this->db));
    }
    private function state(): array
    {
        $state = [];
        foreach (['player', 'player_organization', 'tournament', 'tournament_result', 'tournament_game', 'ranking', 'text_resource'] as $table) {
            $state[$table] = $this->db->fetchOne("SELECT md5(string_agg(row_to_json(x)::text,'' ORDER BY row_to_json(x)::text)) FROM {$table} x");
        }
        return $state;
    }
    public function testRepeatLegacyNoOpAndResumeDoNotDuplicateOrDownload(): void
    {
        $this->seedRankingHistory();
        $dir = sys_get_temp_dir() . '/pfs-cli-' . bin2hex(random_bytes(6));
        mkdir($dir);
        $downloads = 0;
        $version = new DatasetVersionService(new ArrayAdapter());
        $tester = $this->command($dir, $version, $downloads);
        self::assertSame(0, $tester->execute(['--pfsid' => '123', '--year' => '2026', '--no-cache-warmup' => true]), $tester->getDisplay());
        $state = $this->state();
        $v = $version->getVersion();
        $count = $downloads;
        self::assertSame(0, $tester->execute(['--pfsid' => '123', '--year' => '2026', '--no-cache-warmup' => true]), $tester->getDisplay());
        self::assertStringContainsString('unchanged', $tester->getDisplay());
        self::assertSame($state, $this->state());
        self::assertSame($v, $version->getVersion());
        self::assertSame($count, $downloads);
        $this->db->executeStatement('UPDATE tournament SET urlid=NULL WHERE urlid=123');
        self::assertSame(0, $tester->execute(['--pfsid' => '123', '--year' => '2026', '--no-cache-warmup' => true]), $tester->getDisplay());
        self::assertSame(0, $tester->execute(['--pfsid' => '124', '--year' => '2026', '--no-cache-warmup' => true]), $tester->getDisplay());
        self::assertSame(5, (int) $this->db->fetchOne('SELECT count(*) FROM tournament'));
        foreach (glob($dir . '/*') as $f) {
            unlink($f);
        }
        rmdir($dir);
    }
    public function testDivergentExistingImportIsBlockedWithoutOverwriting(): void
    {
        $this->seedRankingHistory();$dir=sys_get_temp_dir().'/pfs-conflict-'.bin2hex(random_bytes(6));mkdir($dir);$downloads=0;
        $tester=$this->command($dir,new DatasetVersionService(new ArrayAdapter()),$downloads);
        self::assertSame(0,$tester->execute(['--pfsid'=>'123','--year'=>'2026','--no-cache-warmup'=>true]));
        $this->db->executeStatement('UPDATE tournament_game SET result1=result1+1 WHERE player1_id=10');$before=$this->state();
        self::assertSame(1,$tester->execute(['--pfsid'=>'123','--year'=>'2026','--no-cache-warmup'=>true]));
        self::assertStringContainsString('blocked',$tester->getDisplay());self::assertSame($before,$this->state());
        foreach(glob($dir.'/*') as $f){unlink($f);}rmdir($dir);
    }

    public function testAnotherConnectionCannotImportWhileOrganizationIsLocked(): void
    {
        $this->seedRankingHistory();
        $this->db->beginTransaction();
        $this->db->executeStatement('SELECT pg_advisory_xact_lock(73673,21)');
        $level = error_reporting(E_ALL & ~E_DEPRECATED);
        try {
            $other = \Doctrine\DBAL\DriverManager::getConnection($this->db->getParams());
            $other->connect();
        } finally {
            error_reporting($level);
        }
        $other->executeStatement("SET lock_timeout='50ms'");
        $n = new PfsNameNormalizer();
        $service = new TournamentRoundImportService($other, $n, new PlayerCatalogService($other, $n), new OldMethodCurrentRankingServicePostgres($other));
        $event = new \App\PfsTournamentImport\CalendarTournament(123, 'Test Tournament', 'Warszawa', new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-01-01'));
        $r = (new PfsTournamentResultsParser())->parse(file_get_contents(__DIR__ . '/../Fixtures/pfs_tournament_html/minimal.html'));
        try {
            $service->import((new PfsTournamentPayloadFactory())->create($event, $r));
            self::fail('Expected organization lock timeout');
        } catch (\Doctrine\DBAL\Exception $e) {
            self::assertStringContainsString('lock timeout', $e->getMessage());
        } finally {
            $other->close();
            $this->db->rollBack();
        }
        self::assertSame(0, (int) $this->db->fetchOne('SELECT count(*) FROM tournament WHERE urlid=123'));
    }
    public function testCacheFailureReportsCommittedDataAndDoesNotCauseDuplicateOnRetry(): void
    {
        $this->seedRankingHistory();
        $dir = sys_get_temp_dir() . '/pfs-cache-' . bin2hex(random_bytes(6));
        mkdir($dir);
        $downloads = 0;
        $pool = $this->createStub(\Psr\Cache\CacheItemPoolInterface::class);
        $pool->method('getItem')->willReturn((new ArrayAdapter())->getItem('app.dataset.version'));
        $pool->method('save')->willReturn(false);
        $tester = $this->command($dir, new DatasetVersionService($pool), $downloads);
        self::assertSame(1, $tester->execute(['--pfsid' => '123', '--year' => '2026', '--no-cache-warmup' => true]));
        self::assertStringContainsString('COMMITTED', $tester->getDisplay());
        self::assertSame(1, (int) $this->db->fetchOne('SELECT count(*) FROM tournament WHERE urlid=123'));
        self::assertSame(0, $tester->execute(['--pfsid' => '123', '--year' => '2026', '--no-cache-warmup' => true]));
        foreach (glob($dir . '/*') as $f) {
            unlink($f);
        }
        rmdir($dir);
    }
}
