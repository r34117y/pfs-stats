<?php

declare(strict_types=1);

namespace App\Service;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use LogicException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class BlubryRankingGameCountService
{
    public const int MIN_GAMES = 10;
    private const int MAX_GAMES = 60;
    // Confirmed source aliases, scoped to BLUBRY. The legacy profile p=57 is
    // Bartosz Bartczak; HH exports use Bartek. Maciej's canonical name is hidden.
    private const array ALIASES = ['Bartek Bartczak' => 'Bartosz Bartczak', 'Maciej Labe' => 'dane ukryte.3'];

    public function __construct(
        #[Autowire(service: 'doctrine.dbal.default_connection')]
        private Connection $connection,
    ) {
    }

    /**
     * @param list<int|string> $playerIds
     * @return array<int,int>|null Null leaves the rules of other organizations unchanged.
     */
    public function countAfterTournament(int $organizationId, int $tournamentId, array $playerIds): ?array
    {
        if ($this->connection->fetchOne('SELECT code FROM organization WHERE id=?', [$organizationId]) !== 'BLUBRY') {
            return null;
        }
        $dateCode = $this->connection->fetchOne('SELECT dt FROM tournament WHERE organization_id=? AND id=?', [$organizationId, $tournamentId]);
        $date = DateTimeImmutable::createFromFormat('!Ymd', (string) $dateCode);
        if (!$date) {
            throw new LogicException('Cannot calculate ranking games without a reference tournament date.');
        }
        $month = $date->modify('first day of this month')->modify('-6 months');
        $cutoff = $month->setDate((int) $month->format('Y'), (int) $month->format('m'), min((int) $date->format('d'), (int) $month->format('t')));
        $canonicalIds = $this->canonicalIds($organizationId);
        $counts = array_fill_keys(array_map('intval', $playerIds), 0);
        $histories = [];
        $rows = $this->connection->fetchAllAssociative(
            'SELECT t.id,t.dt,g.player1_id,COUNT(*) AS games,tr.trank AS performance
             FROM tournament_game g
             JOIN tournament t ON t.id=g.tournament_id AND t.organization_id=g.organization_id
             JOIN tournament_result tr ON tr.tournament_id=t.id AND tr.organization_id=t.organization_id AND tr.player_id=g.player1_id
             WHERE g.organization_id=:organizationId
               AND t.dt>=:cutoff AND (t.dt,t.id)<=(:dateCode,:tournamentId)
               AND g.player1_id IS NOT NULL AND g.player2_id IS NOT NULL AND g.ranko IS NOT NULL
               AND NOT ((g.result1=400 AND g.result2=0) OR (g.result1=0 AND g.result2=400))
               AND tr.trank IS NOT NULL
             GROUP BY t.id,t.dt,g.player1_id,tr.trank',
            [
                'organizationId' => $organizationId,
                'cutoff' => (int) $cutoff->format('Ymd'),
                'dateCode' => (int) $dateCode,
                'tournamentId' => $tournamentId,
            ],
        );
        foreach ($rows as $row) {
            $playerId = $canonicalIds[(int) $row['player1_id']] ?? (int) $row['player1_id'];
            if (!array_key_exists($playerId, $counts)) {
                continue;
            }
            if (isset($histories[$playerId][(int) $row['id']])) {
                throw new LogicException('Two profiles contribute to the same player/tournament ranking history.');
            }
            $histories[$playerId][(int) $row['id']] = $row;
        }
        foreach ($histories as $playerId => $history) {
            // PFS selection: drop whole lowest-performance tournaments until
            // within the limit. Equivalently, keep the best prefix, without
            // splitting a tournament or skipping an oversized boundary event.
            usort($history, static fn (array $a, array $b): int =>
                [(float) $b['performance'], (int) $b['dt'], (int) $b['id']]
                <=> [(float) $a['performance'], (int) $a['dt'], (int) $a['id']]
            );
            foreach ($history as $record) {
                $candidate = $counts[$playerId] + (int) $record['games'];
                if ($candidate > self::MAX_GAMES) {
                    break;
                }
                $counts[$playerId] = $candidate;
            }
        }

        return $counts;
    }

    /** @return array<int,int> */
    private function canonicalIds(int $organizationId): array
    {
        $catalog = $this->connection->fetchAllAssociative('SELECT p.id,p.name_show FROM player p JOIN player_organization po ON po.player_id=p.id WHERE po.organization_id=?', [$organizationId]);
        $idsByName = [];
        foreach ($catalog as $row) {
            $idsByName[$row['name_show']][] = (int) $row['id'];
        }
        $mapping = [];
        foreach ($catalog as $row) {
            $canonicalName = self::ALIASES[$row['name_show']] ?? null;
            $targets = $canonicalName !== null ? ($idsByName[$canonicalName] ?? [(int) $row['id']]) : [(int) $row['id']];
            if (count($targets) !== 1) {
                throw new LogicException('Ambiguous Blubry ranking identity: '.$row['name_show']);
            }
            $mapping[(int) $row['id']] = $targets[0];
        }
        return $mapping;
    }
}
