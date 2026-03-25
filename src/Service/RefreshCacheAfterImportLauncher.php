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
        $command = sprintf(
            'cd %s && %s bin/console app:cache:refresh-after-import --warmup > /dev/null 2>&1 &',
            escapeshellarg($this->projectDir),
            escapeshellarg(PHP_BINARY),
        );

        $handle = @popen($command, 'r');
        if ($handle === false) {
            $this->logger->warning('Failed to launch cache refresh after tournament import.');
            return;
        }

        @pclose($handle);
    }
}
