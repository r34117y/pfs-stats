<?php

declare (strict_types=1);
use App\Service\OldMethodCurrentRanking\OldMethodCurrentRankingServicePostgres;
use Doctrine\DBAL\Logging\DebugStack;
$connection = require __DIR__ . '/pfs-bootstrap.php';
$options = getopt('', ['tournament:', 'output:']);
$organization = (int) $connection->fetchOne("SELECT id FROM organization WHERE lower(code) = 'pfs'");
$id = (int) ($options['tournament'] ?? $connection->fetchOne('SELECT id FROM tournament WHERE organization_id = ? ORDER BY dt DESC,id DESC LIMIT 1', [$organization]));
$logger = new DebugStack();
$connection->getConfiguration()->setSQLLogger($logger);
$runs = [];
for ($i = 0; $i < 4; $i++) {
    $logger->queries = [];
    $start = hrtime(true);
    $result = (new OldMethodCurrentRankingServicePostgres($connection))->calculateRankingSnapshotAfterTournament($organization, $id);
    $runs[] = ['seconds' => (hrtime(true) - $start) / 1000000000.0, 'queries' => count($logger->queries), 'peakBytes' => memory_get_peak_usage(true), 'hash' => hash('sha256', json_encode($result, JSON_THROW_ON_ERROR))];
}
$warm = array_column(array_slice($runs, 1), 'seconds');
sort($warm);
$json = json_encode(['tournamentId' => $id, 'runtime' => PHP_VERSION, 'xdebugMode' => function_exists('xdebug_info') ? xdebug_info('mode') : [], 'runs' => $runs, 'warmMedianSeconds' => $warm[1]], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
if (isset($options['output'])) {
    file_put_contents($options['output'], $json);
}
echo $json;
