<?php

namespace App\Service;

use App\PfsTournamentImport\PfsPlayerImportRow;
use App\PfsTournamentImport\PfsTourHhImportRow;
use App\PfsTournamentImport\PfsTournamentImportPlan;
use App\PfsTournamentImport\PfsTourImportRow;
use App\PfsTournamentImport\PfsTourWynImportRow;

final readonly class PfsTournamentImportSqlRenderer
{
    public function render(PfsTournamentImportPlan $plan): string
    {
        $lines = [];

        foreach ($plan->warnings as $warning) {
            $lines[] = '-- WARNING: ' . $warning;
        }

        foreach ($plan->newPlayers as $row) {
            $lines[] = $this->renderPlayerInsert($row);
        }

        $lines[] = $this->renderTournamentInsert($plan->tournament);

        foreach ($plan->tournamentResults as $row) {
            $lines[] = $this->renderTournamentResultsInsert($row);
        }

        foreach ($plan->tournamentGames as $row) {
            $lines[] = $this->renderTournamentGamesInsert($row);
        }

        return implode("\n", $lines);
    }

    private function renderPlayerInsert(PfsPlayerImportRow $row): string
    {
        return sprintf(
            "INSERT INTO PFSPLAYER (id, name_show, name_alph, utype, cached) VALUES (%d, %s, %s, %s, %s);",
            $row->id,
            $this->quote($row->nameShow),
            $this->quote($row->nameAlph),
            $this->quote($row->utype),
            $this->quote($row->cached),
        );
    }

    private function renderTournamentInsert(PfsTourImportRow $row): string
    {
        return sprintf(
            "INSERT INTO PFSTOURS (id, dt, name, fullname, winner, trank, players, rounds, rrecreated, team, mcategory, wksum, sertour, start, referee, place, organizer, urlid) VALUES (%d, %d, %s, %s, %d, %.3F, %d, %d, %s, %s, %d, %.1F, %d, %d, %s, %s, %s, %d);",
            $row->id,
            $row->dt,
            $this->quote($row->name),
            $this->quote($row->fullname),
            $row->winner,
            $row->trank,
            $row->players,
            $row->rounds,
            $this->quote($row->rrecreated),
            $this->quote($row->team),
            $row->mcategory,
            $row->wksum,
            $row->sertour,
            $row->start,
            $this->quoteNullable($row->referee),
            $this->quoteNullable($row->place),
            $this->quoteNullable($row->organizer),
            $row->urlid,
        );
    }

    private function renderTournamentResultsInsert(PfsTourWynImportRow $row): string
    {
        return sprintf(
            "INSERT INTO PFSTOURWYN (turniej, player, place, gwin, glost, gdraw, games, trank, brank, points, pointo, hostgames, hostwin, masters) VALUES (%d, %d, %d, %d, %d, %d, %d, %.3F, %.2F, %.3F, %.3F, %d, %d, %d);",
            $row->turniej,
            $row->player,
            $row->place,
            $row->gwin,
            $row->glost,
            $row->gdraw,
            $row->games,
            $row->trank,
            $row->brank,
            $row->points,
            $row->pointo,
            $row->hostgames,
            $row->hostwin,
            $row->masters,
        );
    }

    private function renderTournamentGamesInsert(PfsTourHhImportRow $row): string
    {
        return sprintf(
            "INSERT INTO PFSTOURHH (turniej, runda, stol, player1, player2, result1, result2, ranko, host) VALUES (%d, %d, %d, %d, %d, %d, %d, %d, %d);",
            $row->turniej,
            $row->runda,
            $row->stol,
            $row->player1,
            $row->player2,
            $row->result1,
            $row->result2,
            $row->ranko,
            $row->host,
        );
    }

    private function quote(string $value): string
    {
        return "'" . str_replace("'", "''", $value) . "'";
    }

    private function quoteNullable(?string $value): string
    {
        return $value === null ? 'NULL' : $this->quote($value);
    }
}
