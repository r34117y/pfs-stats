<?php

namespace App\Command;

use App\Service\RefreshCacheAfterImportService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:cache:refresh-after-import',
    description: 'Refresh app caches after importing a MySQL dump: clear (optional), bump dataset version, and warmup (optional).',
)]
final class RefreshCacheAfterImportCommand extends Command
{
    public function __construct(
        private readonly RefreshCacheAfterImportService $refreshCacheAfterImportService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dataset-version', null, InputOption::VALUE_REQUIRED, 'Explicit dataset version value to set.')
            ->addOption('clear-cache-app', null, InputOption::VALUE_NONE, 'Clear cache.app pool before bumping version.')
            ->addOption('warmup', null, InputOption::VALUE_NONE, 'Warm selected public API endpoints after version bump.')
            ->addOption('org', null, InputOption::VALUE_OPTIONAL, 'Organization id to be refreshed.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $isWarmupEnabled = (bool) $input->getOption('warmup');
        $orgId = (int) ($input->getOption('org') ?? 21);

        $messages = [];
        $result = $this->refreshCacheAfterImportService->refresh(
            datasetVersion: $this->stringOrNull($input->getOption('dataset-version')),
            clearCacheApp: (bool) $input->getOption('clear-cache-app'),
            warmup: $isWarmupEnabled,
            reporter: static function (string $message) use (&$messages): void {
                $messages[] = $message;
            },
            orgId: $orgId
        );

        foreach ($messages as $message) {
            if (str_starts_with($message, 'WARMED ')) {
                $io->writeln(sprintf('<info>%s</info>', $message));
                continue;
            }

            if (str_starts_with($message, 'FAILED ')) {
                $io->writeln(sprintf('<error>%s</error>', $message));
                continue;
            }

            $io->writeln($message);
        }

        $io->success(sprintf(
            'Dataset version changed: %s -> %s',
            $result->previousDatasetVersion,
            $result->newDatasetVersion,
        ));

        if (!$isWarmupEnabled) {
            return Command::SUCCESS;
        }

        if ($result->hasWarmupFailures()) {
            $io->warning(sprintf(
                'Warmup completed with errors. success=%d, failed=%d',
                count($result->successfulWarmups()),
                count($result->failedWarmups()),
            ));

            return Command::SUCCESS;
        }

        $io->success(sprintf(
            'Warmup completed. success=%d, failed=%d',
            count($result->successfulWarmups()),
            count($result->failedWarmups()),
        ));

        return Command::SUCCESS;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);
        return $trimmed === '' ? null : $trimmed;
    }
}
