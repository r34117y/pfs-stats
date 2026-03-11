<?php

namespace App\Service;

use App\PfsTournamentImport\ParsedTournamentPlayerResults;
use App\PfsTournamentImport\ParsedTournamentResults;
use App\PfsTournamentImport\PfsTourHhImportRow;
use App\PfsTournamentImport\PfsTournamentImportPlan;
use App\PfsTournamentImport\PfsTourImportRow;
use App\PfsTournamentImport\PfsTourWynImportRow;
use App\PfsTournamentImport\ResolvedPlayer;
use App\PfsTournamentImport\TournamentImportMetadata;
use App\Service\PfsPlayerResolver\PfsPlayerResolver;
use RuntimeException;

final readonly class PfsTournamentImportPlanner
{
    public function __construct(
        private PfsPlayerResolver $playerResolver,
        private PfsNameNormalizer $nameNormalizer,
    ) {
    }

    public function buildPlan(TournamentImportMetadata $metadata, ParsedTournamentResults $results): PfsTournamentImportPlan
    {
        $playerRanksByName = [];
        foreach ($results->players as $playerResults) {
            $playerRanksByName[$playerResults->playerName] = max(100.0, $playerResults->tournamentRank);
        }

        $playerResolution = $this->playerResolver->resolve($playerRanksByName, $metadata->tournamentId);
        /** @var array<string, ResolvedPlayer> $resolvedPlayers */
        $resolvedPlayers = $playerResolution['resolved'];
        $warnings = $playerResolution['warnings'];

        $resultRowsByName = [];
        foreach ($results->players as $playerResults) {
            $resultRowsByName[$playerResults->playerName] = $playerResults;
        }

        $standingsByName = [];
        foreach ($results->standings as $standing) {
            $standingsByName[$standing->playerName] = $standing;
        }

        $roundGames = $this->buildRoundGames($metadata->tournamentId, $results, $resolvedPlayers, $warnings);
        $tournamentResults = [];

        foreach ($results->standings as $standing) {
            $playerResults = $resultRowsByName[$standing->playerName] ?? null;
            if (!$playerResults instanceof ParsedTournamentPlayerResults) {
                $warnings[] = sprintf('Missing per-player results block for standing row "%s".', $standing->playerName);
                continue;
            }

            $resolvedPlayer = $resolvedPlayers[$standing->playerName] ?? null;
            if ($resolvedPlayer === null) {
                $warnings[] = sprintf('Missing resolved player for "%s".', $standing->playerName);
                continue;
            }

            $hostGames = 0;
            $hostWins = 0;
            foreach ($roundGames as $roundGame) {
                if ($roundGame->player1 === $resolvedPlayer->id && $roundGame->host === 1) {
                    $hostGames++;
                    if ($roundGame->result1 > $roundGame->result2) {
                        $hostWins++;
                    }
                }
            }

            [$wins, $losses, $draws] = $this->countOutcomes($playerResults);
            $games = max(1, $playerResults->roundsPlayed);
            $pointsFor = $this->sumPointsFor($playerResults);
            $pointsAgainst = $this->sumPointsAgainst($playerResults);

            $tournamentResults[] = new PfsTourWynImportRow(
                turniej: $metadata->tournamentId,
                player: $resolvedPlayer->id,
                place: $standing->place,
                gwin: $wins,
                glost: $losses,
                gdraw: $draws,
                games: $playerResults->roundsPlayed,
                trank: $playerResults->rankAchieved,
                brank: $resolvedPlayer->seedRank,
                points: round($pointsFor / $games, 3),
                pointo: round($pointsAgainst / $games, 3),
                hostgames: $hostGames,
                hostwin: $hostWins,
            );
        }

        usort($tournamentResults, static fn (PfsTourWynImportRow $left, PfsTourWynImportRow $right): int => $left->place <=> $right->place);

        $winnerName = $results->standings[0]->playerName ?? null;
        if ($winnerName === null || !isset($resolvedPlayers[$winnerName])) {
            throw new RuntimeException('Could not resolve tournament winner for PFSTOURS row.');
        }

        $rounds = 0;
        foreach ($results->players as $playerResults) {
            foreach ($playerResults->games as $game) {
                $rounds = max($rounds, $game->round);
            }
        }

        $team = $metadata->team ?? $this->inferTeamFlag($results->tournamentName, $warnings);
        $mcategory = $metadata->mcategory ?? $this->inferCategory($results->tournamentName, $rounds, $team, $warnings);
        $sertour = $metadata->sertour ?? 0;
        if ($metadata->sertour === null) {
            $warnings[] = 'Using default PFSTOURS.sertour = 0. Override it if the source tournament belongs to an existing PFS series.';
        }

        $tournamentRow = new PfsTourImportRow(
            id: $metadata->tournamentId,
            dt: (int) $metadata->endDate->format('Ymd'),
            name: $metadata->shortName,
            fullname: $results->tournamentName,
            winner: $resolvedPlayers[$winnerName]->id,
            trank: round($this->averagePreTournamentRank($resolvedPlayers), 3),
            players: count($results->standings),
            rounds: $rounds,
            rrecreated: '',
            team: $team,
            mcategory: $mcategory,
            wksum: 0.0,
            sertour: $sertour,
            start: (int) $metadata->startDate->format('Ymd'),
            referee: $results->getDetailValue('Sędzia'),
            place: $this->singleLine($results->getDetailValue('Miejsce rozgrywek')),
            organizer: $this->singleLine($results->getDetailValue('Organizatorzy')),
            urlid: $metadata->urlId,
        );

        return new PfsTournamentImportPlan(
            tournament: $tournamentRow,
            newPlayers: $playerResolution['newPlayers'],
            tournamentResults: $tournamentResults,
            tournamentGames: $roundGames,
            warnings: array_values(array_unique($warnings)),
        );
    }

    /**
     * @param array<string, ResolvedPlayer> $resolvedPlayers
     * @param list<string> $warnings
     * @return list<PfsTourHhImportRow>
     */
    private function buildRoundGames(
        int $tournamentId,
        ParsedTournamentResults $results,
        array $resolvedPlayers,
        array &$warnings,
    ): array {
        $playerResultsByName = [];
        foreach ($results->players as $playerResults) {
            $playerResultsByName[$this->nameNormalizer->normalizeForMatch($playerResults->playerName)] = $playerResults;
        }

        $rows = [];
        foreach ($results->roundGames as $roundGame) {
            if ($roundGame->isBye || $roundGame->guestName === null || $roundGame->hostScore === null || $roundGame->guestScore === null) {
                continue;
            }

            $hostName = $this->findCanonicalPlayerName($roundGame->hostName, $resolvedPlayers);
            $guestName = $this->findCanonicalPlayerName($roundGame->guestName, $resolvedPlayers);
            if ($hostName === null || $guestName === null) {
                $warnings[] = sprintf(
                    'Could not resolve round %d table %d pairing "%s" vs "%s".',
                    $roundGame->round,
                    $roundGame->table,
                    $roundGame->hostName,
                    $roundGame->guestName,
                );
                continue;
            }

            $hostPlayer = $resolvedPlayers[$hostName];
            $guestPlayer = $resolvedPlayers[$guestName];
            $hostResults = $playerResultsByName[$this->nameNormalizer->normalizeForMatch($hostName)] ?? null;
            $guestResults = $playerResultsByName[$this->nameNormalizer->normalizeForMatch($guestName)] ?? null;

            if (!$hostResults instanceof ParsedTournamentPlayerResults || !$guestResults instanceof ParsedTournamentPlayerResults) {
                $warnings[] = sprintf('Missing player result block for round pairing "%s" vs "%s".', $hostName, $guestName);
                continue;
            }

            $rows[] = new PfsTourHhImportRow(
                turniej: $tournamentId,
                runda: $roundGame->round,
                stol: $roundGame->table,
                player1: $hostPlayer->id,
                player2: $guestPlayer->id,
                result1: $roundGame->hostScore,
                result2: $roundGame->guestScore,
                ranko: (int) round($guestPlayer->seedRank),
                host: 1,
            );

            $rows[] = new PfsTourHhImportRow(
                turniej: $tournamentId,
                runda: $roundGame->round,
                stol: $roundGame->table,
                player1: $guestPlayer->id,
                player2: $hostPlayer->id,
                result1: $roundGame->guestScore,
                result2: $roundGame->hostScore,
                ranko: (int) round($hostPlayer->seedRank),
                host: 2,
            );
        }

        usort($rows, static function (PfsTourHhImportRow $left, PfsTourHhImportRow $right): int {
            return [$left->player1, $left->runda, $left->stol, $left->player2] <=> [$right->player1, $right->runda, $right->stol, $right->player2];
        });

        return $rows;
    }

    /**
     * @param array<string, ResolvedPlayer> $resolvedPlayers
     */
    private function findCanonicalPlayerName(string $name, array $resolvedPlayers): ?string
    {
        if (isset($resolvedPlayers[$name])) {
            return $name;
        }

        $normalizedNeedle = $this->nameNormalizer->normalizeForMatch($name);
        foreach (array_keys($resolvedPlayers) as $candidateName) {
            if ($this->nameNormalizer->normalizeForMatch($candidateName) === $normalizedNeedle) {
                return $candidateName;
            }
        }

        return null;
    }

    /**
     * @return array{0:int,1:int,2:int}
     */
    private function countOutcomes(ParsedTournamentPlayerResults $playerResults): array
    {
        $wins = 0;
        $losses = 0;
        $draws = 0;

        foreach ($playerResults->games as $game) {
            if ($game->isBye || $game->result === null) {
                continue;
            }

            if ($game->result === '+') {
                $wins++;
            } elseif ($game->result === '-') {
                $losses++;
            } else {
                $draws++;
            }
        }

        return [$wins, $losses, $draws];
    }

    private function sumPointsFor(ParsedTournamentPlayerResults $playerResults): int
    {
        $sum = 0;
        foreach ($playerResults->games as $game) {
            if ($game->isBye || $game->pointsFor === null) {
                continue;
            }
            $sum += $game->pointsFor;
        }

        return $sum;
    }

    private function sumPointsAgainst(ParsedTournamentPlayerResults $playerResults): int
    {
        $sum = 0;
        foreach ($playerResults->games as $game) {
            if ($game->isBye || $game->pointsAgainst === null) {
                continue;
            }
            $sum += $game->pointsAgainst;
        }

        return $sum;
    }

    /**
     * @param array<string, ResolvedPlayer> $resolvedPlayers
     */
    private function averagePreTournamentRank(array $resolvedPlayers): float
    {
        $sum = 0.0;
        $count = 0;

        foreach ($resolvedPlayers as $resolvedPlayer) {
            $sum += $resolvedPlayer->seedRank;
            $count++;
        }

        return $count > 0 ? $sum / $count : 0.0;
    }

    /**
     * @param list<string> $warnings
     */
    private function inferTeamFlag(string $tournamentName, array &$warnings): string
    {
        if (str_contains($tournamentName, 'Klubowe')) {
            return 'Y';
        }

        $warnings[] = 'Using default PFSTOURS.team = N.';

        return 'N';
    }

    /**
     * @param list<string> $warnings
     */
    private function inferCategory(string $tournamentName, int $rounds, string $team, array &$warnings): int
    {
        if ($team === 'Y') {
            return 5;
        }

        if (str_contains($tournamentName, 'Puchar Polski')) {
            return 3;
        }

        if (str_contains($tournamentName, '24h')) {
            return 4;
        }

        if (str_contains($tournamentName, 'Mistrzostwa Polski') && !str_contains($tournamentName, 'towarzyszący')) {
            return 7;
        }

        $warnings[] = sprintf('Using default PFSTOURS.mcategory = 2 for "%s" (%d rounds).', $tournamentName, $rounds);

        return 2;
    }

    private function singleLine(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return trim((string) preg_replace('/\s*\n\s*/u', ', ', $value));
    }
}
