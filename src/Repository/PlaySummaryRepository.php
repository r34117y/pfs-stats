<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PlaySummary;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PlaySummary>
 */
final class PlaySummaryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PlaySummary::class);
    }
}
