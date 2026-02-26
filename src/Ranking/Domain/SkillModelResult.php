<?php

declare(strict_types=1);

namespace App\Ranking\Domain;

final readonly class SkillModelResult
{
    /**
     * @param array<int, SkillEstimate> $estimatesByPlayer
     */
    public function __construct(
        public array $estimatesByPlayer,
        public int $playerCount,
        public int $gameCount,
        public int $iterations,
        public bool $usedFullCovariance,
    ) {
    }

    /**
     * @return array<int, float>
     */
    public function skillsByPlayer(): array
    {
        $result = [];
        foreach ($this->estimatesByPlayer as $playerId => $estimate) {
            $result[(int) $playerId] = $estimate->sHat;
        }

        return $result;
    }
}

