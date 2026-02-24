<?php

namespace App\GcgParser\Helper;

final readonly class KurnikHelper
{
    public function isKurnikScrabbleGame(string $gcg): bool
    {
        $lines = preg_split('/\r?\n/', $gcg);

        $cond1 = $lines[0] === '#character-encoding UTF-8';
        $cond2 = str_starts_with($lines[1], '#player1 1 ');
        $cond3 = str_starts_with($lines[2], '#player2 2 ');

        return $cond1 && $cond2 && $cond3;
    }

    public function isKurnikLiterakiGame(string $gcg): bool
    {
        $lines = preg_split('/\r?\n/', $gcg);

        $cond1 = preg_match('/^#1 .+ : \d+$/', $lines[0]);
        $cond2 = preg_match('/^#2 .+ : \d+$/', $lines[1]);

        return $cond1 && $cond2;
    }

    /**
     * Normalize Kurnik's GCG format:
     * - set players' nicknames
     * - add metadata (authority, lexicon, tile distribution)
     */
    public function preprocessKurnikGcg(string $gcg, string $kurnikId = 'unknown'): string
    {
        $lines = preg_split('/\r?\n/', $gcg);

        $players = [];
        foreach ($lines as &$line) {
            if (str_starts_with($line, '#player1 1')) {
                $parts = explode(' ', $line);
                $nick = array_pop($parts);
                $line = sprintf('#player1 %s %s', $nick, $nick);
                $players['1'] = $nick;
            }
            if (str_starts_with($line, '#player2 2')) {
                $parts = explode(' ', $line);
                $nick = array_pop($parts);
                $line = sprintf('#player2 %s %s', $nick, $nick);
                $players['2'] = $nick;
            }
        }

        foreach ($lines as &$line) {
            if (str_starts_with($line, '>1:')) {
                $line = str_replace('>1:', ">{$players['1']}:", $line);
            }
            if (str_starts_with($line, '>2:')) {
                $line = str_replace('>2:', ">{$players['2']}:", $line);
            }
        }

        $lines[] = '#id pl.kurnik ' . $kurnikId;
        $lines[] = '#lexicon OSPS';
        $lines[] = '#tile-distribution polish';
        return implode("\n", $lines);
    }

    public function isKurnikGameLink(string $content): bool
    {
        $pattern = '#^https://www\.kurnik\.pl/p/\?g=bb\d+\.txt$#';
        return preg_match($pattern, $content) === 1;
    }

    /**
     * Extracts Kurnik id from URL of a game.
     * Example URL: https://www.kurnik.pl/p/?g=bb519164272.txt
     * The id in the link above is bb519164272.
     */
    public function extractIdFromLink(string $content): ?string
    {
        if (preg_match('/\?g=(bb\d+)\.txt/', $content, $matches)) {
            return $matches[1];
        }

        return null;
    }
}
