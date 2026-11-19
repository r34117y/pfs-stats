<?php

declare (strict_types=1);
namespace App\Service;

use App\PfsTournamentImport\{CalendarTournament, PfsTournamentSource};
use Symfony\Component\DependencyInjection\Attribute\Autowire;
final readonly class PfsTournamentArchive
{
    public function __construct(
        #[Autowire('%app.pfs_archive_dir%')]
        private string $directory,
        private PfsTournamentWebsiteClient $client = new PfsTournamentWebsiteClient(),
        private PfsTournamentSourceValidator $validator = new PfsTournamentSourceValidator()
    )
    {
    }
    public function filename(CalendarTournament $event): string
    {
        return $event->startDate->format('Y-m-d') . '-' . $this->slug($event->name) . '-' . $this->slug($event->location) . '.html';
    }
    public function load(CalendarTournament $event, bool $refresh = false): PfsTournamentSource
    {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0775, true) && !is_dir($this->directory)) {
            throw new \RuntimeException('Cannot create PFS archive directory.');
        }
        $path = $this->directory . '/' . $this->filename($event);
        $metaPath = $path . '.meta.json';
        if (is_file($metaPath)) {
            $identityMeta = json_decode(file_get_contents($metaPath), true, flags: JSON_THROW_ON_ERROR);
            if (($identityMeta['pfsId'] ?? null) !== $event->urlId) {
                throw new \RuntimeException('Archive identity collision: ' . $path);
            }
        }
        if (!$refresh && is_file($path)) {
            $html = file_get_contents($path);
            if (is_file($metaPath)) {
                $meta = json_decode(file_get_contents($metaPath), true, flags: JSON_THROW_ON_ERROR);
                if (($meta['pfsId'] ?? null) !== $event->urlId || ($meta['sha256'] ?? null) !== hash('sha256', $html)) {
                    throw new \RuntimeException('Archive identity/hash mismatch: ' . $path . '; verify source before replacing.');
                }
            }
            // Only empty announcements trigger automatic refresh. Corrupt complete results are errors.
            if ($this->hasResults($html)) {
                $source = $this->validated($event, $path, $html);
                if (!is_file($metaPath)) {
                    $this->atomicWrite($metaPath, json_encode(['formatVersion' => 1, 'pfsId' => $event->urlId, 'sourceUrl' => $event->getResultsUrl(), 'fetchedAt' => null, 'verifiedAt' => gmdate('c'), 'sha256' => $source->sha256, 'name' => $event->name, 'date' => $event->startDate->format('Y-m-d'), 'city' => $event->location], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");
                }
                return $source;
            }
        }
        $html = $this->client->fetchTournamentHtml($event->urlId);
        $source = $this->validated($event, $path, $html);
        $meta = ['formatVersion' => 1, 'pfsId' => $event->urlId, 'sourceUrl' => $event->getResultsUrl(), 'fetchedAt' => gmdate('c'), 'sha256' => $source->sha256, 'name' => $event->name, 'date' => $event->startDate->format('Y-m-d'), 'city' => $event->location];
        $this->atomicWrite($path, $html);
        $this->atomicWrite($metaPath, json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");
        return $source;
    }
    /** Downloader can archive announcements, but never replace complete verified results with one. */
    public function download(CalendarTournament $event): string
    {
        $html = $this->client->fetchTournamentHtml($event->urlId);
        if ($this->hasResults($html)) {
            return $this->loadWithHtml($event, $html)->path;
        }
        $path = $this->directory . '/' . $this->filename($event);
        if (is_file($path) && $this->hasResults(file_get_contents($path))) {
            return $path;
        }
        if (!is_dir($this->directory) && !mkdir($this->directory, 0775, true) && !is_dir($this->directory)) {
            throw new \RuntimeException('Cannot create archive directory.');
        }
        $this->atomicWrite($path, $html);
        return $path;
    }
    private function loadWithHtml(CalendarTournament $event, string $html): PfsTournamentSource
    {
        $archive = new self($this->directory, new PfsTournamentWebsiteClient(static fn(string $url): string => $html), $this->validator);
        return $archive->load($event, true);
    }
    private function validated(CalendarTournament $event, string $path, string $html): PfsTournamentSource
    {
        if (preg_match('~<h1\b[^>]*>(.*?)(?:<span|</h1>)~su', $html, $m)) {
            $heading = html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($this->slug($heading) !== $this->slug($event->name)) {
                throw new \RuntimeException('Archive/calendar tournament identity mismatch: ' . $event->urlId);
            }
        }
        $results = $this->validator->validate($html);
        return new PfsTournamentSource($event, $path, hash('sha256', $html), $results);
    }
    private function hasResults(string $html): bool
    {
        return (bool) preg_match("~id=['\"]p_hh['\"][^>]*>\\s*<pre>\\s*(?:\\x{FEFF})?\\s*Wyniki poszczególnych~su", $html);
    }
    private function atomicWrite(string $path, string $data): void
    {
        $tmp = tempnam(dirname($path), '.pfs-');
        if ($tmp === false) {
            throw new \RuntimeException('Cannot stage archive: ' . $path);
        }
        try {
            if (file_put_contents($tmp, $data) !== strlen($data) || !rename($tmp, $path)) {
                throw new \RuntimeException('Cannot save archive: ' . $path);
            }
            chmod($path, 0664);
        } finally {
            if (is_file($tmp)) {
                unlink($tmp);
            }
        }
    }
    private function slug(string $s): string
    {
        $s = mb_strtolower($s);
        $s = strtr($s, ['ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n', 'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z']);
        return trim(preg_replace('/[^a-z0-9]+/u', '-', $s), '-') ?: 'unknown';
    }
}
