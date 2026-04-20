<?php

declare(strict_types=1);

namespace App\Service;

use App\ClubTournamentImport\ParsedClubGame;
use App\ClubTournamentImport\ParsedClubPlayer;
use App\ClubTournamentImport\ParsedClubTournamentResults;
use DateTimeImmutable;
use RuntimeException;

final readonly class ClubTournamentResultsParser
{
    public function parse(string $text): ParsedClubTournamentResults
    {
        $text = $this->normalizeText($text);
        $lines = preg_split('/\n/u', $text) ?: [];
        $heading = $this->firstNonEmptyLine($lines);
        if ($heading === null || !preg_match('/^(\d{2})(\d{2})(\d{4})_(.+)$/u', $heading, $match)) {
            throw new RuntimeException('Could not parse HH heading.');
        }

        $date = DateTimeImmutable::createFromFormat('!dmY', $match[1] . $match[2] . $match[3]);
        if (!$date instanceof DateTimeImmutable) {
            throw new RuntimeException(sprintf('Could not parse tournament date from heading "%s".', $heading));
        }

        return new ParsedClubTournamentResults(
            name: $match[4],
            date: $date,
            players: $this->parsePlayers($lines),
        );
    }

    /**
     * @param list<string> $lines
     * @return list<ParsedClubPlayer>
     */
    private function parsePlayers(array $lines): array
    {
        $players = [];
        $index = 0;
        $count = count($lines);

        while ($index < $count) {
            $line = rtrim($lines[$index]);
            if (!$this->isPlayerHeader($line)) {
                $index++;
                continue;
            }

            $players[] = $this->parsePlayer($lines, $index);
        }

        if ($players === []) {
            throw new RuntimeException('No player sections found in HH file.');
        }

        return $players;
    }

    /**
     * @param list<string> $lines
     */
    private function parsePlayer(array $lines, int &$index): ParsedClubPlayer
    {
        $header = rtrim($lines[$index]);
        if (!preg_match('/^\s*(\d+)\s{2,}(.+?)\s+(\d+)\s+(\S.*)$/u', $header, $match)) {
            throw new RuntimeException(sprintf('Could not parse player header: %s', $header));
        }

        $index++;
        $this->skipToGameRows($lines, $index);

        $games = [];
        $achievedRank = null;
        while ($index < count($lines)) {
            $line = rtrim($lines[$index]);
            $trimmed = trim($line);

            if ($trimmed === '' || preg_match('/^-+$/', $trimmed) === 1) {
                $index++;
                continue;
            }

            if (preg_match('/^RANKING:\s*(\d+(?:\.\d+)?)$/u', $trimmed, $rankMatch)) {
                $achievedRank = (float) $rankMatch[1];
                $index++;
                break;
            }

            if (str_starts_with($trimmed, 'RUNDA ') || str_starts_with($trimmed, '-----')) {
                $index++;
                continue;
            }

            $game = $this->parseGame($trimmed);
            if ($game !== null) {
                $games[] = $game;
                $index++;
                continue;
            }

            if ($this->isPlayerHeader($line)) {
                break;
            }
            $index++;
        }

        if ($achievedRank === null) {
            throw new RuntimeException(sprintf('Could not parse achieved ranking for player %s.', trim($match[2])));
        }

        return new ParsedClubPlayer(
            position: (int) $match[1],
            name: $this->normalizeInlineWhitespace($match[2]),
            initialRank: (int) $match[3],
            city: $this->normalizeInlineWhitespace($match[4]),
            games: $games,
            achievedRank: $achievedRank,
        );
    }

    /**
     * @param list<string> $lines
     */
    private function skipToGameRows(array $lines, int &$index): void
    {
        while ($index < count($lines)) {
            if (str_starts_with(trim($lines[$index]), 'RUNDA ')) {
                $index++;
                return;
            }
            $index++;
        }

        throw new RuntimeException('Could not find game table header.');
    }

    private function parseGame(string $line): ?ParsedClubGame
    {
        if (preg_match('/^(\d+)\s+PAUZA$/u', $line, $match)) {
            return new ParsedClubGame(
                round: (int) $match[1],
                table: null,
                opponentPosition: null,
                opponentName: 'PAUZA',
                opponentRank: null,
                result: null,
                pointsFor: null,
                pointsAgainst: null,
                scalp: null,
                isBye: true,
            );
        }

        if (!preg_match(
            '/^(\d+)\s+(\d+)\s+(\d+)\s+(.+?)\s+(\d+)\s+([+\-=])\s+(\d+):(\d+)\s+(\d+)$/u',
            $line,
            $match,
        )) {
            return null;
        }

        return new ParsedClubGame(
            round: (int) $match[1],
            table: (int) $match[2],
            opponentPosition: (int) $match[3],
            opponentName: $this->normalizeInlineWhitespace($match[4]),
            opponentRank: (int) $match[5],
            result: $match[6],
            pointsFor: (int) $match[7],
            pointsAgainst: (int) $match[8],
            scalp: (int) $match[9],
            isBye: false,
        );
    }

    private function isPlayerHeader(string $line): bool
    {
        return preg_match('/^\s*\d+\s{2,}.+?\s+\d+\s+\S/u', $line) === 1;
    }

    /**
     * @param list<string> $lines
     */
    private function firstNonEmptyLine(array $lines): ?string
    {
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line !== '') {
                return $line;
            }
        }

        return null;
    }

    private function normalizeText(string $text): string
    {
        $text = str_replace("\u{FEFF}", '', $text);
        $text = str_replace("\u{00A0}", ' ', $text);
        $text = preg_replace("/\r\n?/", "\n", $text) ?? $text;

        return trim($text);
    }

    private function normalizeInlineWhitespace(string $text): string
    {
        return trim((string) (preg_replace('/\s+/u', ' ', $text) ?? $text));
    }
}
