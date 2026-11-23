<?php

declare (strict_types=1);
namespace App\Tests\Integration;

use App\Service\OldMethodCurrentRanking\OldMethodCurrentRankingServicePostgres;
use App\Service\PfsImportVerification\OldRankingReferenceCalculator;
final class PfsOldRankingChronologyTest extends PfsImportDatabaseTestCase
{
    public function testForfeitsWithLegacyRankoAreExcludedAndWindowMetadataUses730Days(): void
    {
        $this->seedRankingHistory();
        $this->db->insert('tournament', ['id' => 51, 'organization_id' => 21, 'dt' => 20250101, 'fullname' => 'Eligibility regression']);
        foreach ([10, 11] as $p) {
            $this->db->insert('tournament_result', ['organization_id' => 21, 'tournament_id' => 51, 'player_id' => $p, 'place' => $p - 9]);
        }
        foreach ([[400, 0], [0, 400], [402, 0], [400, 350]] as $i => [$a, $b]) {
            foreach ([false, true] as $reverse) {
                $this->db->insert('tournament_game', ['organization_id' => 21, 'tournament_id' => 51,
                    'round_no' => $i + 1, 'table_no' => 1, 'player1_id' => $reverse ? 11 : 10, 'player2_id' => $reverse ? 10 : 11,
                    'result1' => $reverse ? $b : $a, 'result2' => $reverse ? $a : $b,
                    // Normal positive scores remain eligible without ranko; stale ranko cannot make a forfeit eligible.
                    'ranko' => $i === 3 ? null : ($reverse ? 150 : 100)]);
            }
        }
        $this->db->insert('tournament_game', ['organization_id' => 21, 'tournament_id' => 51,
            'round_no' => 5, 'table_no' => 1, 'player1_id' => 10, 'player2_id' => null, 'result1' => 300, 'result2' => 0, 'ranko' => null]);
        $service = new OldMethodCurrentRankingServicePostgres($this->db);
        $s = $service->calculateRankingSnapshotAfterTournament(21, 51);
        self::assertSame('2023-01-02', $s['windowStartDate']);
        self::assertSame([32, 32], array_column($s['rows'], 'games'));
        self::assertSame([150.0, 100.0], array_column($s['rows'], 'rankExact'));
        $oracle = (new OldRankingReferenceCalculator($this->db))->snapshot(21, 51);
        self::assertSame(array_column($s['rows'], 'games'), array_column($oracle['rows'], 'games'));
        self::assertSame(array_column($s['rows'], 'rankExact'), array_column($oracle['rows'], 'rankExact'));
        foreach ($s['rows'] as $r) {
            $this->db->insert('ranking', ['organization_id' => 21, 'tournament_id' => 51, 'rtype' => 'n',
                'player_id' => $r['playerId'], 'position' => $r['position'], 'rank' => $r['rankExact'], 'games' => $r['games']]);
        }
        self::assertSame('2023-01-02', $service->calculateCurrentRanking()['windowStartDate']);
        self::assertSame(9, (int) $this->db->fetchOne('SELECT COUNT(*) FROM tournament_game WHERE tournament_id=51'));
    }

    public function testDateBoundsDoNotAssumeLocalIdChronologyAndOracleAgrees(): void
    {
        $this->seedRankingHistory();
        // Out-of-order IDs: the imported January 1 event gets ID 1, earlier history has ID 777.
        $first = $this->importFixture();
        $second = $this->importFixture(124, '2026-01-02');
        $service = new OldMethodCurrentRankingServicePostgres($this->db);
        $before = $service->calculateRankingSnapshotAfterTournament(21, $first->tournamentId);
        $after = $service->calculateRankingSnapshotAfterTournament(21, $second->tournamentId);
        self::assertCount(2, $before['waitingRows']);
        self::assertSame([1, 1], array_column($before['waitingRows'], 'games'));
        self::assertSame([2, 2], array_column($after['waitingRows'], 'games'));
        $navigation = new \App\Service\Ranking\RankingServicePostgres($this->db, $this->createStub(\App\Service\RankingSnapshot\RankingSnapshotServiceInterface::class));
        self::assertSame($second->tournamentId, (new \ReflectionMethod($navigation, 'getLatestRankingTournamentId'))->invoke($navigation, 21, 'f'));
        self::assertSame($first->tournamentId, (new \ReflectionMethod($navigation, 'getPreviousNavigationTournamentId'))->invoke($navigation, 21, $second->tournamentId, 'f'));
        self::assertSame($second->tournamentId, (new \ReflectionMethod($navigation, 'getNextNavigationTournamentId'))->invoke($navigation, 21, $first->tournamentId, 'f'));
        $oracle = (new OldRankingReferenceCalculator($this->db))->snapshot(21, $second->tournamentId);
        self::assertSame(array_column($after['waitingRows'], 'rankExact'), array_column($oracle['waitingRows'], 'rankExact'));
        $checkpoints = (new OldRankingReferenceCalculator($this->db))->snapshots(21, [$second->tournamentId, $first->tournamentId]);
        self::assertSame([1, 1], array_column($checkpoints[$first->tournamentId]['waitingRows'], 'games'));
        self::assertSame([2, 2], array_column($checkpoints[$second->tournamentId]['waitingRows'], 'games'));
        self::assertSame($oracle, $checkpoints[$second->tournamentId]);
    }
}
