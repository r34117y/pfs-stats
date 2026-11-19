<?php

declare (strict_types=1);
namespace App\Service\PfsImportVerification;

use App\PfsTournamentImport\PfsTournamentSource;
use App\Service\{PfsNameNormalizer, PfsPlayerIdentityResolver, PlayerCatalogService, PfsTournamentPayloadFactory};
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
final readonly class ImportedTournamentComparator
{
    public function __construct(
        #[Autowire(service: 'doctrine.dbal.default_connection')]
        private Connection $connection
    )
    {
    }
    public function compare(PfsTournamentSource $source, int $org, int $id, bool $old = true, ?array $referenceSnapshot = null): array
    {
        $diff = [];
        $c = $this->connection;
        $normalizer = new PfsNameNormalizer();
        $resolver = new PfsPlayerIdentityResolver($normalizer);
        $index = $resolver->index((new PlayerCatalogService($c, $normalizer))->loadPlayerCatalog($org));
        $identity = static fn(string $name, ?string $city = null): int => (int) ($resolver->resolve($index, $name, $city)['playerId'] ?? throw new \RuntimeException('Unresolved source identity: ' . $name));
        $check = static function (string $field, mixed $want, mixed $have) use (&$diff): void {
            $equal = is_numeric($want) && is_numeric($have) ? abs((float) $want - (float) $have) < 0.00051 : $want === $have;
            if (!$equal) {
                $diff[] = ['field' => $field, 'expected' => $want, 'actual' => $have];
            }
        };
        $t = $c->fetchAssociative('SELECT * FROM tournament WHERE organization_id=? AND id=?', [$org, $id]);
        if (!$t) {
            return [['field' => 'tournament', 'expected' => $id, 'actual' => null]];
        }
        $check('tournament.date', (int) $source->tournament->startDate->format('Ymd'), (int) $t['dt']);
        $check('tournament.name', mb_substr($source->results->tournamentName, 0, 80), $t['fullname']);
        $check('tournament.place', $source->tournament->location, $t['place']);
        $check('tournament.rounds', max(array_column($source->results->roundGames, 'round')), (int) $t['rounds']);
        $check('tournament.players', count($source->results->players), (int) $t['players_count']);
        $results = [];
        foreach ($c->fetchAllAssociative('SELECT * FROM tournament_result WHERE organization_id=? AND tournament_id=?', [$org, $id]) as $r) {
            $results[(int) $r['player_id']] = $r;
        }
        $check('participants.count', count($source->results->players), count($results));
        $hh = [];
        foreach ($source->results->players as $p) {
            $hh[$identity($p->playerName, $p->city)] = $p;
        }
        foreach ($source->results->standings as $s) {
            $pid = $identity($s->playerName, $s->city);
            $r = $results[$pid] ?? [];
            $p = $hh[$pid];
            $n = 0;
            $wins = $losses = $draws = 0;
            foreach ($p->games as $g) {
                if ($g->isBye || $g->scalp === null || \App\Service\PfsTournamentSourceValidator::isForfeit($g->pointsFor, $g->pointsAgainst)) {
                    continue;
                }
                $n++;
                if ($g->result === '+') {
                    $wins++;
                } elseif ($g->result === '-') {
                    $losses++;
                } else {
                    $draws++;
                }
            }
            foreach (['place' => $s->place, 'games' => $n, 'brank' => $s->tournamentRank, 'trank' => $n ? round($s->scalps / $n, 3) : null, 'points' => $n ? round($s->smallPoints / $n, 3) : null, 'pointo' => $n ? round(($s->smallPoints - $s->pointsDiff) / $n, 3) : null, 'gwin' => $wins, 'glost' => $losses, 'gdraw' => $draws] as $field => $want) {
                $check('participant.' . $pid . '.' . $field, $want, $r[$field] ?? null);
            }
        }
        $payload = (new PfsTournamentPayloadFactory())->create($source->tournament, $source->results);
        $ids = [];
        foreach ($payload->players as $p) {
            $ids[$p['startingPosition']] = $identity($p['firstName'] . ' ' . $p['lastName'], $p['city'] ?? null);
        }
        $expectedGames = [];
        foreach ($payload->results as $g) {
            $a = $ids[$g['host']];
            $b = $ids[$g['guest']] ?? null;
            $expectedGames[] = [(int) $g['round'], (int) $g['table'], $a, $b, (int) $g['score1'], (int) $g['score2'], 1];
            $expectedGames[] = [(int) $g['round'], (int) $g['table'], $b, $a, (int) $g['score2'], (int) $g['score1'], 2];
        }
        $actualGames = [];
        foreach ($c->fetchAllAssociative('SELECT round_no,table_no,player1_id,player2_id,result1,result2,host FROM tournament_game WHERE organization_id=? AND tournament_id=?', [$org, $id]) as $g) {
            $actualGames[] = array_map(static fn($x) => $x === null ? null : (int) $x, array_values($g));
        }
        $this->compareRows('games', $expectedGames, $actualGames, $diff);
        $expectedRanks = [];
        foreach ($source->results->ranking as $r) {
            $expectedRanks[] = [$r->main ? 'f' : 'w', $identity($r->playerName, $r->city), $r->position, round($r->rank, 2), $r->games];
        }
        if ($old) {
            $reference = $referenceSnapshot ?? (new OldRankingReferenceCalculator($c))->snapshot($org, $id);
            foreach (['rows' => 'n', 'waitingRows' => 'u'] as $list => $type) {
                foreach ($reference[$list] as $r) {
                    $expectedRanks[] = [$type, $r['playerId'], $r['position'], round($r['rankExact'], 2), $r['games']];
                }
            }
        }
        $sql = "SELECT rtype,player_id,position,rank,games FROM ranking WHERE organization_id=? AND tournament_id=?" . ($old ? '' : " AND rtype IN ('f','w')");
        $actualRanks = [];
        foreach ($c->fetchAllAssociative($sql, [$org, $id]) as $r) {
            $actualRanks[] = [$r['rtype'], (int) $r['player_id'], (int) $r['position'], round((float) $r['rank'], 2), (int) $r['games']];
        }
        foreach (['official' => ['f', 'w'], 'old' => ['n', 'u']] as $group => $types) {
            $select = static fn(array $r): bool => in_array($r[0], $types, true);
            $this->compareRows('ranking.' . $group, array_values(array_filter($expectedRanks, $select)), array_values(array_filter($actualRanks, $select)), $diff);
        }
        $unexpected = array_values(array_filter($actualRanks, static fn(array $r): bool => !in_array($r[0], ['f', 'w', 'n', 'u'], true)));
        $this->compareRows('ranking.unexpected', [], $unexpected, $diff);
        $audit = $c->fetchOne('SELECT data FROM text_resource WHERE organization_id=? AND legacy_id=? AND resource_type=9001 ORDER BY id DESC LIMIT 1', [$org, $id]);
        if ($audit) {
            $a = json_decode($audit, true, flags: JSON_THROW_ON_ERROR);
            if (isset($a['payload'])) {
                $check('classification/source-payload', json_encode(['tournament' => $payload->tournament, 'players' => $payload->players, 'results' => $payload->results, 'rankDay' => $payload->rankDay, 'ranking' => $payload->ranking], JSON_THROW_ON_ERROR), json_encode($a['payload'], JSON_THROW_ON_ERROR));
            }
        }
        return $diff;
    }
    private function compareRows(string $label, array $expected, array $actual, array &$diff): void
    {
        // JSON normalizes PHP int/float representations consistently, while preserving null identities.
        $encode = static fn($r) => json_encode($r, JSON_THROW_ON_ERROR);
        $a = array_map($encode, $expected);
        $b = array_map($encode, $actual);
        sort($a);
        sort($b);
        if ($a !== $b) {
            $diff[] = ['field' => $label, 'missing' => array_values(array_diff($a, $b)), 'extra' => array_values(array_diff($b, $a))];
        }
    }
}
