<?php

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'app_user')]
#[ORM\UniqueConstraint(name: 'UNIQ_IDENTIFIER_EMAIL', fields: ['email'])]
#[UniqueEntity(fields: ['email'], message: 'There is already an account with this email.')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180)]
    private ?string $email = null;

    /**
     * @var list<string>
     */
    #[ORM\Column]
    private array $roles = [];

    #[ORM\Column]
    private ?string $password = null;

    #[ORM\Column(options: ['default' => true])]
    private bool $requiresPasswordChange = true;

    #[ORM\Column(nullable: true)]
    private ?int $yearOfBirth = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $photo = null;

    #[ORM\OneToOne(targetEntity: Player::class, inversedBy: 'user')]
    #[ORM\JoinColumn(name: 'player_id', referencedColumnName: 'id', unique: true, nullable: true, onDelete: 'SET NULL')]
    private ?Player $player = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = mb_strtolower($email);

        return $this;
    }

    public function getUserIdentifier(): string
    {
        return $this->email ?? '';
    }

    /**
     * @return list<string>
     */
    public function getRoles(): array
    {
        $roles = $this->roles;
        $roles[] = 'ROLE_USER';

        return array_values(array_unique($roles));
    }

    /**
     * @param list<string> $roles
     */
    public function setRoles(array $roles): static
    {
        $this->roles = $roles;

        return $this;
    }

    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function setPassword(string $password): static
    {
        $this->password = $password;

        return $this;
    }

    public function eraseCredentials(): void
    {
    }

    public function requiresPasswordChange(): bool
    {
        return $this->requiresPasswordChange;
    }

    public function setRequiresPasswordChange(bool $requiresPasswordChange): static
    {
        $this->requiresPasswordChange = $requiresPasswordChange;

        return $this;
    }

    public function getYearOfBirth(): ?int
    {
        return $this->yearOfBirth;
    }

    public function setYearOfBirth(?int $yearOfBirth): static
    {
        $this->yearOfBirth = $yearOfBirth;

        return $this;
    }

    public function getPhoto(): ?string
    {
        return $this->photo;
    }

    public function setPhoto(?string $photo): static
    {
        $this->photo = $photo;

        return $this;
    }

    public function getPlayerId(): ?int
    {
        return $this->player?->getId();
    }

    public function setPlayerId(?int $playerId): static
    {
        if (null === $playerId) {
            return $this->setPlayer(null);
        }

        if ($this->player?->getId() === $playerId) {
            return $this;
        }

        throw new \LogicException('Cannot assign player by ID without a loaded Player entity. Use User::setPlayer() instead.');
    }

    public function getPlayer(): ?Player
    {
        return $this->player;
    }

    public function setPlayer(?Player $player): static
    {
        if ($this->player === $player) {
            return $this;
        }

        $previousPlayer = $this->player;
        $this->player = $player;

        if (null !== $previousPlayer && $previousPlayer->getUser() === $this) {
            $previousPlayer->setUser(null);
        }

        if (null !== $player && $player->getUser() !== $this) {
            $player->setUser($this);
        }

        return $this;
    }
}
