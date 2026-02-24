<?php

namespace App\Command;

use App\GcgParser\Exception\InvalidGcgEventException;
use App\GcgParser\GcgParser;
use App\GcgParser\ParsedGcg\Events\ChallengeEvent;
use App\GcgParser\ParsedGcg\Events\EndgameEvent;
use App\GcgParser\ParsedGcg\Events\EventInterface;
use App\GcgParser\ParsedGcg\Events\ExchangeEvent;
use App\GcgParser\ParsedGcg\Events\PassEvent;
use App\GcgParser\ParsedGcg\Events\PlayEvent;
use App\GcgParser\ParsedGcg\Events\WithdrawalEvent;
use App\GcgParser\ParsedGcg\ParsedGcg;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:gcg:parse',
    description: 'Parses a GCG file and renders a round-by-round table for both players.',
)]
final class ParseGcgCommand extends Command
{
    public function __construct(
        private readonly GcgParser $gcgParser,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('path', InputArgument::REQUIRED, 'Path to a file containing GCG content.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $path = trim((string) $input->getArgument('path'));

        if ($path === '') {
            $io->error('Path cannot be empty.');

            return Command::INVALID;
        }

        if (!is_file($path)) {
            $io->error(sprintf('File not found: %s', $path));

            return Command::FAILURE;
        }

        if (!is_readable($path)) {
            $io->error(sprintf('File is not readable: %s', $path));

            return Command::FAILURE;
        }

        $gcgContent = file_get_contents($path);
        if ($gcgContent === false) {
            $io->error(sprintf('Could not read file: %s', $path));

            return Command::FAILURE;
        }

        try {
            $parsed = $this->gcgParser->parse($gcgContent);
        } catch (InvalidGcgEventException $exception) {
            $io->error(sprintf('Invalid GCG event: %s', $exception->getMessage()));

            return Command::FAILURE;
        } catch (\Throwable $exception) {
            $io->error(sprintf('Could not parse GCG: %s', $exception->getMessage()));

            return Command::FAILURE;
        }

        [$player1Nick, $player1Name, $player2Nick, $player2Name] = $this->resolvePlayers($parsed);

        $io->title('GCG Parsed Moves');
        $io->writeln(sprintf('<info>Player1:</info> %s (%s)', $player1Name, $player1Nick));
        $io->writeln(sprintf('<info>Player2:</info> %s (%s)', $player2Name, $player2Nick));
        $io->newLine();

        $rows = $this->buildRoundRows($parsed, $player1Nick, $player2Nick);

        if ($rows === []) {
            $io->warning('No events were found in this GCG file.');

            return Command::SUCCESS;
        }

        $io->table(
            ['Round', 'Rack', 'Move', 'Score', 'Total', 'Rack', 'Move', 'Score', 'Total'],
            $rows
        );

        return Command::SUCCESS;
    }

    /**
     * @return array{0:string,1:string,2:string,3:string}
     */
    private function resolvePlayers(ParsedGcg $parsed): array
    {
        $players = $parsed->getPlayers();

        if (count($players) < 2) {
            return ['P1', 'Player 1', 'P2', 'Player 2'];
        }

        $player1 = $players[0];
        $player2 = $players[1];

        return [$player1->nick, $player1->name, $player2->nick, $player2->name];
    }

    /**
     * @return array<int, array<int, string>>
     */
    private function buildRoundRows(ParsedGcg $parsed, string $player1Nick, string $player2Nick): array
    {
        $events = array_values($parsed->getEvents());
        $rows = [];
        $round = 1;

        for ($i = 0; $i < count($events); $i += 2) {
            $chunk = array_slice($events, $i, 2);

            $player1Data = ['-', '-', '-', '-'];
            $player2Data = ['-', '-', '-', '-'];

            foreach ($chunk as $event) {
                $eventData = [
                    $event->getRack() !== '' ? $event->getRack() : '-',
                    $this->renderMove($event),
                    sprintf('%+d', $event->getScore()),
                    (string) $event->getTotalScore(),
                ];

                if ($event->getPlayerNick() === $player1Nick) {
                    $player1Data = $eventData;
                } elseif ($event->getPlayerNick() === $player2Nick) {
                    $player2Data = $eventData;
                } elseif ($player1Data[1] === '-') {
                    $player1Data = $eventData;
                } else {
                    $player2Data = $eventData;
                }
            }

            $rows[] = array_merge([(string) $round], $player1Data, $player2Data);
            $round++;
        }

        return $rows;
    }

    private function renderMove(EventInterface $event): string
    {
        if ($event instanceof PlayEvent) {
            return sprintf('%s %s', $event->getStartField(), implode('/', $event->getWords()));
        }

        if ($event instanceof ExchangeEvent) {
            return sprintf('EXCH %s', $event->getExchanged());
        }

        if ($event instanceof PassEvent) {
            return 'PASS';
        }

        if ($event instanceof EndgameEvent) {
            return 'ENDGAME';
        }

        if ($event instanceof ChallengeEvent) {
            return 'CHALLENGE';
        }

        if ($event instanceof WithdrawalEvent) {
            return 'WITHDRAWAL';
        }

        return strtoupper($event->getType());
    }
}
