<?php

declare (strict_types=1);
namespace App\Service;

use App\PfsTournamentImport\ParsedTournamentResults;
final readonly class PfsTournamentSourceValidator
{
    public function __construct(private PfsTournamentResultsParser $parser = new PfsTournamentResultsParser())
    {
    }
    public function validate(string $html): ParsedTournamentResults
    {
        $diagnostics = $this->parser->diagnose($html);
        $diagnostics->assertComplete();
        $r = $this->parser->parse($html);
        if (!$r->players || !$r->standings || !$r->roundGames || !$r->ranking) {
            throw new \RuntimeException('Incomplete tournament source: empty results section.');
        }
        foreach (['p_klasyfikacja' => count($r->standings), 'p_rundy' => count($r->roundGames), 'p_ranking' => count($r->ranking)] as $section => $count) {
            $rawCount = count(array_filter($diagnostics->sections[$section] ?? [], static fn(array $line): bool => $line['kind'] === 'data'));
            if ($rawCount !== $count) {
                throw new \RuntimeException('Parsed rows missing from ' . $section . ': ' . $rawCount . ' source vs ' . $count . ' parsed.');
            }
        }
        $players = [];
        foreach ($r->players as $p) {
            $key = self::name($p->playerName);
            if (isset($players[$key])) {
                throw new \RuntimeException('Ambiguous duplicate source identity: ' . $p->playerName);
            }
            $players[$key] = $p;
        }
        if (count($players) !== count($r->standings)) {
            throw new \RuntimeException('Classification/HH participant sets differ.');
        }
        $rounds = [];
        foreach ($r->roundGames as $g) {
            $key = $g->round . ':' . $g->table;
            if (isset($rounds[$key])) {
                throw new \RuntimeException('Duplicate round/table (unsupported phase restart): ' . $key);
            }
            $rounds[$key] = $g;
            if (!isset($players[self::name($g->hostName)]) || !$g->isBye && !isset($players[self::name($g->guestName ?? '')])) {
                throw new \RuntimeException('Unknown game participant: ' . $key);
            }
        }
        $seen = [];
        $warnings = [];
        foreach ($r->standings as $standing) {
            $key = self::name($standing->playerName);
            $p = $players[$key] ?? throw new \RuntimeException('Classification identity missing from HH: ' . $standing->playerName);
            if (isset($seen[$key])) {
                throw new \RuntimeException('Duplicate classification identity: ' . $standing->playerName);
            }
            $seen[$key] = true;
            $points = 0;
            $against = 0;
            $scalps = 0;
            $played = 0;
            $displayScalps = 0;
            $displayPlayed = 0;
            $forfeits = 0;
            foreach ($p->games as $g) {
                $round = $rounds[$g->round . ':' . $g->table] ?? throw new \RuntimeException('HH round missing from rounds section.');
                if ($g->isBye !== $round->isBye) {
                    throw new \RuntimeException('HH/round bye mismatch.');
                }
                if ($g->isBye) {
                    if (self::name($round->hostName) !== $key) {
                        throw new \RuntimeException('Bye identity mismatch.');
                    }
                    $points += 300;
                    continue;
                }
                $host = self::name($round->hostName) === $key;
                if (!$host && self::name($round->guestName ?? '') !== $key) {
                    throw new \RuntimeException('HH/round identity mismatch.');
                }
                $opponent = $host ? $round->guestName : $round->hostName;
                if (self::name($g->opponentName) !== self::name($opponent ?? '') || $g->pointsFor !== ($host ? $round->hostScore : $round->guestScore) || $g->pointsAgainst !== ($host ? $round->guestScore : $round->hostScore)) {
                    throw new \RuntimeException(sprintf('HH/round score or opponent mismatch: %s, round %d.', $p->playerName, $g->round));
                }
                $points += $g->pointsFor;
                $against += $g->pointsAgainst;
                $displayScalps += $g->scalp ?? 0;
                $displayPlayed++;
                if (self::isForfeit($g->pointsFor, $g->pointsAgainst)) {
                    $forfeits++;
                    continue;
                }
                $scalps += $g->scalp ?? 0;
                $played += $g->scalp !== null ? 1 : 0;
            }
            if ($forfeits && $p->roundsPlayed !== $played) {
                $warnings[] = ['player' => $p->playerName, 'kind' => 'legacy-forfeit-summary', 'hhGames' => $p->roundsPlayed, 'eligibleGames' => $played];
            }
            if ($scalps !== $standing->scalps) {
                if (abs($scalps - $standing->scalps) > (int) ceil($played / 2)) {
                    throw new \RuntimeException('Inconsistent scalp totals: ' . $p->playerName);
                }
                $warnings[] = ['player' => $p->playerName, 'kind' => 'source-scaling-precision', 'hhRoundedScalps' => $scalps, 'classificationScalps' => $standing->scalps];
            }
            if (!in_array($p->roundsPlayed, $forfeits ? [$played, $displayPlayed] : [$played], true) || !in_array($p->totalScalp, $forfeits ? [$scalps, $displayScalps] : [$scalps], true) || $points !== $standing->smallPoints || $points - $against !== $standing->pointsDiff || abs($p->tournamentRank - $standing->tournamentRank) > 0.001) {
                throw new \RuntimeException('HH/classification totals mismatch: ' . $p->playerName);
            }
        }
        return new ParsedTournamentResults($r->tournamentName, $r->details, $r->players, $r->standings, $r->roundGames, $r->ranking, $warnings);
    }
    public static function isForfeit(?int $a, ?int $b): bool
    {
        return $a === 400 && $b === 0 || $a === 0 && $b === 400;
    }
    public static function name(string $name): string
    {
        return (new PfsNameNormalizer())->normalizeForMatch($name);
    }
}
