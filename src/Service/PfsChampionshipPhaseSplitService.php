<?php

declare(strict_types=1);

namespace App\Service;

use App\PfsTournamentImport\PfsTournamentSource;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** Repairs legacy MP containers without replacing annotated game records. */
final readonly class PfsChampionshipPhaseSplitService
{
    public const int AUDIT_RESOURCE_TYPE = 9002;

    public function __construct(
        #[Autowire(service: 'doctrine.dbal.default_connection')]
        private Connection $connection,
    ) {
    }

    public function plan(int $org, PfsTournamentSource $eliminations, PfsTournamentSource $final): array
    {
        $c = $this->connection;
        $splitDate = $c->fetchOne('SELECT dt FROM tournament WHERE organization_id=? AND urlid=1271', [$org]);
        if (!$splitDate || (int) $eliminations->tournament->startDate->format('Ymd') < (int) $splitDate) {
            throw new \RuntimeException('Refusing to change championships before the old-method simulation boundary.');
        }
        if ($eliminations->tournament->endDate >= $final->tournament->startDate) {
            throw new \RuntimeException('Championship phases must be separate chronological events.');
        }
        $matches = $c->fetchAllAssociative('SELECT * FROM tournament WHERE organization_id=? AND urlid=?', [$org, $final->tournament->urlId]);
        if (count($matches) !== 1) {
            throw new \RuntimeException('Missing or ambiguous championship final.');
        }
        $original = $matches[0];
        $finalId = (int) $original['id'];
        $elimIds = $c->fetchFirstColumn('SELECT id FROM tournament WHERE organization_id=? AND urlid=?', [$org, $eliminations->tournament->urlId]);
        if (count($elimIds) > 1) {
            throw new \RuntimeException('Ambiguous elimination event.');
        }
        $alreadySplit = $elimIds !== [];
        $elimId = $alreadySplit ? (int) $elimIds[0] : null;
        if ((int) $original['rounds'] !== ($alreadySplit ? 15 : 30)) {
            throw new \RuntimeException('Unexpected or partially repaired championship structure.');
        }
        $normalizer = new PfsNameNormalizer();
        $resolver = new PfsPlayerIdentityResolver($normalizer);
        $index = $resolver->index((new PlayerCatalogService($c, $normalizer))->loadPlayerCatalog($org));
        $identity = static fn(string $name, ?string $city = null): int => (int) ($resolver->resolve($index, $name, $city)['playerId'] ?? throw new \RuntimeException('Unresolved source identity: ' . $name));
        $phases = [];
        foreach (['eliminations' => $eliminations, 'final' => $final] as $role => $source) {
            $payload = (new PfsTournamentPayloadFactory())->create($source->tournament, $source->results);
            $players = [];
            foreach ($payload->players as $p) {
                $players[$p['startingPosition']] = $p + ['playerId' => $identity($p['firstName'] . ' ' . $p['lastName'], $p['city'])];
            }
            $rounds = max(array_column($payload->results, 'round'));
            if ($rounds !== 15) {
                throw new \RuntimeException('Expected fifteen rounds in each championship phase.');
            }
            $games = [];
            $stats = [];
            foreach ($players as $p) {
                $stats[$p['playerId']] = ['gwin' => 0, 'glost' => 0, 'gdraw' => 0, 'games' => 0, 'hostgames' => 0, 'hostwin' => 0];
            }
            foreach ($payload->results as $g) {
                $a = $players[$g['host']];
                $b = $players[$g['guest']] ?? null;
                $eligible = $g['rankingEligible'] ?? true;
                foreach ([$a, $b] as $side => $p) {
                    $opponent = $side === 0 ? $b : $a;
                    $scored = $side === 0 ? $g['score1'] : $g['score2'];
                    $conceded = $side === 0 ? $g['score2'] : $g['score1'];
                    $row = ['round_no' => $g['round'], 'table_no' => $g['table'], 'player1_id' => $p['playerId'] ?? null,
                        'player2_id' => $opponent['playerId'] ?? null, 'result1' => $scored, 'result2' => $conceded,
                        'host' => $side + 1, 'ranko' => $eligible ? (int) round($opponent['tournamentRank'], 0, PHP_ROUND_HALF_UP) : null];
                    $key = self::gameKey($row);
                    if (isset($games[$key])) {
                        throw new \RuntimeException('Duplicate source game: ' . $key);
                    }
                    $games[$key] = $row;
                    if ($eligible && $p !== null) {
                        $s = &$stats[$p['playerId']];
                        $s['games']++;
                        $s[$scored > $conceded ? 'gwin' : ($scored < $conceded ? 'glost' : 'gdraw')]++;
                        $s['hostgames'] += $side === 0 ? 1 : 0;
                        $s['hostwin'] += $side === 0 && $scored > $conceded ? 1 : 0;
                        unset($s);
                    }
                }
            }
            $results = [];
            $winner = null;
            foreach ($players as $p) {
                $s = $stats[$p['playerId']];
                $n = $s['games'];
                $results[$p['playerId']] = $s + ['player_id' => $p['playerId'], 'place' => $p['place'],
                    'trank' => $n ? round($p['scalps'] / $n, 3) : null, 'brank' => round($p['tournamentRank'], 3),
                    'points' => $n ? round($p['small'] / $n, 3) : null,
                    'pointo' => $n ? round(($p['small'] - $p['difference']) / $n, 3) : null];
                if ($p['place'] === 1) {
                    $winner = $p['playerId'];
                }
            }
            $ranking = [];
            foreach ($source->results->ranking as $r) {
                $ranking[] = ['rtype' => $r->main ? 'f' : 'w', 'player_id' => $identity($r->playerName, $r->city),
                    'position' => $r->position, 'rank' => round($r->rank, 2), 'games' => $r->games];
            }
            $date = (int) $source->tournament->startDate->format('Ymd');
            $phases[$role] = ['id' => $role === 'final' ? $finalId : $elimId, 'sourceUrl' => $source->tournament->getResultsUrl(), 'sourceSha256' => $source->sha256,
                'metadata' => ['dt' => $date, 'start_round' => $date, 'urlid' => $source->tournament->urlId,
                    'name' => $source->tournament->startDate->format('ymd') . ($role === 'final' ? ' Finał MP' : ' Eliminacje MP'),
                    'fullname' => mb_substr($source->results->tournamentName, 0, 80), 'place' => $source->tournament->location,
                    'winner_player_id' => $winner, 'players_count' => count($players), 'rounds' => $rounds,
                    'trank' => round(array_sum(array_column($players, 'tournamentRank')) / count($players), 3)],
                'games' => $games, 'results' => $results, 'ranking' => $ranking];
        }
        $existingIds = $alreadySplit ? [$finalId, $elimId] : [$finalId];
        $existingGames = $c->fetchAllAssociative('SELECT * FROM tournament_game WHERE organization_id=? AND tournament_id IN (?) ORDER BY id', [$org, $existingIds], [\Doctrine\DBAL\ParameterType::INTEGER, \Doctrine\DBAL\ArrayParameterType::INTEGER]);
        foreach ($existingGames as $g) {
            $role = $alreadySplit ? ((int) $g['tournament_id'] === $elimId ? 'eliminations' : 'final') : ((int) $g['round_no'] <= 15 ? 'eliminations' : 'final');
            $round = (int) $g['round_no'] - (!$alreadySplit && $role === 'final' ? 15 : 0);
            $key = self::gameKey(array_replace($g, ['round_no' => $round]));
            $wanted = $phases[$role]['games'][$key] ?? throw new \RuntimeException('Existing game not found in PFS phase: ' . $g['id']);
            if (isset($wanted['existingId']) || (int) $g['result1'] !== $wanted['result1'] || (int) $g['result2'] !== $wanted['result2']) {
                throw new \RuntimeException('Duplicate game or score mismatch with PFS: ' . $g['id']);
            }
            $phases[$role]['games'][$key]['existingId'] = (int) $g['id'];
        }
        foreach ($phases as $p) {
            foreach ($p['games'] as $g) {
                if (!isset($g['existingId']) && $g['player1_id'] !== null && $g['player2_id'] !== null) {
                    throw new \RuntimeException('A played PFS game is missing from the legacy container.');
                }
            }
        }
        $resultPlayers = array_map('intval', $c->fetchFirstColumn('SELECT player_id FROM tournament_result WHERE organization_id=? AND tournament_id IN (?)', [$org, $existingIds], [\Doctrine\DBAL\ParameterType::INTEGER, \Doctrine\DBAL\ArrayParameterType::INTEGER]));
        $sourcePlayers = array_unique(array_merge(array_keys($phases['eliminations']['results']), array_keys($phases['final']['results'])));
        sort($resultPlayers); $sourcePlayers = array_values($sourcePlayers); sort($sourcePlayers);
        if (array_values(array_unique($resultPlayers)) !== $sourcePlayers) {
            throw new \RuntimeException('Legacy and PFS participant sets differ.');
        }
        $officialRankingsDiffer = false;
        foreach ($phases as &$phase) {
            $expected = array_map(static fn(array $r): array => array_values($r), $phase['ranking']);
            $actual = [];
            if ($phase['id'] !== null) {
                foreach ($c->fetchAllAssociative("SELECT rtype,player_id,position,rank,games FROM ranking WHERE organization_id=? AND tournament_id=? AND rtype IN ('f','w')", [$org, $phase['id']]) as $r) {
                    $actual[] = [$r['rtype'], (int) $r['player_id'], (int) $r['position'], round((float) $r['rank'], 2), (int) $r['games']];
                }
            }
            sort($expected); sort($actual);
            $phase['officialRankingMatches'] = $expected === $actual;
            $officialRankingsDiffer = $officialRankingsDiffer || !$phase['officialRankingMatches'];
        }
        unset($phase);
        return ['organizationId' => $org, 'alreadySplit' => $alreadySplit, 'original' => $original, 'phases' => $phases,
            'officialRankingsDiffer' => $officialRankingsDiffer, 'preservedGameIds' => array_map('intval', array_column($existingGames, 'id'))];
    }

    /** Caller must hold the organization advisory lock and own the outer transaction. */
    public function apply(array $plan): array
    {
        $c = $this->connection;
        if (!$c->isTransactionActive()) {
            throw new \LogicException('Phase splitting requires a transaction.');
        }
        if ($plan['alreadySplit']) {
            foreach ($plan['phases'] as $phase) {
                if (!$phase['officialRankingMatches']) {
                    $this->replaceOfficialRankings($plan['organizationId'], $phase['id'], $phase['ranking']);
                }
            }
            return ['final' => $plan['phases']['final']['id'], 'eliminations' => $plan['phases']['eliminations']['id']];
        }
        $org = $plan['organizationId'];
        $ids = ['final' => (int) $plan['original']['id']];
        $c->insert('tournament', ['organization_id' => $org, 'team' => 'N', 'rrecreated' => '', 'wksum' => 0] + $plan['phases']['eliminations']['metadata']);
        $ids['eliminations'] = (int) $c->lastInsertId();
        $c->update('tournament', $plan['phases']['final']['metadata'], ['id' => $ids['final'], 'organization_id' => $org]);
        // Move elimination games before renumbering finals to avoid round/player unique collisions.
        foreach (['eliminations', 'final'] as $role) {
            foreach ($plan['phases'][$role]['games'] as $g) {
                $id = $g['existingId'] ?? null;
                unset($g['existingId']);
                $values = ['organization_id' => $org, 'tournament_id' => $ids[$role]] + $g;
                if ($id !== null) {
                    $c->update('tournament_game', $values, ['id' => $id]);
                } else {
                    $c->insert('tournament_game', $values);
                }
            }
        }
        $existingResults = [];
        foreach ($c->fetchAllAssociative('SELECT id,player_id FROM tournament_result WHERE organization_id=? AND tournament_id=?', [$org, $ids['final']]) as $r) {
            $existingResults[(int) $r['player_id']] = (int) $r['id'];
        }
        foreach (['eliminations', 'final'] as $role) {
            foreach ($plan['phases'][$role]['results'] as $pid => $r) {
                $values = ['organization_id' => $org, 'tournament_id' => $ids[$role]] + $r;
                // Finals retain their result IDs; non-finalists' existing rows move to eliminations.
                $reuse = $role === 'final' || !isset($plan['phases']['final']['results'][$pid]);
                if ($reuse && isset($existingResults[$pid])) {
                    $c->update('tournament_result', $values, ['id' => $existingResults[$pid]]);
                } else {
                    $c->insert('tournament_result', $values);
                }
            }
        }
        foreach ($plan['phases'] as $role => $phase) {
            $this->replaceOfficialRankings($org, $ids[$role], $phase['ranking']);
        }
        $c->insert('text_resource', ['organization_id' => $org, 'resource_type' => self::AUDIT_RESOURCE_TYPE, 'legacy_id' => $ids['final'],
            'data' => json_encode(['operation' => 'split-mp-phases', 'originalTournament' => $plan['original'], 'phaseIds' => $ids,
                'sources' => array_map(static fn(array $p): array => ['url' => $p['sourceUrl'], 'sha256' => $p['sourceSha256']], $plan['phases']),
                'preservedGameIds' => $plan['preservedGameIds'], 'repairedAt' => gmdate('c')], JSON_THROW_ON_ERROR)]);
        return $ids;
    }

    private function replaceOfficialRankings(int $org, int $id, array $rows): void
    {
        $this->connection->executeStatement("DELETE FROM ranking WHERE organization_id=? AND tournament_id=? AND rtype IN ('f','w')", [$org, $id]);
        foreach ($rows as $r) {
            $this->connection->insert('ranking', ['organization_id' => $org, 'tournament_id' => $id] + $r);
        }
    }

    private static function gameKey(array $g): string
    {
        return implode(':', [(int) $g['round_no'], $g['player1_id'] ?? 0, $g['player2_id'] ?? 0]);
    }
}
