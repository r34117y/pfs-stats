<?php

declare (strict_types=1);
namespace App\Tests\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
abstract class PfsImportDatabaseTestCase extends TestCase
{
    protected Connection $db;
    protected function setUp(): void
    {
        $url = getenv('PFS_IMPORT_TEST_DATABASE_URL');
        if (!$url) {
            self::markTestSkipped('Set PFS_IMPORT_TEST_DATABASE_URL to an isolated *_test database.');
        }
        $database = basename((string) parse_url($url, PHP_URL_PATH));
        if (!str_ends_with($database, '_test') || $url === getenv('DATABASE_URL')) {
            self::fail('Refusing to modify a database without a separate *_test identity.');
        }
        // DBAL 3 invokes deprecated PDO constants under PHP 8.5; isolate that vendor notice.
        $reporting = error_reporting(E_ALL & ~E_DEPRECATED);
        try {
            $this->db = DriverManager::getConnection(['url' => $url]);
            $this->db->connect();
        } finally {
            error_reporting($reporting);
        }
        $this->db->executeStatement('TRUNCATE organization, player RESTART IDENTITY CASCADE');
        $this->db->insert('organization', ['id' => 21, 'code' => 'PFS', 'name' => 'PFS']);
    }
    protected function seedRankingHistory(): void
    {
        foreach ([10 => ['Main Player', 'Player Main'], 11 => ['Wait Player', 'Player Wait']] as $id => [$name, $sort]) {
            $this->db->insert('player', ['id' => $id, 'name_show' => $name, 'name_alph' => $sort]);
            $this->db->insert('player_organization', ['player_id' => $id, 'organization_id' => 21]);
        }
        foreach ([777 => [20230423, 'Seed', null], 19 => [20230507, 'II Wielicki Turniej w Scrabble', 1271], 40 => [20251231, 'Anchor', 9999]] as $id => [$date, $name, $url]) {
            $this->db->insert('tournament', ['id' => $id, 'organization_id' => 21, 'dt' => $date, 'fullname' => $name, 'urlid' => $url]);
        }
        foreach ([10 => 150, 11 => 100] as $id => $rank) {
            $this->db->insert('tournament_result', ['organization_id' => 21, 'tournament_id' => 777, 'player_id' => $id, 'place' => $id - 9, 'games' => 30, 'trank' => $rank]);
            $this->db->insert('ranking', ['organization_id' => 21, 'tournament_id' => 777, 'player_id' => $id, 'rtype' => 'f', 'position' => $id - 9, 'rank' => $rank, 'games' => 30]);
        }
    }
    protected function importFixture(int $pfsId = 123, string $date = '2026-01-01'): \App\Service\TournamentRoundImportResult
    {
        $n = new \App\Service\PfsNameNormalizer();
        $old = new \App\Service\OldMethodCurrentRanking\OldMethodCurrentRankingServicePostgres($this->db);
        $service = new \App\Service\TournamentRoundImportService($this->db, $n, new \App\Service\PlayerCatalogService($this->db, $n), $old);
        $event = new \App\PfsTournamentImport\CalendarTournament($pfsId, 'Test Tournament', 'Warszawa', new \DateTimeImmutable($date), new \DateTimeImmutable($date));
        $r = (new \App\Service\PfsTournamentResultsParser())->parse(file_get_contents(__DIR__ . '/../Fixtures/pfs_tournament_html/minimal.html'));
        return $service->import((new \App\Service\PfsTournamentPayloadFactory())->create($event, $r));
    }
    protected function tearDown(): void
    {
        if (isset($this->db)) {
            while ($this->db->isTransactionActive()) {
                $this->db->rollBack();
            }
            $this->db->close();
        }
    }
}
