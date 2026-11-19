<?php

declare (strict_types=1);
namespace App\Tests\Service;

use App\Service\DatasetVersionService;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
final class PfsImportCachePublicationTest extends TestCase
{
    public function testFailedVersionSaveIsAnError(): void
    {
        $pool = $this->createStub(CacheItemPoolInterface::class);
        $pool->method('getItem')->willReturn((new ArrayAdapter())->getItem('app.dataset.version'));
        $pool->method('save')->willReturn(false);
        $this->expectException(\RuntimeException::class);
        (new DatasetVersionService($pool))->bumpVersion();
    }
    public function testSuccessfulVersionIsVisibleAndUnique(): void
    {
        $version = new DatasetVersionService(new ArrayAdapter());
        $a = $version->bumpVersion();
        self::assertSame($a, $version->getVersion());
        self::assertNotSame($a, $version->bumpVersion());
    }
}
