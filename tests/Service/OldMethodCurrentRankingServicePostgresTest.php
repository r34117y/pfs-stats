<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\OldMethodCurrentRanking\OldMethodCurrentRankingServicePostgres;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

final class OldMethodCurrentRankingServicePostgresTest extends TestCase
{
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
