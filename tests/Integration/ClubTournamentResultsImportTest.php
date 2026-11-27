<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use ApiPlatform\Metadata\Post;
use App\Command\ImportClubTournamentResultsCommand;
use App\Entity\Player;
use App\Entity\User;
use App\Service\ClubTournamentExistingTournamentFinder;
use App\Service\ClubTournamentResultsFileDecoder;
use App\Service\ClubTournamentResultsImportService;
use App\Service\ClubTournamentResultsParser;
use App\Service\ClubTournamentStandingsBuilder;
use App\Service\PfsNameNormalizer;
use App\Service\DatasetVersionService;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use App\Service\PlayerCatalogService;
use App\Service\UserOrganizationAdminChecker;
use App\State\Processor\ImportClubTournamentResultsProcessor;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final class ClubTournamentResultsImportTest extends PfsImportDatabaseTestCase
{
    private ?DatasetVersionService $datasetVersion = null;

    private function datasetVersion(): DatasetVersionService
    {
        return $this->datasetVersion ??= new DatasetVersionService(new ArrayAdapter());
    }

    private function fixture(): string
    {
        return dirname(__DIR__, 2).'/blubry_wyniki/17032026_Blubry531_KuczbajHH.txt';
    }

    private function service(): ClubTournamentResultsImportService
    {
        $normalizer = new PfsNameNormalizer();
        return new ClubTournamentResultsImportService($this->db, $normalizer, new ClubTournamentStandingsBuilder(), new ClubTournamentExistingTournamentFinder($this->db), new PlayerCatalogService($this->db, $normalizer));
    }

    private function command(?DatasetVersionService $datasetVersion = null): CommandTester
    {
        return new CommandTester(new ImportClubTournamentResultsCommand(new ClubTournamentResultsFileDecoder(), new ClubTournamentResultsParser(), $this->service(), $datasetVersion ?? $this->datasetVersion()));
    }

    public function testConsoleImportsRealHhAndRejectsDuplicateWithoutWriting(): void
    {
        $tester = $this->command();
        $before = $this->datasetVersion()->getVersion();
        self::assertSame(0, $tester->execute(['path' => $this->fixture(), 'organization-id' => 21]));
        self::assertStringContainsString('database id', $tester->getDisplay());
        $published = $this->datasetVersion()->getVersion();
        self::assertNotSame($before, $published);
        self::assertSame(8, (int) $this->db->fetchOne('SELECT COUNT(*) FROM tournament_result'));
        self::assertSame(37, (int) $this->db->fetchOne('SELECT COUNT(*) FROM tournament_game'));
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM tournament_game WHERE host IS NOT NULL'));
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM tournament_game WHERE player2_id IS NULL AND ranko IS NOT NULL'));
        self::assertSame(1, $tester->execute(['path' => $this->fixture(), 'organization-id' => 21]));
        self::assertStringContainsString('already exists', $tester->getDisplay());
        self::assertSame($published, $this->datasetVersion()->getVersion());
        self::assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM tournament'));
        self::assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM text_resource'));
    }

    public function testReusesGlobalPlayerAndLinksOrganization(): void
    {
        $this->db->insert('player', ['id' => 400, 'name_show' => 'Przemysław Kuczyński', 'name_alph' => 'Kuczyński Przemysław']);
        $parsed = (new ClubTournamentResultsParser())->parse((new ClubTournamentResultsFileDecoder())->decode(file_get_contents($this->fixture())), (new ClubTournamentResultsFileDecoder())->decode(file_get_contents(str_replace('HH.txt', '.txt', $this->fixture()))));
        $result = $this->service()->import($parsed, 21);
        self::assertSame([400], $result->linkedPlayerIds);
        self::assertCount(7, $result->createdPlayerIds);
        self::assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM player WHERE name_show = ?', ['Przemysław Kuczyński']));
        self::assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM player_organization WHERE player_id = 400 AND organization_id = 21'));
    }

    public function testAmbiguousByeUpdatesStandingsAndAuditWithoutInventingRound(): void
    {
        $path = dirname(__DIR__).'/Fixtures/club_tournament_results/04082026_Blubry550_MagdynoHH.txt';
        $decoder = new ClubTournamentResultsFileDecoder();
        $parsed = (new ClubTournamentResultsParser())->parse(
            $decoder->decode(file_get_contents($path)),
            $decoder->decode(file_get_contents(str_replace('HH.txt', '.txt', $path))),
        );
        $result = $this->service()->import($parsed, 21);
        $standing = $this->db->fetchAssociative("SELECT tr.* FROM tournament_result tr JOIN player p ON p.id=tr.player_id WHERE p.name_show='Sylwia Nowak'");
        self::assertSame(3, $standing['games']);
        self::assertSame(1, $standing['gdraw']);
        self::assertEquals(203.333, $standing['points']);
        self::assertSame(2, (int) $this->db->fetchOne('SELECT COUNT(*) FROM tournament_game WHERE player1_id=?', [$standing['player_id']]));
        $audit = json_decode($this->db->fetchOne('SELECT data FROM text_resource'), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame([['playerId' => $standing['player_id'], 'count' => 1]], $audit['byesWithoutKnownRound']);
        self::assertSame($result->tournamentId, $audit['tournamentDbId']);
    }

    public function testConsoleRejectsMissingFileAndInvalidOrganizationWithoutWriting(): void
    {
        $tester = $this->command();
        self::assertSame(2, $tester->execute(['path' => '/nonexistent/HH.txt', 'organization-id' => 21]));
        self::assertSame(2, $tester->execute(['path' => $this->fixture(), 'organization-id' => 0]));
        self::assertSame(1, $tester->execute(['path' => $this->fixture(), 'organization-id' => 999]));
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM tournament'));
    }

    public function testFailedImportRollsBackPlayersTournamentAndAudit(): void
    {
        $raw = (new ClubTournamentResultsFileDecoder())->decode(file_get_contents($this->fixture()));
        $raw = preg_replace('/^(\s*1\s+\d+\s+)\d+(\s+\p{L})/mu', '${1}999${2}', $raw, 1);
        $parsed = (new ClubTournamentResultsParser())->parse($raw, (new ClubTournamentResultsFileDecoder())->decode(file_get_contents(str_replace('HH.txt', '.txt', $this->fixture()))));
        try {
            $this->service()->import($parsed, 21);
            self::fail('Expected missing opponent to fail.');
        } catch (\Symfony\Component\HttpKernel\Exception\BadRequestHttpException $exception) {
            self::assertStringContainsString('resolve opponent', $exception->getMessage());
        }
        foreach (['player', 'player_organization', 'tournament', 'tournament_game', 'tournament_result', 'text_resource'] as $table) {
            self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM '.$table), $table);
        }
    }

    private function processor(Request $request, bool $admin = true, ?DatasetVersionService $datasetVersion = null): ImportClubTournamentResultsProcessor
    {
        $this->db->executeStatement("INSERT INTO player (id, name_show) VALUES (500, 'Import Admin') ON CONFLICT DO NOTHING");
        $this->db->executeStatement('INSERT INTO player_organization (player_id, organization_id, is_admin) VALUES (500, 21, ?) ON CONFLICT (player_id, organization_id) DO UPDATE SET is_admin = EXCLUDED.is_admin', [$admin], [\Doctrine\DBAL\ParameterType::BOOLEAN]);
        $player = new Player();
        (new \ReflectionProperty(Player::class, 'id'))->setValue($player, 500);
        $user = (new User())->setEmail('club-test@example.invalid')->setPlayer($player);
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);
        $stack = new RequestStack();
        $stack->push($request);
        return new ImportClubTournamentResultsProcessor($security, $stack, new UserOrganizationAdminChecker($this->db), new ClubTournamentResultsFileDecoder(), new ClubTournamentResultsParser(), $this->service(), $datasetVersion ?? $this->datasetVersion());
    }

    private function upload(): Request
    {
        return new Request([], ['organizationId' => '21'], [], [], ['results' => new UploadedFile($this->fixture(), 'resultsHH.txt', 'text/plain', null, true), 'standings' => new UploadedFile(str_replace('HH.txt', '.txt', $this->fixture()), 'results.txt', 'text/plain', null, true)]);
    }

    public function testMultipartImportReturnsDatabaseIdAndDuplicateConflict(): void
    {
        $before = $this->datasetVersion()->getVersion();
        $response = $this->processor($this->upload())->process(null, new Post());
        self::assertNotSame($before, $this->datasetVersion()->getVersion());
        self::assertSame(200, $response->getStatusCode());
        $payload = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertGreaterThan(0, $payload['tournamentId']);
        self::assertSame(8, $payload['playersCount']);
        self::assertSame(19, $payload['gamesCount']);
        self::assertArrayNotHasKey('legacyTournamentId', $payload);
        self::assertSame(409, $this->processor($this->upload())->process(null, new Post())->getStatusCode());
        self::assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM tournament'));
    }

    public function testMultipartRequiresFileAndPositiveOrganization(): void
    {
        self::assertSame(400, $this->processor(new Request([], ['organizationId' => '21']))->process(null, new Post())->getStatusCode());
        self::assertSame(400, $this->processor(new Request())->process(null, new Post())->getStatusCode());
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM tournament'));
    }

    public function testMultipartRejectsUnpairedHhWithoutWriting(): void
    {
        $request = $this->upload();
        $request->files->remove('standings');
        $response = $this->processor($request)->process(null, new Post());
        self::assertSame(400, $response->getStatusCode());
        self::assertStringContainsString('PAUZA', $response->getContent());
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM tournament'));
    }

    public function testConsoleReportsCommittedImportWhenCachePublicationFails(): void
    {
        $pool = $this->createStub(\Psr\Cache\CacheItemPoolInterface::class);
        $pool->method('getItem')->willReturn(new \Symfony\Component\Cache\CacheItem());
        $pool->method('save')->willReturn(false);
        $version = new DatasetVersionService($pool);
        $tester = $this->command($version);
        self::assertSame(0, $tester->execute(['path' => $this->fixture(), 'organization-id' => 21]));
        self::assertStringContainsString('is saved', $tester->getDisplay());
        self::assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM tournament'));
    }

    public function testMultipartReportsCommittedImportWhenCachePublicationFails(): void
    {
        $pool = $this->createStub(\Psr\Cache\CacheItemPoolInterface::class);
        $pool->method('getItem')->willReturn(new \Symfony\Component\Cache\CacheItem());
        $pool->method('save')->willReturn(false);
        $version = new DatasetVersionService($pool);
        $response = $this->processor($this->upload(), true, $version)->process(null, new Post());
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Turniej jest zapisany', $response->getContent());
        self::assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM tournament'));
    }

    public function testNonAdminCannotImport(): void
    {
        $this->expectException(AccessDeniedHttpException::class);
        $this->processor($this->upload(), false)->process(null, new Post());
    }
}
