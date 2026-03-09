<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\TournamentGame;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TournamentGame>
 */
final class TournamentGameRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TournamentGame::class);
    }
}
