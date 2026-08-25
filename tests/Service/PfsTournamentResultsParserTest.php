<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\PfsTournamentImport\ParsedTournamentRankingRow;
use App\Service\PfsTournamentResultsParser;
use PHPUnit\Framework\TestCase;

final class PfsTournamentResultsParserTest extends TestCase
{
    public function testParsesTournamentDetailsAndPlayerResultsFromDownloadedFixture(): void
    {
        $parser = new PfsTournamentResultsParser();

        $results = $parser->parse($this->fixtureHtml('2026-02-14-v-wielicki-turniej-w-scrabble-wieliczka.html'));

        self::assertSame('V Wielicki Turniej w Scrabble', $results->tournamentName);
        self::assertSame('Mariusz Wrześniewski', $results->getDetailValue('Sędzia'));
        self::assertSame(
            "Wielickie Centrum Kultury\n\nRynek Górny 6\n\n32-020 Wieliczka",
            $results->getDetailValue('Miejsce rozgrywek')
        );
        self::assertCount(63, $results->players);
        self::assertCount(63, $results->standings);
        self::assertCount(361, $results->roundGames);
        self::assertCount(654, $results->ranking);

        $firstStanding = $results->standings[0];
        self::assertSame(1, $firstStanding->place);
        self::assertSame('Łukasz Bobowski', $firstStanding->playerName);
        self::assertSame('Suchedniów', $firstStanding->city);
        self::assertSame(10.0, $firstStanding->bigPoints);
        self::assertSame(4935, $firstStanding->smallPoints);
        self::assertSame(2209, $firstStanding->scalps);
        self::assertSame(576, $firstStanding->pointsDiff);

        $firstRoundGame = $results->roundGames[0];
        self::assertSame(1, $firstRoundGame->round);
        self::assertSame(1, $firstRoundGame->table);
        self::assertSame('Przemysław Herdzina', $firstRoundGame->hostName);
        self::assertSame('Zuzanna Rapacz', $firstRoundGame->guestName);
        self::assertSame(464, $firstRoundGame->hostScore);
        self::assertSame(293, $firstRoundGame->guestScore);

        $firstPlayer = $results->players[0];
        self::assertSame('Przemysław Herdzina', $firstPlayer->playerName);
        self::assertSame(177.79, $firstPlayer->tournamentRank);
        self::assertSame('Kraków', $firstPlayer->city);
        self::assertSame(1980, $firstPlayer->totalScalp);
        self::assertSame(12, $firstPlayer->roundsPlayed);
        self::assertSame(165.00, $firstPlayer->rankAchieved);
        self::assertCount(12, $firstPlayer->games);

        $firstGame = $firstPlayer->games[0];
        self::assertSame(1, $firstGame->round);
        self::assertSame(1, $firstGame->table);
        self::assertSame('Zuzanna Rapacz', $firstGame->opponentName);
        self::assertSame(133.97, $firstGame->opponentRank);
        self::assertSame('+', $firstGame->result);
        self::assertSame(464, $firstGame->pointsFor);
        self::assertSame(293, $firstGame->pointsAgainst);
        self::assertSame(184, $firstGame->scalp);
    }

    public function testParsesByeRows(): void
    {
        $parser = new PfsTournamentResultsParser();

        $html = <<<'HTML'
<table id='szczegoly'><tr><td>Sędzia</td><td>Test Ref</td></tr></table>
<div id='p_klasyfikacja'><pre>Wyniki turnieju Test

Lp. Imię            Nazwisko             Miasto               Ranking  Duże pkt Małe pkt Skalpy   Różn. MP
--- --------------- -------------------- -------------------- -------- -------- -------- -------- --------
  1 Player          Example              Miasto                 100.00      1.0      350      170       50
</pre></div>
<div id='p_rundy'><pre>Wyniki turnieju Test

Runda nr  1
-----------
Stół Gospodarz                             Gość                                Wynik
---- ----------------------------------- - ----------------------------------- ---------
  29 Player         Example               - PAUZA

Runda nr  2
-----------
Stół Gospodarz                             Gość                                Wynik
---- ----------------------------------- - ----------------------------------- ---------
   3 Player         Example               - Opponent       Name                 350: 300
</pre></div>
<div id='p_hh'><pre>Wyniki poszczególnych graczy w turnieju Test

	Player Example 100.00 Miasto

Runda Stół  Przeciwnik                          Ranking   Wynik     Skalp
----- ----- ----------------------------------- ------- - --------- -----
    1    29 PAUZA
    2     3 Opponent Name                       120.00 +  350: 300  170
                                                          --------------- ---- ------
                                                                     170 / 1 170.00
</pre></div>
<div id='ranking'><pre>Ranking po turnieju Test

Lp. Imię            Nazwisko             Miasto               Ranking  Skalpy   Gry
--- --------------- -------------------- -------------------- -------- -------- --------
  1 Player          Example              Miasto                100.00      170        1
</pre></div>
HTML;

        $results = $parser->parse($html);
        $games = $results->players[0]->games;

        self::assertCount(2, $games);
        self::assertTrue($games[0]->isBye);
        self::assertSame('PAUZA', $games[0]->opponentName);
        self::assertNull($games[0]->opponentRank);
        self::assertNull($games[0]->result);
        self::assertNull($games[0]->pointsFor);
        self::assertNull($games[0]->pointsAgainst);
        self::assertNull($games[0]->scalp);
        self::assertFalse($games[1]->isBye);
        self::assertSame(170, $results->players[0]->totalScalp);
        self::assertSame(1, $results->players[0]->roundsPlayed);
    }

