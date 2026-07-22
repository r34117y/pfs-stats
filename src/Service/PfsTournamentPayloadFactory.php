<?php

namespace App\Service;

use App\ApiResource\TournamentRound\TournamentRound;
use App\PfsTournamentImport\CalendarTournament;
use App\PfsTournamentImport\ParsedTournamentRankingRow;
use App\PfsTournamentImport\ParsedTournamentResults;
use App\PfsTournamentImport\ParsedTournamentRoundGame;
use App\PfsTournamentImport\ParsedTournamentStandingRow;

final readonly class PfsTournamentPayloadFactory
{
    public function create(CalendarTournament $calendarTournament, ParsedTournamentResults $results): TournamentRound
    {
        $startingPositionsByName = $this->buildStartingPositionsByName($results);

        return new TournamentRound(
            tournament: [
                'org' => 'pfs',
                'dateStart' => $calendarTournament->startDate->format('Y-m-d'),
                'name' => $results->tournamentName,
                'city' => $calendarTournament->location,
            ],
            players: $this->buildPlayers($results, $startingPositionsByName),
            results: $this->buildResults($results, $startingPositionsByName),
            rankDay: $calendarTournament->startDate->format('Y-m-d'),
            ranking: $this->buildRanking($results),
        );
    }

    /**
     * @return array<string, int>
     */
    private function buildStartingPositionsByName(ParsedTournamentResults $results): array
    {
        $positions = [];
        foreach ($results->players as $index => $player) {
            $key = $this->normalizeName($player->playerName);
            if (isset($positions[$key])) {
                throw new \RuntimeException(sprintf('Duplicate player name in PFS player results: %s.', $player->playerName));
            }

            $positions[$key] = $index + 1;
        }

        return $positions;
    }

    /**
     * @param array<string, int> $startingPositionsByName
     * @return list<array<string, mixed>>
     */
    private function buildPlayers(ParsedTournamentResults $results, array $startingPositionsByName): array
    {
        return array_map(function (ParsedTournamentStandingRow $standing) use ($startingPositionsByName): array {
            $position = $startingPositionsByName[$this->normalizeName($standing->playerName)] ?? null;
            if ($position === null) {
                throw new \RuntimeException(sprintf('Could not resolve starting position for player "%s".', $standing->playerName));
            }

            [$firstName, $lastName] = $this->splitPlayerName($standing->playerName);

            return [
                'startingPosition' => $position,
                'place' => $standing->place,
                'ppGroup' => '',
                'firstName' => $firstName,
                'lastName' => $lastName,
                'city' => $standing->city,
                'tournamentRank' => $standing->tournamentRank,
                'big' => $standing->bigPoints,
                'small' => $standing->smallPoints,
                'scalps' => $standing->scalps,
                'difference' => $standing->pointsDiff,
            ];
        }, $results->standings);
    }

    /**
     * @param array<string, int> $startingPositionsByName
     * @return list<array<string, int>>
     */
    private function buildResults(ParsedTournamentResults $results, array $startingPositionsByName): array
    {
        return array_map(function (ParsedTournamentRoundGame $game) use ($startingPositionsByName): array {
            $hostPosition = $this->resolveStartingPosition($startingPositionsByName, $game->hostName);
            $guestPosition = $game->guestName === null ? 0 : $this->resolveStartingPosition($startingPositionsByName, $game->guestName);

            return [
                'round' => $game->round,
                'table' => $game->table,
                'host' => $hostPosition,
                'guest' => $guestPosition,
                'score1' => $game->isBye ? 300 : $this->requireScore($game->hostScore, $game),
                'score2' => $game->isBye ? 0 : $this->requireScore($game->guestScore, $game),
            ];
        }, $results->roundGames);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildRanking(ParsedTournamentResults $results): array
    {
        return array_map(
            static fn (ParsedTournamentRankingRow $row): array => [
                'lp' => $row->position,
                'main' => $row->main,
                'player' => $row->playerName,
                'city' => $row->city,
                'rank' => $row->rank,
                'scalp' => $row->scalp,
                'games' => $row->games,
            ],
            $results->ranking,
        );
    }

    /**
     * @param array<string, int> $startingPositionsByName
     */
    private function resolveStartingPosition(array $startingPositionsByName, string $playerName): int
    {
        $position = $startingPositionsByName[$this->normalizeName($playerName)] ?? null;
        if ($position === null) {
            throw new \RuntimeException(sprintf('Could not resolve game player "%s".', $playerName));
        }

        return $position;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function splitPlayerName(string $playerName): array
    {
        $parts = preg_split('/\s+/u', trim($playerName)) ?: [];
        if (count($parts) < 2) {
            throw new \RuntimeException(sprintf('Could not split player name into first and last name: %s.', $playerName));
        }

        $lastName = (string) array_pop($parts);

        return [implode(' ', $parts), $lastName];
    }

    private function requireScore(?int $score, ParsedTournamentRoundGame $game): int
    {
        if ($score === null) {
            throw new \RuntimeException(sprintf('Missing score for round %d table %d.', $game->round, $game->table));
        }

        return $score;
    }

    private function normalizeName(string $name): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $name)));
    }
}
