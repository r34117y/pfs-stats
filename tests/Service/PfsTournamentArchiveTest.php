<?php

declare (strict_types=1);
namespace App\Tests\Service;

use App\PfsTournamentImport\CalendarTournament;
use App\Service\{PfsTournamentArchive, PfsTournamentWebsiteClient};
use PHPUnit\Framework\TestCase;
final class PfsTournamentArchiveTest extends TestCase
{
    public function testTamperedArchiveAndConflictingIdentityCannotBeReused(): void
    {
        $dir = sys_get_temp_dir() . '/pfs-archive-' . bin2hex(random_bytes(5));
        mkdir($dir);
        $html = file_get_contents(__DIR__ . '/../Fixtures/pfs_tournament_html/current-1399.html');
        $downloads = 0;
        $archive = new PfsTournamentArchive($dir, new PfsTournamentWebsiteClient(function (string $url) use ($html, &$downloads): string {
            $downloads++;
            return $html;
        }));
        $event = new CalendarTournament(1399, 'XXXIII Mistrzostwa Polski — faza eliminacyjna', 'Płock', new \DateTimeImmutable('2026-11-14'), new \DateTimeImmutable('2026-11-15'));
        try {
            $source = $archive->load($event);
            file_put_contents($source->path, $html . "\n<!-- changed -->");
            try {
                $archive->load($event);
                self::fail('Changed bytes must invalidate the archive hash.');
            } catch (\RuntimeException $e) {
                self::assertStringContainsString('hash mismatch', $e->getMessage());
            }
            $other = new CalendarTournament(9999, $event->name, $event->location, $event->startDate, $event->endDate);
            try {
                $archive->load($other, true);
                self::fail('Forced refresh must not replace another tournament.');
            } catch (\RuntimeException $e) {
                self::assertStringContainsString('identity', strtolower($e->getMessage()));
            }
            self::assertSame(1, $downloads);
            self::assertSame($html . "\n<!-- changed -->", file_get_contents($source->path));
        } finally {
            foreach (glob($dir . '/*') as $path) {
                unlink($path);
            }
            rmdir($dir);
        }
    }

    public function testCompleteArchiveNeedsNoDownloadAndIncompleteGetsRefreshed(): void
    {
        $dir = sys_get_temp_dir() . '/pfs-archive-' . bin2hex(random_bytes(5));
        mkdir($dir);
        $html = file_get_contents(__DIR__ . '/../Fixtures/pfs_tournament_html/current-1399.html');
        $count = 0;
        $client = new PfsTournamentWebsiteClient(function (string $url) use (&$count, $html): string {
            $count++;
            return $html;
        });
        $event = new CalendarTournament(1399, 'XXXIII Mistrzostwa Polski — faza eliminacyjna', 'Płock', new \DateTimeImmutable('2026-11-14'), new \DateTimeImmutable('2026-11-15'));
        $archive = new PfsTournamentArchive($dir, $client);
        $first = $archive->load($event);
        self::assertSame(1, $count);
        self::assertCount(120, $first->results->players);
        $second = $archive->load($event);
        self::assertSame(1, $count);
        self::assertSame($first->sha256, $second->sha256);
        // Simulate an old announcement without metadata, not a tampered verified copy.
        unlink($first->path . '.meta.json');
        file_put_contents($first->path, file_get_contents(__DIR__ . '/../Fixtures/pfs_tournament_html/announcement-1399.html'));
        $archive->load($event);
        self::assertSame(2, $count);
        foreach (glob($dir . '/*') as $p) {
            unlink($p);
        }
        rmdir($dir);
    }
}
