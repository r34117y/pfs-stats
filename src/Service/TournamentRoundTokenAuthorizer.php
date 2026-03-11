<?php

namespace App\Service;

final readonly class TournamentRoundTokenAuthorizer
{
    /**
     * @param array<int, string> $validTokens
     */
    public function __construct(
        private array $validTokens,
    ) {
    }

    public function isAuthorized(string $token): bool
    {
        $normalizedToken = trim($token);
        if ($normalizedToken === '') {
            return false;
        }

        $normalizedValidTokens = array_values(array_filter(
            array_map(
                static fn (mixed $validToken): string => trim((string) $validToken),
                $this->validTokens
            ),
            static fn (string $validToken): bool => $validToken !== ''
        ));

        return in_array($normalizedToken, $normalizedValidTokens, true);
    }
}
