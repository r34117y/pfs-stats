<?php

declare (strict_types=1);
namespace App\Tests\Service;

use App\Service\PfsPlayerIdentityResolver;
use PHPUnit\Framework\TestCase;
final class PfsPlayerIdentityResolverTest extends TestCase
{
    public function testChangedCityAndConfirmedAliasKeepIdentity(): void
    {
        $r = new PfsPlayerIdentityResolver();
        $index = $r->index([['playerId' => 1, 'nameShow' => 'Anna Kowalska-Demczyszyn', 'nameAlph' => 'Kowalska-Demczyszyn Anna', 'city' => 'Old City']]);
        self::assertSame(1, $r->resolve($index, 'Anna Demczyszyn', 'New City')['playerId']);
        self::assertNull($r->resolve($index, 'New Person', 'Old City'));
    }
    public function testNormalizationCollisionRequiresConfirmation(): void
    {
        $r = new PfsPlayerIdentityResolver();
        $index = $r->index([['playerId' => 1, 'nameShow' => 'Jan Łęcki'], ['playerId' => 2, 'nameShow' => 'Jan Lecki']]);
        $this->expectException(\LogicException::class);
        $r->resolve($index, 'JAN LECKI');
    }
}
