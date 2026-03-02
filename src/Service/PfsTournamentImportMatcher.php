<?php

namespace App\Service;

use App\PfsTournamentImport\CalendarTournament;
use App\PfsTournamentImport\ImportedTournamentRecord;

final readonly class PfsTournamentImportMatcher
{
    /**
     * @param list<CalendarTournament> $calendarTournaments
     * @param list<ImportedTournamentRecord> $importedTournaments
     * @return list<array{calendarTournament: CalendarTournament, inferredId: int}>
     */
    public function matchPendingImports(
        array $calendarTournaments,
        array $importedTournaments,
        \DateTimeImmutable $today,
    ): array {
        $importedUrlIds = [];
        $usedSuffixesByDate = [];

        foreach ($importedTournaments as $importedTournament) {
            if ($importedTournament->urlId !== null) {
                $importedUrlIds[$importedTournament->urlId] = true;
            }

            $usedSuffixesByDate[$importedTournament->getDatePrefix()][] = $importedTournament->getSuffix();
        }

        $todayDate = $today->setTime(0, 0);
        $pendingImports = [];

        foreach ($calendarTournaments as $calendarTournament) {
            if ($calendarTournament->endDate >= $todayDate) {
                continue;
            }

            if (isset($importedUrlIds[$calendarTournament->urlId])) {
                continue;
            }

            $datePrefix = (int) $calendarTournament->endDate->format('Ymd');
            $suffix = $this->findNextFreeSuffix($usedSuffixesByDate[$datePrefix] ?? []);
            $usedSuffixesByDate[$datePrefix][] = $suffix;

            $pendingImports[] = [
                'calendarTournament' => $calendarTournament,
                'inferredId' => ($datePrefix * 10) + $suffix,
            ];
        }

        return $pendingImports;
    }

    /**
     * @param list<int> $usedSuffixes
     */
    private function findNextFreeSuffix(array $usedSuffixes): int
    {
        $suffix = 0;
        while (in_array($suffix, $usedSuffixes, true)) {
            $suffix++;
        }

        return $suffix;
    }
}
