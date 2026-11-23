<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\OldMethodCurrentRanking\OldMethodCurrentRankingServicePostgres;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OldMethodCurrentRankingServicePostgresTest extends TestCase
{
    #[DataProvider('tournamentStartingRanks')]
    public function testTournamentStartingRankIsRoundedAndAtLeast100(?float $listedRank, array $results, float $expected): void
    {
        $service = new OldMethodCurrentRankingServicePostgres($this->createStub(Connection::class));
        $history = [];
        foreach ($results as $index => [$games, $rank]) {
            $history[1][] = ['tournamentId' => $index + 1, 'dateInt' => 20200101 + $index, 'games' => $games, 'achievedRank' => $rank];
        }
        $career = (new \ReflectionMethod($service, 'buildCareerStatsFromHistory'))->invoke($service, $history);
        $snapshot = $listedRank === null ? [] : [1 => ['rank' => $listedRank, 'games' => 30, 'tournaments' => 1]];

        $actual = (new \ReflectionMethod($service, 'calculateTournamentRankForPlayer'))->invoke($service, 1, $snapshot, $history, $career);

        self::assertSame($expected, $actual);
    }

    public static function tournamentStartingRanks(): iterable
    {
        yield 'listed below minimum' => [85.54, [], 100.0];
        yield 'listed below half' => [149.49, [], 149.0];
        yield 'listed half rounds up' => [149.5, [], 150.0];
        yield 'listed above half' => [149.51, [], 150.0];
        yield 'returning below minimum' => [null, [[32, 85.54]], 100.0];
        yield 'returning below half' => [null, [[32, 149.49]], 149.0];
        yield 'returning half rounds up' => [null, [[32, 149.5]], 150.0];
        yield 'returning uses whole recent tournaments' => [null, [[50, 200.0], [20, 120.0], [12, 200.0]], 150.0];
        yield 'short career below minimum' => [null, [[10, 85.54]], 100.0];
        yield 'short career padded before rounding' => [null, [[10, 131.5]], 111.0];
        yield 'short career below half' => [null, [[10, 131.47]], 110.0];
        yield 'debutant' => [null, [], 100.0];
    }

    public function testListOrderingStillUsesExactAverages(): void
    {
        $service = new OldMethodCurrentRankingServicePostgres($this->createStub(Connection::class));
        $rows = (new \ReflectionMethod($service, 'buildRankingRows'))->invoke($service, [
            1 => ['rank' => 149.1, 'games' => 100, 'tournaments' => 8],
            2 => ['rank' => 149.49, 'games' => 30, 'tournaments' => 3],
        ], [
            1 => ['name' => 'A', 'nameSort' => 'A', 'photo' => null, 'slug' => null],
            2 => ['name' => 'B', 'nameSort' => 'B', 'photo' => null, 'slug' => null],
        ]);

        self::assertSame([2, 1], array_column($rows, 'playerId'));
        self::assertSame([149.49, 149.1], array_column($rows, 'rankExact'));
        self::assertSame([149, 149], array_column($rows, 'rankRounded'));
    }

    public function testRebuildSnapshotSeparatesMainAndWaitingRows(): void
    {
        $connection = $this->createStub(Connection::class);
        $service = new OldMethodCurrentRankingServicePostgres($connection);
        $method = new \ReflectionMethod($service, 'rebuildSnapshot');

        $snapshot = $method->invoke($service, [
            1 => [
                ['tournamentId' => 9367, 'dateInt' => 20260101, 'games' => 20, 'achievedRank' => 120.0],
                ['tournamentId' => 9368, 'dateInt' => 20260201, 'games' => 10, 'achievedRank' => 180.0],
            ],
            2 => [
                ['tournamentId' => 9368, 'dateInt' => 20260201, 'games' => 12, 'achievedRank' => 90.0],
            ],
        ], 20260201, 9368);

        self::assertArrayHasKey(1, $snapshot['main']);
        self::assertArrayNotHasKey(1, $snapshot['waiting']);
        self::assertSame(30, $snapshot['main'][1]['games']);
        self::assertSame(140.0, $snapshot['main'][1]['rank']);

        self::assertArrayHasKey(2, $snapshot['waiting']);
        self::assertArrayNotHasKey(2, $snapshot['main']);
        self::assertSame(12, $snapshot['waiting'][2]['games']);
        self::assertSame(90.0, $snapshot['waiting'][2]['rank']);
    }

    public function testWholeTournamentCapStopsAndKeepsInclusiveWindow(): void
    {
        $service = new OldMethodCurrentRankingServicePostgres($this->createStub(Connection::class));
        $m = new \ReflectionMethod($service, 'rebuildSnapshot');
        $r = $m->invoke($service, [1 => [
            ['tournamentId' => 9000, 'dateInt' => 20240101, 'games' => 1, 'achievedRank' => 100.0],
            ['tournamentId' => 9001, 'dateInt' => 20250101, 'games' => 12, 'achievedRank' => 200.0],
            ['tournamentId' => 9002, 'dateInt' => 20260101, 'games' => 199, 'achievedRank' => 150.0],
        ]], 20260101, 9002);
        self::assertSame(199, $r['main'][1]['games']);
        self::assertSame(150.0, $r['main'][1]['rank']);
        $r = $m->invoke($service, [1 => [
            ['tournamentId' => 9003, 'dateInt' => 20240101, 'games' => 1, 'achievedRank' => 100.0],
            ['tournamentId' => 9002, 'dateInt' => 20260101, 'games' => 199, 'achievedRank' => 150.0],
        ]], 20260101, 9002);
        self::assertSame(200, $r['main'][1]['games']);
        self::assertEqualsWithDelta(149.75, $r['main'][1]['rank'], 0.0001);
    }

    public function testNoDivisionByZeroForOversizedTournament(): void
    {
        $service = new OldMethodCurrentRankingServicePostgres($this->createStub(Connection::class));
        $m = new \ReflectionMethod($service, 'rebuildSnapshot');
        $r = $m->invoke($service, [1 => [['tournamentId' => 9002, 'dateInt' => 20260101, 'games' => 201, 'achievedRank' => 150.0]]], 20260101, 9002);
        self::assertSame(['main' => [], 'waiting' => []], $r);
    }

    public function testRebuildSnapshotReturnsEmptySetsForInvalidDate(): void
    {
        $connection = $this->createStub(Connection::class);
        $service = new OldMethodCurrentRankingServicePostgres($connection);
        $method = new \ReflectionMethod($service, 'rebuildSnapshot');

        self::assertSame(['main' => [], 'waiting' => []], $method->invoke($service, [], 0, 9367));
    }
}
