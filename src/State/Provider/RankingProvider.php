<?php

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Ranking\GetRanking;
use App\ApiResource\Ranking\RankingRow;
use App\Repository\UserRepository;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

class RankingProvider implements ProviderInterface
{
    public function __construct(
        #[Autowire(service: 'doctrine.dbal.mysql_connection')]
        private Connection $connection,
        private UserRepository $userRepository,
    ) {
    }
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): GetRanking
    {
        $latestTournamentId = $this->getLatestRankingTournamentId();
        if ($latestTournamentId === null) {
            return new GetRanking([]);
        }

        $sql = "SELECT r.player, r.pos, r.rank, r.games, p.name_show, p.name_alph, p.id
                FROM PFSRANKING r
                INNER JOIN PFSPLAYER p ON r.player = p.id
                WHERE turniej = :latestTournamentId
                AND rtype='f'
                ORDER BY r.rank DESC";
        $result = $this->connection->executeQuery($sql, ['latestTournamentId' => $latestTournamentId]);
        $dbRows = $result->fetchAllAssociative();
        $rankingRows = [];
        $photosByPlayerId = $this->loadPhotosByPlayerId($dbRows);
        $participants = $this->loadParticipantsInTournament($latestTournamentId);
        $previousRankingByPlayer = $this->loadPreviousRankingByPlayer($latestTournamentId);

        foreach ($dbRows as $row) {
            $playerId = (int) $row['id'];
            $rankDelta = null;
            $positionDelta = null;

            if (isset($participants[$playerId]) && isset($previousRankingByPlayer[$playerId])) {
                $previous = $previousRankingByPlayer[$playerId];
                $currentRank = (float) $row['rank'];
                $currentPosition = (int) $row['pos'];
                $rankDelta = round($currentRank - $previous['rank'], 2);
                $positionDelta = $previous['position'] - $currentPosition;
            }

            $rankingRows[] = new RankingRow(
                (int) $row['pos'],
                (string) $row['name_show'],
                (string) $row['name_alph'],
                $playerId,
                $photosByPlayerId[$playerId] ?? null,
                (float) $row['rank'],
                (int) $row['games'],
                $rankDelta,
                $positionDelta
            );
        }

        return new GetRanking($rankingRows);
    }

    private function getLatestRankingTournamentId(): ?int
    {
        $value = $this->connection->fetchOne("SELECT MAX(turniej) FROM PFSRANKING WHERE rtype='f'");
        if ($value === false || $value === null) {
            return null;
        }

        return (int) $value;
    }

    /**
     * @return array<int, true>
     */
    private function loadParticipantsInTournament(int $tournamentId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT DISTINCT tw.player
             FROM PFSTOURWYN tw
             WHERE tw.turniej = :tournamentId",
            ['tournamentId' => $tournamentId]
        );

        $participants = [];
        foreach ($rows as $row) {
            $participants[(int) $row['player']] = true;
        }

        return $participants;
    }

    /**
     * @return array<int, array{rank: float, position: int}>
     */
    private function loadPreviousRankingByPlayer(int $latestTournamentId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT r.player, r.rank, r.pos
             FROM PFSRANKING r
             INNER JOIN (
                 SELECT player, MAX(turniej) AS prev_turniej
                 FROM PFSRANKING
                 WHERE rtype = 'f' AND turniej < :latestTournamentId
                 GROUP BY player
             ) prev ON prev.player = r.player AND prev.prev_turniej = r.turniej
             WHERE r.rtype = 'f'",
            ['latestTournamentId' => $latestTournamentId]
        );

        $previousRankingByPlayer = [];
        foreach ($rows as $row) {
            $previousRankingByPlayer[(int) $row['player']] = [
                'rank' => (float) $row['rank'],
                'position' => (int) $row['pos'],
            ];
        }

        return $previousRankingByPlayer;
    }

    /**
     * @param list<array<string, mixed>> $dbRows
     * @return array<int, string>
     */
    private function loadPhotosByPlayerId(array $dbRows): array
    {
        $playerIds = [];
        foreach ($dbRows as $row) {
            if (isset($row['id'])) {
                $playerIds[] = (int) $row['id'];
            }
        }

        $playerIds = array_values(array_unique($playerIds));
        if ($playerIds === []) {
            return [];
        }

        $photosByPlayerId = [];
        $users = $this->userRepository->findBy(['playerId' => $playerIds]);
        foreach ($users as $user) {
            $playerId = $user->getPlayerId();
            $photo = $user->getPhoto();
            if ($playerId === null || $photo === null || $photo === '') {
                continue;
            }

            if (!isset($photosByPlayerId[$playerId])) {
                $photosByPlayerId[$playerId] = $photo;
            }
        }

        return $photosByPlayerId;
    }
}
