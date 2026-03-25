<?php

namespace App\Service;

final class RefreshCacheAfterImportResult
{
    /**
     * @var list<array{path: string, statusCode: int}>
     */
    private array $successfulWarmups = [];

    /**
     * @var list<array{path: string, reason: string}>
     */
    private array $failedWarmups = [];

    public function __construct(
        public readonly string $previousDatasetVersion,
        public readonly string $newDatasetVersion,
    ) {
    }

    public function addSuccess(string $path, int $statusCode): void
    {
        $this->successfulWarmups[] = [
            'path' => $path,
            'statusCode' => $statusCode,
        ];
    }

    public function addFailure(string $path, string $reason): void
    {
        $this->failedWarmups[] = [
            'path' => $path,
            'reason' => $reason,
        ];
    }

    /**
     * @return list<array{path: string, statusCode: int}>
     */
    public function successfulWarmups(): array
    {
        return $this->successfulWarmups;
    }

    /**
     * @return list<array{path: string, reason: string}>
     */
    public function failedWarmups(): array
    {
        return $this->failedWarmups;
    }

    public function hasWarmupFailures(): bool
    {
        return $this->failedWarmups !== [];
    }
}
