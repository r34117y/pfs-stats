<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\PfsTournamentImport\{CalendarTournament, ParsedTournamentPlayerGame, ParsedTournamentPlayerResults, ParsedTournamentRankingRow, ParsedTournamentResults, ParsedTournamentRoundGame, ParsedTournamentStandingRow, PfsTournamentSource};
use App\Service\PfsChampionshipPhaseSplitService;

final class PfsChampionshipPhaseSplitTest extends PfsImportDatabaseTestCase
{
    private function seedCombinedChampionship(): void
    {
        $this->seedRankingHistory();
        $this->db->insert('tournament', ['id' => 500, 'organization_id' => 21, 'dt' => 20241117,
            'fullname' => 'XXXI Mistrzostwa Polski', 'urlid' => 1299, 'rounds' => 30, 'players_count' => 2]);
        foreach ([10, 11] as $p) {
            $this->db->insert('tournament_result', ['organization_id' => 21, 'tournament_id' => 500,
                'player_id' => $p, 'place' => $p - 9, 'games' => 30, 'trank' => 100]);
        }
        for ($round = 1; $round <= 30; $round++) {
            foreach ([10, 11] as $p) {
                $this->db->insert('tournament_game', ['organization_id' => 21, 'tournament_id' => 500,
                    'round_no' => $round, 'table_no' => 1, 'player1_id' => $p, 'player2_id' => 21 - $p,
                    'result1' => $p === 10 ? 400 : 300, 'result2' => $p === 10 ? 300 : 400,
                    'ranko' => 100, 'host' => $p === 10 ? 1 : 2, 'gcg' => 'original annotation ' . $p . ':' . $round]);
            }
        }
    }

    private function source(bool $final, int $score = 400, int $year = 2024): PfsTournamentSource
    {
        $name = 'XXXI Mistrzostwa Polski — faza ' . ($final ? 'finałowa' : 'eliminacyjna');
        $games = $hhA = $hhB = [];
        for ($round = 1; $round <= 15; $round++) {
            $games[] = new ParsedTournamentRoundGame($round, 1, 'Main Player', 'Wait Player', $score, 300);
            $hhA[] = new ParsedTournamentPlayerGame($round, 1, 'Wait Player', 100, '+', $score, 300, 150);
            $hhB[] = new ParsedTournamentPlayerGame($round, 1, 'Main Player', 150, '-', 300, $score, 100);
        }
        $date = new \DateTimeImmutable($year . ($final ? '-11-16' : '-11-09'));
        return new PfsTournamentSource(new CalendarTournament($final ? 1299 : 1298, $name, 'Łódź', $date, $date->modify('+1 day')), 'fixture.html', hash('sha256', $name), new ParsedTournamentResults($name, [], [
            new ParsedTournamentPlayerResults('Main Player', 150, 'Łódź', $hhA, 2250, 15, 150),
            new ParsedTournamentPlayerResults('Wait Player', 100, 'Łódź', $hhB, 1500, 15, 100),
        ], [
            new ParsedTournamentStandingRow(1, 'Main Player', 'Łódź', 150, 15, $score * 15, 2250, ($score - 300) * 15),
            new ParsedTournamentStandingRow(2, 'Wait Player', 'Łódź', 100, 0, 4500, 1500, (300 - $score) * 15),
        ], $games, [new ParsedTournamentRankingRow(1, 'Main Player', 'Łódź', 150, 4500, 30)]));
    }

    public function testSplitsPhasesPreservesAnnotationsAndIsIdempotent(): void
    {
        $this->seedCombinedChampionship();
        $before = $this->db->fetchAllAssociative('SELECT id,gcg FROM tournament_game ORDER BY id');
        $service = new PfsChampionshipPhaseSplitService($this->db);
        $plan = $service->plan(21, $this->source(false), $this->source(true));
        self::assertFalse($plan['alreadySplit']);
        $this->db->beginTransaction();
        $ids = $service->apply($plan);
        self::assertSame(500, $ids['final']);
        self::assertNotSame(500, $ids['eliminations']);
        foreach (['eliminations' => 20241109, 'final' => 20241116] as $role => $date) {
            $t = $this->db->fetchAssociative('SELECT * FROM tournament WHERE id=?', [$ids[$role]]);
            self::assertSame($date, (int) $t['dt']);
            self::assertSame(15, (int) $t['rounds']);
            self::assertSame([15, 15], array_map('intval', $this->db->fetchFirstColumn('SELECT games FROM tournament_result WHERE tournament_id=?', [$ids[$role]])));
            self::assertSame(15, (int) $this->db->fetchOne('SELECT MAX(round_no) FROM tournament_game WHERE tournament_id=?', [$ids[$role]]));
            self::assertSame(30, (int) $this->db->fetchOne('SELECT COUNT(*) FROM tournament_game WHERE tournament_id=?', [$ids[$role]]));
        }
        self::assertSame($before, $this->db->fetchAllAssociative('SELECT id,gcg FROM tournament_game ORDER BY id'));
        self::assertSame(2, (int) $this->db->fetchOne("SELECT COUNT(*) FROM ranking WHERE rtype='f' AND tournament_id IN (?,?)", array_values($ids)));
        $second = $service->plan(21, $this->source(false), $this->source(true));
        self::assertTrue($second['alreadySplit']);
        self::assertSame($ids, $service->apply($second));
        self::assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM text_resource WHERE resource_type=9002'));
        $this->db->rollBack();
        self::assertSame(30, (int) $this->db->fetchOne('SELECT rounds FROM tournament WHERE id=500'));
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM tournament WHERE urlid=1298'));
        self::assertSame($before, $this->db->fetchAllAssociative('SELECT id,gcg FROM tournament_game ORDER BY id'));
    }

    public function testRejectsSourceScoreMismatchBeforeWriting(): void
    {
        $this->seedCombinedChampionship();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('score mismatch');
        (new PfsChampionshipPhaseSplitService($this->db))->plan(21, $this->source(false), $this->source(true, 401));
    }

    public function testRejectsYearsBeforeSimulationBoundary(): void
    {
        $this->seedCombinedChampionship();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('before the old-method simulation boundary');
        (new PfsChampionshipPhaseSplitService($this->db))->plan(21, $this->source(false, year: 2022), $this->source(true, year: 2022));
    }
}
