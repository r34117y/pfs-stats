<?php

namespace App\Service;

use App\PfsTournamentImport\CalendarTournament;

final readonly class PfsTournamentCalendarParser
{
    /**
     * @return list<CalendarTournament>
     */
    public function parse(string $html, int $year): array
    {
        preg_match_all("/turnieje\\[(\\d+)\\]\\s*=\\s*'(.*?)';/su", $html, $matches, PREG_SET_ORDER);

        $tournaments = [];
        foreach ($matches as $match) {
            $urlId = (int) $match[1];
            $fragment = html_entity_decode($match[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');

            $name = $this->extractTagContents($fragment, 'i');
            $dateAndPlace = $this->extractTagContents($fragment, 'b');

            if ($name === null || $dateAndPlace === null) {
                continue;
            }

            $tournaments[] = new CalendarTournament(
                urlId: $urlId,
                name: $this->normalizeWhitespace(strip_tags($name)),
                location: $this->parseLocation($dateAndPlace),
                startDate: $this->parseStartDate($dateAndPlace, $year),
                endDate: $this->parseEndDate($dateAndPlace, $year),
            );
        }

        return $tournaments;
    }

    private function extractTagContents(string $fragment, string $tag): ?string
    {
        if (!preg_match(sprintf('~<%1$s>(.*?)</%1$s>~su', preg_quote($tag, '~')), $fragment, $match)) {
            return null;
        }

        return $match[1];
    }

    private function parseEndDate(string $dateAndPlace, int $year): \DateTimeImmutable
    {
        [$datePart] = $this->splitDateAndLocation($dateAndPlace);

        if (!preg_match('/(\d{1,2})(?:\s*-\s*(\d{1,2}))?\s+([[:alpha:]]+)/u', $datePart, $match)) {
            throw new \RuntimeException(sprintf('Could not parse tournament date fragment: %s', $dateAndPlace));
        }

        $endDay = isset($match[2]) && $match[2] !== '' ? (int) $match[2] : (int) $match[1];
        $month = $this->resolveMonthNumber($match[3]);
        $date = \DateTimeImmutable::createFromFormat('!Y-n-j', sprintf('%d-%d-%d', $year, $month, $endDay));

        if (!$date instanceof \DateTimeImmutable) {
            throw new \RuntimeException(sprintf('Could not build tournament end date for fragment: %s', $dateAndPlace));
        }

        return $date;
    }

    private function parseStartDate(string $dateAndPlace, int $year): \DateTimeImmutable
    {
        [$datePart] = $this->splitDateAndLocation($dateAndPlace);

        if (!preg_match('/(\d{1,2})(?:\s*-\s*(\d{1,2}))?\s+([[:alpha:]]+)/u', $datePart, $match)) {
            throw new \RuntimeException(sprintf('Could not parse tournament start date fragment: %s', $dateAndPlace));
        }

        $startDay = (int) $match[1];
        $month = $this->resolveMonthNumber($match[3]);
        $startMonth = $month;

        if (isset($match[2]) && $match[2] !== '' && $startDay > (int) $match[2]) {
            $startMonth--;
            if ($startMonth === 0) {
                $startMonth = 12;
                $year--;
            }
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-n-j', sprintf('%d-%d-%d', $year, $startMonth, $startDay));
        if (!$date instanceof \DateTimeImmutable) {
            throw new \RuntimeException(sprintf('Could not build tournament start date for fragment: %s', $dateAndPlace));
        }

        return $date;
    }

    private function parseLocation(string $dateAndPlace): string
    {
        [, $location] = $this->splitDateAndLocation($dateAndPlace);

        return $location;
    }

    /**
     * @return array{0:string,1:string}
     */
    private function splitDateAndLocation(string $dateAndPlace): array
    {
        $parts = explode(',', $this->normalizeWhitespace(strip_tags($dateAndPlace)), 2);
        $datePart = trim($parts[0] ?? '');
        $location = trim($parts[1] ?? '');

        return [$datePart, $location];
    }

    private function resolveMonthNumber(string $monthName): int
    {
        $normalized = strtolower(strtr($monthName, [
            'Ą' => 'ą',
            'Ć' => 'ć',
            'Ę' => 'ę',
            'Ł' => 'ł',
            'Ń' => 'ń',
            'Ó' => 'ó',
            'Ś' => 'ś',
            'Ź' => 'ź',
            'Ż' => 'ż',
        ]));

        return match ($normalized) {
            'stycznia' => 1,
            'lutego' => 2,
            'marca' => 3,
            'kwietnia' => 4,
            'maja' => 5,
            'czerwca' => 6,
            'lipca' => 7,
            'sierpnia' => 8,
            'września', 'wrzesnia' => 9,
            'października', 'pazdziernika' => 10,
            'listopada' => 11,
            'grudnia' => 12,
            default => throw new \RuntimeException(sprintf('Unsupported month name: %s', $monthName)),
        };
    }

    private function normalizeWhitespace(string $value): string
    {
        $value = str_replace("\u{00A0}", ' ', $value);

        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }
}
