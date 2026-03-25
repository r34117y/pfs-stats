<?php

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class RefreshCacheAfterImportLauncher
{
    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private string $projectDir,
        #[Autowire(service: 'monolog.logger.tournament_round_error')]
        private LoggerInterface $logger,
    ) {
    }

    public function launchWarmup(): void
    {
        $phpBinary = $this->resolvePhpCliBinary();
        if ($phpBinary === null) {
            $this->logger->warning('Failed to resolve PHP CLI binary for cache refresh after tournament import.');
            return;
        }

        $command = sprintf(
            'cd %s && nohup %s bin/console app:cache:refresh-after-import --warmup > /dev/null 2>&1 &',
            escapeshellarg($this->projectDir),
            escapeshellarg($phpBinary),
        );

        $handle = @popen($command, 'r');
        if ($handle === false) {
            $this->logger->warning('Failed to launch cache refresh after tournament import.', [
                'php_binary' => $phpBinary,
            ]);
            return;
        }

        @pclose($handle);
    }

    private function resolvePhpCliBinary(): ?string
    {
        $candidates = [
            PHP_BINDIR . '/php',
            '/usr/local/bin/php',
            PHP_BINARY,
        ];

        foreach (array_values(array_unique($candidates)) as $candidate) {
            if (!is_string($candidate) || $candidate === '') {
                continue;
            }

            if (str_contains(basename($candidate), 'php-fpm')) {
                continue;
            }

            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
