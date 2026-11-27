<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\ClubTournamentImport\ParsedClubTournamentResults;
use App\Service\ClubTournamentResultsParser;
use App\Service\ClubTournamentResultsFileDecoder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ClubTournamentResultsParserTest extends TestCase
{
    public function testParsesHhFileAndComputesSameStandingsAsFinalResultsFile(): void
    {
        $parser = new ClubTournamentResultsParser();
        $parsed = $parser->parse($this->readFixture('blubry_wyniki/10032026_Blubry530_GP7_ZafuHH.txt'), $this->readFixture('blubry_wyniki/10032026_Blubry530_GP7_Zafu.txt'));

        self::assertSame('Blubry530_GP7_Zafu', $parsed->name);
        self::assertSame('2026-03-10', $parsed->date->format('Y-m-d'));
        self::assertCount(12, $parsed->players);
        self::assertSame('Natalia Woźniak', $parsed->players[9]->name);
        self::assertSame('Łukasz Kania', $parsed->players[6]->name);
        self::assertTrue($parsed->players[7]->games[1]->isBye);

        self::assertSame(
            $this->parseFinalStandings($this->readFixture('blubry_wyniki/10032026_Blubry530_GP7_Zafu.txt')),
            $this->computeStandings($parsed),
        );
    }

    public function testParsesAnotherFixtureWithPolishCharacters(): void
    {
        $parser = new ClubTournamentResultsParser();
        $parsed = $parser->parse($this->readFixture('blubry_wyniki/17032026_Blubry531_KuczbajHH.txt'), $this->readFixture('blubry_wyniki/17032026_Blubry531_Kuczbaj.txt'));

        self::assertSame('Blubry531_Kuczbaj', $parsed->name);
        self::assertSame('Przemysław Kuczyński', $parsed->players[7]->name);
        self::assertSame('Chomęcice', $parsed->players[7]->city);
        self::assertSame(
            $this->parseFinalStandings($this->readFixture('blubry_wyniki/17032026_Blubry531_Kuczbaj.txt')),
            $this->computeStandings($parsed),
        );
    }

    public static function extendedFixtures(): iterable
    {
        foreach (glob(dirname(__DIR__).'/Fixtures/club_tournament_results/*HH.txt') as $path) {
            yield basename($path) => [$path];
        }
    }

    #[DataProvider('extendedFixtures')]
    public function testRealFilesWithPaddedScoresAndDottedDate(string $path): void
    {
        $decoder = new ClubTournamentResultsFileDecoder();
        $parsed = (new ClubTournamentResultsParser())->parse($decoder->decode(file_get_contents($path)), $decoder->decode(file_get_contents(str_replace('HH.txt', '.txt', $path))));
        $final = $decoder->decode(file_get_contents(str_replace('HH.txt', '.txt', $path)));
        self::assertSame($this->parseFinalStandings($final), $this->computeStandings($parsed));
        if (str_contains($path, 'Klymenia')) {
            self::assertSame('2026-05-25', $parsed->date->format('Y-m-d'));
        }
    }

    public function testRejectsImpossibleDate(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Could not parse tournament date');
        (new ClubTournamentResultsParser())->parse('31022026_Invalid');
    }

    public function testRequiresFinalStandingsForAmbiguousPauses(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('PAUZA');
        (new ClubTournamentResultsParser())->parse($this->readFixture('blubry_wyniki/17032026_Blubry531_KuczbajHH.txt'));
    }

    public function testRejectsFinalStandingsFromAnotherTournament(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('różnych turniejów');
        (new ClubTournamentResultsParser())->parse(
            $this->readFixture('blubry_wyniki/17032026_Blubry531_KuczbajHH.txt'),
            $this->readFixture('blubry_wyniki/10032026_Blubry530_GP7_Zafu.txt'),
        );
    }

    public function testRejectsScoresInconsistentWithFinalStandings(): void
    {
        $hh = $this->readFixture('blubry_wyniki/17032026_Blubry531_KuczbajHH.txt');
        $hh = preg_replace('/\d+:\d+/', '999:999', $hh, 1);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('disagree with final standings');
        (new ClubTournamentResultsParser())->parse($hh, $this->readFixture('blubry_wyniki/17032026_Blubry531_Kuczbaj.txt'));
    }

    public function testRejectsUnrecognizedGameInsteadOfSilentlyLosingIt(): void
    {
        $text = $this->readFixture('blubry_wyniki/17032026_Blubry531_KuczbajHH.txt');
        $text = preg_replace('/\d+:\d+/', 'invalid-score', $text, 1);
        $this->expectException(\RuntimeException::class);
        (new ClubTournamentResultsParser())->parse($text);
    }

    private function readFixture(string $path): string
    {
        $raw = file_get_contents(dirname(__DIR__, 2) . '/' . $path);
        self::assertIsString($raw);
        $decoded = iconv('Windows-1250', 'UTF-8//IGNORE', $raw);
        self::assertIsString($decoded);

        return $decoded;
    }

    /**
     * @return list<array{name:string,points:int,big:float}>
     */
    private function computeStandings(ParsedClubTournamentResults $results): array
    {
        $rows = [];
        foreach ($results->players as $player) {
            $points = 0;
            $big = 0.0;

            foreach ($player->games as $game) {
                if ($game->isBye) {
                    $points += 350;
                    $big += 0.5;
                    continue;
                }

                $points += $game->pointsFor ?? 0;
                if ($game->result === '+') {
                    $big += 1.0;
                } elseif ($game->result === '=') {
                    $big += 0.5;
                }
            }

            $rows[] = [
                'name' => $player->name,
                'points' => $points,
                'big' => $big,
            ];
        }

        usort($rows, static fn (array $left, array $right): int => [$right['big'], $right['points']] <=> [$left['big'], $left['points']]);

        return $rows;
    }

    /**
     * @return list<array{name:string,points:int,big:float}>
     */
    private function parseFinalStandings(string $text): array
    {
        $text = preg_replace("/\r\n?/", "\n", $text) ?? $text;
        $rows = [];
        foreach (preg_split('/\n/u', $text) ?: [] as $line) {
            if (!preg_match('/^\s*\d+\.(.+?)\s+(\d+)\s+\S.*?\s+(\d+)\s+(\d+\.\d)$/u', rtrim($line), $match)) {
                continue;
            }

            $rows[] = [
                'name' => trim((string) preg_replace('/\s+/u', ' ', $match[1])),
                'points' => (int) $match[3],
                'big' => (float) $match[4],
            ];
        }

        return $rows;
    }
}
