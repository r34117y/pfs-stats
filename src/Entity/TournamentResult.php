<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TournamentResultRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TournamentResultRepository::class)]
#[ORM\Table(name: 'tournament_result')]
#[ORM\UniqueConstraint(name: 'uniq_tournament_result_org_tournament_player', columns: ['organization_id', 'tournament_id', 'player_id'])]
#[ORM\UniqueConstraint(name: 'uniq_tournament_result_org_tournament_place_player', columns: ['organization_id', 'tournament_id', 'place', 'player_id'])]
#[ORM\Index(name: 'idx_tournament_result_org_legacy_tournament_player', columns: ['organization_id', 'legacy_tournament_id', 'legacy_player_id'])]
final class TournamentResult
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'organization_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Organization $organization;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'tournament_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Tournament $tournament = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'player_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Player $player = null;

    #[ORM\Column(name: 'legacy_tournament_id', nullable: true)]
    private ?int $legacyTournamentId = null;

    #[ORM\Column(name: 'legacy_player_id', nullable: true)]
    private ?int $legacyPlayerId = null;

    #[ORM\Column]
    private int $place;

    #[ORM\Column(nullable: true)]
    private ?int $gwin = null;

    #[ORM\Column(nullable: true)]
    private ?int $glost = null;

    #[ORM\Column(nullable: true)]
    private ?int $gdraw = null;

    #[ORM\Column(nullable: true)]
    private ?int $games = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $trank = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $brank = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $points = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $pointo = null;

    #[ORM\Column(name: 'hostgames', nullable: true)]
    private ?int $hostGames = null;

    #[ORM\Column(name: 'hostwin', nullable: true)]
    private ?int $hostWin = null;

    #[ORM\Column(nullable: true)]
    private ?int $masters = null;
}
