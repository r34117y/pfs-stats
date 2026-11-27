<?php

declare(strict_types=1);

namespace App\Service;

use App\ClubTournamentImport\ClubTournamentRankingUnavailable;
use DOMDocument;
use DOMXPath;
use RuntimeException;

final readonly class ClubTournamentRankingParser
{
    /** @return list<array{position:int,name:string,rank:float,games:int}> */
    public function parse(string $html, int $dateCode): array
    {
        if (!str_contains(strtolower($html), '</body>')) {
            throw new RuntimeException('Club ranking HTML is incomplete.');
        }
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $xpath = new DOMXPath($document);
        $heading = $xpath->query('//h2/a')->item(0);
        if ($heading === null || $heading->getAttribute('href') !== 'ktour.php?t='.$dateCode.'0') {
            throw new RuntimeException('Club ranking does not belong to the requested tournament date.');
        }
        $rows = [];
        $names = [];
        foreach ($xpath->query('//tr[td/a[starts-with(@href,"zindex.php?p=")]]') as $row) {
            $cells = $xpath->query('./td', $row);
            $position = trim($cells->item(0)?->textContent ?? '');
            $name = trim(preg_replace('/\s+/u', ' ', $cells->item(1)?->textContent ?? ''));
            $rank = trim($cells->item(2)?->textContent ?? '');
            $games = trim($cells->item(3)?->textContent ?? '');
            // The legacy site also renders placeholder lists with starting ranks
            // and zero games. These are not published post-tournament snapshots.
            if ($games === '0') {
                throw new ClubTournamentRankingUnavailable('The club source has not published a complete ranking for this tournament (zero games).');
            }
            if (!ctype_digit($position) || (int) $position !== count($rows) + 1 || $name === '' || isset($names[$name])
                || !preg_match('/^\d+\.\d{2}$/', $rank) || !ctype_digit($games) || (int) $games <= 0) {
                throw new RuntimeException(sprintf('Invalid or duplicated club ranking row: position=%s, name=%s, rank=%s, games=%s.', $position, $name, $rank, $games));
            }
            if ($rows !== [] && (float) $rank > $rows[array_key_last($rows)]['rank']) {
                throw new RuntimeException('Club ranking rows must be ordered by ranking position.');
            }
            $names[$name] = true;
            $rows[] = ['position' => (int) $position, 'name' => $name, 'rank' => (float) $rank, 'games' => (int) $games];
        }
        if ($rows === [] || $xpath->query('//tr[@id="sum"]')->length === 0) {
            throw new RuntimeException('Club ranking has no complete ranking table.');
        }

        return $rows;
    }
}
