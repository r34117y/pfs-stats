<?php

declare (strict_types=1);
namespace App\Service;

use App\PfsTournamentImport\PfsTournamentSource;
use App\PfsTournamentImport\PfsTournamentPreflightResult;
use App\Service\PfsImportVerification\HistoryManifestVerifier;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
final readonly class PfsTournamentImportPreflight
{
    public function __construct(
        #[Autowire(service: 'doctrine.dbal.default_connection')]
        private Connection $connection,
        #[Autowire('%app.pfs_manifest_path%')]
        private string $manifestPath
    )
    {
    }
    public function check(PfsTournamentSource $source): PfsTournamentPreflightResult
    {
        if (!is_file($this->manifestPath)) {
            throw new \RuntimeException('Missing history manifest; run bin/verify-pfs-import.php --mode=preflight.');
        }
        $m = json_decode(file_get_contents($this->manifestPath), true, flags: JSON_THROW_ON_ERROR);
        $org = (int) $this->connection->fetchOne("SELECT id FROM organization WHERE lower(code) = 'pfs'");
        $verifier = new HistoryManifestVerifier($this->connection);
        $verifier->assertBaseline($m, $org);
        if (isset($m['aliasHash']) && $m['aliasHash'] !== hash_file('sha256', dirname(__DIR__, 2) . '/config/pfs_player_aliases.yaml')) {
            throw new \RuntimeException('Identity mappings changed; rerun verifier preflight.');
        }
        $found = false;
        $predecessors = [];
        foreach ($m['events'] as $entry) {
            if (!is_file($entry['path']) || hash_file('sha256', $entry['path']) !== $entry['sha256']) {
                throw new \RuntimeException('Source changed; rerun verifier preflight: PFS ' . $entry['pfsId']);
            }
            if ($entry['pfsId'] === $source->tournament->urlId) {
                if ($source->sha256 !== $entry['sha256']) {
                    throw new \RuntimeException('Manifest/source hash mismatch.');
                }
                $found = true;
                break;
            }
            $id = $verifier->findTournament($org, $entry['pfsId'], $entry['date'], $entry['name'], $entry['city']);
            if ($id === null) {
                throw new \RuntimeException('Required predecessor missing: PFS ' . $entry['pfsId']);
            }
            $types = $this->connection->fetchFirstColumn('SELECT DISTINCT rtype FROM ranking WHERE organization_id = ? AND tournament_id = ?', [$org, $id]);
            if (!in_array('f', $types, true) || !array_intersect(['n', 'u'], $types)) {
                throw new \RuntimeException('Required predecessor rankings missing: PFS ' . $entry['pfsId']);
            }
            $priorEvent = new \App\PfsTournamentImport\CalendarTournament($entry['pfsId'], $entry['name'], $entry['city'], \DateTimeImmutable::createFromFormat('!Ymd', (string) $entry['date']), \DateTimeImmutable::createFromFormat('!Ymd', (string) $entry['date']));
            $priorSource = new PfsTournamentSource($priorEvent, $entry['path'], $entry['sha256'], (new PfsTournamentSourceValidator())->validate(file_get_contents($entry['path'])));
            $predecessors[$id] = $priorSource;
        }
        if ($predecessors) {
            $snapshots = (new \App\Service\PfsImportVerification\OldRankingReferenceCalculator($this->connection))->snapshots($org, array_keys($predecessors));
            foreach ($predecessors as $id => $priorSource) {
                $differences = (new \App\Service\PfsImportVerification\ImportedTournamentComparator($this->connection))->compare($priorSource, $org, $id, true, $snapshots[$id]);
                if ($differences) {
                    throw new \RuntimeException('Required predecessor data changed: PFS ' . $priorSource->tournament->urlId . '; ' . json_encode(array_slice($differences, 0, 2), JSON_UNESCAPED_UNICODE));
                }
            }
        }
        if (!$found) {
            throw new \RuntimeException('Tournament outside confirmed manifest.');
        }
        $approved = [];
        foreach ($m['confirmedDebutants'] ?? [] as $name => $evidence) {
            if ($evidence['date'] > (int) $source->tournament->startDate->format('Ymd')) {
                continue;
            }
            if (!is_file($evidence['path']) || hash_file('sha256', $evidence['path']) !== $evidence['sha256']) {
                throw new \RuntimeException('Debut evidence source changed; rerun verifier preflight.');
            }
            $approved[$name] = $evidence;
        }
        $event = $source->tournament;
        return new PfsTournamentPreflightResult($org, $verifier->findTournament($org, $event->urlId, (int) $event->startDate->format('Ymd'), $source->results->tournamentName, $event->location), $approved);
    }
}
