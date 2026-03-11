<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TournamentRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TournamentRepository::class)]
#[ORM\Table(name: 'tournament')]
#[ORM\UniqueConstraint(name: 'uniq_tournament_org_legacy_id', columns: ['organization_id', 'legacy_id'])]
#[ORM\Index(name: 'idx_tournament_org_dt', columns: ['organization_id', 'dt'])]
#[ORM\Index(name: 'idx_tournament_org_urlid', columns: ['organization_id', 'urlid'])]
class Tournament
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'organization_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Organization $organization;

    #[ORM\Column(name: 'legacy_id', nullable: true)]
    private ?int $legacyId = null;

    #[ORM\Column(name: 'dt')]
    private int $dateCode;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $name = null;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $fullname = null;

    #[ORM\ManyToOne(targetEntity: Player::class)]
    #[ORM\JoinColumn(name: 'winner_player_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Player $winnerPlayer = null;

    #[ORM\Column(name: 'legacy_winner_player_id', nullable: true)]
    private ?int $legacyWinnerPlayerId = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $trank = null;

    #[ORM\Column(name: 'players_count', nullable: true)]
    private ?int $playersCount = null;

    #[ORM\Column(nullable: true)]
    private ?int $rounds = null;

    #[ORM\Column(length: 1, nullable: true)]
    private ?string $rrecreated = null;

    #[ORM\Column(length: 1, nullable: true)]
    private ?string $team = null;

    #[ORM\Column(nullable: true)]
    private ?int $mcategory = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $wksum = null;

    #[ORM\ManyToOne(targetEntity: Series::class)]
    #[ORM\JoinColumn(name: 'series_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Series $series = null;

    #[ORM\Column(name: 'legacy_series_id', nullable: true)]
    private ?int $legacySeriesId = null;

    #[ORM\Column(name: 'start_round', nullable: true)]
    private ?int $startRound = null;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $referee = null;

    #[ORM\Column(length: 256, nullable: true)]
    private ?string $place = null;

    #[ORM\Column(length: 256, nullable: true)]
    private ?string $organizer = null;

    #[ORM\Column(nullable: true)]
    private ?int $urlid = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOrganization(): Organization
    {
        return $this->organization;
    }

    public function setOrganization(Organization $organization): self
    {
        $this->organization = $organization;

        return $this;
    }

    public function getLegacyId(): ?int
    {
        return $this->legacyId;
    }

    public function setLegacyId(?int $legacyId): self
    {
        $this->legacyId = $legacyId;

        return $this;
    }

    public function getDateCode(): int
    {
        return $this->dateCode;
    }

    public function setDateCode(int $dateCode): self
    {
        $this->dateCode = $dateCode;

        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getFullname(): ?string
    {
        return $this->fullname;
    }

    public function setFullname(?string $fullname): self
    {
        $this->fullname = $fullname;

        return $this;
    }

    public function getWinnerPlayer(): ?Player
    {
        return $this->winnerPlayer;
    }

    public function setWinnerPlayer(?Player $winnerPlayer): self
    {
        $this->winnerPlayer = $winnerPlayer;

        return $this;
    }

    public function getLegacyWinnerPlayerId(): ?int
    {
        return $this->legacyWinnerPlayerId;
    }

    public function setLegacyWinnerPlayerId(?int $legacyWinnerPlayerId): self
    {
        $this->legacyWinnerPlayerId = $legacyWinnerPlayerId;

        return $this;
    }

    public function getTrank(): ?float
    {
        return $this->trank;
    }

    public function setTrank(?float $trank): self
    {
        $this->trank = $trank;

        return $this;
    }

    public function getPlayersCount(): ?int
    {
        return $this->playersCount;
    }

    public function setPlayersCount(?int $playersCount): self
    {
        $this->playersCount = $playersCount;

        return $this;
    }

    public function getRounds(): ?int
    {
        return $this->rounds;
    }

    public function setRounds(?int $rounds): self
    {
        $this->rounds = $rounds;

        return $this;
    }

    public function getRrecreated(): ?string
    {
        return $this->rrecreated;
    }

    public function setRrecreated(?string $rrecreated): self
    {
        $this->rrecreated = $rrecreated;

        return $this;
    }

    public function getTeam(): ?string
    {
        return $this->team;
    }

    public function setTeam(?string $team): self
    {
        $this->team = $team;

        return $this;
    }

    public function getMcategory(): ?int
    {
        return $this->mcategory;
    }

    public function setMcategory(?int $mcategory): self
    {
        $this->mcategory = $mcategory;

        return $this;
    }

    public function getWksum(): ?float
    {
        return $this->wksum;
    }

    public function setWksum(?float $wksum): self
    {
        $this->wksum = $wksum;

        return $this;
    }

    public function getSeries(): ?Series
    {
        return $this->series;
    }

    public function setSeries(?Series $series): self
    {
        $this->series = $series;

        return $this;
    }

    public function getLegacySeriesId(): ?int
    {
        return $this->legacySeriesId;
    }

    public function setLegacySeriesId(?int $legacySeriesId): self
    {
        $this->legacySeriesId = $legacySeriesId;

        return $this;
    }

    public function getStartRound(): ?int
    {
        return $this->startRound;
    }

    public function setStartRound(?int $startRound): self
    {
        $this->startRound = $startRound;

        return $this;
    }

    public function getReferee(): ?string
    {
        return $this->referee;
    }

    public function setReferee(?string $referee): self
    {
        $this->referee = $referee;

        return $this;
    }

    public function getPlace(): ?string
    {
        return $this->place;
    }

    public function setPlace(?string $place): self
    {
        $this->place = $place;

        return $this;
    }

    public function getOrganizer(): ?string
    {
        return $this->organizer;
    }

    public function setOrganizer(?string $organizer): self
    {
        $this->organizer = $organizer;

        return $this;
    }

    public function getUrlid(): ?int
    {
        return $this->urlid;
    }

    public function setUrlid(?int $urlid): self
    {
        $this->urlid = $urlid;

        return $this;
    }
}
