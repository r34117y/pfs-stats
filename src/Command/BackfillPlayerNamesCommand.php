<?php

declare(strict_types=1);

namespace App\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(
    name: 'app:player:backfill-names',
    description: 'Fill player.first_name, player.last_name, and player.slug from clear two-word name_show values.',
)]
final class BackfillPlayerNamesCommand extends Command
{
    public function __construct(
        #[Autowire(service: 'doctrine.dbal.default_connection')]
        private readonly Connection $connection,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Preview updates and roll them back.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, name_show, first_name, last_name, slug FROM player ORDER BY id'
        );

        $updatedCount = 0;
        $skippedCount = 0;
        $updatedRows = [];

        $this->connection->beginTransaction();

        try {
            foreach ($rows as $row) {
                $playerId = (int) $row['id'];
                $nameShow = $this->normalizeNullableString($row['name_show']);

                if ($nameShow === null) {
                    $skippedCount++;
                    continue;
                }

                $parts = preg_split('/\s+/u', trim($nameShow)) ?: [];
                if (count($parts) !== 2) {
                    $skippedCount++;
                    continue;
                }

                [$firstName, $lastName] = $parts;
                $firstName = $this->normalizeNullableString($firstName);
                $lastName = $this->normalizeNullableString($lastName);

                if ($firstName === null || $lastName === null) {
                    $skippedCount++;
                    continue;
                }

                $slug = $this->buildSlug($firstName, $lastName);
                if ($slug === null) {
                    $skippedCount++;
                    continue;
                }

                $newFirstName = $row['first_name'] ?? null;
                $newLastName = $row['last_name'] ?? null;
                $newSlug = $row['slug'] ?? null;
                $shouldUpdate = false;

                if ($this->normalizeNullableString($newFirstName) === null) {
                    $newFirstName = $firstName;
                    $shouldUpdate = true;
                }

                if ($this->normalizeNullableString($newLastName) === null) {
                    $newLastName = $lastName;
                    $shouldUpdate = true;
                }

                if ($this->normalizeNullableString($newSlug) === null) {
                    $newSlug = $slug;
                    $shouldUpdate = true;
                }

                if (!$shouldUpdate) {
                    $skippedCount++;
                    continue;
                }

                $this->connection->executeStatement(
                    'UPDATE player
                     SET first_name = :firstName,
                         last_name = :lastName,
                         slug = :slug
                     WHERE id = :id',
                    [
                        'id' => $playerId,
                        'firstName' => $newFirstName,
                        'lastName' => $newLastName,
                        'slug' => $newSlug,
                    ],
                );

                $updatedCount++;
                if (count($updatedRows) < 20) {
                    $updatedRows[] = [
                        (string) $playerId,
                        $nameShow,
                        (string) $newFirstName,
                        (string) $newLastName,
                        (string) $newSlug,
                    ];
                }
            }

            if ($dryRun) {
                $this->connection->rollBack();
                $io->warning('Dry-run mode enabled: transaction rolled back.');
            } else {
                $this->connection->commit();
            }
        } catch (\Throwable $exception) {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }

            $io->error(sprintf('Backfill failed: %s', $exception->getMessage()));

            return Command::FAILURE;
        }

        $io->table(
            ['Metric', 'Value'],
            [
                ['Updated rows', (string) $updatedCount],
                ['Skipped rows', (string) $skippedCount],
                ['Dry run', $dryRun ? 'yes' : 'no'],
            ],
        );

        if ($updatedRows !== []) {
            $io->section('Sample updated rows');
            $io->table(['id', 'name_show', 'first_name', 'last_name', 'slug'], $updatedRows);
        }

        $io->success('Player name backfill completed.');

        return Command::SUCCESS;
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function buildSlug(string $firstName, string $lastName): ?string
    {
        $first = $this->slugifyPart($firstName);
        $last = $this->slugifyPart($lastName);

        if ($first === null || $last === null) {
            return null;
        }

        return sprintf('%s-%s', $first, $last);
    }

    private function slugifyPart(string $value): ?string
    {
        $value = strtolower(strtr($value, [
            'Ą' => 'ą',
            'Ć' => 'ć',
            'Ę' => 'ę',
            'Ł' => 'ł',
            'Ń' => 'ń',
            'Ó' => 'ó',
            'Ś' => 'ś',
            'Ź' => 'ź',
            'Ż' => 'ż',
            'ą' => 'a',
            'ć' => 'c',
            'ę' => 'e',
            'ł' => 'l',
            'ń' => 'n',
            'ó' => 'o',
            'ś' => 's',
            'ź' => 'z',
            'ż' => 'z',
        ]));

        $value = (string) preg_replace('/[^a-z0-9]+/u', '-', $value);
        $value = trim($value, '-');

        return $value === '' ? null : $value;
    }
}
