<?php

declare (strict_types=1);
namespace App\Tests\Service;

use App\Service\PfsTournamentSourceValidator;
use PHPUnit\Framework\TestCase;
final class PfsTournamentSourceValidatorTest extends TestCase
{
    private function html(): string
    {
        return file_get_contents(__DIR__ . '/../Fixtures/pfs_tournament_html/current-1399.html');
    }
    public function testAcceptsCompleteFifteenRoundResults(): void
    {
        $r = (new PfsTournamentSourceValidator())->validate($this->html());
        self::assertCount(120, $r->players);
        self::assertCount(120, $r->standings);
        self::assertCount(891, $r->roundGames);
    }
    public function testRejectsSilentlySkippedRankingLineWithLocation(): void
    {
        $html = preg_replace("/(id='p_ranking'[^>]*><pre>)/", '$1' . "\n999 Corrupted ranking data\n", $this->html(), 1);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('p_ranking:2');
        (new PfsTournamentSourceValidator())->validate($html);
    }
    public function testRejectsChangedRoundScoreEvenIfRowCountsAreUnchanged(): void
    {
        $html = preg_replace("/(id='p_rundy'.*?428:) 363/s", '$1 999', $this->html(), 1);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('score');
        (new PfsTournamentSourceValidator())->validate($html);
    }
    public function testAcceptsOverflowedNameColumnsAndForfeit(): void
    {
        $validator = new PfsTournamentSourceValidator();
        self::assertCount(60, $validator->validate(file_get_contents(__DIR__ . '/../Fixtures/pfs_tournament_html/current-1425.html'))->players);
        self::assertNotEmpty($validator->validate(file_get_contents(__DIR__ . '/../Fixtures/pfs_tournament_html/current-1451.html'))->players);
    }
    public function testRejectsRecognizableDataDroppedBySelectedParserFormat(): void
    {
        $html=preg_replace("/(id='p_ranking'[^>]*><pre>)/", '$1'."\n999. Extra Person  Warsaw  100.00 100 1\n", $this->html(),1);
        $this->expectException(\RuntimeException::class);$this->expectExceptionMessage('Parsed rows missing');
        (new PfsTournamentSourceValidator())->validate($html);
    }

    public function testRejectsAnnouncement(): void
    {
        $this->expectException(\RuntimeException::class);
        (new PfsTournamentSourceValidator())->validate(file_get_contents(__DIR__ . '/../Fixtures/pfs_tournament_html/announcement-1399.html'));
    }
}
