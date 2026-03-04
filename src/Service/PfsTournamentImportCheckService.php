<?php

namespace App\Service;

use App\PfsTournamentImport\ImportedTournamentRecord;
use App\PfsTournamentImport\PendingTournamentImport;
use App\PfsTournamentImport\TournamentImportCheckResult;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class PfsTournamentImportCheckService
{
    public function __construct(
        #[Autowire(service: 'doctrine.dbal.mysql_connection')]
        private readonly Connection $connection,
        private readonly PfsTournamentWebsiteClient $websiteClient,
        private readonly PfsTournamentCalendarParser $calendarParser,
        private readonly PfsTournamentResultsParser $resultsParser,
        private readonly PfsTournamentImportMatcher $matcher,
    ) {
    }

    public function check(int $year, ?\DateTimeImmutable $today = null): TournamentImportCheckResult
    {
        $today ??= new \DateTimeImmutable('today');

        $calendarHtml = $this->websiteClient->fetchCalendarHtml($year);
        $calendarTournaments = $this->calendarParser->parse($calendarHtml, $year);
        $importedTournaments = $this->fetchImportedTournaments($year);
        $pendingReferences = $this->matcher->matchPendingImports($calendarTournaments, $importedTournaments, $today);

        $pendingImports = [];
        foreach ($pendingReferences as $pendingReference) {
            $calendarTournament = $pendingReference['calendarTournament'];
            $tournamentHtml = $this->websiteClient->fetchTournamentHtml($calendarTournament->urlId);

            $pendingImports[] = new PendingTournamentImport(
                inferredId: $pendingReference['inferredId'],
                urlId: $calendarTournament->urlId,
                name: $calendarTournament->name,
                endDate: $calendarTournament->endDate,
                results: $this->resultsParser->parse($tournamentHtml),
            );
        }

        return new TournamentImportCheckResult(
            year: $year,
            latestImportedTournamentId: $this->fetchLatestImportedTournamentId(),
            pendingImports: $pendingImports,
        );
    }

    /**
     * @return list<ImportedTournamentRecord>
     */
    private function fetchImportedTournaments(int $year): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, urlid FROM PFSTOURS WHERE dt BETWEEN :from AND :to ORDER BY dt ASC, id ASC',
            [
                'from' => (int) sprintf('%d0101', $year),
                'to' => (int) sprintf('%d1231', $year),
            ],
        );

        return array_map(
            static fn (array $row): ImportedTournamentRecord => new ImportedTournamentRecord(
                id: (int) $row['id'],
                urlId: $row['urlid'] !== null ? (int) $row['urlid'] : null,
            ),
            $rows,
        );
    }

    private function fetchLatestImportedTournamentId(): ?int
    {
        $value = $this->connection->fetchOne('SELECT id FROM PFSTOURS ORDER BY dt DESC, id DESC LIMIT 1');
        if ($value === false || $value === null) {
            return null;
        }

        return (int) $value;
    }
}
