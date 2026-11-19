<?php

declare (strict_types=1);
namespace App\Tests\Service;

use App\Service\PfsImportVerification\HistoryManifestVerifier;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
final class PfsImportHistoryManifestVerifierTest extends TestCase
{
    public function testBaselineChangeInvalidatesManifest(): void
    {
        $c = $this->createStub(Connection::class);
        $c->method('fetchOne')->willReturn('new-fingerprint');
        $v = new HistoryManifestVerifier($c);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('historical data changed');
        $v->assertBaseline(['formatVersion' => 1, 'organizationId' => 21, 'baselineDate' => 20260718, 'baselineId' => 1, 'baselineFingerprint' => array_fill_keys(['tournament', 'tournament_result', 'tournament_game', 'ranking'], 'old-fingerprint')], 21);
    }
    public function testExpectedAppendDoesNotChangeBaselineEvidence(): void
    {
        $c = $this->createMock(Connection::class);
        $c->expects(self::exactly(4))->method('fetchOne')->with(self::stringContains('(t.dt,t.id) <= (:dt,:id)'), ['org' => 21, 'dt' => 20260718, 'id' => 1])->willReturn('unchanged');
        $v = new HistoryManifestVerifier($c);
        $v->assertBaseline(['formatVersion' => 1, 'organizationId' => 21, 'baselineDate' => 20260718, 'baselineId' => 1, 'baselineFingerprint' => array_fill_keys(['tournament', 'tournament_result', 'tournament_game', 'ranking'], 'unchanged')], 21);
        self::assertTrue(true);
    }
}
