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
            'SELECT data, to_char(updated_at, \'YYYY-MM-DD HH24:MI:SS\') AS updated
             FROM game_record
             WHERE tournament_id = :tour AND round_no = :round AND player1_id = :player1
             ORDER BY id DESC
             LIMIT 1',
            [
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
            round: $round,
            player1Id: $player1Id,
            data: (string) $row['data'],
            updated: (string) $row['updated'],
            parsedGcg: $parsedGcg
        );
    }
}
