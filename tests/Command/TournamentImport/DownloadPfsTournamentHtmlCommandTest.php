<?php

declare(strict_types=1);

namespace App\Tests\Command\TournamentImport;

use App\Command\TournamentImport\DownloadPfsTournamentHtmlCommand;
use App\Service\PfsTournamentCalendarParser;
use App\Service\PfsTournamentWebsiteClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class DownloadPfsTournamentHtmlCommandTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir() . '/pfs-html-download-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($this->projectDir, 0775, true));
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->projectDir);
    }

    public function testRejectsInvalidUrlIdBeforeFetchingRemoteData(): void
    {
        $tester = new CommandTester($this->command());

        self::assertSame(Command::INVALID, $tester->execute(['--url-id' => '0']));
        self::assertStringContainsString('Option --url-id must be a positive integer.', $tester->getDisplay());
    }

    public function testDownloadsSpecificTournamentByUrlId(): void
    {
        $this->withHttpsStub([
            'kalendarz.php?rok=2025' => <<<'HTML'
<script>
turnieje[100] = '<i>Ignored Tournament</i><br><b>1 stycznia, Warszawa</b>';
</script>
HTML,
            'kalendarz.php?rok=2026' => <<<'HTML'
<script>
turnieje[1421] = '<i>VIII Babskie granie (turniej bez jaj)</i><br><b>14 marca, Katowice</b>';
</script>
HTML,
            'turniej.php?id=1421' => '<html><body>full tournament html</body></html>',
        ], function (): void {
            $tester = new CommandTester($this->command());

            self::assertSame(Command::SUCCESS, $tester->execute([
                '--url-id' => '1421',
                '--from-year' => '2025',
                '--to-year' => '2026',
            ]));
        });

        $expectedFile = $this->projectDir . '/tournament_html/2026-03-14-viii-babskie-granie-turniej-bez-jaj-katowice.html';
        self::assertFileExists($expectedFile);
        self::assertSame('<html><body>full tournament html</body></html>', file_get_contents($expectedFile));
    }

    public function testDownloadsAllTournamentsOrderedByDate(): void
    {
        $this->withHttpsStub([
            'kalendarz.php?rok=2026' => <<<'HTML'
<script>
turnieje[1421] = '<i>VIII Babskie granie (turniej bez jaj)</i><br><b>14 marca, Katowice</b>';
turnieje[1413] = '<i>V Wadowickie Kremówkogranie</i><br><b>31 - 1 lutego, Wadowice</b>';
</script>
HTML,
            'turniej.php?id=1413' => '<html>first by date</html>',
            'turniej.php?id=1421' => '<html>second by date</html>',
        ], function (): void {
            $tester = new CommandTester($this->command());

            self::assertSame(Command::SUCCESS, $tester->execute([
                '--from-year' => '2026',
                '--to-year' => '2026',
            ]));
        });

        $files = array_values(array_diff(scandir($this->projectDir . '/tournament_html') ?: [], ['.', '..']));

        self::assertSame([
            '2026-01-31-v-wadowickie-kremowkogranie-wadowice.html',
            '2026-03-14-viii-babskie-granie-turniej-bez-jaj-katowice.html',
        ], $files);
        self::assertSame('<html>first by date</html>', file_get_contents($this->projectDir . '/tournament_html/' . $files[0]));
        self::assertSame('<html>second by date</html>', file_get_contents($this->projectDir . '/tournament_html/' . $files[1]));
    }

    private function command(): DownloadPfsTournamentHtmlCommand
    {
        return new DownloadPfsTournamentHtmlCommand(
            new PfsTournamentWebsiteClient(),
            new PfsTournamentCalendarParser(),
            $this->projectDir,
        );
    }

    /**
     * @param array<string, string> $responses
     */
    private function withHttpsStub(array $responses, \Closure $callback): void
    {
        $restoreHttps = in_array('https', stream_get_wrappers(), true);
        if ($restoreHttps) {
            stream_wrapper_unregister('https');
        }
        stream_wrapper_register('https', PfsTournamentHtmlDownloadHttpsStreamStub::class);
        PfsTournamentHtmlDownloadHttpsStreamStub::$responses = $responses;

        try {
            $callback();
        } finally {
            stream_wrapper_unregister('https');
            if ($restoreHttps) {
                stream_wrapper_restore('https');
            }
        }
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

final class PfsTournamentHtmlDownloadHttpsStreamStub
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
     * @return array<string, mixed>
     */
    public function stream_stat(): array
    {
        return [];
    }
}
