<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TournamentGameRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TournamentGameRepository::class)]
#[ORM\Table(name: 'tournament_game')]
#[ORM\UniqueConstraint(name: 'uniq_tournament_game_org_tournament_round_players', columns: ['organization_id', 'tournament_id', 'round_no', 'player1_id', 'player2_id'])]
#[ORM\Index(name: 'idx_tournament_game_org_player1_player2', columns: ['organization_id', 'player1_id', 'player2_id'])]
#[ORM\Index(name: 'idx_tournament_game_org_legacy_tournament_round_players', columns: ['organization_id', 'legacy_tournament_id', 'round_no', 'legacy_player1_id', 'legacy_player2_id'])]
final class TournamentGame
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

    #[ORM\Column(name: 'legacy_tournament_id', nullable: true)]
    private ?int $legacyTournamentId = null;

    #[ORM\Column(name: 'round_no')]
    private int $roundNo;

    #[ORM\Column(name: 'table_no', nullable: true)]
    private ?int $tableNo = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'player1_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Player $player1 = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'player2_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Player $player2 = null;

    #[ORM\Column(name: 'legacy_player1_id', nullable: true)]
    private ?int $legacyPlayer1Id = null;

    #[ORM\Column(name: 'legacy_player2_id', nullable: true)]
    private ?int $legacyPlayer2Id = null;

    #[ORM\Column]
    private int $result1;

    #[ORM\Column]
    private int $result2;

    #[ORM\Column(nullable: true)]
    private ?int $ranko = null;

    #[ORM\Column(nullable: true)]
    private ?int $host = null;
}
