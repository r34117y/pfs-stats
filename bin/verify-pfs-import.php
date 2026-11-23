<?php

declare (strict_types=1);
use App\Service\{PfsTournamentWebsiteClient, PfsTournamentCalendarParser, PfsTournamentArchive, PfsTournamentSourceValidator, PfsNameNormalizer, PfsPlayerIdentityResolver, PlayerCatalogService};
use App\Service\PfsImportVerification\{HistoryManifestVerifier, ImportedTournamentComparator};
$options = getopt('', ['year:', 'from-pfsid:', 'through-pfsid:', 'mode:', 'output:', 'include-history-repairs']);
try {
    foreach (['year', 'from-pfsid', 'through-pfsid'] as $key) {
        if (!isset($options[$key]) || !preg_match('/^[1-9][0-9]*$/D', $options[$key])) {
            throw new InvalidArgumentException('Missing/invalid --' . $key);
        }
    }
    if (!in_array($options['mode'] ?? '', ['preflight', 'verify'], true)) {
        throw new InvalidArgumentException('Expected --mode=preflight or --mode=verify');
    }
    $c = require __DIR__ . '/pfs-bootstrap.php';
    $root = dirname(__DIR__);
    chdir($root);
    $out = $options['output'] ?? 'var/pfs-import';
    if (!is_dir($out) && !mkdir($out, 0775, true) && !is_dir($out)) {
        throw new RuntimeException('Cannot create report directory.');
    }
    $org = (int) $c->fetchOne("SELECT id FROM organization WHERE lower(code)='pfs'");
    $client = new PfsTournamentWebsiteClient();
    $parser = new PfsTournamentCalendarParser();
    $archive = new PfsTournamentArchive($root . '/tournament_html');
    $year = (int) $options['year'];
    $calendarFile = $out . '/calendar-' . $year . '.html';
    if (!is_file($calendarFile)) {
        file_put_contents($calendarFile, $client->fetchCalendarHtml($year));
    }
    $events = $parser->parse(file_get_contents($calendarFile), $year);
    $byId = [];
    foreach ($events as $e) {
        $byId[$e->urlId] = $e;
    }
    $from = $byId[(int) $options['from-pfsid']] ?? throw new RuntimeException('From event not found in official calendar.');
    $through = $byId[(int) $options['through-pfsid']] ?? throw new RuntimeException('Through event not found in official calendar.');
    if ($from->startDate >= $through->startDate) {
        throw new InvalidArgumentException('Event date range is empty/reversed.');
    }
    $verifier = new HistoryManifestVerifier($c);
    $baseSource = $archive->load($from);
    $baseId = $verifier->findTournament($org, $from->urlId, (int) $from->startDate->format('Ymd'), $baseSource->results->tournamentName, $from->location) ?? throw new RuntimeException('Baseline event missing from database.');
    $baselineDate = (int) $c->fetchOne('SELECT dt FROM tournament WHERE id=?', [$baseId]);
    $baselineIds = array_map('intval', $c->fetchFirstColumn('SELECT id FROM tournament WHERE organization_id=? AND (dt,id)<=(?,?) ORDER BY dt,id', [$org, $baselineDate, $baseId]));
    $selected = array_values(array_filter($events, fn($e) => $e->startDate > $from->startDate && $e->startDate <= $through->startDate));
    // Calendar order is the explicit initial tie order. Existing same-day rows retain local order.
    usort($selected, fn($a, $b) => $a->startDate <=> $b->startDate);
    $sources = [];
    $entries = [];
    foreach ($selected as $e) {
        $source = $archive->load($e);
        $sources[$e->urlId] = $source;
        $entries[] = ['pfsId' => $e->urlId, 'date' => (int) $e->startDate->format('Ymd'), 'name' => $source->results->tournamentName, 'city' => $e->location, 'path' => $source->path, 'sha256' => $source->sha256];
    }
    $manifestPath = $out . '/history-manifest.json';
    if ($options['mode'] === 'verify') {
        if (!is_file($manifestPath)) {
            throw new RuntimeException('Missing manifest; run preflight first.');
        }
        $manifest = json_decode(file_get_contents($manifestPath), true, flags: JSON_THROW_ON_ERROR);
        $verifier->assertBaseline($manifest, $org);
        $entries = $manifest['events'];
        foreach ($entries as $entry) {
            $sources[$entry['pfsId']] ??= $archive->load($byId[$entry['pfsId']] ?? throw new RuntimeException('Confirmed repair event absent from current calendar.'));
            if ($sources[$entry['pfsId']]->sha256 !== $entry['sha256']) { throw new RuntimeException('Confirmed source changed; rerun preflight.'); }
        }
        $comparator = new ImportedTournamentComparator($c);
        $report = [];
        $failed = false;
        $ids = [];
        foreach ($entries as $entry) {
            $ids[$entry['pfsId']] = $verifier->findTournament($org, $entry['pfsId'], $entry['date'], $entry['name'], $entry['city']);
        }
        $references = (new \App\Service\PfsImportVerification\OldRankingReferenceCalculator($c))->snapshots($org, array_values(array_filter($ids)));
        foreach ($entries as $entry) {
            $id = $ids[$entry['pfsId']];
            $diff = $id === null ? [['field' => 'missing tournament']] : $comparator->compare($sources[$entry['pfsId']], $org, $id, true, $references[$id]);
            $report[] = ['pfsId' => $entry['pfsId'], 'localId' => $id, 'differences' => $diff,
                'sourceSha256' => $entry['sha256'],
                'officialSourceRows' => $sources[$entry['pfsId']]->results->ranking,
                'oldReferenceRows' => $references[$id] ?? null,
                'storedRankingRows' => $id === null ? [] : $c->fetchAllAssociative('SELECT rtype,player_id,position,rank,games FROM ranking WHERE organization_id=? AND tournament_id=? ORDER BY rtype,position', [$org, $id]),
            ];
            $failed = $failed || $diff !== [];
            echo $entry['pfsId'], ' ', $diff ? 'FAILED' : 'verified', "\n";
        }
        $latest = (int) $c->fetchOne('SELECT id FROM tournament WHERE organization_id=? ORDER BY dt DESC,id DESC LIMIT 1', [$org]);
        $target = $report[array_key_last($report)]['localId'];
        if ($latest !== $target) {
            $failed = true;
            $report[] = ['field' => 'latest tournament', 'expected' => $target, 'actual' => $latest];
        }
        file_put_contents($out . '/ranking-verification.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");
        exit($failed ? 1 : 0);
    }
    $normalizer = new PfsNameNormalizer();
    $resolver = new PfsPlayerIdentityResolver($normalizer);
    $index = $resolver->index((new PlayerCatalogService($c, $normalizer))->loadPlayerCatalog($org));
    $debutants = [];
    $unresolved = [];
    foreach ($sources as $source) {
        foreach ($source->results->players as $p) {
            if (!$resolver->resolve($index, $p->playerName)) {
                // A source starting rank of 100 plus no catalog collision is sufficient debut evidence.
                if (isset($debutants[$p->playerName])) {
                    continue;
                }
                if (abs($p->tournamentRank - 100.0) > 0.001) {
                    $unresolved[$p->playerName] = 'Unmatched participant has a non-debut tournament rank.';
                    continue;
                }
                $debutants[$p->playerName] = ['name' => $p->playerName, 'pfsId' => $source->tournament->urlId, 'date' => (int) $source->tournament->startDate->format('Ymd'), 'path' => $source->path, 'sha256' => $source->sha256];
            }
        }
    }
    foreach ($sources as $source) {
        foreach ($source->results->ranking as $r) {
            if ($resolver->resolve($index, $r->playerName)) {
                continue;
            }
            $evidence = $debutants[$r->playerName] ?? null;
            if (!$evidence || $evidence['date'] > (int) $source->tournament->startDate->format('Ymd')) {
                $unresolved[$r->playerName] = 'Ranking-only identity lacks confirmed debut evidence at this date.';
            }
        }
    }
    $split = $c->fetchAssociative("SELECT id,dt FROM tournament WHERE organization_id=? AND (urlid=1271 OR fullname='II Wielicki Turniej w Scrabble') ORDER BY dt,id LIMIT 1", [$org]);
    if (!$split) {
        throw new RuntimeException('Missing method split source event.');
    }
    $missing = [];
    $excluded = [];
    $historicalGroups = [];
    $repairEvents = [];
    for ($y = (int) substr((string) $split['dt'], 0, 4); $y <= $year; $y++) {
        $f = $out . '/calendar-' . $y . '.html';
        if (!is_file($f)) {
            file_put_contents($f, $client->fetchCalendarHtml($y));
        }
        $html = file_get_contents($f);
        foreach ($parser->parse($html, $y) as $e) {
            $dt = (int) $e->startDate->format('Ymd');
            if ($dt < (int) $split['dt'] - 1 || $e->startDate > $from->startDate) {
                continue;
            }
            if (!$parser->isRankingEvent($html, $e->urlId)) {
                $excluded[] = ['pfsId' => $e->urlId, 'reason' => 'Official calendar row is not a PFS ranking tournament', 'calendar' => $f];
                continue;
            }
            $group = $c->fetchFirstColumn('SELECT id FROM tournament WHERE organization_id=? AND urlid=? ORDER BY dt,id', [$org, $e->urlId]);
            if (count($group) > 1) {
                $historicalGroups[] = ['pfsId' => $e->urlId, 'localIds' => array_map('intval', $group), 'reason' => 'Existing historical decomposition of a combined PFS event; preserved without reimport'];
                $id = (int) $group[0];
            } else {
                $id = $verifier->findTournament($org, $e->urlId, $dt, $e->name, $e->location);
            }
            if ($id === null) {
                // The calendar explicitly labels holidays; these are not ranking events.
                if (str_contains($e->name, 'Planszówki')) {
                    $excluded[] = ['pfsId' => $e->urlId, 'reason' => 'Scrabble holiday in official calendar, not a ranking tournament', 'calendar' => $f];
                    continue;
                }
                $legacy = $c->fetchOne('SELECT id FROM tournament WHERE organization_id=? AND dt BETWEEN ? AND ? AND (fullname=? OR fullname=?)', [$org, $dt, (int) $e->endDate->format('Ymd'), $e->name, mb_substr($e->name, 0, 80)]);
                if (!$legacy && str_contains($e->name, 'faza eliminacyjna')) {
                    $baseName = trim(preg_replace('/\s*[—–-]\s*faza eliminacyjna/u', '', $e->name));
                    $combined = $c->fetchAssociative('SELECT id,dt,rounds,players_count FROM tournament WHERE organization_id=? AND fullname=? AND dt BETWEEN ? AND ? AND rounds>=30', [$org, $baseName, $dt, (int) $e->endDate->modify('+10 days')->format('Ymd')]);
                    if ($combined) {
                        $missing[] = ['pfsId' => $e->urlId, 'localIds' => [(int) $combined['id']], 'reason' => 'Legacy MP combines eliminations and finals; run bin/split-pfs-championship-phases.php before importing history', 'evidence' => $combined, 'calendar' => $f];
                        continue;
                    }
                }
                if (!$legacy) {
                    if (isset($options['include-history-repairs'])) {
                        $repair = $archive->load($e);
                        $repairEvents[] = $e;
                        $sources[$e->urlId] = $repair;
                        $entries[] = ['pfsId' => $e->urlId, 'date' => $dt, 'name' => $repair->results->tournamentName, 'city' => $e->location, 'path' => $repair->path, 'sha256' => $repair->sha256, 'historyRepair' => true];
                    } else {
                        $missing[] = ['pfsId' => $e->urlId, 'date' => $dt, 'name' => $e->name];
                    }
                    continue;
                }
                $id = (int) $legacy;
            }
            $count = (int) $c->fetchOne('SELECT count(*) FROM tournament_result WHERE organization_id=? AND tournament_id=?', [$org, $id]);
            if (!$count) {
                $missing[] = ['pfsId' => $e->urlId, 'reason' => 'No participant results'];
            }
        }
    }
    $repairPlanPath = $out . '/history-repair-events.json';
    if ($repairEvents) {
        file_put_contents($repairPlanPath, json_encode(array_values(array_filter($entries, static fn (array $entry): bool => $entry['historyRepair'] ?? false)), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    } elseif (isset($options['include-history-repairs']) && is_file($repairPlanPath)) {
        foreach (json_decode(file_get_contents($repairPlanPath), true, flags: JSON_THROW_ON_ERROR) as $entry) {
            $event = $byId[$entry['pfsId']] ?? throw new RuntimeException('Repair event is absent from official calendar.');
            $source = $archive->load($event);
            if ($source->sha256 !== $entry['sha256']) { throw new RuntimeException('Repair source changed; regenerate its confirmed plan.'); }
            $sources[$event->urlId] = $source;
            $entries[] = $entry;
        }
    }
    if ($repairEvents) {
        foreach ($repairEvents as $e) {
            $source = $sources[$e->urlId];
            foreach ($source->results->players as $p) {
                if (!$resolver->resolve($index, $p->playerName) && abs($p->tournamentRank - 100.0) < 0.001) {
                    $evidence = ['name' => $p->playerName, 'pfsId' => $e->urlId, 'date' => (int) $e->startDate->format('Ymd'), 'path' => $source->path, 'sha256' => $source->sha256];
                    if (!isset($debutants[$p->playerName]) || $evidence['date'] < $debutants[$p->playerName]['date']) {
                        $debutants[$p->playerName] = $evidence;
                        unset($unresolved[$p->playerName]);
                    }
                }
            }
        }
    }
    usort($entries, static fn($a, $b) => $a['date'] <=> $b['date']);
    // Historical repairs are discovered after the initial target range. Validate
    // their complete ranking lists too, including a retained repair plan on retry.
    foreach ($sources as $source) {
        foreach ($source->results->ranking as $r) {
            if ($resolver->resolve($index, $r->playerName)) { continue; }
            $evidence = $debutants[$r->playerName] ?? null;
            if (!$evidence || $evidence['date'] > (int) $source->tournament->startDate->format('Ymd')) {
                $unresolved[$r->playerName] = 'Ranking-only identity lacks confirmed debut evidence at this date.';
            }
        }
    }
    file_put_contents($out . '/preflight-problems.json', json_encode(['missingHistory' => $missing, 'unresolvedIdentities' => $unresolved, 'exclusions' => $excluded], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    if ($missing || $unresolved) {
        throw new RuntimeException('Preflight blocked; see ' . $out . '/preflight-problems.json');
    }
    $manifest = ['formatVersion' => 1, 'organizationId' => $org, 'methodSplit' => $split, 'baselineId' => $baseId, 'baselineDate' => $baselineDate, 'baselineIds' => $baselineIds, 'baselineFingerprint' => $verifier->baselineFingerprint($org, $baselineDate, $baseId, $baselineIds), 'aliasHash' => hash_file('sha256', $root . '/config/pfs_player_aliases.yaml'), 'events' => $entries, 'confirmedDebutants' => $debutants, 'exclusions' => $excluded, 'historicalGroups' => $historicalGroups];
    $staged = $manifestPath . '.tmp.' . bin2hex(random_bytes(5));
    if (file_put_contents($staged, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n") === false || !rename($staged, $manifestPath)) {
        throw new RuntimeException('Cannot publish verified history manifest.');
    }
    echo 'Preflight verified: ', count($entries), ' events; baseline local ID ', $baseId, "\n";
} catch (InvalidArgumentException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(2);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
