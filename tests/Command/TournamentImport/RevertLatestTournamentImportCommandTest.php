<?php

declare(strict_types=1);

namespace App\Tests\Command\TournamentImport;

use App\Command\TournamentImport\RevertLatestTournamentImportCommand;
use App\Service\RefreshCacheAfterImportLauncher;
use App\Service\TournamentRoundRollbackService;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class RevertLatestTournamentImportCommandTest extends TestCase
{
    public function testReportsFailureWhenNoImportAuditExists(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchAssociative')->willReturn(false);
        $tester = new CommandTester(new RevertLatestTournamentImportCommand(
            new TournamentRoundRollbackService($connection),
            new RefreshCacheAfterImportLauncher(__DIR__, new NullLogger()),
        ));

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('No tournament round', $tester->getDisplay());
    }
}
