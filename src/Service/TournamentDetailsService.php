<?php

namespace App\Service;

use App\ApiResource\TournamentDetails\TournamentDetails;
use App\ApiResource\TournamentDetails\TournamentResultRow;
use App\ApiResource\TournamentDetails\TournamentResults;
use DateTime;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class TournamentDetailsService
{
    public function __construct(
        #[Autowire(service: 'doctrine.dbal.mysql_connection')]
        private Connection $connection,
    ) {
    }

    public function getTournamentDetails(int $tournamentId): TournamentDetails
    {
        $row = $this->connection->fetchAssociative(
            "SELECT id, COALESCE(fullname, name) AS name, dt, referee, place
            FROM PFSTOURS
            WHERE id = :tournamentId",
            ['tournamentId' => $tournamentId]
        );

        if ($row === false) {
            throw new NotFoundHttpException(sprintf('Tournament with id %d was not found.', $tournamentId));
        }

        $date = DateTime::createFromFormat('Ymd', (string) $row['dt']);

        return new TournamentDetails(
            id: (int) $row['id'],
            name: (string) $row['name'],
            date: $date ? $date->format('Y-m-d') : (string) $row['dt'],
            refereeName: $row['referee'] !== null && $row['referee'] !== '' ? (string) $row['referee'] : null,
            address: $row['place'] !== null && $row['place'] !== '' ? (string) $row['place'] : null,
        );
    }

    public function getTournamentResults(int $tournamentId): TournamentResults
    {
        $tournamentExists = $this->connection->fetchOne(
            'SELECT 1 FROM PFSTOURS WHERE id = :tournamentId',
            ['tournamentId' => $tournamentId]
        );

        if ($tournamentExists === false) {
            throw new NotFoundHttpException(sprintf('Tournament with id %d was not found.', $tournamentId));
        }

        $rows = $this->connection->fetchAllAssociative(
            "SELECT
                tw.place,
                tw.player,
                p.name_show AS playerName,
                tw.brank AS rankBefore,
                tw.gwin AS wins,
                tw.games AS games,
                tw.glost AS losses,
                tw.points AS avgPointsScored,
                tw.pointo AS avgPointsLost,
                tw.trank AS rankAchieved
            FROM PFSTOURWYN tw
            INNER JOIN PFSPLAYER p ON p.id = tw.player
            WHERE tw.turniej = :tournamentId
            ORDER BY CASE WHEN tw.place = 0 THEN 1 ELSE 0 END, tw.place ASC, p.name_show ASC",
            ['tournamentId' => $tournamentId]
        );

        $resultRows = [];
        foreach ($rows as $row) {
            $games = max(0, (int) $row['games']);
            $wins = (int) $row['wins'];
            $losses = (int) $row['losses'];
            $rankAchieved = (float) $row['rankAchieved'];
            $scalp = $games > 0 ? round($rankAchieved * $games, 2) : 0.0;
            $avgOpponentRank = $games > 0 ? round(($scalp - (50.0 * ($wins - $losses))) / $games, 2) : 0.0;
            $totalPointsScored = $games > 0 ? (int) round(((float) $row['avgPointsScored']) * $games) : 0;
            $totalPointsLost = $games > 0 ? (int) round(((float) $row['avgPointsLost']) * $games) : 0;

            $resultRows[] = new TournamentResultRow(
                position: (int) $row['place'],
                playerId: (int) $row['player'],
                playerName: (string) $row['playerName'],
                gamesCount: $games,
                rankBefore: (float) $row['rankBefore'],
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
}
