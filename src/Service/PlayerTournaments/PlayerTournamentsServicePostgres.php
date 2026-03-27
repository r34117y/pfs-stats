<?php

namespace App\Service\PlayerTournaments;

use App\ApiResource\PlayerTournaments\PlayerTournaments;
use App\ApiResource\PlayerTournaments\PlayerTournamentsTournament;
use DateTime;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final readonly class PlayerTournamentsServicePostgres implements PlayerTournamentsServiceInterface
{
    private const string ORGANIZATION_CODE = 'PFS';
    private const array TOURNAMENT_NAME_OVERRIDES = [
        201204220 => "XVI Mistrzostwa Ziemi Kujawskiej w Scrabble 'O Kryształowe Jajo Świąteczne' pod ",
    ];

    public function __construct(
        #[Autowire(service: 'doctrine.dbal.default_connection')]
        private Connection $connection,
    ) {
    }

    /**
     * @throws Exception
     */
    public function getPlayerTournaments(string $playerSlug): PlayerTournaments
    {
        $organizationId = $this->fetchOrganizationId();
        if ($organizationId === null) {
            throw new NotFoundHttpException(sprintf('Player with id %s was not found.', $playerSlug));
        }

        $playerId = $this->connection->fetchOne(
            'SELECT id from player where slug = :slug',
            [
                'slug' => $playerSlug,
            ]
        );

        if (!$playerId) {
            throw new NotFoundHttpException(sprintf('Player %s was not found.', $playerSlug));
        }

        $rows = $this->connection->fetchAllAssociative(
            "SELECT
                t.legacy_id AS id,
                t.name,
                t.fullname,
                t.dt,
                t.trank AS tournament_rank,
                t.players_count AS number_of_players,
                tw.place AS final_position,
                tw.gwin AS games_won,
                tw.gdraw AS games_draw,
                tw.glost AS games_lost,
                tw.points AS average_points,
                tw.pointo AS average_points_lost,
                tw.trank AS achieved_rank,
                CASE
                    WHEN t.players_count > 0 AND tw.place > 0 THEN (tw.place * 100.0 / t.players_count)
                    ELSE NULL
                END AS position_as_percent
            FROM tournament_result tw
            INNER JOIN tournament t
                ON t.organization_id = tw.organization_id
               AND t.legacy_id = tw.legacy_tournament_id
            WHERE tw.organization_id = :organizationId
              AND tw.player_id = :playerId
              AND t.legacy_id IS NOT NULL
            ORDER BY t.dt DESC, t.legacy_id DESC",
            [
                'organizationId' => $organizationId,
                'playerId' => $playerId,
            ]
        );

        $tournaments = [];

        foreach ($rows as $row) {
            $date = DateTime::createFromFormat('Ymd', (string) $row['dt']);
            $averagePoints = (float) $row['average_points'];
            $averagePointsLost = (float) $row['average_points_lost'];
            $tournamentId = (int) $row['id'];
            $rawName = (string) ($row['fullname'] ?: $row['name']);
            $name = self::TOURNAMENT_NAME_OVERRIDES[$tournamentId] ?? $rawName;

            $tournaments[] = new PlayerTournamentsTournament(
                $tournamentId,
                $name,
                $date ? $date->format('Y-m-d') : (string) $row['dt'],
                (float) $row['tournament_rank'],
                (int) $row['number_of_players'],
                (int) $row['final_position'],
                (int) $row['games_won'],
                (int) $row['games_draw'],
                (int) $row['games_lost'],
                $averagePoints,
                $averagePointsLost,
                $averagePoints + $averagePointsLost,
                (float) $row['achieved_rank'],
                $row['position_as_percent'] !== null ? round((float) $row['position_as_percent'], 2) : null,
            );
        }

        return new PlayerTournaments($tournaments);
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
