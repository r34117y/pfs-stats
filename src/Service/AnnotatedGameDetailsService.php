<?php

namespace App\Service;

use App\ApiResource\AnnotatedGameDetails\AnnotatedGameDetails;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final readonly class AnnotatedGameDetailsService
{
    public function __construct(
        #[Autowire(service: 'doctrine.dbal.mysql_connection')]
        private Connection $connection,
    ) {
    }

    public function getByKey(int $tournamentId, int $round, int $player1Id): AnnotatedGameDetails
    {
        $row = $this->connection->fetchAssociative(
            'SELECT data, updated FROM PFSGCG WHERE tour = :tour AND `round` = :round AND player1 = :player1',
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

        return new AnnotatedGameDetails(
            tournamentId: $tournamentId,
            round: $round,
            player1Id: $player1Id,
            data: (string) $row['data'],
            updated: (string) $row['updated'],
        );
    }
}
