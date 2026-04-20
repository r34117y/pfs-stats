<?php

declare(strict_types=1);

namespace App\Service;

use RuntimeException;

final readonly class ClubTournamentResultsFileDecoder
{
    public function decode(string $raw): string
    {
        if (@preg_match('//u', $raw) === 1) {
            return $raw;
        }

        foreach (['Windows-1250', 'ISO-8859-2'] as $encoding) {
            $decoded = @iconv($encoding, 'UTF-8//IGNORE', $raw);
            if ($decoded !== false && @preg_match('//u', $decoded) === 1) {
                return $decoded;
            }
        }

        throw new RuntimeException('Could not decode file as UTF-8, Windows-1250, or ISO-8859-2.');
    }
}
