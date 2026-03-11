<?php

namespace App\Service\TournamentDetails;

use App\ApiResource\TournamentDetails\TournamentDetails;
use App\ApiResource\TournamentDetails\TournamentResultRow;
use App\ApiResource\TournamentDetails\TournamentResults;
use DateTime;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class TournamentDetailsServicePostgres implements TournamentDetailsServiceInterface
{
    private const string ORGANIZATION_CODE = 'PFS';
    private const array TOURNAMENT_NAME_OVERRIDES = [
        199703150 => '',
        199811220 => '',
        200506191 => '',
        200606180 => '',
        200706100 => '',
        200805250 => '',
        201204220 => "XVI Mistrzostwa Ziemi Kujawskiej w Scrabble 'O Kryształowe Jajo Świąteczne' pod ",
    ];

    public function __construct(
        #[Autowire(service: 'doctrine.dbal.default_connection')]
        private Connection $connection,
    ) {
    }

    public function getTournamentDetails(int $tournamentId): TournamentDetails
    {
        $organizationId = $this->fetchOrganizationId();
        if ($organizationId === null) {
            throw new NotFoundHttpException(sprintf('Tournament with id %d was not found.', $tournamentId));
        }

        $row = $this->connection->fetchAssociative(
            "SELECT legacy_id AS id, COALESCE(fullname, name) AS tournament_name, dt, referee, place
            FROM tournament
            WHERE organization_id = :organizationId
              AND legacy_id = :tournamentId",
            [
                'organizationId' => $organizationId,
                'tournamentId' => $tournamentId,
            ]
        );

        if ($row === false) {
            throw new NotFoundHttpException(sprintf('Tournament with id %d was not found.', $tournamentId));
        }

        $date = DateTime::createFromFormat('Ymd', (string) $row['dt']);
        $name = self::TOURNAMENT_NAME_OVERRIDES[$tournamentId] ?? (string) $row['tournament_name'];

        return new TournamentDetails(
            id: (int) $row['id'],
            name: $name,
            date: $date ? $date->format('Y-m-d') : (string) $row['dt'],
            refereeName: $row['referee'] !== null && $row['referee'] !== '' ? (string) $row['referee'] : null,
            address: $row['place'] !== null && $row['place'] !== '' ? (string) $row['place'] : null,
        );
    }

    public function getTournamentResults(int $tournamentId): TournamentResults
    {
        $organizationId = $this->fetchOrganizationId();
        if ($organizationId === null) {
            throw new NotFoundHttpException(sprintf('Tournament with id %d was not found.', $tournamentId));
        }

        $tournamentExists = $this->connection->fetchOne(
            'SELECT 1
             FROM tournament
             WHERE organization_id = :organizationId
               AND legacy_id = :tournamentId',
            [
                'organizationId' => $organizationId,
                'tournamentId' => $tournamentId,
            ]
        );

        if ($tournamentExists === false) {
            throw new NotFoundHttpException(sprintf('Tournament with id %d was not found.', $tournamentId));
        }

        $rows = $this->connection->fetchAllAssociative(
            "SELECT
                tw.place,
                tw.legacy_player_id AS player,
                p.name_show AS player_name,
                tw.brank AS rank_before,
                tw.gwin AS wins,
                tw.games AS games,
                tw.glost AS losses,
                tw.points AS avg_points_scored,
                tw.pointo AS avg_points_lost,
                tw.trank AS rank_achieved
            FROM tournament_result tw
            INNER JOIN player p ON p.id = tw.player_id
            WHERE tw.organization_id = :organizationId
              AND tw.legacy_tournament_id = :tournamentId
              AND tw.legacy_player_id IS NOT NULL
            ORDER BY CASE WHEN tw.place = 0 THEN 1 ELSE 0 END, tw.place ASC, p.name_show ASC",
            [
                'organizationId' => $organizationId,
                'tournamentId' => $tournamentId,
            ]
        );

        $resultRows = [];
        foreach ($rows as $row) {
            $games = max(0, (int) $row['games']);
            $wins = (int) $row['wins'];
            $losses = (int) $row['losses'];
            $rankAchieved = (float) $row['rank_achieved'];
            $scalp = $games > 0 ? round($rankAchieved * $games, 2) : 0.0;
            $avgOpponentRank = $games > 0 ? round(($scalp - (50.0 * ($wins - $losses))) / $games, 2) : 0.0;
            $totalPointsScored = $games > 0 ? (int) round(((float) $row['avg_points_scored']) * $games) : 0;
            $totalPointsLost = $games > 0 ? (int) round(((float) $row['avg_points_lost']) * $games) : 0;

            $resultRows[] = new TournamentResultRow(
                position: (int) $row['place'],
                playerId: (int) $row['player'],
                playerName: (string) $row['player_name'],
                gamesCount: $games,
                rankBefore: (float) $row['rank_before'],
                wins: $wins,
                totalPointsScored: $totalPointsScored,
                diff: $totalPointsScored - $totalPointsLost,
                sumPoints: $totalPointsScored + $totalPointsLost,
                scalp: $scalp,
                rankAchieved: $rankAchieved,
                avgOpponentRank: $avgOpponentRank,
            );
        }

        return new TournamentResults($resultRows);
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
}
