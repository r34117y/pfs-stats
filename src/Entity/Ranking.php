<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\RankingRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: RankingRepository::class)]
#[ORM\Table(name: 'ranking')]
#[ORM\UniqueConstraint(name: 'uniq_ranking_org_rtype_player_tournament', columns: ['organization_id', 'rtype', 'player_id', 'tournament_id'])]
#[ORM\Index(name: 'idx_ranking_org_rtype_tournament_pos', columns: ['organization_id', 'rtype', 'tournament_id', 'position'])]
#[ORM\Index(name: 'idx_ranking_org_rtype_legacy_player_tournament', columns: ['organization_id', 'rtype', 'legacy_player_id', 'legacy_tournament_id'])]
final class Ranking
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'organization_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Organization $organization;

    #[ORM\Column(length: 1)]
    private string $rtype;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'player_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Player $player = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'tournament_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Tournament $tournament = null;

    #[ORM\Column(name: 'legacy_player_id', nullable: true)]
    private ?int $legacyPlayerId = null;

    #[ORM\Column(name: 'legacy_tournament_id', nullable: true)]
    private ?int $legacyTournamentId = null;

    #[ORM\Column(name: 'position')]
    private int $position;

    #[ORM\Column(type: 'float')]
    private float $rank;

    #[ORM\Column]
    private int $games;
}
