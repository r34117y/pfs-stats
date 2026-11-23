<?php

declare(strict_types=1);

use App\PfsTournamentImport\PfsTournamentSource;
use App\Service\PfsChampionshipPhaseSplitService;
use App\Service\PfsImportVerification\ImportedTournamentComparator;
use App\Service\PfsImportVerification\OldRankingReferenceCalculator;
use App\Service\OldMethodCurrentRanking\OldMethodCurrentRankingServicePostgres;
use App\Service\{PfsTournamentCalendarParser, PfsTournamentSourceValidator, PfsTournamentWebsiteClient};
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\ConsoleOutput;

$c = require __DIR__ . '/pfs-bootstrap.php';
$options = getopt('', ['mode:', 'source-dir:', 'output:', 'dry-run']);
$mode = $options['mode'] ?? 'plan';
$dryRun = isset($options['dry-run']);
$out = $options['output'] ?? dirname(__DIR__) . '/var/pfs-mp-split';
$sourceDir = $options['source-dir'] ?? $out . '/sources';
try {
    if (!in_array($mode, ['plan', 'apply', 'verify'], true) || $dryRun && $mode !== 'apply') {
        throw new InvalidArgumentException('Use --mode=plan|apply|verify; --dry-run is valid only with apply.');
    }
    foreach ([$out, $sourceDir] as $dir) {
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Cannot create report/source directory.');
        }
    }
    $org = (int) $c->fetchOne("SELECT id FROM organization WHERE code='PFS'");
    $boundary = (int) $c->fetchOne('SELECT dt FROM tournament WHERE organization_id=? AND urlid=1271', [$org]);
    if (!$org || !$boundary) {
        throw new RuntimeException('Missing PFS organization or method split event.');
    }
    $client = new PfsTournamentWebsiteClient();
    $calendarParser = new PfsTournamentCalendarParser();
    $validator = new PfsTournamentSourceValidator();
    $service = new PfsChampionshipPhaseSplitService($c);
    $sources = [];
    $manifestPath = $sourceDir . '/manifest.json';
    $manifest = is_file($manifestPath) ? json_decode(file_get_contents($manifestPath), true, flags: JSON_THROW_ON_ERROR) : [];
    $oldManifest = $manifest;
    $load = static function (string $filename, Closure $fetch) use ($sourceDir, &$manifest, $oldManifest): string {
        $path = $sourceDir . '/' . $filename;
        if (!is_file($path)) {
            file_put_contents($path, $fetch());
        }
        $html = file_get_contents($path);
        $hash = hash('sha256', $html);
        if (isset($oldManifest[$filename]) && $oldManifest[$filename] !== $hash) {
            throw new RuntimeException('Source file changed: ' . $filename);
        }
        $manifest[$filename] = $hash;
        return $html;
    };
    $candidates = $c->fetchAllAssociative('SELECT t.* FROM tournament t WHERE t.organization_id=? AND t.dt>=? AND (t.rounds=30 OR EXISTS(SELECT 1 FROM text_resource x WHERE x.organization_id=t.organization_id AND x.legacy_id=t.id AND x.resource_type=?)) ORDER BY t.dt,t.id', [$org, $boundary, PfsChampionshipPhaseSplitService::AUDIT_RESOURCE_TYPE]);
    foreach ($candidates as $t) {
        if (!preg_match('/^[IVXLCDM]+ Mistrzostwa Polski(?:\s*[—–-]\s*faza finałowa)?$/u', $t['fullname'] ?? '')) {
            continue;
        }
        $year = (int) substr((string) $t['dt'], 0, 4);
        $calendar = $load('calendar-' . $year . '.html', fn() => $client->fetchCalendarHtml($year));
        $events = $calendarParser->parse($calendar, $year);
        $finals = array_values(array_filter($events, fn($e) => $e->urlId === (int) $t['urlid'] && str_contains($e->name, 'faza finałowa')));
        if (count($finals) !== 1) {
            throw new RuntimeException('Cannot find unique final in PFS calendar: ' . $year);
        }
        $final = $finals[0];
        $elimName = str_replace('faza finałowa', 'faza eliminacyjna', $final->name);
        $eliminations = array_values(array_filter($events, fn($e) => $e->name === $elimName));
        if (count($eliminations) !== 1) {
            throw new RuntimeException('Cannot find unique eliminations in PFS calendar: ' . $year);
        }
        foreach (['eliminations' => $eliminations[0], 'final' => $final] as $role => $event) {
            if (!$calendarParser->isRankingEvent($calendar, $event->urlId)) {
                throw new RuntimeException('Source phase is not a ranking event.');
            }
            $html = $load($event->urlId . '.html', fn() => $client->fetchTournamentHtml($event->urlId));
            $r = $validator->validate($html);
            if (str_replace(['—', '–'], '-', $r->tournamentName) !== str_replace(['—', '–'], '-', $event->name)) {
                throw new RuntimeException('Source/calendar identity mismatch: ' . $event->urlId);
            }
            $sources[$year][$role] = new PfsTournamentSource($event, $sourceDir . '/' . $event->urlId . '.html', hash('sha256', $html), $r);
        }
    }
    file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
    $c->beginTransaction();
    $c->executeStatement('SELECT pg_advisory_xact_lock(73673, ?)', [$org]);
    $plans = [];
    foreach ($sources as $year => $pair) {
        $plans[$year] = $service->plan($org, $pair['eliminations'], $pair['final']);
    }
    file_put_contents($out . '/plan.json', json_encode($plans, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    foreach ($plans as $year => $p) {
        echo $year, ' ', $p['alreadySplit'] ? 'already split' : 'combined', ': PFS ', $p['phases']['eliminations']['metadata']['urlid'], ' + ', $p['phases']['final']['metadata']['urlid'], '; preserving ', count($p['preservedGameIds']), " game IDs\n";
    }
    if ($mode === 'plan') {
        $c->rollBack();
        exit(0);
    }
    $originalIds = array_map(static fn(array $p): int => (int) $p['original']['id'], $plans);
    $allIds = $originalIds;
    foreach ($plans as $p) {
        if ($p['phases']['eliminations']['id'] !== null) {
            $allIds[] = $p['phases']['eliminations']['id'];
        }
    }
    $invariants = static function (array $exclude) use ($c, $org, $boundary): array {
        $ids = implode(',', array_map('intval', $exclude)) ?: '0';
        $hash = static fn(string $table, string $where = 'TRUE'): array => $c->fetchAssociative("SELECT count(*) AS rows,md5(COALESCE(string_agg(md5(r::text),'' ORDER BY r.id),'')) AS hash FROM $table r WHERE $where");
        return ['otherTournaments' => $hash('tournament', "id NOT IN ($ids)"),
            'otherResults' => $hash('tournament_result', "tournament_id IS NULL OR tournament_id NOT IN ($ids)"),
            'otherGames' => $hash('tournament_game', "tournament_id IS NULL OR tournament_id NOT IN ($ids)"),
            'otherRankings' => $hash('ranking', "(tournament_id IS NULL OR tournament_id NOT IN ($ids)) AND NOT (organization_id=$org AND rtype IN ('n','u') AND tournament_id IN (SELECT id FROM tournament WHERE organization_id=$org AND dt>=$boundary))"),
            'annotatedGames' => $c->fetchAssociative("SELECT count(*) AS rows,md5(COALESCE(string_agg(md5(json_build_array(id,gcg,gcg_updated_at)::text),'' ORDER BY id),'')) AS hash FROM tournament_game WHERE gcg IS NOT NULL OR gcg_updated_at IS NOT NULL"),
            'photos' => $hash('game_photo'), 'players' => $hash('player')];
    };
    $before = $invariants($allIds);
    $idsByYear = [];
    foreach ($plans as $year => $p) {
        if ($mode === 'verify' && !$p['alreadySplit']) {
            throw new RuntimeException('Championship is still combined: ' . $year);
        }
        $idsByYear[$year] = $mode === 'apply' ? $service->apply($p) : array_map(static fn(array $v): int => $v['id'], $p['phases']);
        $allIds = array_merge($allIds, array_values($idsByYear[$year]));
    }
    $changed = count(array_filter($plans, static fn(array $p): bool => !$p['alreadySplit']));
    if ($mode === 'apply' && $changed > 0) {
        $command = new App\Command\BackfillOldMethodRankingSnapshotsCommand(new OldMethodCurrentRankingServicePostgres($c), $c);
        if ($command->run(new ArrayInput(['--changed-only' => true]), new ConsoleOutput()) !== 0) {
            throw new RuntimeException('Old ranking rebuild failed; the phase split will be rolled back.');
        }
    }
    $snapshots = [];
    foreach ((new OldMethodCurrentRankingServicePostgres($c))->calculateRankingSnapshots() as $s) {
        $snapshots[$s['referenceTournamentId']] = $s;
    }
    $oracle = (new OldRankingReferenceCalculator($c))->snapshots($org, array_keys($snapshots));
    $comparator = new ImportedTournamentComparator($c);
    foreach ($idsByYear as $year => $ids) {
        foreach ($ids as $role => $id) {
            $differences = $comparator->compare($sources[$year][$role], $org, $id, true, $oracle[$id]);
            if ($differences !== []) {
                file_put_contents($out . '/differences.json', json_encode($differences, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
                throw new RuntimeException('PFS source comparison failed: ' . $year . ' ' . $role . '; see differences.json');
            }
        }
    }
    $rowCount = 0;
    foreach ($snapshots as $id => $s) {
        $expected = $reference = [];
        foreach (['rows' => 'n', 'waitingRows' => 'u'] as $list => $type) {
            foreach ($s[$list] as $r) {
                $expected[] = [$type, $r['playerId'], $r['position'], round($r['rankExact'], 2), $r['games']];
            }
            foreach ($oracle[$id][$list] as $r) {
                $reference[] = [$type, $r['playerId'], $r['position'], round($r['rankExact'], 2), $r['games']];
            }
        }
        $actual = [];
        foreach ($c->fetchAllAssociative("SELECT rtype,player_id,position,rank,games FROM ranking WHERE organization_id=? AND tournament_id=? AND rtype IN ('n','u')", [$org, $id]) as $r) {
            $actual[] = [$r['rtype'], (int) $r['player_id'], (int) $r['position'], round((float) $r['rank'], 2), (int) $r['games']];
        }
        sort($expected); sort($reference); sort($actual);
        if ($expected !== $actual || $expected !== $reference) {
            throw new RuntimeException('Stored/calculated/independent old rankings differ: ' . $id);
        }
        $rowCount += count($expected);
    }
    if ($before !== $invariants($allIds)) {
        throw new RuntimeException('Unrelated data or game annotations changed.');
    }
    $report = ['mode' => $mode, 'dryRun' => $dryRun, 'changedChampionships' => $mode === 'apply' ? $changed : 0,
        'phaseIds' => $idsByYear, 'verifiedSnapshots' => count($snapshots), 'verifiedRankingRows' => $rowCount,
        'sourceComparisonsPassed' => true, 'unrelatedDataAndAnnotationsUnchanged' => true,
        'uglikAfter2026Eliminations' => $c->fetchAllAssociative("SELECT r.rank,r.games,r.position FROM ranking r JOIN tournament t ON t.id=r.tournament_id JOIN player p ON p.id=r.player_id WHERE r.organization_id=? AND t.urlid=1399 AND r.rtype='n' AND p.name_show='Mirosław Uglik'", [$org])];
    if ($dryRun || $mode === 'verify') {
        $c->rollBack();
    } else {
        $c->commit();
    }
    file_put_contents($out . '/' . $mode . ($dryRun ? '-dry-run' : '') . '-report.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), "\n";
    if ($mode === 'apply' && !$dryRun && $changed > 0) {
        $application = new Symfony\Bundle\FrameworkBundle\Console\Application($kernel);
        $application->setAutoExit(false);
        if ($application->run(new ArrayInput(['command' => 'app:cache:refresh-after-import', '--org' => $org, '--no-debug' => true]), new ConsoleOutput()) !== 0) {
            throw new RuntimeException('Data committed, but cache publication failed; run app:cache:refresh-after-import.');
        }
    }
} catch (Throwable $e) {
    while ($c->isTransactionActive()) {
        $c->rollBack();
    }
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
