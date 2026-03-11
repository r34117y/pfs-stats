<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TextResourceRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TextResourceRepository::class)]
#[ORM\Table(name: 'text_resource')]
#[ORM\UniqueConstraint(name: 'uniq_text_resource_org_type_legacy_id', columns: ['organization_id', 'resource_type', 'legacy_id'])]
class TextResource
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'organization_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Organization $organization;

    #[ORM\Column(name: 'resource_type')]
    private int $resourceType;

    #[ORM\Column(name: 'legacy_id', nullable: true)]
    private ?int $legacyId = null;

    #[ORM\Column(type: 'text')]
    private string $data;
}
