<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\PfsTournamentCalendarParser;
use PHPUnit\Framework\TestCase;

final class PfsTournamentCalendarParserTest extends TestCase
{
    public function testOfficialRankingMarkerDistinguishesOtherEvents(): void
    {
        $p=new PfsTournamentCalendarParser();
        $h="<tr><td><a href='../turniej.php?id=1'>Main</a> (turniej&nbsp;rankingowy)</td></tr><tr><td><a href='../turniej.php?id=2'>Masters</a> (turniej&nbsp;nierankingowy)</td></tr>";
        self::assertTrue($p->isRankingEvent($h,1));self::assertFalse($p->isRankingEvent($h,2));
    }

    public function testParsesCalendarEntriesAndInfersDateRange(): void
    {
        $parser = new PfsTournamentCalendarParser();

        $html = <<<'HTML'
<script>
turnieje[1413] = '<i>V Wadowickie Kremówkogranie</i><br><b>31 - 1 lutego, Wadowice</b>';
turnieje[1421] = '<i>VIII Babskie granie (turniej bez jaj)</i><br><b>14 marca, Katowice</b>';
turnieje[1409] = '<i>XVII Mistrzostwa Wrocławia w&nbsp;Scrabble</i><br><b>11 - 12 kwietnia, Wrocław</b>';
</script>
HTML;

        $tournaments = $parser->parse($html, 2026);

        self::assertCount(3, $tournaments);
        self::assertSame(1413, $tournaments[0]->urlId);
        self::assertSame('V Wadowickie Kremówkogranie', $tournaments[0]->name);
        self::assertSame('Wadowice', $tournaments[0]->location);
        self::assertSame('2026-01-31', $tournaments[0]->startDate->format('Y-m-d'));
        self::assertSame('2026-02-01', $tournaments[0]->endDate->format('Y-m-d'));
        self::assertSame(1421, $tournaments[1]->urlId);
        self::assertSame('2026-03-14', $tournaments[1]->startDate->format('Y-m-d'));
        self::assertSame('2026-03-14', $tournaments[1]->endDate->format('Y-m-d'));
        self::assertSame('XVII Mistrzostwa Wrocławia w Scrabble', $tournaments[2]->name);
        self::assertSame('Wrocław', $tournaments[2]->location);
        self::assertSame('2026-04-11', $tournaments[2]->startDate->format('Y-m-d'));
        self::assertSame('2026-04-12', $tournaments[2]->endDate->format('Y-m-d'));
    }
}
