<?php

declare(strict_types=1);

namespace App\Tests\Command\TournamentImport;

use App\Command\TournamentImport\ImportPfsTournamentCommand;
use App\Service\OldMethodCurrentRanking\OldMethodCurrentRankingServiceInterface;
use App\Service\PfsNameNormalizer;
use App\Service\PfsTournamentCalendarParser;
use App\Service\PfsTournamentPayloadFactory;
use App\Service\PfsTournamentResultsParser;
use App\Service\PfsTournamentWebsiteClient;
use App\Service\PlayerCatalogService;
use App\Service\RefreshCacheAfterImportLauncher;
use App\Service\TournamentRoundImportService;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class ImportPfsTournamentCommandTest extends TestCase
{
    public function testRejectsInvalidPfsIdBeforeFetchingRemoteData(): void
    {
        $tester = new CommandTester($this->command());

        self::assertSame(Command::INVALID, $tester->execute(['--pfsid' => '0']));
        self::assertStringContainsString('Option --pfsid must be a positive integer.', $tester->getDisplay());
    }

    public function testDisplaysRankingCountsAfterSuccessfulImport(): void
    {
        $restoreHttps = in_array('https', stream_get_wrappers(), true);
        if ($restoreHttps) {
            stream_wrapper_unregister('https');
        }
        stream_wrapper_register('https', PfsTournamentCommandHttpsStreamStub::class);
        PfsTournamentCommandHttpsStreamStub::$responses = [
            'kalendarz.php' => <<<'HTML'
<script>
turnieje[123] = '<i>Test Tournament</i><br><b>1 stycznia, Warszawa</b>';
</script>
HTML,
            'turniej.php' => $this->tournamentHtml(),
        ];

        try {
            $tester = new CommandTester($this->command($this->importConnection()));

            self::assertSame(Command::SUCCESS, $tester->execute([
                '--pfsid' => '123',
                '--year' => '2026',
                '--no-cache-warmup' => true,
            ]));
        } finally {
            stream_wrapper_unregister('https');
            if ($restoreHttps) {
                stream_wrapper_restore('https');
            }
        }

        $display = $tester->getDisplay();
        self::assertStringContainsString('Ranking rows f', $display);
        self::assertStringContainsString('Ranking rows w', $display);
        self::assertStringContainsString('Ranking rows n', $display);
        self::assertStringContainsString('Ranking rows u', $display);
    }

    private function command(?Connection $connection = null): ImportPfsTournamentCommand
    {
        $connection ??= $this->createStub(Connection::class);

        return new ImportPfsTournamentCommand(
            new PfsTournamentWebsiteClient(),
            new PfsTournamentCalendarParser(),
            new PfsTournamentResultsParser(),
            new PfsTournamentPayloadFactory(),
            new TournamentRoundImportService(
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
                        return null;
                    }
                },
            ),
            new RefreshCacheAfterImportLauncher(__DIR__, new NullLogger()),
        );
    }

    private function importConnection(): Connection
    {
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
            ->method('executeStatement')
            ->willReturn(0);

        return $connection;
    }

    private function tournamentHtml(): string
    {
        return <<<'HTML'
<table id='szczegoly'><tr><td>Sędzia</td><td>Test Ref</td></tr></table>
<div id='p_klasyfikacja'><pre>Wyniki turnieju Test Tournament

Lp. Imię            Nazwisko             Miasto               Ranking  Duże pkt Małe pkt Skalpy   Różn. MP
--- --------------- -------------------- -------------------- -------- -------- -------- -------- --------
  1 Main            Player               Warszawa              150.00      1.0      350      150       50
  2 Wait            Player               Kraków                100.00      0.0      300       50      -50
</pre></div>
<div id='p_rundy'><pre>Wyniki poszczególnych rund turnieju Test Tournament

Runda nr  1
-----------
Stół Gospodarz                             Gość                                Wynik
---- ----------------------------------- - ----------------------------------- ---------
   1 Main           Player                - Wait           Player               350: 300
</pre></div>
<div id='p_hh'><pre>Wyniki poszczególnych graczy w turnieju Test Tournament

	Main Player 150.00 Warszawa

Runda Stół  Przeciwnik                          Ranking   Wynik     Skalp
----- ----- ----------------------------------- ------- - --------- -----
    1     1 Wait Player                         100.00 +  350: 300  150
                                                          --------------- ---- ------
                                                                     150 / 1 150.00

	Wait Player 100.00 Kraków

Runda Stół  Przeciwnik                          Ranking   Wynik     Skalp
----- ----- ----------------------------------- ------- - --------- -----
    1     1 Main Player                         150.00 -  300: 350   50
                                                          --------------- ---- ------
                                                                      50 / 1  50.00
</pre></div>
<div id='ranking'><pre>Ranking po turnieju Test Tournament

Lp. Imię            Nazwisko             Miasto               Ranking  Skalpy   Gry
--- --------------- -------------------- -------------------- -------- -------- --------
  1 Main            Player               Warszawa              150.00      150       30

Poczekalnia na dzień 2026-01-01

Lp. Imię            Nazwisko             Miasto               Ranking  Skalpy   Gry
--- --------------- -------------------- -------------------- -------- -------- --------
  1 Wait            Player               Kraków                100.00       50        1
</pre></div>
HTML;
    }
}

final class PfsTournamentCommandHttpsStreamStub
{
    /** @var array<string, string> */
    public static array $responses = [];

    /** @var resource|null */
    public $context = null;

    private int $position = 0;
    private string $content = '';

    public function stream_open(string $path): bool
    {
        foreach (self::$responses as $needle => $response) {
            if (str_contains($path, $needle)) {
                $this->content = $response;

                return true;
            }
        }

        return false;
    }

    public function stream_read(int $count): string
    {
        $chunk = substr($this->content, $this->position, $count);
        $this->position += strlen($chunk);

        return $chunk;
    }

    public function stream_eof(): bool
    {
        return $this->position >= strlen($this->content);
    }

    /**
     * @return array<string, int>
     */
    public function stream_stat(): array
    {
        return [];
    }
}
