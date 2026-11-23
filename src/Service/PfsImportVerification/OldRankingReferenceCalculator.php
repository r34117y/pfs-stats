<?php

declare (strict_types=1);
namespace App\Service\PfsImportVerification;

use Doctrine\DBAL\Connection;
/** Verification oracle: separate data traversal and arithmetic from the import calculator. */
final readonly class OldRankingReferenceCalculator
{
    public function __construct(
        #[\Symfony\Component\DependencyInjection\Attribute\Autowire(service: 'doctrine.dbal.default_connection')]
        private Connection $connection
    )
    {
    }
    /** @return array{rows:array,waitingRows:array} */
    public function snapshot(int $org, int $target): array
    {
        return $this->snapshots($org, [$target])[$target];
    }
    /** Calculate several checkpoints in one independent chronological replay. */
    public function snapshots(int $org, array $targets): array
    {
        if ($targets === []) {
            return [];
        }
        $wanted = array_fill_keys($targets, true);
        $snapshots = [];
        $split = $this->connection->fetchAssociative("SELECT id,dt FROM tournament WHERE organization_id=? AND (urlid=1271 OR fullname='II Wielicki Turniej w Scrabble') ORDER BY dt,id LIMIT 1", [$org]);
        $end = $this->connection->fetchAssociative('SELECT id,dt FROM tournament WHERE organization_id=? AND id IN (?) ORDER BY dt DESC,id DESC LIMIT 1', [$org, $targets], [\Doctrine\DBAL\ParameterType::INTEGER, \Doctrine\DBAL\ArrayParameterType::INTEGER]);
        if (!$split || !$end) {
            throw new \RuntimeException('Reference ranking: missing split or target.');
        }
        $history = [];
        foreach ($this->connection->fetchAllAssociative('SELECT w.player_id,w.tournament_id,t.dt,w.games,w.trank FROM tournament_result w JOIN tournament t ON t.id=w.tournament_id WHERE w.organization_id=? AND (t.dt,t.id)<(?,?) AND w.games>0 ORDER BY t.dt,t.id', [$org, $split['dt'], $split['id']]) as $r) {
            $history[(int) $r['player_id']][] = ['date' => (int) $r['dt'], 'games' => (int) $r['games'], 'scalps' => (float) $r['trank'] * (int) $r['games']];
        }
        $seedId = $this->connection->fetchOne("SELECT t.id FROM tournament t JOIN ranking r ON r.tournament_id=t.id WHERE t.organization_id=? AND r.rtype='f' AND (t.dt,t.id)<(?,?) ORDER BY t.dt DESC,t.id DESC LIMIT 1", [$org, $split['dt'], $split['id']]);
        if (!$seedId) {
            throw new \RuntimeException('Reference ranking: missing initial ranking.');
        }
        $main = [];
        foreach ($this->connection->fetchAllAssociative("SELECT player_id,rank FROM ranking WHERE organization_id=? AND tournament_id=? AND rtype='f'", [$org, $seedId]) as $r) {
            $main[(int) $r['player_id']] = (float) $r['rank'];
        }
        $events = $this->connection->fetchAllAssociative('SELECT id,dt FROM tournament WHERE organization_id=? AND (dt,id)>=(?,?) AND (dt,id)<=(?,?) ORDER BY dt,id', [$org, $split['dt'], $split['id'], $end['dt'], $end['id']]);
        $gamesByEvent = [];
        $rows = $this->connection->fetchAllAssociative('SELECT g.tournament_id,g.round_no,g.table_no,g.player1_id,g.player2_id,g.result1,g.result2 FROM tournament_game g JOIN tournament t ON t.id=g.tournament_id WHERE g.organization_id=? AND g.player1_id IS NOT NULL AND g.player2_id IS NOT NULL AND ((g.result1>0 AND g.result2>0) OR g.ranko IS NOT NULL) AND (t.dt,t.id)>=(?,?) AND (t.dt,t.id)<=(?,?) ORDER BY t.dt,t.id,g.round_no,g.table_no,g.id', [$org, $split['dt'], $split['id'], $end['dt'], $end['id']]);
        $seen = [];
        foreach ($rows as $g) {
            // Independently exclude legacy non-ranking forfeits even when ranko was populated.
            if (((int) $g['result1'] === 400 && (int) $g['result2'] === 0)
                || ((int) $g['result1'] === 0 && (int) $g['result2'] === 400)) {
                continue;
            }
            $a = (int) $g['player1_id'];
            $b = (int) $g['player2_id'];
            $key = implode(':', [$g['tournament_id'], $g['round_no'], $g['table_no'], min($a, $b), max($a, $b)]);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $gamesByEvent[(int) $g['tournament_id']][] = $g;
        }
        $names = [];
        foreach ($this->connection->fetchAllAssociative('SELECT id,name_alph FROM player') as $p) {
            $names[(int) $p['id']] = $p['name_alph'];
        }
        foreach ($events as $event) {
            $eventRanks = [];
            $performance = [];
            foreach ($gamesByEvent[(int) $event['id']] ?? [] as $g) {
                $a = (int) $g['player1_id'];
                $b = (int) $g['player2_id'];
                foreach ([$a, $b] as $p) {
                    $eventRanks[$p] ??= self::beforeEvent($main[$p] ?? null, $history[$p] ?? []);
                }
                [$sa, $sb] = self::scalps($eventRanks[$a], $eventRanks[$b], (int) $g['result1'], (int) $g['result2']);
                foreach ([$a => $sa, $b => $sb] as $p => $scalp) {
                    $performance[$p]['games'] = ($performance[$p]['games'] ?? 0) + 1;
                    $performance[$p]['scalps'] = ($performance[$p]['scalps'] ?? 0.0) + $scalp;
                }
            }
            foreach ($performance as $p => $stats) {
                $history[$p][] = ['date' => (int) $event['dt']] + $stats;
            }
            $lists = self::lists($history, (int) $event['dt']);
            $main = [];
            foreach ($lists['rows'] as $r) {
                $main[$r['playerId']] = $r['rankExact'];
            }
            if (isset($wanted[(int) $event['id']])) {
                foreach (['rows', 'waitingRows'] as $type) {
                    usort($lists[$type], static fn(array $a, array $b): int => ($b['rankExact'] <=> $a['rankExact'] ?: $b['games'] <=> $a['games']) ?: strcmp($names[$a['playerId']] ?? '', $names[$b['playerId']] ?? ''));
                    foreach ($lists[$type] as $i => &$row) {
                        $row['position'] = $i + 1;
                    }
                    unset($row);
                }
                $snapshots[(int) $event['id']] = $lists;
            }
        }
        if (count($snapshots) !== count($wanted)) {
            throw new \RuntimeException('Reference ranking: requested checkpoint outside replay history.');
        }
        return $snapshots;
    }
    public static function beforeEvent(?float $rank, array $history): float
    {
        if ($rank !== null) {
            return round(max(100.0, $rank), 0, PHP_ROUND_HALF_UP);
        }
        $games = array_sum(array_column($history, 'games'));
        $scalps = array_sum(array_column($history, 'scalps'));
        if ($games < 30) {
            return round(max(100.0, ($scalps + (30 - $games) * 100) / 30), 0, PHP_ROUND_HALF_UP);
        }
        $n = 0;
        $total = 0.0;
        foreach (array_reverse($history) as $r) {
            $n += $r['games'];
            $total += $r['scalps'];
            if ($n >= 30) {
                break;
            }
        }
        return round(max(100.0, $total / $n), 0, PHP_ROUND_HALF_UP);
    }
    public static function scalps(float $a, float $b, int $pa, int $pb): array
    {
        if ($pa === $pb) {
            return [$b, $a];
        }
        $aWins = $pa > $pb;
        $winner = $aWins ? $a : $b;
        $loser = $aWins ? $b : $a;
        $won = $winner - $loser > 50 ? $winner : $loser + 50;
        $lost = $winner - $loser > 50 ? $loser : $winner - 50;
        return $aWins ? [$won, $lost] : [$lost, $won];
    }
    public static function lists(array $history, int $date): array
    {
        $from = (int) \DateTimeImmutable::createFromFormat('!Ymd', (string) $date)->sub(new \DateInterval('P730D'))->format('Ymd');
        $lists = ['rows' => [], 'waitingRows' => []];
        foreach ($history as $player => $records) {
            $games = 0;
            $scalps = 0.0;
            foreach (array_reverse($records) as $r) {
                if ($r['date'] > $date) {
                    continue;
                }
                if ($r['date'] < $from || $games + $r['games'] > 200) {
                    break;
                }
                $games += $r['games'];
                $scalps += $r['scalps'];
            }
            if ($games) {
                $lists[$games >= 30 ? 'rows' : 'waitingRows'][] = ['playerId' => (int) $player, 'rankExact' => $scalps / $games, 'games' => $games];
            }
        }
        return $lists;
    }
}
