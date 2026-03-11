<?php

namespace App\Service\PfsPlayerResolver;

use App\PfsTournamentImport\PfsPlayerImportRow;
use App\PfsTournamentImport\ResolvedPlayer;
use App\Service\PfsNameNormalizer;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class PfsPlayerResolver implements PfsPlayerResolverInterface {
    public function __construct(
        #[Autowire(service: 'doctrine.dbal.mysql_connection')]
        private Connection $connection,
        private PfsNameNormalizer $nameNormalizer,
    ) {
    }

    /**
     * @param array<string, float> $playerRanksByName
     * @return array{resolved: array<string, ResolvedPlayer>, newPlayers: list<PfsPlayerImportRow>, warnings: list<string>}
     * @throws Exception
     */
    public function resolve(array $playerRanksByName, int $tournamentId): array
    {
        $catalog = $this->loadCatalog($tournamentId);
        $nextId = $this->fetchNextPlayerId();
        $resolved = [];
        $newPlayers = [];
        $warnings = [];

        foreach ($playerRanksByName as $playerName => $tournamentRank) {
            $resolvedPlayer = $this->resolveSinglePlayer($catalog, $playerName, $tournamentRank, $warnings);
            if ($resolvedPlayer === null) {
                $nameAlph = $this->nameNormalizer->toAlphabeticalName($playerName);
                $resolvedPlayer = new ResolvedPlayer(
                    id: $nextId,
                    nameShow: $playerName,
                    nameAlph: $nameAlph,
                    seedRank: 100.0,
                    isNew: true,
                );
                $newPlayers[] = new PfsPlayerImportRow(
                    id: $nextId,
                    nameShow: $playerName,
                    nameAlph: $nameAlph,
                );
                $nextId++;
            }

            $resolved[$playerName] = $resolvedPlayer;
        }

        return [
            'resolved' => $resolved,
            'newPlayers' => $newPlayers,
            'warnings' => $warnings,
        ];
    }

    /**
     * @return list<array{id:int,nameShow:string,nameAlph:string,normalized:string,latestRank:float|null}>
     * @throws Exception
     */
    private function loadCatalog(int $tournamentId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT p.id, p.name_show AS nameShow, p.name_alph AS nameAlph,
                latest.brank AS latestRank
            FROM PFSPLAYER p
            LEFT JOIN (
                SELECT tw.player, tw.brank
                FROM PFSTOURWYN tw
                INNER JOIN (
                    SELECT player, MAX(turniej) AS lastTournamentId
                    FROM PFSTOURWYN
                    WHERE turniej < :tournamentId
                    GROUP BY player
                ) latestIds
                    ON latestIds.player = tw.player
                    AND latestIds.lastTournamentId = tw.turniej
            ) latest ON latest.player = p.id',
            ['tournamentId' => $tournamentId],
        );

        return array_map(function (array $row): array {
            return [
                'id' => (int) $row['id'],
                'nameShow' => (string) $row['nameShow'],
                'nameAlph' => (string) $row['nameAlph'],
                'normalized' => $this->nameNormalizer->normalizeForMatch((string) $row['nameShow']),
                'latestRank' => $row['latestRank'] !== null ? (float) $row['latestRank'] : null,
            ];
        }, $rows);
    }

    /**
     * @param list<array{id:int,nameShow:string,nameAlph:string,normalized:string,latestRank:float|null}> $catalog
     * @param list<string> $warnings
     */
    private function resolveSinglePlayer(array $catalog, string $playerName, float $tournamentRank, array &$warnings): ?ResolvedPlayer
    {
        foreach ($catalog as $candidate) {
            if ($candidate['nameShow'] === $playerName) {
                return new ResolvedPlayer(
                    id: $candidate['id'],
                    nameShow: $candidate['nameShow'],
                    nameAlph: $candidate['nameAlph'],
                    seedRank: $candidate['latestRank'] ?? 100.0,
                    isNew: false,
                );
            }
        }

        $normalizedName = $this->nameNormalizer->normalizeForMatch($playerName);
        $normalizedTournamentRank = max(100.0, $tournamentRank);
        $normalizedMatches = array_values(array_filter(
            $catalog,
            static fn (array $candidate): bool => $candidate['normalized'] === $normalizedName,
        ));

        if (count($normalizedMatches) === 1) {
            $candidate = $normalizedMatches[0];

            return new ResolvedPlayer(
                id: $candidate['id'],
                nameShow: $candidate['nameShow'],
                nameAlph: $candidate['nameAlph'],
                seedRank: $candidate['latestRank'] ?? 100.0,
                isNew: false,
            );
        }

        if ($normalizedMatches !== []) {
            usort($normalizedMatches, static function (array $left, array $right) use ($normalizedTournamentRank): int {
                $leftDistance = abs(($left['latestRank'] ?? 100.0) - $normalizedTournamentRank);
                $rightDistance = abs(($right['latestRank'] ?? 100.0) - $normalizedTournamentRank);

                return $leftDistance <=> $rightDistance;
            });

            $best = $normalizedMatches[0];
            $bestDistance = abs(($best['latestRank'] ?? 100.0) - $normalizedTournamentRank);
            if ($bestDistance <= 1.0) {
                return new ResolvedPlayer(
                    id: $best['id'],
                    nameShow: $best['nameShow'],
                    nameAlph: $best['nameAlph'],
                    seedRank: $best['latestRank'] ?? 100.0,
                    isNew: false,
                );
            }

            $warnings[] = sprintf(
                'Ambiguous player match for "%s" at rank %.2f. Falling back to new player insert.',
                $playerName,
                $normalizedTournamentRank,
            );
        }

        return null;
    }

    /**
     * @throws Exception
     */
    private function fetchNextPlayerId(): int
    {
        $value = $this->connection->fetchOne('SELECT COALESCE(MAX(id), 0) + 1 FROM PFSPLAYER');

        return (int) $value;
    }
}
