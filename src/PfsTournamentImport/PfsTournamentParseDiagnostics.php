<?php

declare (strict_types=1);
namespace App\PfsTournamentImport;

final readonly class PfsTournamentParseDiagnostics
{
    /** @param array<string, list<array{line:int,text:string,kind:string}>> $sections */
    public function __construct(public array $sections)
    {
    }
    public function assertComplete(): void
    {
        foreach ($this->sections as $section => $lines) {
            foreach ($lines as $line) {
                if ($line['kind'] === 'unknown') {
                    throw new \RuntimeException(sprintf('Unrecognized source row %s:%d: %s', $section, $line['line'], $line['text']));
                }
            }
        }
    }
}
