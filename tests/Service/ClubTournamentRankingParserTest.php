<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\ClubTournamentRankingParser;
use PHPUnit\Framework\TestCase;

final class ClubTournamentRankingParserTest extends TestCase
{
    public function testParsesCompletePublishedRanking(): void
    {
        $rows = (new ClubTournamentRankingParser())->parse($this->fixture(), 20261117);
        self::assertCount(24, $rows);
        self::assertSame(['position'=>1,'name'=>'Jakub Błażejewski','rank'=>163.70,'games'=>60], $rows[0]);
        self::assertSame('Bartosz Bartczak', $rows[6]['name']);
        self::assertSame(72.46, $rows[23]['rank']);
    }

    public function testRejectsWrongDateInsteadOfAcceptingLatestRanking(): void
    {
        $this->expectException(\RuntimeException::class);
        (new ClubTournamentRankingParser())->parse($this->fixture(), 20261124);
    }

    public function testParsesHistoricalPublishedSnapshot(): void
    {
        $html = file_get_contents(dirname(__DIR__).'/Fixtures/club_rankings/20260217.html');
        $rows = (new ClubTournamentRankingParser())->parse($html, 20260217);
        self::assertCount(24, $rows);
        self::assertSame('Jakub Hassa', $rows[0]['name']);
        self::assertSame(158.95, $rows[0]['rank']);
    }

    public function testRejectsTruncatedPage(): void
    {
        $this->expectException(\RuntimeException::class);
        (new ClubTournamentRankingParser())->parse(str_replace('</body>', '', $this->fixture()), 20261117);
    }

    public function testRejectsMissingPlayerRow(): void
    {
        $this->expectException(\RuntimeException::class);
        (new ClubTournamentRankingParser())->parse(str_replace('<td>2<td>', '<td>3<td>', $this->fixture()), 20261117);
    }

    public function testRejectsUnpublishedZeroGamePlaceholder(): void
    {
        $this->expectException(\App\ClubTournamentImport\ClubTournamentRankingUnavailable::class);
        (new ClubTournamentRankingParser())->parse(str_replace('<td>60', '<td>0', $this->fixture()), 20261117);
    }

    private function fixture(): string
    {
        return file_get_contents(dirname(__DIR__).'/Fixtures/club_rankings/20261117.html');
    }
}
