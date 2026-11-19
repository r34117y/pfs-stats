<?php

declare (strict_types=1);
namespace App\Service\PfsImportVerification;

use App\PfsTournamentImport\PfsTournamentSource;
final readonly class RankingComparison
{
    public function __construct(private ImportedTournamentComparator $comparator)
    {
    }
    public function compare(PfsTournamentSource $source, int $org, int $id): array
    {
        return $this->comparator->compare($source, $org, $id);
    }
}