    public function testParsesCurrentRankingWaitingListRowsFromDownloadedFixture(): void
    {
        $rows = $this->parseRankingFixture('2026-02-14-v-wielicki-turniej-w-scrabble-wieliczka.html');

        self::assertCount(654, $rows);
        $this->assertRankingRow($rows[0], 1, 'Dominik Urbacki', 'Pruszków', 188.06, 37424, 199, true);
        $this->assertRankingRow($rows[343], 1, 'Beata Kowaluk-Jagielska', 'Stepnica', 107.76, 3125, 29, false);
        $this->assertRankingRow($rows[653], 311, 'Jakub Zarzycki', 'Lublin', 50.00, 50, 1, false);
    }

    public function testParsesOldStyleRankingRowsFromDownloadedFixture(): void
    {
        $rows = $this->parseRankingFixture('2002-09-14-i-mistrzostwa-lublina-lublin.html');

        self::assertCount(446, $rows);
        $this->assertRankingRow($rows[0], 1, 'Paweł Stanosz', 'Warszawa', 146.22, 8481, 58, true);
        $this->assertRankingRow($rows[64], 65, 'Justyna Fugiel', 'Sandomierz', 117.10, 9017, 77, true);
        $this->assertRankingRow($rows[65], 65, 'Jerzy Matyasik', 'Kraków', 117.10, 19790, 169, true);
        $this->assertRankingRow($rows[195], 1, 'Ewa Zając', 'Mrągowo', 99.72, 2892, 29, false);
        $this->assertRankingRow($rows[445], 245, 'Elżbieta Jaskuła', 'Zielona Góra', 0.00, 0, 0, false);
    }

    public function testParsesSurnameFirstRankingRowsFromDownloadedFixture(): void
    {
        $rows = $this->parseRankingFixture('2003-02-15-viii-mistrzostwa-warszawy-warszawa.html');

        self::assertCount(499, $rows);
        $this->assertRankingRow($rows[0], 1, 'Mariusz Skrobosz', 'Warszawa', 143.32, 28521, 199, true);
        $this->assertRankingRow($rows[234], 235, 'Krzysztof Jaworski', 'Warszawa', 92.03, 2669, 29, false);
        $this->assertRankingRow($rows[498], 499, 'Barbara Graczykowska', 'Łódź', 50.00, 50, 1, false);
    }

    public function testParsesDottedRankingRowsFromDownloadedFixture(): void
    {
        $rows = $this->parseRankingFixture('2011-10-29-ii-otwarte-mistrzostwa-bialegostoku-o-puchar-przewodniczacego-rady-miejskiej-bialystok.html');

        self::assertCount(713, $rows);
        $this->assertRankingRow($rows[0], 1, 'Dariusz Kosz', 'Nysa', 153.96, 30792, 200, true);
        $this->assertRankingRow($rows[329], 1, 'Anna Krynicka', 'Warszawa', 120.38, 3491, 29, false);
        $this->assertRankingRow($rows[712], 384, 'Bernadeta Zapała', 'Jaworzno', 50.00, 50, 1, false);
    }

    private function parseRankingFixture(string $filename): array
    {
        return (new PfsTournamentResultsParser())->parseRankingRows($this->fixtureHtml($filename));
    }

    private function fixtureHtml(string $filename): string
    {
        return (string) file_get_contents(__DIR__ . '/../Fixtures/pfs_tournament_html/' . $filename);
    }

    private function assertRankingRow(
        ParsedTournamentRankingRow $row,
        int $position,
        string $playerName,
        string $city,
        float $rank,
        int $scalp,
        int $games,
        bool $main,
    ): void {
        self::assertSame($position, $row->position);
        self::assertSame($playerName, $row->playerName);
        self::assertSame($city, $row->city);
        self::assertSame($rank, $row->rank);
        self::assertSame($scalp, $row->scalp);
        self::assertSame($games, $row->games);
        self::assertSame($main, $row->main);
    }
}
