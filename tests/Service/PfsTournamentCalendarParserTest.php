<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\PfsTournamentCalendarParser;
use PHPUnit\Framework\TestCase;

final class PfsTournamentCalendarParserTest extends TestCase
{
    public function testParsesCalendarEntriesAndInfersEndDate(): void
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
        self::assertSame('2026-02-01', $tournaments[0]->endDate->format('Y-m-d'));
        self::assertSame(1421, $tournaments[1]->urlId);
        self::assertSame('2026-03-14', $tournaments[1]->endDate->format('Y-m-d'));
        self::assertSame('XVII Mistrzostwa Wrocławia w Scrabble', $tournaments[2]->name);
        self::assertSame('2026-04-12', $tournaments[2]->endDate->format('Y-m-d'));
    }
}
