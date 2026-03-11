<?php

namespace App\Service\AnnotatedGameDetails;

use App\ApiResource\AnnotatedGameDetails\AnnotatedGameDetails;
use App\GcgParser\Exception\InvalidGcgEventException;
use App\GcgParser\GcgParser;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final readonly class AnnotatedGameDetailsServicePostgres implements AnnotatedGameDetailsServiceInterface
{
    private const string ORGANIZATION_CODE = 'PFS';

    public function __construct(
        #[Autowire(service: 'doctrine.dbal.default_connection')]
        private Connection $connection,
        private GcgParser $gcgParser,
    ) {
    }

    /**
     * @throws InvalidGcgEventException
     * @throws Exception
     */
    public function getByKey(int $tournamentId, int $round, int $player1Id): AnnotatedGameDetails
    {
        $row = $this->connection->fetchAssociative(
            'SELECT
                g.data,
                to_char(g.updated_at, \'YYYY-MM-DD HH24:MI:SS\') AS updated,
                t.name AS tournament_name,
                p1.name_show AS player1_name,
                h.player2_id AS player2_id,
                p2.name_show AS player2_name
             FROM game_record g
             INNER JOIN organization o
                ON o.id = g.organization_id
             INNER JOIN tournament t
                ON t.organization_id = g.organization_id
               AND t.id = g.tournament_id
             INNER JOIN tournament_game h
                ON h.organization_id = g.organization_id
               AND h.tournament_id = g.tournament_id
               AND h.round_no = g.round_no
               AND h.player1_id = g.player1_id
             INNER JOIN player p1
                ON p1.id = g.player1_id
             INNER JOIN player p2
                ON p2.id = h.player2_id
             WHERE o.code = :organizationCode
               AND g.tournament_id = :tour
               AND g.round_no = :round
               AND g.player1_id = :player1
             ORDER BY g.id DESC
             LIMIT 1',
            [
                'organizationCode' => self::ORGANIZATION_CODE,
                'tour' => $tournamentId,
                'round' => $round,
                'player1' => $player1Id,
            ]
        );

        if ($row === false) {
            throw new NotFoundHttpException(sprintf(
                'Annotated game not found for key %d-%d-%d.',
                $tournamentId,
                $round,
                $player1Id,
            ));
        }

        $parsedGcg = $this->gcgParser->parse($row['data']);

        return new AnnotatedGameDetails(
            tournamentId: $tournamentId,
            tournamentName: (string) $row['tournament_name'],
            round: $round,
            player1Id: $player1Id,
            player1Name: (string) $row['player1_name'],
            player2Id: (int) $row['player2_id'],
            player2Name: (string) $row['player2_name'],
            data: (string) $row['data'],
            updated: (string) $row['updated'],
            parsedGcg: $parsedGcg
        );
    }
}
