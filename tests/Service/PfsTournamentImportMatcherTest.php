<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\PfsTournamentImport\CalendarTournament;
use App\PfsTournamentImport\ImportedTournamentRecord;
use App\Service\PfsTournamentImportMatcher;
use PHPUnit\Framework\TestCase;

final class PfsTournamentImportMatcherTest extends TestCase
{
    public function testMatchesFinishedUnimportedTournamentsAndInfersNextId(): void
    {
        $matcher = new PfsTournamentImportMatcher();

        $calendarTournaments = [
            new CalendarTournament(1404, 'IV Mistrzostwa Redy', new \DateTimeImmutable('2026-01-18')),
            new CalendarTournament(1413, 'V Wadowickie Kremówkogranie', new \DateTimeImmutable('2026-02-01')),
            new CalendarTournament(1432, 'V Mistrzostwa Sochaczewa', new \DateTimeImmutable('2026-03-15')),
            new CalendarTournament(1421, 'VIII Babskie granie', new \DateTimeImmutable('2026-03-14')),
        ];

        $importedTournaments = [
            new ImportedTournamentRecord(202601180, 1404),
            new ImportedTournamentRecord(202602010, 1413),
            new ImportedTournamentRecord(202603140, 9999),
        ];

        $pendingImports = $matcher->matchPendingImports(
            $calendarTournaments,
            $importedTournaments,
            new \DateTimeImmutable('2026-03-20'),
        );

        self::assertCount(2, $pendingImports);
        self::assertSame(1421, $pendingImports[0]['calendarTournament']->urlId);
        self::assertSame(202603141, $pendingImports[0]['inferredId']);
        self::assertSame(1432, $pendingImports[1]['calendarTournament']->urlId);
        self::assertSame(202603150, $pendingImports[1]['inferredId']);
    }

    public function testIgnoresTournamentThatEndsToday(): void
    {
        $matcher = new PfsTournamentImportMatcher();

        $pendingImports = $matcher->matchPendingImports(
            [new CalendarTournament(1500, 'Today Event', new \DateTimeImmutable('2026-03-20'))],
            [],
            new \DateTimeImmutable('2026-03-20 13:00:00'),
        );

        self::assertSame([], $pendingImports);
    }
}
