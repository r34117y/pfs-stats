<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\GameRecordRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: GameRecordRepository::class)]
#[ORM\Table(name: 'game_record')]
#[ORM\UniqueConstraint(name: 'uniq_game_record_org_tournament_round_player1', columns: ['organization_id', 'tournament_id', 'round_no', 'player1_id'])]
#[ORM\Index(name: 'idx_game_record_org_legacy_tournament_round_player1', columns: ['organization_id', 'legacy_tournament_id', 'round_no', 'legacy_player1_id'])]
class GameRecord
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

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'player1_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Player $player1 = null;

    #[ORM\Column(name: 'legacy_player1_id', nullable: true)]
    private ?int $legacyPlayer1Id = null;

    #[ORM\Column(length: 4096)]
    private string $data;

    #[ORM\Column(name: 'updated_at')]
    private \DateTimeImmutable $updatedAt;
}
