<?php

declare(strict_types=1);

namespace App\Tests\Command\TournamentImport;

use App\Command\TournamentImport\BackfillPfsWaitingRankingsCommand;
use App\Ranking\Domain\RankingType;
use App\Service\OldMethodCurrentRanking\OldMethodCurrentRankingServiceInterface;
use App\Service\PfsNameNormalizer;
use App\Service\PfsTournamentResultsParser;
use App\Service\PlayerCatalogService;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class BackfillPfsWaitingRankingsCommandTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir() . '/pfs-waiting-rankings-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($this->projectDir . '/tournament_html', 0775, true));
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->projectDir);
    }

    public function testRejectsInvalidLimitBeforeReadingLocalFiles(): void
    {
        $tester = new CommandTester($this->command($this->createStub(Connection::class)));

        self::assertSame(Command::INVALID, $tester->execute([
            '--from-year' => '2026',
            '--to-year' => '2026',
            '--limit' => '0',
        ]));
        self::assertStringContainsString('Option --limit must be a positive integer.', $tester->getDisplay());
    }

    public function testDryRunBackfillsOfficialAndOldMethodWaitingRows(): void
    {
        $insertedRows = [];
        $this->writeTournamentHtml('2026-01-01-ii-wielicki-turniej-w-scrabble-wieliczka.html', <<<'HTML'
<div id='ranking'><pre>Ranking po turnieju II Wielicki Turniej w Scrabble

Lp. Imię            Nazwisko             Miasto               Ranking  Skalpy   Gry
--- --------------- -------------------- -------------------- -------- -------- --------
  1 Main            Player               Warszawa              150.00      150       30

Poczekalnia na dzień 2026-01-01

Lp. Imię            Nazwisko             Miasto               Ranking  Skalpy   Gry
--- --------------- -------------------- -------------------- -------- -------- --------
  1 Wait            Player               Kraków                100.00       50        1
</pre></div>
HTML);

        $tester = new CommandTester($this->command($this->connection($insertedRows)));

        self::assertSame(Command::SUCCESS, $tester->execute([
            '--from-year' => '2026',
            '--to-year' => '2026',
            '--dry-run' => true,
        ]));

        self::assertSame([RankingType::FUCKED_WAITING, RankingType::NORMAL_WAITING], array_column($insertedRows, 'rtype'));
        self::assertStringContainsString('Inserted w rows', $tester->getDisplay());
        self::assertStringContainsString('Inserted u rows', $tester->getDisplay());
        self::assertStringContainsString('Dry-run mode enabled', $tester->getDisplay());
    }

    public function testCopiesOfficialWaitingRowsToOldMethodTypeBeforeStartTournament(): void
    {
        $insertedRows = [];
        $this->writeTournamentHtml('2025-01-01-earlier-tournament-wieliczka.html', <<<'HTML'
<div id='ranking'><pre>Ranking po turnieju Earlier Tournament

Lp. Imię            Nazwisko             Miasto               Ranking  Skalpy   Gry
--- --------------- -------------------- -------------------- -------- -------- --------
  1 Main            Player               Warszawa              150.00      150       30

Poczekalnia na dzień 2025-01-01

Lp. Imię            Nazwisko             Miasto               Ranking  Skalpy   Gry
--- --------------- -------------------- -------------------- -------- -------- --------
  1 Wait            Player               Kraków                100.00       50        1
</pre></div>
HTML);

        $tester = new CommandTester($this->command($this->connection(
            $insertedRows,
            tournamentId: 9000,
            tournamentDate: 20250101,
            tournamentName: 'Earlier Tournament',
            oldMethodStartId: 9367,
            oldMethodStartDate: 20260101,
        )));

        self::assertSame(Command::SUCCESS, $tester->execute([
            '--from-year' => '2025',
            '--to-year' => '2025',
            '--dry-run' => true,
        ]));

        self::assertSame([RankingType::FUCKED_WAITING, RankingType::NORMAL_WAITING], array_column($insertedRows, 'rtype'));
        self::assertSame([2, 2], array_column($insertedRows, 'player_id'));
        self::assertStringContainsString('Copied u before old-method start', $tester->getDisplay());
    }

    public function testCreatesMissingWaitingListPlayersBeforeInsertingRankingRows(): void
    {
        $insertedRows = [];
        $createdPlayers = [];
        $linkedPlayers = [];
        $this->writeTournamentHtml('2025-01-01-earlier-tournament-wieliczka.html', <<<'HTML'
<div id='ranking'><pre>Ranking po turnieju Earlier Tournament

Lp. Imię            Nazwisko             Miasto               Ranking  Skalpy   Gry
--- --------------- -------------------- -------------------- -------- -------- --------
  1 Main            Player               Warszawa              150.00      150       30

Poczekalnia na dzień 2025-01-01

Lp. Imię            Nazwisko             Miasto               Ranking  Skalpy   Gry
--- --------------- -------------------- -------------------- -------- -------- --------
  1 New             Player               Kraków                100.00       50        1
</pre></div>
HTML);

        $tester = new CommandTester($this->command($this->connection(
            $insertedRows,
            tournamentId: 9000,
            tournamentDate: 20250101,
            tournamentName: 'Earlier Tournament',
            oldMethodStartId: 9367,
            oldMethodStartDate: 20260101,
            catalogRows: [
                ['player_id' => 1, 'name_show' => 'Main Player', 'name_alph' => 'Player Main'],
            ],
            createdPlayers: $createdPlayers,
            linkedPlayers: $linkedPlayers,
            createdPlayerId: 77,
        )));

        self::assertSame(Command::SUCCESS, $tester->execute([
            '--from-year' => '2025',
            '--to-year' => '2025',
            '--dry-run' => true,
        ]));

        self::assertSame([
            [
                'nameShow' => 'New Player',
                'nameAlph' => 'Player New',
                'firstName' => 'New',
                'lastName' => 'Player',
                'slug' => 'new-player',
            ],
        ], $createdPlayers);
        self::assertSame([
            [
                'playerId' => 77,
                'organizationId' => 21,
            ],
        ], $linkedPlayers);
        self::assertSame([77, 77], array_column($insertedRows, 'player_id'));
        self::assertSame([RankingType::FUCKED_WAITING, RankingType::NORMAL_WAITING], array_column($insertedRows, 'rtype'));
        self::assertStringContainsString('Created players', $tester->getDisplay());
        self::assertStringContainsString('1', $tester->getDisplay());
    }

    public function testIgnoresAndReportsSecondOccurrenceOfDoubledPlayerName(): void
    {
        $insertedRows = [];
        $this->writeTournamentHtml('2025-01-01-earlier-tournament-wieliczka.html', <<<'HTML'
<div id='ranking'><pre>Ranking po turnieju Earlier Tournament

Lp. Imię            Nazwisko             Miasto               Ranking  Skalpy   Gry
--- --------------- -------------------- -------------------- -------- -------- --------
  1 Main            Player               Warszawa              150.00      150       30

Poczekalnia na dzień 2025-01-01

Lp. Imię            Nazwisko             Miasto               Ranking  Skalpy   Gry
--- --------------- -------------------- -------------------- -------- -------- --------
  1 Wait            Player               Kraków                100.00       50        1
  2 Wait            Player               Warszawa               90.00       45        1
</pre></div>
HTML);

        $tester = new CommandTester($this->command($this->connection(
            $insertedRows,
            tournamentId: 9000,
            tournamentDate: 20250101,
            tournamentName: 'Earlier Tournament',
            oldMethodStartId: 9367,
            oldMethodStartDate: 20260101,
        )));

        self::assertSame(Command::SUCCESS, $tester->execute([
            '--from-year' => '2025',
            '--to-year' => '2025',
            '--dry-run' => true,
        ]));

        self::assertSame([RankingType::FUCKED_WAITING, RankingType::NORMAL_WAITING], array_column($insertedRows, 'rtype'));
        self::assertSame([2, 2], array_column($insertedRows, 'player_id'));
        self::assertSame([1, 1], array_column($insertedRows, 'position'));
        self::assertStringContainsString('Doubled players ignored', $tester->getDisplay());
        self::assertStringContainsString('1', $tester->getDisplay());

        $reportPath = $this->projectDir . '/doubled_players.md';
        self::assertFileExists($reportPath);
        $report = file_get_contents($reportPath);
        self::assertIsString($report);
        self::assertStringContainsString('# Doubled Players', $report);
        self::assertStringContainsString('Earlier Tournament (20250101, tournament_id=9000)', $report);
        self::assertStringContainsString('Player name: Wait Player', $report);
        self::assertStringContainsString('Kept: position 1, city "Kraków"', $report);
        self::assertStringContainsString('Ignored: position 2, city "Warszawa"', $report);
    }

    /**
     * @param list<array<string, mixed>> $insertedRows
     */
    private function command(Connection $connection, array &$insertedRows = []): BackfillPfsWaitingRankingsCommand
    {
        return new BackfillPfsWaitingRankingsCommand(
            new PfsTournamentResultsParser(),
            new PlayerCatalogService($connection, new PfsNameNormalizer()),
            new PfsNameNormalizer(),
            new class implements OldMethodCurrentRankingServiceInterface {
                public function calculateCurrentRanking(): array
                {
                    return [];
                }

                public function calculateRankingSnapshots(): iterable
                {
                    yield [
                        'referenceTournamentId' => 9367,
                        'rows' => [],
                        'waitingRows' => [
                            [
                                'position' => 1,
                                'playerId' => 3,
                                'rankExact' => 99.5,
                                'games' => 12,
                            ],
                        ],
                    ];
                }

                public function calculateRankingSnapshotAfterTournament(int $organizationId, int $tournamentId): ?array
                {
                    return [
                        'rows' => [],
                        'waitingRows' => [
                            [
                                'position' => 1,
                                'playerId' => 3,
                                'rankExact' => 99.5,
                                'games' => 12,
                            ],
                        ],
                    ];
                }
            },
            $connection,
            $this->projectDir,
        );
    }

    /**
     * @param list<array<string, mixed>> $insertedRows
     */
    private function connection(
        array &$insertedRows,
        int $tournamentId = 9367,
        int $tournamentDate = 20260101,
        string $tournamentName = 'II Wielicki Turniej w Scrabble',
        string $tournamentPlace = 'Wieliczka',
        int $oldMethodStartId = 9367,
        int $oldMethodStartDate = 20260101,
        array $catalogRows = [
            ['player_id' => 1, 'name_show' => 'Main Player', 'name_alph' => 'Player Main'],
            ['player_id' => 2, 'name_show' => 'Wait Player', 'name_alph' => 'Player Wait'],
        ],
        array &$createdPlayers = [],
        array &$linkedPlayers = [],
        int $createdPlayerId = 77,
    ): Connection {
        $transactionActive = false;
        $connection = $this->createStub(Connection::class);
        $connection
            ->method('beginTransaction')
            ->willReturnCallback(static function () use (&$transactionActive): void {
                $transactionActive = true;
            });
        $connection
            ->method('rollBack')
            ->willReturnCallback(static function () use (&$transactionActive): void {
                $transactionActive = false;
            });
        $connection
            ->method('isTransactionActive')
            ->willReturnCallback(static fn (): bool => $transactionActive);
        $connection
            ->method('fetchOne')
            ->willReturnCallback(static function (string $sql, array $params = []) use (&$createdPlayers, $createdPlayerId): bool|int|null {
                if (str_contains($sql, 'FROM organization')) {
                    return 21;
                }

                if (str_contains($sql, 'SELECT EXISTS')) {
                    return false;
                }

                if (str_contains($sql, 'INSERT INTO player')) {
                    $createdPlayers[] = [
                        'nameShow' => $params['nameShow'],
                        'nameAlph' => $params['nameAlph'],
                        'firstName' => $params['firstName'],
                        'lastName' => $params['lastName'],
                        'slug' => $params['slug'],
                    ];

                    return $createdPlayerId;
                }

                return null;
            });
        $connection
            ->method('fetchAssociative')
            ->willReturnCallback(static function (string $sql) use (
                $oldMethodStartId,
                $oldMethodStartDate,
            ): array|false {
                if (str_contains($sql, 'LOWER(COALESCE')) {
                    return [
                        'id' => $oldMethodStartId,
                        'dt' => $oldMethodStartDate,
                    ];
                }

                return false;
            });
        $connection
            ->method('fetchAllAssociative')
            ->willReturnCallback(static function (string $sql) use ($catalogRows, $tournamentId, $tournamentDate, $tournamentName, $tournamentPlace): array {
                if (str_contains($sql, 'FROM tournament')) {
                    return [
                        [
                            'id' => $tournamentId,
                            'dt' => $tournamentDate,
                            'name' => $tournamentName,
                            'fullname' => $tournamentName,
                            'place' => $tournamentPlace,
                            'urlid' => 123,
                        ],
                    ];
                }

                return $catalogRows;
            });
        $connection
            ->method('insert')
            ->willReturnCallback(static function (string $table, array $data) use (&$insertedRows): int {
                self::assertSame('ranking', $table);
                $insertedRows[] = $data;

                return 1;
            });
        $connection
            ->method('executeStatement')
            ->willReturnCallback(static function (string $sql, array $params = []) use (&$linkedPlayers): int {
                if (str_contains($sql, 'INSERT INTO player_organization')) {
                    $linkedPlayers[] = [
                        'playerId' => $params['playerId'],
                        'organizationId' => $params['organizationId'],
                    ];
                }

                return 1;
            });

        return $connection;
    }

    private function writeTournamentHtml(string $filename, string $html): void
    {
        file_put_contents($this->projectDir . '/tournament_html/' . $filename, $html);
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        foreach (array_diff(scandir($directory) ?: [], ['.', '..']) as $entry) {
            $path = sprintf('%s/%s', $directory, $entry);
            if (is_dir($path)) {
                $this->removeDirectory($path);
                continue;
            }

            unlink($path);
        }

        rmdir($directory);
    }
}
