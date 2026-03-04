<?php

namespace App\Service;

use App\PfsTournamentImport\ParsedTournamentDetail;
use App\PfsTournamentImport\ParsedTournamentPlayerGame;
use App\PfsTournamentImport\ParsedTournamentPlayerResults;
use App\PfsTournamentImport\ParsedTournamentResults;
use App\PfsTournamentImport\ParsedTournamentRoundGame;
use App\PfsTournamentImport\ParsedTournamentStandingRow;

final readonly class PfsTournamentResultsParser
{
    public function parse(string $html): ParsedTournamentResults
    {
        $details = $this->parseDetails($html);
        $hhText = $this->extractRawResultsText($html);
        $lines = preg_split('/\R/u', $hhText) ?: [];

        $heading = $this->consumeFirstNonEmptyLine($lines);
        if ($heading === null || !preg_match('/^Wyniki poszczególnych graczy w turnieju (.+)$/u', $heading, $match)) {
            throw new \RuntimeException('Could not parse tournament name from #hh section.');
        }

        $players = $this->parsePlayerSections($lines);

        return new ParsedTournamentResults(
            tournamentName: $match[1],
            details: $details,
            players: $players,
            standings: $this->parseStandings($html, $players),
            roundGames: $this->parseRoundGames($html),
        );
    }

    /**
     * @param list<ParsedTournamentPlayerResults> $players
     * @return list<ParsedTournamentStandingRow>
     */
    private function parseStandings(string $html, array $players): array
    {
        $text = $this->extractPreBlockText($html, 'p_klasyfikacja');
        $lines = preg_split('/\R/u', $text) ?: [];

        while ($lines !== []) {
            $line = trim((string) array_shift($lines));
            if (str_starts_with($line, 'Lp. ')) {
                break;
            }
        }

        if ($lines !== [] && preg_match('/^-+\s+-+/', trim((string) $lines[0]))) {
            array_shift($lines);
        }

        $rows = [];
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                continue;
            }

            if (!preg_match(
                '/^\s*(\d+)\s+(.+?)\s+(\d+\.\d{2})\s+(\d+\.\d)\s+(\d+)\s+(\d+)\s+(-?\d+)$/u',
                $trimmed,
                $match
            )) {
                continue;
            }

            [$playerName, $city] = $this->resolveStandingIdentity(
                $match[2],
                (float) $match[3],
                $players,
            );

            $rows[] = new ParsedTournamentStandingRow(
                place: (int) $match[1],
                playerName: $playerName,
                city: $city,
                tournamentRank: (float) $match[3],
                bigPoints: (float) $match[4],
                smallPoints: (int) $match[5],
                scalps: (int) $match[6],
                pointsDiff: (int) $match[7],
            );
        }

        return $rows;
    }

    /**
     * @param list<ParsedTournamentPlayerResults> $players
     * @return array{0:string,1:string}
     */
    private function resolveStandingIdentity(string $identityPart, float $tournamentRank, array $players): array
    {
        $chunks = preg_split('/\s{2,}/u', trim($identityPart)) ?: [];
        if (count($chunks) >= 3) {
            $city = array_pop($chunks);
            $surname = array_pop($chunks);
            $firstName = implode(' ', $chunks);

            return [
                $this->normalizeInlineWhitespace($firstName . ' ' . $surname),
                $this->normalizeInlineWhitespace((string) $city),
            ];
        }

        $identityNormalized = $this->normalizeInlineWhitespace($identityPart);
        foreach ($players as $player) {
            if (abs($player->tournamentRank - $tournamentRank) > 0.01) {
                continue;
            }

            $firstToken = explode(' ', $player->playerName)[0] ?? $player->playerName;
            if (str_starts_with($identityNormalized, $firstToken) && str_ends_with($identityNormalized, $player->city)) {
                return [$player->playerName, $player->city];
            }
        }

        if (count($chunks) === 2) {
            return [
                $this->normalizeInlineWhitespace($chunks[0] . ' ' . $chunks[1]),
                '',
            ];
        }

        return [$identityNormalized, ''];
    }

    /**
     * @return list<ParsedTournamentRoundGame>
     */
    private function parseRoundGames(string $html): array
    {
        $text = $this->extractPreBlockText($html, 'p_rundy');
        $lines = preg_split('/\R/u', $text) ?: [];
        $currentRound = null;
        $rows = [];

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                continue;
            }

            if (preg_match('/^Runda nr\s+(\d+)$/u', $trimmed, $match)) {
                $currentRound = (int) $match[1];
                continue;
            }

            if ($currentRound === null || str_starts_with($trimmed, 'Stół ') || preg_match('/^-+\s+-+/', $trimmed)) {
                continue;
            }

            if (preg_match('/^(\d+)\s+(.+?)\s+-\s+(?:PAUZA|BYE(?:\s+BYE)?)(?:\s+\d+:\s*\d+)?$/u', $trimmed, $match)) {
                $rows[] = new ParsedTournamentRoundGame(
                    round: $currentRound,
                    table: (int) $match[1],
                    hostName: $this->normalizeInlineWhitespace($match[2]),
                    guestName: null,
                    hostScore: null,
                    guestScore: null,
                    isBye: true,
                );
                continue;
            }

            if (preg_match('/^(\d+)\s+(.+?)\s+-\s+(.+?)\s+(\d+):\s*(\d+)$/u', $trimmed, $match)) {
                $rows[] = new ParsedTournamentRoundGame(
                    round: $currentRound,
                    table: (int) $match[1],
                    hostName: $this->normalizeInlineWhitespace($match[2]),
                    guestName: $this->normalizeInlineWhitespace($match[3]),
                    hostScore: (int) $match[4],
                    guestScore: (int) $match[5],
                    isBye: false,
                );
            }
        }

        return $rows;
    }

    /**
     * @return list<ParsedTournamentDetail>
     */
    private function parseDetails(string $html): array
    {
        $tableStart = strpos($html, "id='szczegoly'");
        if ($tableStart === false) {
            $tableStart = strpos($html, 'id="szczegoly"');
        }

        if ($tableStart === false) {
            throw new \RuntimeException('Could not find tournament details table.');
        }

        $sectionEnd = strpos($html, "id='p_klasyfikacja'", $tableStart);
        if ($sectionEnd === false) {
            $sectionEnd = strpos($html, 'id="p_klasyfikacja"', $tableStart);
        }

        $detailsSection = $sectionEnd === false ? substr($html, $tableStart) : substr($html, $tableStart, $sectionEnd - $tableStart);
        preg_match_all('~<tr>\s*<td[^>]*>(.*?)</td>\s*<td[^>]*>(.*?)</td>\s*</tr>~su', $detailsSection, $rows, PREG_SET_ORDER);
        $details = [];

        foreach ($rows as $rowMatch) {
            $details[] = new ParsedTournamentDetail(
                label: $this->normalizeHtmlText($rowMatch[1]),
                value: $this->normalizeHtmlText($rowMatch[2], preserveLineBreaks: true),
            );
        }

        return $details;
    }

    /**
     * @param list<string> $lines
     * @return list<ParsedTournamentPlayerResults>
     */
    private function parsePlayerSections(array $lines): array
    {
        $players = [];
        $index = 0;
        $lineCount = count($lines);

        while ($index < $lineCount) {
            $line = $lines[$index] ?? '';
            $trimmed = trim($line);

            if ($trimmed === '') {
                $index++;
                continue;
            }

            if (!$this->isPlayerHeader($trimmed)) {
                $index++;
                continue;
            }

            $players[] = $this->parseSinglePlayerSection($lines, $index);
        }

        return $players;
    }

    /**
     * @param list<string> $lines
     */
    private function parseSinglePlayerSection(array $lines, int &$index): ParsedTournamentPlayerResults
    {
        $headerLine = trim($lines[$index]);
        if (!preg_match('/^(.+?)\s+(\d+\.\d{2})\s+(.+)$/u', $headerLine, $match)) {
            throw new \RuntimeException(sprintf('Could not parse player header line: %s', $headerLine));
        }

        $index++;
        $this->skipUntilResultsBody($lines, $index);

        $games = [];
        $totalScalp = null;
        $roundsPlayed = null;
        $rankAchieved = null;

        while ($index < count($lines)) {
            $line = rtrim($lines[$index]);
            $trimmed = trim($line);

            if ($trimmed === '') {
                $index++;
                if ($games !== [] && $totalScalp !== null) {
                    break;
                }

                continue;
            }

            if (preg_match('/^-+\s+-+\s+-+/', $trimmed)) {
                $index++;
                continue;
            }

            if (preg_match('/^(\d+)\s*\/\s*(\d+)\s+(\d+\.\d{2})$/u', $trimmed, $summaryMatch)) {
                $totalScalp = (int) $summaryMatch[1];
                $roundsPlayed = (int) $summaryMatch[2];
                $rankAchieved = (float) $summaryMatch[3];
                $index++;
                continue;
            }

            if ($this->isPlayerHeader($trimmed)) {
                break;
            }

            $games[] = $this->parseGameRow($trimmed);
            $index++;
        }

        if ($games === [] || $totalScalp === null || $roundsPlayed === null || $rankAchieved === null) {
            throw new \RuntimeException(sprintf('Incomplete results block for player %s.', $match[1]));
        }

        return new ParsedTournamentPlayerResults(
            playerName: $match[1],
            tournamentRank: (float) $match[2],
            city: $match[3],
            games: $games,
            totalScalp: $totalScalp,
            roundsPlayed: $roundsPlayed,
            rankAchieved: $rankAchieved,
        );
    }

    private function parseGameRow(string $line): ParsedTournamentPlayerGame
    {
        if (preg_match('/^(\d+)\s+(\d+)\s+PAUZA$/u', $line, $match)) {
            return new ParsedTournamentPlayerGame(
                round: (int) $match[1],
                table: (int) $match[2],
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
            '/^(\d+)\s+(\d+)\s+(.+?)\s+(\d+\.\d{2})\s+([+\-=])\s+(\d+):\s*(\d+)\s+(\d+)$/u',
            $line,
            $match
        )) {
            throw new \RuntimeException(sprintf('Could not parse game row: %s', $line));
        }

        return new ParsedTournamentPlayerGame(
            round: (int) $match[1],
            table: (int) $match[2],
            opponentName: $this->normalizeInlineWhitespace($match[3]),
            opponentRank: (float) $match[4],
            result: $match[5],
            pointsFor: (int) $match[6],
            pointsAgainst: (int) $match[7],
            scalp: (int) $match[8],
            isBye: false,
        );
    }

    /**
     * @param list<string> $lines
     */
    private function skipUntilResultsBody(array $lines, int &$index): void
    {
        while ($index < count($lines)) {
            $trimmed = trim($lines[$index]);
            if ($trimmed === '') {
                $index++;
                continue;
            }

            if (str_starts_with($trimmed, 'Runda ')) {
                $index++;
                if ($index < count($lines) && preg_match('/^-+\s+-+\s+-+/', trim($lines[$index]))) {
                    $index++;
                }

                return;
            }

            $index++;
        }

        throw new \RuntimeException('Could not find results table header for player block.');
    }

    /**
     * @param list<string> $lines
     */
    private function consumeFirstNonEmptyLine(array &$lines): ?string
    {
        while ($lines !== []) {
            $line = trim((string) array_shift($lines));
            if ($line !== '') {
                return $line;
            }
        }

        return null;
    }

    private function isPlayerHeader(string $line): bool
    {
        return preg_match('/^[^\d].+\s+\d+\.\d{2}\s+.+$/u', $line) === 1
            && !str_starts_with($line, 'Runda ')
            && !preg_match('/^\d+\s*\/\s*\d+\s+\d+\.\d{2}$/', $line);
    }

    private function extractRawResultsText(string $html): string
    {
        return $this->extractPreBlockText($html, 'p_hh');
    }

    private function extractPreBlockText(string $html, string $containerId): string
    {
        $pattern = sprintf("~<div id=['\"]%s['\"][^>]*>\\s*<pre>(.*?)</pre>\\s*</div>~su", preg_quote($containerId, '~'));
        if (!preg_match($pattern, $html, $match)) {
            throw new \RuntimeException(sprintf('Could not find the #%s section in tournament HTML.', $containerId));
        }

        return $this->normalizePreformattedText($match[1]);
    }

    private function normalizePreformattedText(string $value): string
    {
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = str_replace("\u{FEFF}", '', $value);
        $value = str_replace("\u{00A0}", ' ', $value);
        $value = preg_replace("/\r\n?/", "\n", $value) ?? $value;

        return trim($value);
    }

    private function normalizeHtmlText(string $html, bool $preserveLineBreaks = false): string
    {
        if ($preserveLineBreaks) {
            $html = preg_replace('~<br\s*/?>~i', "\n", $html) ?? $html;
        }

        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace("\u{00A0}", ' ', $text);
        $text = preg_replace("/\r\n?/", "\n", $text) ?? $text;

        if ($preserveLineBreaks) {
            $text = preg_replace("/[ \t]+\n/u", "\n", $text) ?? $text;
            $text = preg_replace("/\n{3,}/u", "\n\n", $text) ?? $text;
        } else {
            $text = $this->normalizeInlineWhitespace($text);
        }

        return trim($text);
    }

    private function normalizeInlineWhitespace(string $text): string
    {
        return trim((string) (preg_replace('/\s+/u', ' ', $text) ?? $text));
    }
}
