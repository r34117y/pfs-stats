<?php

declare(strict_types=1);

namespace App\Service;

use App\ClubTournamentImport\ClubTournamentResultsPreview;
use App\ClubTournamentImport\ParsedClubPlayer;

final readonly class ClubTournamentResultsPreprocessor
{
    public function __construct(
        private ClubTournamentResultsFileDecoder $fileDecoder,
        private ClubTournamentResultsParser $parser,
        private ClubTournamentStandingsBuilder $standingsBuilder,
        private ?ClubTournamentMetadataResolver $metadataResolver = null,
    ) {
    }

    public function preprocess(string $raw, ?string $standings = null, ?string $sourceDirectory = null): ClubTournamentResultsPreview
    {
        $decoded = $this->fileDecoder->decode($raw);
        $results = $this->parser->parse($decoded, $standings !== null ? $this->fileDecoder->decode($standings) : null);
        $results = ($this->metadataResolver ?? new ClubTournamentMetadataResolver())->apply($results, $sourceDirectory);

        return new ClubTournamentResultsPreview(
            results: $results,
            standings: $this->standingsBuilder->buildStandings($this->playersByPosition($results->players)),
            hhText: $this->removeHeaderLine($decoded),
        );
    }

    /**
     * @param list<ParsedClubPlayer> $players
     * @return array<int, array{source:ParsedClubPlayer,playerId:int,nameShow:string,nameAlph:string}>
     */
    private function playersByPosition(array $players): array
    {
        $playersByPosition = [];
        foreach ($players as $player) {
            $playersByPosition[$player->position] = [
                'source' => $player,
                'playerId' => $player->position,
                'nameShow' => $player->name,
                'nameAlph' => $player->name,
            ];
        }

        return $playersByPosition;
    }

    private function removeHeaderLine(string $text): string
    {
        $text = str_replace("\u{FEFF}", '', $text);
        $text = preg_replace("/\r\n?/", "\n", $text) ?? $text;
        $lines = preg_split('/\n/u', $text) ?: [];

        foreach ($lines as $index => $line) {
            if (trim($line) === '') {
                continue;
            }

            unset($lines[$index]);
            break;
        }

        return trim(implode("\n", $lines));
    }
}
