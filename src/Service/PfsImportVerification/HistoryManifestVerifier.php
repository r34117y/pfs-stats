<?php

declare (strict_types=1);
namespace App\Service\PfsImportVerification;

use Doctrine\DBAL\Connection;
/** Read-only integrity evidence; expected appends never alter the baseline. */
final readonly class HistoryManifestVerifier
{
    public function __construct(
        #[\Symfony\Component\DependencyInjection\Attribute\Autowire(service: 'doctrine.dbal.default_connection')]
        private Connection $connection
    )
    {
    }
    public function baselineFingerprint(int $org, int $throughDate, int $throughId, ?array $baselineIds = null): array
    {
        $out = [];
        foreach (['tournament' => 'id', 'tournament_result' => 'tournament_id', 'tournament_game' => 'tournament_id', 'ranking' => 'tournament_id'] as $table => $fk) {
            $params = ['org' => $org, 'dt' => $throughDate, 'id' => $throughId];
            $types = [];
            $condition = '(t.dt,t.id) <= (:dt,:id)';
            if ($baselineIds !== null) {
                $condition .= ' AND t.id IN (:ids)';
                $params['ids'] = $baselineIds;
                $types['ids'] = \Doctrine\DBAL\ArrayParameterType::INTEGER;
            }
            $out[$table] = $this->connection->fetchOne("SELECT md5(COALESCE(string_agg(md5(row_to_json(x)::text), '' ORDER BY x.id), '')) FROM {$table} x JOIN tournament t ON t.id = x.{$fk} AND t.organization_id = x.organization_id WHERE x.organization_id = :org AND {$condition}", $params, $types);
        }
        return $out;
    }
    public function assertBaseline(array $manifest, int $org): void
    {
        if (($manifest['formatVersion'] ?? null) !== 1 || ($manifest['organizationId'] ?? null) !== $org) {
            throw new \RuntimeException('Invalid PFS history manifest version or organization; rerun verifier preflight.');
        }
        if ($this->baselineFingerprint($org, $manifest['baselineDate'], $manifest['baselineId'], $manifest['baselineIds'] ?? null) !== $manifest['baselineFingerprint']) {
            throw new \RuntimeException('Confirmed historical data changed; rerun verifier preflight before importing.');
        }
    }
    public function findTournament(int $org, int $pfsId, int $date, string $name, string $city): ?int
    {
        $rows = $this->connection->fetchFirstColumn('SELECT id FROM tournament WHERE organization_id = ? AND urlid = ?', [$org, $pfsId]);
        if (!$rows) {
            $rows = $this->connection->fetchFirstColumn('SELECT id FROM tournament WHERE organization_id = ? AND dt = ? AND fullname = ? AND place = ?', [$org, $date, mb_substr($name, 0, 80), $city]);
        }
        if (count($rows) > 1) {
            throw new \RuntimeException('Ambiguous tournament identity for PFS ' . $pfsId);
        }
        return $rows ? (int) $rows[0] : null;
    }
}
