<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\GamePhotoRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: GamePhotoRepository::class)]
#[ORM\Table(name: 'game_photo')]
#[ORM\Index(name: 'idx_game_photo_tournament_game', columns: ['tournament_game_id'])]
#[ORM\Index(name: 'idx_game_photo_uploaded_by', columns: ['uploaded_by_player_id'])]
#[ORM\Index(name: 'idx_game_photo_category', columns: ['category'])]
class GamePhoto
{
    public const string CATEGORY_BOARD = 'board';
    public const string CATEGORY_RESULTS = 'results';
    public const string CATEGORY_OTHER = 'other';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'photos')]
    #[ORM\JoinColumn(name: 'tournament_game_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?TournamentGame $tournamentGame = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'uploaded_by_player_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?Player $uploadedByPlayer = null;

    #[ORM\Column(length: 16)]
    private string $category;

    #[ORM\Column(length: 255)]
    private string $path;

    #[ORM\Column(name: 'uploaded_at')]
    private \DateTimeImmutable $uploadedAt;

    public static function isValidCategory(string $category): bool
    {
        return in_array($category, self::allowedCategories(), true);
    }

    /**
     * @return list<string>
     */
    public static function allowedCategories(): array
    {
        return [
            self::CATEGORY_BOARD,
            self::CATEGORY_RESULTS,
            self::CATEGORY_OTHER,
        ];
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTournamentGame(): ?TournamentGame
    {
        return $this->tournamentGame;
    }

    public function setTournamentGame(?TournamentGame $tournamentGame): self
    {
        $this->tournamentGame = $tournamentGame;

        return $this;
    }

    public function getUploadedByPlayer(): ?Player
    {
        return $this->uploadedByPlayer;
    }

    public function setUploadedByPlayer(Player $uploadedByPlayer): self
    {
        $this->uploadedByPlayer = $uploadedByPlayer;

        return $this;
    }

    public function getCategory(): string
    {
        return $this->category;
    }

    public function setCategory(string $category): self
    {
        $this->category = $category;

        return $this;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function setPath(string $path): self
    {
        $this->path = $path;

        return $this;
    }

    public function getUploadedAt(): \DateTimeImmutable
    {
        return $this->uploadedAt;
    }

    public function setUploadedAt(\DateTimeImmutable $uploadedAt): self
    {
        $this->uploadedAt = $uploadedAt;

        return $this;
    }
}
