<?php

namespace App\Service;

use App\ApiResource\PlayerTournaments\PlayerTournaments;
use App\ApiResource\PlayerTournaments\PlayerTournamentsTournament;
use DateTime;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class PlayerTournamentsService
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function getPlayerTournaments(int $playerId): PlayerTournaments
    {
        $playerExists = $this->connection->fetchOne(
            'SELECT 1 FROM PFSPLAYER WHERE id = :playerId',
            ['playerId' => $playerId]
        );

        if ($playerExists === false) {
            throw new NotFoundHttpException(sprintf('Player with id %d was not found.', $playerId));
        }

        $rows = $this->connection->fetchAllAssociative(
            "SELECT
                t.id,
                t.name,
                t.fullname,
                t.dt,
                t.trank AS tournamentRank,
                t.players AS numberOfPlayers,
                tw.place AS finalPosition,
                tw.gwin AS gamesWon,
                tw.gdraw AS gamesDraw,
                tw.glost AS gamesLost,
                tw.points AS averagePoints,
                tw.pointo AS averagePointsLost,
                tw.trank AS achievedRank,
                CASE
                    WHEN t.players > 0 AND tw.place > 0 THEN (tw.place * 100.0 / t.players)
                    ELSE NULL
                END AS positionAsPercent
            FROM PFSTOURWYN tw
            INNER JOIN PFSTOURS t ON t.id = tw.turniej
            WHERE tw.player = :playerId
            ORDER BY t.dt DESC, t.id DESC",
            ['playerId' => $playerId]
        );

        $tournaments = [];

        foreach ($rows as $row) {
            $date = DateTime::createFromFormat('Ymd', (string) $row['dt']);
            $averagePoints = (float) $row['averagePoints'];
            $averagePointsLost = (float) $row['averagePointsLost'];

            $tournaments[] = new PlayerTournamentsTournament(
                (int) $row['id'],
                (string) ($row['fullname'] ?: $row['name']),
                $date ? $date->format('Y-m-d') : (string) $row['dt'],
                (float) $row['tournamentRank'],
                (int) $row['numberOfPlayers'],
                (int) $row['finalPosition'],
                (int) $row['gamesWon'],
                (int) $row['gamesDraw'],
                (int) $row['gamesLost'],
                $averagePoints,
                $averagePointsLost,
                $averagePoints + $averagePointsLost,
                (float) $row['achievedRank'],
                $row['positionAsPercent'] !== null ? round((float) $row['positionAsPercent'], 2) : null,
            );
        }

        return new PlayerTournaments($tournaments);
    }
}
