<?php

namespace App\GcgParser\Mapper;

use App\Domain\Scrabble\Enum\PlayerTypeEnum;
use App\Entity\Postgres\Domain\Scrabble\ScrabbleGame;
use App\Entity\Postgres\Domain\Scrabble\ScrabbleGameEvent;
use App\Entity\Postgres\Domain\Scrabble\ScrabbleGamePlayer;
use App\Entity\Postgres\Domain\Scrabble\ScrabblePlayer;
use App\GcgParser\ParsedGcg\Events\PlayEvent;
use App\GcgParser\ParsedGcg\ParsedGcg;
use App\GcgParser\ParsedGcg\Player;
use App\Repository\Domain\Scrabble\ScrabblePlayerRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Exception;

final readonly class ParsedGcgToGameEntityMapper
{
    public function __construct(
        private ScrabblePlayerRepository $playerRepository,
    ) {
    }

    /**
     * This method does not set game owner.
     * @throws Exception
     */
    public function map(ParsedGcg $parsedGcg): ScrabbleGame
    {
        $game = new ScrabbleGame();

        $meta = $parsedGcg->getMeta();
        $authorityId = $meta->getId();
        if ($authorityId === 'unknown') {
            $authorityId = null;
        }


        $game->setAnnotatedGame($parsedGcg->getGcg());
        $game->setAuthority($meta->getAuthority());
        $game->setAuthorityId($authorityId);
        $game->setDescription($meta->getDescription());
        $game->setHash(hash('sha256', $parsedGcg->getGcg()));
        $game->setLexicon($meta->getLexicon());
        $game->setTileDistribution($meta->getTileDistribution());

        // Assumes that players are in correct order!
        $playerEntities = [];
        foreach ($parsedGcg->getPlayers() as $gcgPlayer) {
            $playerEntities[$gcgPlayer->nick] = $this->resolvePlayer($gcgPlayer);
        }

        $events = [];
        foreach ($parsedGcg->getEvents() as $parsedEvent) {
            $event = new ScrabbleGameEvent();
            $event->setGame($game);
            $event->setPoints($parsedEvent->getScore());
            $event->setTotalPoints($parsedEvent->getTotalScore());
            $event->setRackFromUnsorted($parsedEvent->getRack());
            $event->setType($parsedEvent->getType());
            $event->setPlayer($playerEntities[$parsedEvent->getPlayerNick()]);

            if ($parsedEvent instanceof PlayEvent) {
                $event->setWordsCreated($parsedEvent->getWords());
                $event->setStartField($parsedEvent->getStartField());
            }

            $events[] = $event;
        }
        $game->setEvents(new ArrayCollection($events));

        foreach ($parsedGcg->getPlayers() as $gcgPlayer) {
            $playerEntity = $playerEntities[$gcgPlayer->nick];
            $gamePlayer = new ScrabbleGamePlayer();
            $gamePlayer->setPlayer($playerEntity);
            $gamePlayer->setGame($game);
            $gamePlayer->setPosition($gcgPlayer->number);
            $gamePlayer->setScore($this->resolveFinalScoreFromMoves($playerEntity, $events));
            $game->addPlayer($gamePlayer);
        }

        return $game;
    }

    private function resolvePlayer(Player $player): ScrabblePlayer
    {
        $playerEntity = $this->playerRepository->findOneBy(['name' => $player->nick]);
        if ($playerEntity === null) {
            $playerEntity = new ScrabblePlayer();
            $playerEntity->setName($player->nick);
            $playerEntity->setFullname($player->name);
            $playerEntity->setType($player->name === 'HastyBot' ? PlayerTypeEnum::COMPUTER_HASTY : PlayerTypeEnum::HUMAN);
        }
        return $playerEntity;
    }

    /**
     * @param ScrabbleGameEvent[] $events
     * @throws Exception
     */
    private function resolveFinalScoreFromMoves(ScrabblePlayer $player, array $events): int
    {
        $reversedEvents = array_reverse($events);
        foreach ($reversedEvents as $event) {
            if ($event->getPlayer() === $player) {
                return $event->getTotalPoints();
            }
        }
        throw new Exception('Cannot resolve final score');
    }
}
