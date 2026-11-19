<?php

declare (strict_types=1);
namespace App\PfsTournamentImport;

enum PfsTournamentImportOutcome : string
{
    case Imported = 'imported';
    case Unchanged = 'unchanged';
    case Blocked = 'blocked';
    case Failed = 'failed';
    case CommittedCacheError = 'committed-cache-error';
}
