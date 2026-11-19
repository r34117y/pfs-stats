<?php

declare (strict_types=1);
namespace App\Tests\Integration;

use App\PfsTournamentImport\CalendarTournament;
use App\Service\{PfsNameNormalizer, PfsTournamentPayloadFactory, PfsTournamentResultsParser, PlayerCatalogService, TournamentRoundImportService};
use App\Service\OldMethodCurrentRanking\OldMethodCurrentRankingServicePostgres;
final class PfsPlayerIdentityImportTest extends PfsImportDatabaseTestCase
{
    public function testConfirmedSameDateDebutCreatesOnlyProfileAndOfficialRowUntilSecondEvent(): void
    {
        $this->seedRankingHistory();
        $n = new PfsNameNormalizer();
        $service = new TournamentRoundImportService($this->db, $n, new PlayerCatalogService($this->db, $n), new OldMethodCurrentRankingServicePostgres($this->db));
        $r = (new PfsTournamentResultsParser())->parse(file_get_contents(__DIR__ . '/../Fixtures/pfs_tournament_html/minimal.html'));
        $event = new CalendarTournament(123, 'Test Tournament', 'Warszawa', new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-01-01'));
        $payload = (new PfsTournamentPayloadFactory())->create($event, $r);
        $payload->ranking[] = ['lp' => 2, 'main' => false, 'player' => 'Future Player', 'rank' => 100, 'games' => 1];
        $first = $service->import($payload, ['Future Player' => ['date' => 20260101, 'pfsId' => 124, 'sha256' => 'verified-by-preflight']]);
        $pid = (int) $this->db->fetchOne("SELECT id FROM player WHERE name_show='Future Player'");
        self::assertGreaterThan(0, $pid);
        self::assertSame(0, (int) $this->db->fetchOne('SELECT count(*) FROM tournament_result WHERE player_id=?', [$pid]));
        self::assertSame(0, (int) $this->db->fetchOne("SELECT count(*) FROM ranking WHERE player_id=? AND rtype IN ('n','u')", [$pid]));
        $payload->tournament['pfsUrlId'] = 124;
        $payload->tournament['name'] = 'Second same-day event';
        $payload->players[0]['firstName'] = 'Future';
        $payload->players[0]['lastName'] = 'Player';
        $second = $service->import($payload);
        self::assertSame(1, (int) $this->db->fetchOne('SELECT count(*) FROM tournament_result WHERE player_id=? AND tournament_id=?', [$pid, $second->tournamentId]));
        self::assertSame(1, (int) $this->db->fetchOne("SELECT count(*) FROM player WHERE name_show='Future Player'"));
    }
    public function testUnknownRankingOnlyIdentityRollsBackInsteadOfNullDereference(): void
    {
        $this->seedRankingHistory();
        $n = new PfsNameNormalizer();
        $service = new TournamentRoundImportService($this->db, $n, new PlayerCatalogService($this->db, $n), new OldMethodCurrentRankingServicePostgres($this->db));
        $r = (new PfsTournamentResultsParser())->parse(file_get_contents(__DIR__ . '/../Fixtures/pfs_tournament_html/minimal.html'));
        $event = new CalendarTournament(123, 'Test Tournament', 'Warszawa', new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-01-01'));
        $payload = (new PfsTournamentPayloadFactory())->create($event, $r);
        $payload->ranking[] = ['lp' => 2, 'main' => false, 'player' => 'Unconfirmed Person', 'rank' => 100, 'games' => 1];
        try {
            $service->import($payload);
            self::fail('Expected unresolved identity');
        } catch (\LogicException $e) {
            self::assertStringContainsString('confirmed evidence required', $e->getMessage());
        }
        self::assertSame(0, (int) $this->db->fetchOne('SELECT count(*) FROM tournament WHERE urlid=123'));
    }
}
