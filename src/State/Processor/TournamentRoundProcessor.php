<?php

namespace App\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\TournamentRound\TournamentRound;
use App\ApiResource\TournamentRound\TournamentRoundResponse;
use App\Service\RefreshCacheAfterImportLauncher;
use App\Service\TournamentRoundImportService;
use App\Service\TournamentRoundTokenAuthorizer;
use Doctrine\DBAL\Exception;
use JsonException;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

final readonly class TournamentRoundProcessor implements ProcessorInterface
{
    public function __construct(
        private TournamentRoundTokenAuthorizer $tournamentRoundTokenAuthorizer,
        private TournamentRoundImportService $tournamentRoundImportService,
        private RefreshCacheAfterImportLauncher $refreshCacheAfterImportLauncher,
        private RequestStack $requestStack,
        #[Autowire(service: 'monolog.logger.tournament_round_error')]
        private LoggerInterface $tournamentRoundErrorLogger,
        #[Autowire('%kernel.logs_dir%/tournament_round_payload.log')]
        private string $payloadLogPath,
    ) {
    }

    /**
     * @throws \Throwable
     * @throws Exception
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): TournamentRoundResponse
    {
        $rawPayload = $this->requestStack->getCurrentRequest()?->getContent() ?? '';
        $this->saveRawPayload($rawPayload);

        try {
            $payload = $this->createPayload($rawPayload);

            if (!$this->tournamentRoundTokenAuthorizer->isAuthorized($payload->token)) {
                throw new UnauthorizedHttpException('Bearer', 'Unauthorized.');
            }

            $tournamentId = $this->tournamentRoundImportService->import($payload);
            $this->refreshCacheAfterImportLauncher->launchWarmup();

            return new TournamentRoundResponse(sprintf('Imported tournament %d.', $tournamentId));
        } catch (\Throwable $exception) {
            $this->tournamentRoundErrorLogger->error('Tournament round request failed.', [
                'exception_class' => $exception::class,
                'message' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString(),
            ]);

            throw $exception;
        }
    }

    private function createPayload(string $rawPayload): TournamentRound
    {
        try {
            $decoded = json_decode($this->stripUtf8Bom($rawPayload), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new BadRequestHttpException('Invalid JSON payload: ' . $exception->getMessage(), $exception);
        }

        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new BadRequestHttpException('Invalid payload.');
        }

        return new TournamentRound(
            token: $this->getStringField($decoded, 'token'),
            tournament: $this->getArrayField($decoded, 'tournament'),
            players: $this->getArrayField($decoded, 'players'),
            results: $this->getArrayField($decoded, 'results'),
            rankDay: $decoded['rankDay'] ?? null,
            ranking: $this->getArrayField($decoded, 'ranking'),
        );
    }

    private function stripUtf8Bom(string $payload): string
    {
        return str_starts_with($payload, "\xEF\xBB\xBF") ? substr($payload, 3) : $payload;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function getStringField(array $payload, string $field): string
    {
        $value = $payload[$field] ?? '';

        return is_string($value) ? $value : '';
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<mixed>
     */
    private function getArrayField(array $payload, string $field): array
    {
        $value = $payload[$field] ?? [];

        return is_array($value) ? $value : [];
    }

    private function saveRawPayload(string $rawPayload): void
    {
        $directory = dirname($this->payloadLogPath);
        if (!is_dir($directory) && !@mkdir($directory, 0777, true) && !is_dir($directory)) {
            $this->tournamentRoundErrorLogger->error('Failed to create tournament round payload log directory.', [
                'directory' => $directory,
            ]);

            return;
        }

        $payloadToWrite = $rawPayload;
        if ($payloadToWrite !== '' && !str_ends_with($payloadToWrite, PHP_EOL)) {
            $payloadToWrite .= PHP_EOL;
        }

        if (@file_put_contents($this->payloadLogPath, $payloadToWrite, FILE_APPEND | LOCK_EX) === false) {
            $this->tournamentRoundErrorLogger->error('Failed to append tournament round payload log.', [
                'path' => $this->payloadLogPath,
            ]);
        }
    }
}
