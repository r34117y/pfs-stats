<?php

declare(strict_types=1);

namespace App\Service;

use App\ClubTournamentImport\ParsedClubTournamentResults;
use DateTimeImmutable;
use RuntimeException;

final readonly class ClubTournamentMetadataResolver
{
    public function directoryFromPath(string $path): ?string
    {
        $directory = basename(dirname($path));
        return preg_match('/^\d{4}\.?\d{4}_Blubry\d+_/u', $directory) ? $directory : null;
    }

    public function apply(ParsedClubTournamentResults $results, ?string $directory, bool $requireDirectory = false): ParsedClubTournamentResults
    {
        if ($directory === null || trim($directory) === '') {
            if ($requireDirectory && preg_match('/^Blubry\d+/', $results->name)) {
                throw new RuntimeException('Wybierz katalog turnieju Blubry albo podaj jego nazwę w polu Katalog turnieju.');
            }
            return $results;
        }
        $directory = trim($directory);
        if (!preg_match('/^(\d{2})(\d{2})\.?(\d{4})_(Blubry\d+_[^\/\\\\]+)$/u', $directory, $match)) {
            throw new RuntimeException('Nazwa katalogu musi mieć format DDMMYYYY_BlubryXXX_Nazwa.');
        }
        $date = DateTimeImmutable::createFromFormat('!dmY', $match[1].$match[2].$match[3]);
        if (!$date || $date->format('dmY') !== $match[1].$match[2].$match[3]) {
            throw new RuntimeException('Nieprawidłowa data w nazwie katalogu turnieju.');
        }

        return new ParsedClubTournamentResults($match[4], $date, $results->players, $directory);
    }
}
