<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;

final readonly class DatasetCache implements CacheInterface
{
    public function __construct(
        #[Autowire(service: 'cache.app')]
        private CacheInterface $innerCache,
        private DatasetVersionService $datasetVersionService,
    ) {
    }

    public function get(string $key, callable $callback, ?float $beta = null, ?array &$metadata = null): mixed
    {
        return $this->innerCache->get(
            $this->versionedKey($key),
            $callback,
            $beta,
            $metadata,
        );
    }

    public function delete(string $key): bool
    {
        return $this->innerCache->delete($this->versionedKey($key));
    }

    private function versionedKey(string $key): string
    {
        return sprintf('dataset.%s.%s', $this->datasetVersionService->getVersion(), $key);
    }
}
