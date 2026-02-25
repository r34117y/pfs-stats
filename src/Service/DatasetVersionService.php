<?php

namespace App\Service;

use DateTimeImmutable;
use DateTimeZone;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class DatasetVersionService
{
    private const string CACHE_KEY = 'app.dataset.version';

    public function __construct(
        #[Autowire(service: 'cache.app')]
        private CacheItemPoolInterface $cachePool,
    ) {
    }

    public function getVersion(): string
    {
        $item = $this->cachePool->getItem(self::CACHE_KEY);
        if ($item->isHit()) {
            $value = $item->get();
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        $defaultVersion = 'v1';
        $item->set($defaultVersion);
        $this->cachePool->save($item);

        return $defaultVersion;
    }

    public function bumpVersion(?string $version = null): string
    {
        $newVersion = $version !== null && trim($version) !== ''
            ? trim($version)
            : 'v' . (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('YmdHisv');

        $item = $this->cachePool->getItem(self::CACHE_KEY);
        $item->set($newVersion);
        $this->cachePool->save($item);

        return $newVersion;
    }
}
