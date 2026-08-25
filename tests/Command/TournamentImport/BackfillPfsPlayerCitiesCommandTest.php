<?php

declare(strict_types=1);

namespace App\Tests\Command\TournamentImport;

use App\Command\TournamentImport\BackfillPfsPlayerCitiesCommand;
use App\Service\PfsNameNormalizer;
use App\Service\PfsTournamentResultsParser;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class BackfillPfsPlayerCitiesCommandTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir() . '/pfs-player-cities-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($this->projectDir . '/tournament_html', 0775, true));
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->projectDir);
    }

    public function testBackfillsCitiesFromLocalTournamentHtmlFiles(): void
    {
        file_put_contents(
            $this->projectDir . '/tournament_html/2002-09-01-before-embedded-rankings-warszawa.html',
            "<div id='p_ranking'><pre></pre></div>",
        );
        file_put_contents(
            $this->projectDir . '/tournament_html/2002-10-20-empty-ranking-nowhere.html',
            "<div id='p_ranking'><pre>\u{FEFF}</pre></div>",
        );
        file_put_contents(
            $this->projectDir . '/tournament_html/2026-01-01-local-test-warszawa.html',
            <<<'HTML'
<div id='ranking'><pre>Ranking po turnieju Local Test

Lp. Imię            Nazwisko             Miasto               Ranking  Skalpy   Gry
--- --------------- -------------------- -------------------- -------- -------- --------
  1 Main            Player               Warszawa              150.00      150       30
</pre></div>
HTML,
        );

        $updatedRows = [];
        $tester = new CommandTester($this->command($this->connection($updatedRows)));

        self::assertSame(Command::SUCCESS, $tester->execute([
            '--from-year' => '2026',
            '--to-year' => '2026',
            '--dry-run' => true,
        ]));

        self::assertSame([
            [
                'city' => 'Warszawa',
                'playerIds' => [10],
            ],
        ], $updatedRows);
        self::assertStringContainsString('Tournament HTML files', $tester->getDisplay());
        self::assertStringContainsString('2', $tester->getDisplay());
        self::assertStringNotContainsString('Skipping 2002-10-20-empty-ranking-nowhere.html', $tester->getDisplay());
        self::assertStringContainsString('Dry-run mode enabled', $tester->getDisplay());
    }

    public function testRejectsInvalidLimitBeforeReadingLocalFiles(): void
    {
        $tester = new CommandTester($this->command($this->createStub(Connection::class)));

        self::assertSame(Command::INVALID, $tester->execute(['--limit' => '0']));
        self::assertStringContainsString('Option --limit must be a positive integer.', $tester->getDisplay());
    }

    private function command(Connection $connection): BackfillPfsPlayerCitiesCommand
    {
        return new BackfillPfsPlayerCitiesCommand(
            new PfsTournamentResultsParser(),
            new PfsNameNormalizer(),
            $connection,
            $this->projectDir,
        );
    }

    /**
     * @param list<array{city:string,playerIds:list<int>}> $updatedRows
     */
    private function connection(array &$updatedRows): Connection
    {
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
            ->willReturnCallback(static function (string $sql): int|null {
                if (str_contains($sql, 'FROM organization')) {
                    return 21;
                }

                return null;
            });
        $connection
            ->method('fetchAllAssociative')
            ->willReturn([
                [
                    'id' => 10,
                    'name_show' => 'Main Player',
                    'city' => null,
                ],
            ]);
        $connection
            ->method('executeStatement')
            ->willReturnCallback(static function (string $sql, array $params = []) use (&$updatedRows): int {
                if (str_contains($sql, 'UPDATE player')) {
                    $updatedRows[] = [
                        'city' => $params['city'],
                        'playerIds' => $params['playerIds'],
                    ];
                }

                return 1;
            });

        return $connection;
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
