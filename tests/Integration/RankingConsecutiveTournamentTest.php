<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Service\Ranking\RankingServicePostgres;
use App\Service\RankingSnapshot\RankingSnapshotServicePostgres;
use App\Service\ClubTournamentRankingImportService;
use App\Service\ClubTournamentRankingParser;
use App\ClubTournamentImport\ClubTournamentRankingSnapshot;

final class RankingConsecutiveTournamentTest extends PfsImportDatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->db->insert('player', ['id'=>10,'name_show'=>'Jakub Błażejewski','name_alph'=>'Błażejewski Jakub','slug'=>'jakub-blazejewski']);
        $this->db->insert('player_organization', ['player_id'=>10,'organization_id'=>21]);
        foreach ([101=>[20260922,'Dolente'],102=>[20261110,'Amsonia'],103=>[20261117,'Sanidyn']] as $id=>[$date,$name]) {
            $this->db->insert('tournament', ['id'=>$id,'organization_id'=>21,'dt'=>$date,'name'=>$name,'fullname'=>$name]);
        }
    }

    private function rank(int $tournament, float $rank): void
    {
        $this->db->insert('ranking',['organization_id'=>21,'tournament_id'=>$tournament,'player_id'=>10,'rtype'=>'f','position'=>1,'rank'=>$rank,'games'=>60]);
    }

    private function service(): RankingServicePostgres
    {
        return new RankingServicePostgres($this->db, new RankingSnapshotServicePostgres($this->db));
    }

    public function testMissingPreviousSnapshotDoesNotAccumulateChangesSinceSeptember(): void
    {
        $this->rank(101,155.28);$this->rank(103,163.70);
        $result=$this->service()->getRanking(21);
        self::assertSame(102,$result->comparisonTournamentId);
        self::assertSame('Amsonia',$result->comparisonTournamentName);
        self::assertFalse($result->comparisonAvailable);
        self::assertNull($result->rows[0]->rankDelta);
        self::assertNull($result->rows[0]->positionDelta);
    }

    public function testIdenticalPreviousRankingIsNotSkipped(): void
    {
        $this->rank(101,155.28);$this->rank(102,163.70);$this->rank(103,163.70);
        $result=$this->service()->getRanking(21);
        self::assertTrue($result->comparisonAvailable);
        self::assertSame(102,$result->previousTournamentId);
        self::assertSame('0.00',$result->rows[0]->rankDelta);
        self::assertSame(0,$result->rows[0]->positionDelta);
    }

    public function testSourceValuesWithUnknownGamesProvideCorrectImmediateComparison(): void
    {
        $this->rank(101,155.28);$this->rank(103,163.70);
        $html='<html><body><h2><a href="ktour.php?t=202611100">Amsonia</a></h2><table><tr><td>1<td><a href="zindex.php?p=6">Jakub Błażejewski</a><td>161.00<td>0<tr id="sum"><td>-</table></body></html>';
        $parser=new ClubTournamentRankingParser();
        $snapshot=new ClubTournamentRankingSnapshot(20261110,'https://example.invalid/source',$html,$parser->parse($html,20261110,true));
        $importer=new ClubTournamentRankingImportService($this->db,$parser);
        self::assertSame(1,$importer->store($snapshot,21,102));
        self::assertSame(0,$importer->store($snapshot,21,102));
        self::assertSame(2,(int)$this->db->fetchOne('SELECT COUNT(*) FROM ranking'));
        $result=$this->service()->getRanking(21);
        self::assertSame(102,$result->previousTournamentId);
        self::assertSame(102,$result->comparisonTournamentId);
        self::assertTrue($result->comparisonAvailable);
        self::assertSame('2.70',$result->rows[0]->rankDelta);
        $previous=$this->service()->getRanking(21,'f',102);
        self::assertFalse($previous->gameCountsAvailable);
        self::assertNull($previous->rows[0]->numberOfGames);
        self::assertSame('161.00',$previous->rows[0]->rank);
        self::assertSame(103,$previous->nextTournamentId);
    }

    public function testLatestValuesAreVisibleEvenWhenGameCountsAreUnknown(): void
    {
        $this->rank(101,155.28);
        $snapshot=new ClubTournamentRankingSnapshot(20261117,'https://example.invalid/source','source', [['position'=>1,'name'=>'Jakub Błażejewski','rank'=>163.70,'games'=>null]]);
        (new ClubTournamentRankingImportService($this->db,new ClubTournamentRankingParser()))->store($snapshot,21,103);
        $result=$this->service()->getRanking(21);
        self::assertSame(103,$result->lastTournamentId);
        self::assertFalse($result->gameCountsAvailable);
        self::assertNull($result->rows[0]->numberOfGames);
        self::assertNull($result->rows[0]->rankDelta);
    }
}
