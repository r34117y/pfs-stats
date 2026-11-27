<?php

declare(strict_types=1);

namespace App\Service;

use App\ClubTournamentImport\ClubTournamentRankingSnapshot;
use App\ClubTournamentImport\ClubTournamentRankingUnavailable;
use Doctrine\DBAL\Connection;
use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final readonly class ClubTournamentRankingImportService
{
    public const int AUDIT_RESOURCE_TYPE = 9004;
    public const int COMPARISON_RESOURCE_TYPE = 9006;

    public function __construct(
        #[Autowire(service: 'doctrine.dbal.default_connection')]
        private Connection $connection,
        private ClubTournamentRankingParser $parser,
        private ?\Closure $fetcher = null,
    ) {
    }

    public function fetchSnapshot(int $organizationId, int $dateCode): ?ClubTournamentRankingSnapshot
    {
        $code = $this->connection->fetchOne('SELECT code FROM organization WHERE id=?', [$organizationId]);
        if ($code !== 'BLUBRY') {
            return null;
        }
        // The supplied Klymenia files contain 25 May; the published event is 26 May.
        $sourceDate = $dateCode === 20260525 ? 20260526 : $dateCode;
        $url = sprintf('https://scrabble.stats.org.pl/blubry/karank.php?t=%d0&r=f', $sourceDate);
        if ($this->fetcher !== null) {
            $html = ($this->fetcher)($url);
        } else {
            $context = stream_context_create(['http' => ['timeout' => 20, 'header' => "User-Agent: scrabble-stats-api/1.0\r\n"]]);
            $html = @file_get_contents($url, false, $context);
        }
        if (!is_string($html) || $html === '') {
            throw new ClubTournamentRankingUnavailable('Could not fetch the published club ranking: '.$url);
        }

        return new ClubTournamentRankingSnapshot($dateCode, $url, $html, $this->parser->parse($html, $sourceDate, true));
    }

    /** Returns inserted row count; an identical saved snapshot is a no-op. */
    public function store(ClubTournamentRankingSnapshot $snapshot, int $organizationId, int $tournamentId): int
    {
        return $this->connection->transactional(function (Connection $db) use ($snapshot, $organizationId, $tournamentId): int {
            $db->executeStatement('SELECT pg_advisory_xact_lock(73674, ?)', [$organizationId]);
            $wanted = $this->validateSnapshot($snapshot, $organizationId, $tournamentId);
            $actual = $this->storedRows($organizationId, $tournamentId, $snapshot->hasGameCounts());
            if ($actual !== []) {
                return 0;
            }
            if (!$snapshot->hasGameCounts() && $this->storedRows($organizationId, $tournamentId, true) !== []) {
                return 0; // An incomplete source cannot downgrade a complete snapshot.
            }
            if ($snapshot->hasGameCounts()) {
                foreach ($wanted as $row) {
                    $db->insert('ranking', ['organization_id' => $organizationId, 'tournament_id' => $tournamentId, 'rtype' => 'f'] + $row);
                }
            }
            $db->insert('text_resource', [
                'organization_id' => $organizationId,
                'resource_type' => $snapshot->hasGameCounts() ? self::AUDIT_RESOURCE_TYPE : self::COMPARISON_RESOURCE_TYPE,
                'data' => json_encode([
                    'tournamentDbId' => $tournamentId,
                    'sourceUrl' => $snapshot->sourceUrl,
                    'gameCountsAvailable' => $snapshot->hasGameCounts(),
                    'sourceSha256' => hash('sha256', $snapshot->html),
                    'sourceHtml' => $snapshot->html,
                    'rankingRows' => $wanted,
                    'importedAt' => (new \DateTimeImmutable())->format(DATE_ATOM),
                ], JSON_THROW_ON_ERROR),
            ]);

            return count($wanted);
        });
    }

    /** @return list<array{player_id:int,position:int,rank:float,games:?int}> */
    public function validateSnapshot(ClubTournamentRankingSnapshot $snapshot, int $organizationId, int $tournamentId): array
    {
        $db = $this->connection;
        $date = $db->fetchOne('SELECT dt FROM tournament WHERE organization_id=? AND id=?', [$organizationId, $tournamentId]);
        if ((int) $date !== $snapshot->dateCode) {
            throw new RuntimeException('Club ranking target tournament does not match its source date.');
        }
        $wanted = [];
        $seen = [];
        foreach ($snapshot->rows as $row) {
            $ids = $db->fetchFirstColumn('SELECT p.id FROM player p JOIN player_organization po ON po.player_id=p.id WHERE po.organization_id=? AND p.name_show=?', [$organizationId, $row['name']]);
            if (count($ids) !== 1 || isset($seen[(int) ($ids[0] ?? 0)])) {
                throw new RuntimeException('Club ranking requires one existing organization profile for '.$row['name']);
            }
            $id = (int) $ids[0];
            $seen[$id] = true;
            $wanted[] = ['player_id' => $id, 'position' => $row['position'], 'rank' => $row['rank'], 'games' => $row['games']];
        }
        $actual = $this->storedRows($organizationId, $tournamentId, $snapshot->hasGameCounts());
        if ($actual !== [] && $actual != $wanted) {
            throw new ConflictHttpException('Different club ranking data already exists for tournament '.$tournamentId);
        }

        return $wanted;
    }

    private function storedRows(int $organizationId, int $tournamentId, bool $complete): array
    {
        if ($complete) {
            return $this->connection->fetchAllAssociative("SELECT player_id,position,rank,games FROM ranking WHERE organization_id=? AND tournament_id=? AND rtype='f' ORDER BY position", [$organizationId, $tournamentId]);
        }
        $data = $this->connection->fetchOne(
            "SELECT data FROM text_resource WHERE organization_id=? AND resource_type=? AND data::jsonb->>'tournamentDbId'=? ORDER BY id DESC LIMIT 1",
            [$organizationId, self::COMPARISON_RESOURCE_TYPE, (string) $tournamentId],
        );
        return $data === false ? [] : json_decode($data, true, flags: JSON_THROW_ON_ERROR)['rankingRows'];
    }
}
