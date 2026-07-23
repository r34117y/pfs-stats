<?php

declare(strict_types=1);

namespace App\Tests\Api;

final class DevDataLookupTest extends ApiEndpointTestCase
{
    public function testResolvesRepresentativeOrganizationId(): void
    {
        self::createApiClient();

        $organizationId = self::devData()->findOrganizationId();
        self::skipIfMissing($organizationId, 'No organization exists in the dev database.');

        self::assertGreaterThan(0, $organizationId);
    }

    public function testResolvesRepresentativePlayerSlug(): void
    {
        self::createApiClient();

        $player = self::devData()->findPlayer();
        self::skipIfMissing($player, 'No player with a slug exists in the dev database.');

        self::assertGreaterThan(0, $player['id']);
        self::assertNotSame('', $player['slug']);
    }

    public function testResolvesRepresentativeTournamentId(): void
    {
        self::createApiClient();

        $tournamentId = self::devData()->findTournamentId();
        self::skipIfMissing($tournamentId, 'No tournament with results exists in the dev database.');

        self::assertGreaterThan(0, $tournamentId);
    }

    public function testResolvesRepresentativeTournamentPlayerPair(): void
    {
        self::createApiClient();

        $pair = self::devData()->findTournamentPlayer();
        self::skipIfMissing($pair, 'No tournament/player result pair exists in the dev database.');

        self::assertGreaterThan(0, $pair['tournamentId']);
        self::assertGreaterThan(0, $pair['playerId']);
        self::assertNotSame('', $pair['playerSlug']);
    }

    public function testResolvesRepresentativeAnnotatedGameId(): void
    {
        self::createApiClient();

        $annotatedGameId = self::devData()->findAnnotatedGameId();
        self::skipIfMissing($annotatedGameId, 'No annotated game exists in the dev database.');

        self::assertMatchesRegularExpression('/^\d+-\d+-\d+$/', $annotatedGameId);
    }

    public function testResolvesRepresentativeOrganizationAdminUser(): void
    {
        self::createApiClient();

        $adminUser = self::devData()->findOrganizationAdminUser();
        self::skipIfMissing($adminUser, 'No organization-admin user exists in the dev database.');

        self::assertGreaterThan(0, $adminUser['id']);
        self::assertNotSame('', $adminUser['email']);
        self::assertGreaterThan(0, $adminUser['playerId']);
        self::assertGreaterThan(0, $adminUser['organizationId']);
    }

    private static function skipIfMissing(mixed $value, string $message): void
    {
        if ($value === null) {
            self::markTestSkipped($message);
        }
    }
}
