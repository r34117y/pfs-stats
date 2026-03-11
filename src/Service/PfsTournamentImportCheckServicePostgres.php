<?php

namespace App\Service;

use App\PfsTournamentImport\ImportedTournamentRecord;
use App\PfsTournamentImport\PendingTournamentImport;
use App\PfsTournamentImport\TournamentImportCheckResult;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class PfsTournamentImportCheckServicePostgres implements PfsTournamentImportCheckServiceInterface
{
    private const string ORGANIZATION_CODE = 'PFS';

    public function __construct(
        #[Autowire(service: 'doctrine.dbal.default_connection')]
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
        $organizationId = $this->fetchOrganizationId();
        if ($organizationId === null) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT legacy_id AS id, urlid
             FROM tournament
             WHERE organization_id = :organizationId
               AND dt BETWEEN :from AND :to
               AND legacy_id IS NOT NULL
             ORDER BY dt ASC, legacy_id ASC',
            [
                'organizationId' => $organizationId,
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
        $organizationId = $this->fetchOrganizationId();
        if ($organizationId === null) {
            return null;
        }

        $value = $this->connection->fetchOne(
            'SELECT legacy_id
             FROM tournament
             WHERE organization_id = :organizationId
               AND legacy_id IS NOT NULL
             ORDER BY dt DESC, legacy_id DESC
             LIMIT 1',
            ['organizationId' => $organizationId],
        );
        if ($value === false || $value === null) {
            return null;
        }

        return (int) $value;
    }

    private function fetchOrganizationId(): ?int
    {
        $value = $this->connection->fetchOne(
            'SELECT id FROM organization WHERE code = :code LIMIT 1',
            ['code' => self::ORGANIZATION_CODE],
        );

        if ($value === false || $value === null) {
            return null;
        }

        return (int) $value;
    }
}
