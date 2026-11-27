<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Service\BlubryRankingGameCountService;
use App\Service\RankingSnapshot\RankingSnapshotServicePostgres;

final class BlubryRankingGameCountTest extends PfsImportDatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->db->update('organization', ['code' => 'BLUBRY'], ['id' => 21]);
        foreach ([10 => 'Bartosz Bartczak', 11 => 'Opponent', 12 => 'Bartek Bartczak', 13 => 'No history'] as $id => $name) {
            $this->db->insert('player', ['id' => $id, 'name_show' => $name, 'name_alph' => $name]);
            $this->db->insert('player_organization', ['player_id' => $id, 'organization_id' => 21]);
        }
    }

    private function event(int $id, int $date, int $games, float $performance, int $player = 10): void
    {
        $this->db->insert('tournament', ['id' => $id, 'organization_id' => 21, 'dt' => $date, 'name' => 'Test', 'fullname' => 'Test']);
        $this->db->insert('tournament_result', ['organization_id' => 21, 'tournament_id' => $id, 'player_id' => $player, 'place' => 1, 'games' => $games, 'trank' => $performance]);
        for ($round = 1; $round <= $games; ++$round) {
            $this->db->insert('tournament_game', ['organization_id' => 21, 'tournament_id' => $id, 'player1_id' => $player, 'player2_id' => 11, 'round_no' => $round, 'result1' => 350, 'result2' => 300, 'ranko' => 100]);
        }
    }

    private function counts(int $reference): array
    {
        return (new BlubryRankingGameCountService($this->db))->countAfterTournament(21, $reference, [10, 13]);
    }

    public function testSelectsWholeBestTournamentsRatherThanLatestOrPartialGames(): void
    {
        $this->event(101, 20260601, 59, 200);
        $this->event(102, 20261101, 4, 150);
        $this->event(103, 20261117, 1, 100);
        self::assertSame([10 => 59, 13 => 0], $this->counts(103));
        $this->db->update('tournament_result', ['trank' => 250], ['tournament_id' => 103]);
        self::assertSame([10 => 60, 13 => 0], $this->counts(103));
    }

    public function testSixCalendarMonthsIncludeBoundaryAndExcludeFutureEvents(): void
    {
        $this->event(100, 20260516, 8, 200);
        $this->event(101, 20260517, 3, 200);
        $this->event(102, 20261117, 4, 150);
        $this->event(103, 20261117, 5, 180);
        $this->event(104, 20261118, 6, 190);
        self::assertSame(7, $this->counts(102)[10]);
    }

    public function testMonthEndClampsToLastDayOfFebruary(): void
    {
        $this->event(100, 20260227, 8, 200);
        $this->event(101, 20260228, 3, 200);
        $this->event(102, 20260831, 4, 150);
        self::assertSame(7, $this->counts(102)[10]);
    }

    public function testLeapYearIncludesFebruary29Boundary(): void
    {
        $this->event(100, 20240228, 8, 200);
        $this->event(101, 20240229, 3, 200);
        $this->event(102, 20240831, 4, 150);
        self::assertSame(7, $this->counts(102)[10]);
    }

    public function testCountsPlayedGamesFromHhRatherThanCreditedByesOrForfeits(): void
    {
        $this->event(101, 20261117, 7, 200);
        $this->db->update('tournament_game', ['player2_id' => null], ['tournament_id' => 101, 'round_no' => 1]);
        $this->db->update('tournament_game', ['result1' => 400, 'result2' => 0], ['tournament_id' => 101, 'round_no' => 2]);
        $this->db->update('tournament_game', ['result1' => 0, 'result2' => 400], ['tournament_id' => 101, 'round_no' => 3]);
        $this->db->update('tournament_game', ['ranko' => null], ['tournament_id' => 101, 'round_no' => 4]);
        $this->db->update('tournament_game', ['result1' => 350, 'result2' => 0], ['tournament_id' => 101, 'round_no' => 5]);
        self::assertSame(3, $this->counts(101)[10]);
    }

    public function testConfirmedHhAliasContributesToCanonicalRankingPlayer(): void
    {
        $this->event(101, 20261001, 4, 200, 12);
        $this->event(102, 20261117, 4, 150);
        self::assertSame(8, $this->counts(102)[10]);
    }

    public function testCompleteSnapshotUsesCalculatedCountAndPreservesSourceRecord(): void
    {
        $this->event(101, 20261117, 4, 200);
        $this->db->insert('ranking', ['organization_id' => 21, 'tournament_id' => 101, 'player_id' => 10, 'rtype' => 'f', 'position' => 1, 'rank' => 163.70, 'games' => 60]);
        $rows = (new RankingSnapshotServicePostgres($this->db))->getRankingAfterTournament(21, 101);
        self::assertSame(4, $rows[0]['games']);
        self::assertSame(163.70, $rows[0]['rank']);
        self::assertSame(60, (int) $this->db->fetchOne('SELECT games FROM ranking'));
        $this->db->update('organization', ['code' => 'PFS'], ['id' => 21]);
        self::assertSame(60, (new RankingSnapshotServicePostgres($this->db))->getRankingAfterTournament(21, 101)[0]['games']);
    }

    public function testIncompleteSnapshotGetsCalculatedCountWithoutChangingAudit(): void
    {
        $this->event(101, 20261117, 4, 200);
        $payload = json_encode(['tournamentDbId' => 101, 'rankingRows' => [['player_id' => 10, 'position' => 1, 'rank' => 163.70, 'games' => null]]], JSON_THROW_ON_ERROR);
        $this->db->insert('text_resource', ['organization_id' => 21, 'resource_type' => 9006, 'data' => $payload]);
        self::assertSame(4, (new RankingSnapshotServicePostgres($this->db))->getRankingAfterTournament(21, 101)[0]['games']);
        self::assertSame($payload, $this->db->fetchOne('SELECT data FROM text_resource'));
    }
}
