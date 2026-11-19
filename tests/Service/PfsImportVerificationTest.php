<?php

declare (strict_types=1);
namespace App\Tests\Service;

use App\Tests\Integration\PfsImportDatabaseTestCase;
use App\PfsTournamentImport\{PfsTournamentSource, CalendarTournament};
use App\Service\PfsTournamentResultsParser;
use App\Service\PfsImportVerification\ImportedTournamentComparator;
final class PfsImportVerificationTest extends PfsImportDatabaseTestCase
{
    public function testSingleChangedScoreIsDetectedWithSameCountsAndRankings(): void
    {
        $this->seedRankingHistory();
        $result = $this->importFixture();
        $event = new CalendarTournament(123, 'Test Tournament', 'Warszawa', new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-01-01'));
        $html = file_get_contents(__DIR__ . '/../Fixtures/pfs_tournament_html/minimal.html');
        $source = new PfsTournamentSource($event, 'fixture', hash('sha256', $html), (new PfsTournamentResultsParser())->parse($html));
        $compare = new ImportedTournamentComparator($this->db);
        self::assertSame([], $compare->compare($source, 21, $result->tournamentId));
        $this->db->executeStatement('UPDATE tournament_game SET result1=result1+1 WHERE player1_id=10');
        $diff = $compare->compare($source, 21, $result->tournamentId);
        self::assertContains('games', array_column($diff, 'field'));
    }
}
