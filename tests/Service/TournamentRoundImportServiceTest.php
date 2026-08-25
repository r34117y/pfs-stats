<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\ApiResource\TournamentRound\TournamentRound;
use App\Ranking\Domain\RankingType;
use App\Service\OldMethodCurrentRanking\OldMethodCurrentRankingServiceInterface;
use App\Service\PfsNameNormalizer;
use App\Service\PlayerCatalogService;
use App\Service\TournamentRoundImportService;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

final class TournamentRoundImportServiceTest extends TestCase
{
    public function testImportsCurrentAndOldWaitingRankingRows(): void
    {
        $insertedRankingRows = [];
        $connection = $this->createStub(Connection::class);
        $connection
            ->method('transactional')
            ->willReturnCallback(static fn (\Closure $callback): mixed => $callback($connection));
        $connection
            ->method('fetchAssociative')
            ->willReturn(['id' => 21, 'code' => 'PFS']);
        $connection
            ->method('fetchAllAssociative')
            ->willReturn([
                ['player_id' => 1, 'name_show' => 'Main Player', 'name_alph' => 'Player Main'],
                ['player_id' => 2, 'name_show' => 'Wait Player', 'name_alph' => 'Player Wait'],
            ]);
        $connection
            ->method('fetchOne')
            ->willReturnCallback(static function (string $sql): ?int {
                if (str_contains($sql, 'INSERT INTO tournament')) {
                    return 500;
                }

                return null;
            });
        $connection
            ->method('insert')
            ->willReturnCallback(static function (string $table, array $data) use (&$insertedRankingRows): int {
                if ($table === 'ranking') {
                    $insertedRankingRows[] = $data;
                }

                return 1;
            });
        $connection
            ->method('executeStatement')
            ->willReturn(0);

        $service = new TournamentRoundImportService(
            $connection,
            new PfsNameNormalizer(),
            new PlayerCatalogService($connection, new PfsNameNormalizer()),
            new class implements OldMethodCurrentRankingServiceInterface {
                public function calculateCurrentRanking(): array
                {
                    return [];
                }

                public function calculateRankingSnapshots(): iterable
                {
                    return [];
                }

                public function calculateRankingSnapshotAfterTournament(int $organizationId, int $tournamentId): ?array
                {
                    return [
                        'rows' => [
                            ['playerId' => 1, 'position' => 1, 'rankExact' => 150.0, 'games' => 30],
                        ],
                        'waitingRows' => [
                            ['playerId' => 2, 'position' => 1, 'rankExact' => 100.0, 'games' => 12],
                        ],
                    ];
                }
            },
        );

        $result = $service->import($this->payload());

        self::assertSame(500, $result->tournamentId);
        self::assertSame(1, $result->rankingRowsInserted(RankingType::FUCKED));
        self::assertSame(1, $result->rankingRowsInserted(RankingType::FUCKED_WAITING));
        self::assertSame(1, $result->rankingRowsInserted(RankingType::NORMAL));
        self::assertSame(1, $result->rankingRowsInserted(RankingType::NORMAL_WAITING));
        self::assertSame(
            [
                RankingType::FUCKED,
                RankingType::FUCKED_WAITING,
                RankingType::NORMAL,
                RankingType::NORMAL_WAITING,
            ],
            array_column($insertedRankingRows, 'rtype'),
        );
    }

    private function payload(): TournamentRound
    {
        return new TournamentRound(
            tournament: [
                'org' => 'pfs',
                'dateStart' => '2026-01-01',
                'name' => 'Test Tournament',
                'city' => 'Warszawa',
            ],
            players: [
                [
                    'startingPosition' => 1,
                    'place' => 1,
                    'firstName' => 'Main',
                    'lastName' => 'Player',
                    'tournamentRank' => 150.0,
                    'big' => 1.0,
                    'small' => 350,
                    'scalps' => 150.0,
                    'difference' => 50,
                ],
                [
                    'startingPosition' => 2,
                    'place' => 2,
                    'firstName' => 'Wait',
                    'lastName' => 'Player',
                    'tournamentRank' => 100.0,
                    'big' => 0.0,
                    'small' => 300,
                    'scalps' => 50.0,
                    'difference' => -50,
                ],
            ],
            results: [
                [
                    'round' => 1,
                    'table' => 1,
                    'host' => 1,
                    'guest' => 2,
                    'score1' => 350,
                    'score2' => 300,
                ],
            ],
            ranking: [
                [
                    'lp' => 1,
                    'main' => true,
                    'player' => 'Main Player',
                    'rank' => 150.0,
                    'games' => 30,
                ],
                [
                    'lp' => 1,
                    'main' => false,
                    'player' => 'Wait Player',
                    'rank' => 100.0,
                    'games' => 12,
                ],
            ],
        );
    }
}
