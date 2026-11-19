<?php

declare (strict_types=1);
namespace App\Tests\Integration;

use App\Service\OldMethodCurrentRanking\OldMethodCurrentRankingServicePostgres;
use App\Service\PfsImportVerification\OldRankingReferenceCalculator;
final class PfsOldRankingChronologyTest extends PfsImportDatabaseTestCase
{
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
