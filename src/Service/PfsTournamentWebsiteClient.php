<?php

namespace App\Service;

final readonly class PfsTournamentWebsiteClient
{
    private const BASE_URL = 'https://www.pfs.org.pl';

    public function fetchCalendarHtml(int $year): string
    {
        return $this->fetch(sprintf('%s/kalendarz.php?rok=%d', self::BASE_URL, $year));
    }

    public function fetchTournamentHtml(int $urlId): string
    {
        return $this->fetch(sprintf('%s/turniej.php?id=%d#hh', self::BASE_URL, $urlId));
    }

    private function fetch(string $url): string
    {
        $context = stream_context_create([
            'http' => [
                'header' => "User-Agent: scrabble-stats-api/1.0\r\n",
                'timeout' => 20,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        $html = @file_get_contents($url, false, $context);
        if (!is_string($html) || $html === '') {
            throw new \RuntimeException(sprintf('Could not fetch remote URL: %s', $url));
        }

        return $html;
    }
}
