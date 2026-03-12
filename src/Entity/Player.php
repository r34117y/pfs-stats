<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PlayerRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PlayerRepository::class)]
#[ORM\Table(name: 'player')]
#[ORM\Index(name: 'idx_player_name_alph', columns: ['name_alph'])]
class Player
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'name_show', length: 40, nullable: true)]
    private ?string $nameShow = null;

    #[ORM\Column(name: 'name_alph', length: 40, nullable: true)]
    private ?string $nameAlph = null;

    #[ORM\Column(length: 1, nullable: true)]
    private ?string $utype = null;

    #[ORM\Column(length: 1, nullable: true)]
    private ?string $cached = null;

    /**
     * @var Collection<int, Organization>
     */
    #[ORM\ManyToMany(targetEntity: Organization::class, inversedBy: 'players')]
    #[ORM\JoinTable(name: 'player_organization')]
    #[ORM\JoinColumn(name: 'player_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'organization_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Collection $organizations;

    #[ORM\OneToOne(targetEntity: User::class, mappedBy: 'player')]
    private ?User $user = null;

    public function __construct()
    {
        $this->organizations = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNameShow(): ?string
    {
        return $this->nameShow;
    }

    public function setNameShow(?string $nameShow): self
    {
        $this->nameShow = $nameShow;

        return $this;
    }

    public function getNameAlph(): ?string
    {
        return $this->nameAlph;
    }

    public function setNameAlph(?string $nameAlph): self
    {
        $this->nameAlph = $nameAlph;

        return $this;
    }

    public function getUtype(): ?string
    {
        return $this->utype;
    }

    public function setUtype(?string $utype): self
    {
        $this->utype = $utype;

        return $this;
    }

    public function getCached(): ?string
    {
        return $this->cached;
    }

    public function setCached(?string $cached): self
    {
        $this->cached = $cached;

        return $this;
    }

    /**
     * @return Collection<int, Organization>
     */
    public function getOrganizations(): Collection
    {
        return $this->organizations;
    }

    public function addOrganization(Organization $organization): self
    {
        if (!$this->organizations->contains($organization)) {
            $this->organizations->add($organization);
        }

        return $this;
    }

    public function removeOrganization(Organization $organization): self
    {
        $this->organizations->removeElement($organization);

        return $this;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): self
    {
        if ($this->user === $user) {
            return $this;
        }

        $previousUser = $this->user;
        $this->user = $user;

        if (null !== $previousUser && $previousUser->getPlayer() === $this) {
            $previousUser->setPlayer(null);
        }

        if (null !== $user && $user->getPlayer() !== $this) {
            $user->setPlayer($this);
        }

        return $this;
    }
}
