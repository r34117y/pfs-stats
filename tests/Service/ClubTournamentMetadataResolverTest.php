<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\ClubTournamentImport\ParsedClubTournamentResults;
use App\Service\ClubTournamentMetadataResolver;
use PHPUnit\Framework\TestCase;

final class ClubTournamentMetadataResolverTest extends TestCase
{
    public function testDirectoryOverridesConflictingDateAndTournamentNumber(): void
    {
        $source=new ParsedClubTournamentResults('Blubry549_Klymenia',new \DateTimeImmutable('2026-05-26'),[]);
        $result=(new ClubTournamentMetadataResolver())->apply($source,'2505.2026_Blubry540_Klymenia');
        self::assertSame('Blubry540_Klymenia',$result->name);
        self::assertSame('2026-05-25',$result->date->format('Y-m-d'));
        self::assertSame('2505.2026_Blubry540_Klymenia',$result->sourceDirectory);
    }

    public function testPathUsesParentDirectoryAndDoesNotGuessFromConflictingFilename(): void
    {
        $resolver=new ClubTournamentMetadataResolver();
        self::assertSame('21042026_Blubry535_GP8_Regosol',$resolver->directoryFromPath('/inputs/21042026_Blubry535_GP8_Regosol/21042026_Blubry536_GP8_RegosolHH.txt'));
        self::assertNull($resolver->directoryFromPath('/inputs/21042026_Blubry536_GP8_RegosolHH.txt'));
    }

    public function testInvalidDirectoryDateIsRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        (new ClubTournamentMetadataResolver())->apply(new ParsedClubTournamentResults('Test',new \DateTimeImmutable(),[]),'31022026_Blubry535_Test');
    }

    public function testBrowserCannotSilentlyFallBackToAConflictingFileHeading(): void
    {
        $fixture = dirname(__DIR__).'/Fixtures/club_tournament_results/2505.2026_Blubry540_Klymenia';
        $preprocessor = new \App\Service\ClubTournamentResultsPreprocessor(new \App\Service\ClubTournamentResultsFileDecoder(), new \App\Service\ClubTournamentResultsParser(), new \App\Service\ClubTournamentStandingsBuilder());
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Katalog turnieju');
        $preprocessor->preprocess(file_get_contents($fixture.'HH.txt'), file_get_contents($fixture.'.txt'));
    }

    public function testBrowserPreviewUsesTheSameDirectoryMetadata(): void
    {
        $fixture = dirname(__DIR__).'/Fixtures/club_tournament_results/2505.2026_Blubry540_Klymenia';
        $preprocessor = new \App\Service\ClubTournamentResultsPreprocessor(
            new \App\Service\ClubTournamentResultsFileDecoder(),
            new \App\Service\ClubTournamentResultsParser(),
            new \App\Service\ClubTournamentStandingsBuilder(),
        );
        $preview = $preprocessor->preprocess(file_get_contents($fixture.'HH.txt'), file_get_contents($fixture.'.txt'), '2505.2026_Blubry540_Klymenia');
        self::assertSame('Blubry540_Klymenia', $preview->results->name);
        self::assertSame(20260525, $preview->results->getDateCode());
    }
}
