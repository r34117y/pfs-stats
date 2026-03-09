<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PlaySummaryRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PlaySummaryRepository::class)]
#[ORM\Table(name: 'play_summary')]
#[ORM\UniqueConstraint(name: 'uniq_play_summary_org_player_stype', columns: ['organization_id', 'player_id', 'stype'])]
#[ORM\Index(name: 'idx_play_summary_org_legacy_player_stype', columns: ['organization_id', 'legacy_player_id', 'stype'])]
final class PlaySummary
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'organization_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Organization $organization;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'player_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Player $player = null;

    #[ORM\Column(name: 'legacy_player_id', nullable: true)]
    private ?int $legacyPlayerId = null;

    #[ORM\Column]
    private int $stype;

    #[ORM\Column(nullable: true)]
    private ?int $gwin = null;

    #[ORM\Column(nullable: true)]
    private ?int $glost = null;

    #[ORM\Column(nullable: true)]
    private ?int $gdraw = null;

    #[ORM\Column(nullable: true)]
    private ?int $games = null;

    #[ORM\Column(nullable: true)]
    private ?int $gwinnw = null;

    #[ORM\Column(nullable: true)]
    private ?int $glostnw = null;

    #[ORM\Column(nullable: true)]
    private ?int $gdrawnw = null;

    #[ORM\Column(nullable: true)]
    private ?int $gamesnw = null;

    #[ORM\Column(nullable: true)]
    private ?int $gwin130 = null;

    #[ORM\Column(nullable: true)]
    private ?int $games130 = null;

    #[ORM\Column(nullable: true)]
    private ?int $gwin110 = null;

    #[ORM\Column(nullable: true)]
    private ?int $games110 = null;

    #[ORM\Column(nullable: true)]
    private ?int $gwin100 = null;

    #[ORM\Column(nullable: true)]
    private ?int $games100 = null;

    #[ORM\Column(nullable: true)]
    private ?int $over350 = null;

    #[ORM\Column(nullable: true)]
    private ?int $over400 = null;

    #[ORM\Column(nullable: true)]
    private ?int $over500 = null;

    #[ORM\Column(nullable: true)]
    private ?int $over600 = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $grank = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $points = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $pointo = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $pointsw = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $pointow = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $pointsl = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $pointol = null;
}
