<?php

namespace App\GcgParser\ParsedGcg;

class MetaInfo
{
    private ?string $description = null;
    private ?string $id = null;
    private ?string $authority = null;
    private ?string $lexicon = null;
    private ?string $tileDistribution = null;
    private ?string $characterEncoding = null;
    private ?array $other = [];

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(string $description): void
    {
        $this->description = $description;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function setId(string $id): void
    {
        $this->id = $id;
    }

    public function getAuthority(): string
    {
        return $this->authority;
    }

    public function setAuthority(string $authority): void
    {
        $this->authority = $authority;
    }

    public function getLexicon(): string
    {
        return $this->lexicon;
    }

    public function setLexicon(string $lexicon): void
    {
        $this->lexicon = $lexicon;
    }

    public function getTileDistribution(): string
    {
        return $this->tileDistribution;
    }

    public function setTileDistribution(string $tileDistribution): void
    {
        $this->tileDistribution = $tileDistribution;
    }

    public function getCharacterEncoding(): string
    {
        return $this->characterEncoding;
    }

    public function setCharacterEncoding(string $characterEncoding): void
    {
        $this->characterEncoding = $characterEncoding;
    }

    public function getOther(): array
    {
        return $this->other;
    }

    public function setOther(array $other): void
    {
        $this->other = $other;
    }

    public function addOther(string $other): void
    {
        $this->other[] = $other;
    }
}
