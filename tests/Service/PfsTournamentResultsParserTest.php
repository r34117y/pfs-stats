<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\PfsTournamentResultsParser;
use PHPUnit\Framework\TestCase;

final class PfsTournamentResultsParserTest extends TestCase
{
    public function testParsesTournamentDetailsAndPlayerResults(): void
    {
        $parser = new PfsTournamentResultsParser();

        $html = <<<'HTML'
<html>
<head><title>Polska Federacja Scrabble :: V Wielicki Turniej w Scrabble</title></head>
<body>
<table id='szczegoly' class='ramkadolna'>
    <tr><td>Rodzaj turnieju</td><td>otwarty</td></tr>
    <tr><td>Miejsce rozgrywek</td><td>Wielickie Centrum Kultury<br><br>Rynek Górny 6<br>32-020 Wieliczka</td></tr>
    <tr><td>Sędzia</td><td>Mariusz Wrześniewski</td></tr>
</table>
<div id='p_klasyfikacja' class='page'><pre>Wyniki turnieju V Wielicki Turniej w Scrabble

Lp. Imię            Nazwisko             Miasto               Ranking  Duże pkt Małe pkt Skalpy   Różn. MP
--- --------------- -------------------- -------------------- -------- -------- -------- -------- --------
  1 Paweł           Mazurek              Polanica-Zdrój         177.06      2.0      828      379      135
  2 Przemysław      Herdzina             Kraków                 177.79      3.0     1735      645      682
</pre></div>
<div id='p_rundy' class='page'><pre>Wyniki poszczególnych rund turnieju V Wielicki Turniej w Scrabble

Runda nr  1
-----------
Stół Gospodarz                             Gość                                Wynik
---- ----------------------------------- - ----------------------------------- ---------
   1 Przemysław     Herdzina             - Zuzanna        Rapacz                464: 293
   2 Dorota         Rudzińska            - Paweł          Mazurek               317: 334

Runda nr  2
-----------
Stół Gospodarz                             Gość                                Wynik
---- ----------------------------------- - ----------------------------------- ---------
   9 Przemysław     Herdzina             - Stanisław      Gasik                 440: 285
  14 Paweł          Mazurek              - Arkadiusz      Łydka                 494: 376
</pre></div>
<div id='p_hh' class='page'><pre>﻿Wyniki poszczególnych graczy w turnieju V Wielicki Turniej w Scrabble



	Przemysław Herdzina 177.79 Kraków

Runda Stół  Przeciwnik                          Ranking   Wynik     Skalp
----- ----- ----------------------------------- ------- - --------- -----
    1     1 Zuzanna        Rapacz                133.97 +  464: 293  184
    2     9 Stanisław      Gasik                 138.24 +  440: 285  188
    3     5 Zdzisław       Stańkowski            141.98 +  461: 354  192
    4     2 Marzena        Ziębicka              130.70 -  370: 406   81
                                                          --------------- ---- ------
                                                                     645 / 4 161.25




	Paweł Mazurek 177.06 Polanica-Zdrój

Runda Stół  Przeciwnik                          Ranking   Wynik     Skalp
----- ----- ----------------------------------- ------- - --------- -----
    1     2 Dorota         Rudzińska             133.48 +  334: 317  183
    2    14 Arkadiusz      Łydka                 145.74 +  494: 376  196
                                                          --------------- ---- ------
                                                                     379 / 2 189.50
</pre></div>
</body>
</html>
HTML;

        $results = $parser->parse($html);

        self::assertSame('V Wielicki Turniej w Scrabble', $results->tournamentName);
        self::assertSame('Mariusz Wrześniewski', $results->getDetailValue('Sędzia'));
        self::assertSame(
            "Wielickie Centrum Kultury\n\nRynek Górny 6\n32-020 Wieliczka",
            $results->getDetailValue('Miejsce rozgrywek')
        );
        self::assertCount(2, $results->players);
        self::assertCount(2, $results->standings);
        self::assertCount(4, $results->roundGames);
        self::assertSame(1, $results->standings[0]->place);
        self::assertSame('Paweł Mazurek', $results->standings[0]->playerName);
        self::assertSame(2.0, $results->standings[0]->bigPoints);
        self::assertSame('Przemysław Herdzina', $results->roundGames[0]->hostName);
        self::assertSame('Zuzanna Rapacz', $results->roundGames[0]->guestName);
        self::assertSame(464, $results->roundGames[0]->hostScore);
        self::assertSame(293, $results->roundGames[0]->guestScore);

        $firstPlayer = $results->players[0];
        self::assertSame('Przemysław Herdzina', $firstPlayer->playerName);
        self::assertSame(177.79, $firstPlayer->tournamentRank);
        self::assertSame('Kraków', $firstPlayer->city);
        self::assertSame(645, $firstPlayer->totalScalp);
        self::assertSame(4, $firstPlayer->roundsPlayed);
        self::assertSame(161.25, $firstPlayer->rankAchieved);
        self::assertCount(4, $firstPlayer->games);

        $firstGame = $firstPlayer->games[0];
        self::assertSame(1, $firstGame->round);
        self::assertSame(1, $firstGame->table);
        self::assertSame('Zuzanna Rapacz', $firstGame->opponentName);
        self::assertSame(133.97, $firstGame->opponentRank);
        self::assertSame('+', $firstGame->result);
        self::assertSame(464, $firstGame->pointsFor);
        self::assertSame(293, $firstGame->pointsAgainst);
        self::assertSame(184, $firstGame->scalp);

        $secondPlayer = $results->players[1];
        self::assertSame('Paweł Mazurek', $secondPlayer->playerName);
        self::assertSame('Polanica-Zdrój', $secondPlayer->city);
        self::assertSame(379, $secondPlayer->totalScalp);
        self::assertSame(189.50, $secondPlayer->rankAchieved);
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
<div id='p_rundy'><pre>Wyniki poszczególnych rund turnieju Test

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
}
