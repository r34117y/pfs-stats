<?php

namespace App\Service;

use App\PfsTournamentImport\PfsTournamentImportComparison;
use App\PfsTournamentImport\PfsTournamentImportPlan;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class PfsTournamentImportComparerPostgres implements PfsTournamentImportComparerInterface
{
    private const string ORGANIZATION_CODE = 'PFS';

    public function __construct(
        #[Autowire(service: 'doctrine.dbal.default_connection')]
        private readonly Connection $connection,
    ) {
    }

    public function compare(PfsTournamentImportPlan $plan): PfsTournamentImportComparison
    {
        $findings = [];

        $this->compareTournamentRow($plan, $findings);
        $this->compareTournamentResults($plan, $findings);
        $this->compareTournamentGames($plan, $findings);

        return new PfsTournamentImportComparison(
            matches: $findings === [],
            findings: $findings,
        );
    }

    /**
     * @param list<string> $findings
     */
    private function compareTournamentRow(PfsTournamentImportPlan $plan, array &$findings): void
    {
        $organizationId = $this->fetchOrganizationId();
        if ($organizationId === null) {
            $findings[] = sprintf('PFSTOURS row %d is missing in database.', $plan->tournament->id);

            return;
        }

        $actual = $this->connection->fetchAssociative(
            'SELECT
                legacy_id AS id,
                dt,
                name,
                fullname,
                legacy_winner_player_id AS winner,
                trank,
                players_count AS players,
                rounds,
                team,
                mcategory,
                wksum,
                legacy_series_id AS sertour,
                start_round AS start,
                referee,
                place,
                organizer,
                urlid
            FROM tournament
            WHERE organization_id = :organizationId
              AND legacy_id = :id',
            [
                'organizationId' => $organizationId,
                'id' => $plan->tournament->id,
            ],
        );

        if ($actual === false) {
            $findings[] = sprintf('PFSTOURS row %d is missing in database.', $plan->tournament->id);

            return;
        }

        $expected = [
            'id' => $plan->tournament->id,
            'dt' => $plan->tournament->dt,
            'name' => $plan->tournament->name,
            'fullname' => $plan->tournament->fullname,
            'winner' => $plan->tournament->winner,
            'trank' => $plan->tournament->trank,
            'players' => $plan->tournament->players,
            'rounds' => $plan->tournament->rounds,
            'team' => $plan->tournament->team,
            'mcategory' => $plan->tournament->mcategory,
            'wksum' => $plan->tournament->wksum,
            'sertour' => $plan->tournament->sertour,
            'start' => $plan->tournament->start,
            'referee' => $plan->tournament->referee,
            'place' => $plan->tournament->place,
            'organizer' => $plan->tournament->organizer,
            'urlid' => $plan->tournament->urlid,
        ];

        foreach ($expected as $field => $expectedValue) {
            $actualValue = $actual[$field] ?? null;
            if (!$this->valuesEqual($expectedValue, $actualValue)) {
                $findings[] = sprintf(
                    'PFSTOURS.%s differs: expected=%s actual=%s',
                    $field,
                    $this->stringify($expectedValue),
                    $this->stringify($actualValue),
                );
            }
        }
    }

    /**
     * @param list<string> $findings
     */
    private function compareTournamentResults(PfsTournamentImportPlan $plan, array &$findings): void
    {
        $organizationId = $this->fetchOrganizationId();
        if ($organizationId === null) {
            $findings[] = sprintf('PFSTOURWYN row count differs: expected=%d actual=%d', count($plan->tournamentResults), 0);

            return;
        }

        $actualRows = $this->connection->fetchAllAssociative(
            'SELECT
                legacy_player_id AS player,
                place,
                gwin,
                glost,
                gdraw,
                games,
                trank,
                brank,
                points,
                pointo,
                hostgames,
                hostwin,
                masters
            FROM tournament_result
            WHERE organization_id = :organizationId
              AND legacy_tournament_id = :id
              AND legacy_player_id IS NOT NULL',
            [
                'organizationId' => $organizationId,
                'id' => $plan->tournament->id,
            ],
        );

        $expectedByPlayer = [];
        foreach ($plan->tournamentResults as $row) {
            $expectedByPlayer[$row->player] = $row;
        }

        $actualByPlayer = [];
        foreach ($actualRows as $row) {
            $actualByPlayer[(int) $row['player']] = $row;
        }

        if (count($expectedByPlayer) !== count($actualByPlayer)) {
            $findings[] = sprintf('PFSTOURWYN row count differs: expected=%d actual=%d', count($expectedByPlayer), count($actualByPlayer));
        }

        foreach ($expectedByPlayer as $playerId => $expected) {
            $actual = $actualByPlayer[$playerId] ?? null;
            if ($actual === null) {
                $findings[] = sprintf('PFSTOURWYN row for player %d is missing in database.', $playerId);
                continue;
            }

            foreach ([
                'place' => $expected->place,
                'gwin' => $expected->gwin,
                'glost' => $expected->glost,
                'gdraw' => $expected->gdraw,
                'games' => $expected->games,
                'trank' => $expected->trank,
                'brank' => $expected->brank,
                'points' => $expected->points,
                'pointo' => $expected->pointo,
                'hostgames' => $expected->hostgames,
                'hostwin' => $expected->hostwin,
                'masters' => $expected->masters,
            ] as $field => $expectedValue) {
                if (!$this->valuesEqual($expectedValue, $actual[$field] ?? null)) {
                    $findings[] = sprintf(
                        'PFSTOURWYN player %d field %s differs: expected=%s actual=%s',
                        $playerId,
                        $field,
                        $this->stringify($expectedValue),
                        $this->stringify($actual[$field] ?? null),
                    );
                }
            }
        }

        foreach (array_diff(array_keys($actualByPlayer), array_keys($expectedByPlayer)) as $extraPlayerId) {
            $findings[] = sprintf('PFSTOURWYN has unexpected player row %d in database.', $extraPlayerId);
        }
    }

    /**
     * @param list<string> $findings
     */
    private function compareTournamentGames(PfsTournamentImportPlan $plan, array &$findings): void
    {
        $organizationId = $this->fetchOrganizationId();
        if ($organizationId === null) {
            $findings[] = sprintf('PFSTOURHH row count differs: expected=%d actual=%d', count($plan->tournamentGames), 0);

            return;
        }

        $actualRows = $this->connection->fetchAllAssociative(
            'SELECT
                round_no AS runda,
                table_no AS stol,
                legacy_player1_id AS player1,
                legacy_player2_id AS player2,
                result1,
                result2,
                ranko,
                host
            FROM tournament_game
            WHERE organization_id = :organizationId
              AND legacy_tournament_id = :id
              AND legacy_player1_id IS NOT NULL
              AND legacy_player2_id IS NOT NULL
              AND table_no IS NOT NULL',
            [
                'organizationId' => $organizationId,
                'id' => $plan->tournament->id,
            ],
        );

        $expectedByKey = [];
        foreach ($plan->tournamentGames as $row) {
            $expectedByKey[$this->gameKey($row->runda, $row->stol, $row->player1, $row->player2)] = $row;
        }

        $actualByKey = [];
        foreach ($actualRows as $row) {
            $actualByKey[$this->gameKey((int) $row['runda'], (int) $row['stol'], (int) $row['player1'], (int) $row['player2'])] = $row;
        }

        if (count($expectedByKey) !== count($actualByKey)) {
            $findings[] = sprintf('PFSTOURHH row count differs: expected=%d actual=%d', count($expectedByKey), count($actualByKey));
        }

        foreach ($expectedByKey as $key => $expected) {
            $actual = $actualByKey[$key] ?? null;
            if ($actual === null) {
                $findings[] = sprintf('PFSTOURHH row %s is missing in database.', $key);
                continue;
            }

            foreach ([
                'result1' => $expected->result1,
                'result2' => $expected->result2,
                'ranko' => $expected->ranko,
                'host' => $expected->host,
            ] as $field => $expectedValue) {
                if (!$this->valuesEqual($expectedValue, $actual[$field] ?? null)) {
                    $findings[] = sprintf(
                        'PFSTOURHH %s field %s differs: expected=%s actual=%s',
                        $key,
                        $field,
                        $this->stringify($expectedValue),
                        $this->stringify($actual[$field] ?? null),
                    );
                }
            }
        }

        foreach (array_diff(array_keys($actualByKey), array_keys($expectedByKey)) as $extraKey) {
            $findings[] = sprintf('PFSTOURHH has unexpected row %s in database.', $extraKey);
        }
    }

    private function fetchOrganizationId(): ?int
    {
        $value = $this->connection->fetchOne(
            'SELECT id FROM organization WHERE code = :code LIMIT 1',
            ['code' => self::ORGANIZATION_CODE]
        );

        if ($value === false || $value === null) {
            return null;
        }

        return (int) $value;
    }

    private function gameKey(int $round, int $table, int $player1, int $player2): string
    {
        return sprintf('%d:%d:%d:%d', $round, $table, $player1, $player2);
    }

    private function valuesEqual(mixed $expected, mixed $actual): bool
    {
        if ($expected === null || $actual === null) {
            return $expected === $actual;
        }

        if (is_numeric($expected) && is_numeric($actual)) {
            return abs((float) $expected - (float) $actual) < 0.005;
        }

        return (string) $expected === (string) $actual;
    }

    private function stringify(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return (string) $value;
    }
}
