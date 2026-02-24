<?php

namespace App\GcgParser;

use App\GcgParser\Exception\InvalidGcgEventException;
use App\GcgParser\ParsedGcg\Events\EndgameEvent;
use App\GcgParser\ParsedGcg\Events\EventInterface;
use App\GcgParser\ParsedGcg\Events\ExchangeEvent;
use App\GcgParser\ParsedGcg\Events\PassEvent;
use App\GcgParser\ParsedGcg\Events\PlayEvent;
use App\GcgParser\ParsedGcg\Events\WithdrawalEvent;
use App\GcgParser\ParsedGcg\MetaInfo;
use App\GcgParser\ParsedGcg\ParsedGcg;
use App\GcgParser\ParsedGcg\Player;

class GcgParser
{
    private const string SIGNED_SCORE_PATTERN = '([+\-](?:\d+|-\d+))';

    /**
     * @throws InvalidGcgEventException
     */
    public function parse(string $gcgContent): ParsedGcg
    {
        $lines = preg_split('/\r?\n/', $gcgContent);
        $parsed = new ParsedGcg();
        $parsed->setGcg($gcgContent);
        $parsed->setMeta(new MetaInfo());
        $pendingNote = null;
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === ';') continue;
            if ($line[0] === '#') {
                $pendingNote = $this->parsePragma($line, $parsed, $pendingNote);
            } elseif ($line[0] === '>') {
                $event = $this->parseEvent($line, $parsed);
                if ($pendingNote) {
                    $event->setNote($pendingNote);
                    $pendingNote = null;
                }
                $parsed->addEvent($event);
            }
        }
        return $parsed;
    }

    private function parsePragma(string $line, ParsedGcg $parsed, $pendingNote)
    {
        if (preg_match('/^#player([12])\s+(\S+)\s+(.+)$/', $line, $m)) {
            $player = new Player($m[2], $m[3], (int)$m[1]);
            $parsed->addPlayer($player);
        } elseif (preg_match('/^#description\s+(.+)$/', $line, $m)) {
            $parsed->getMeta()->setDescription($m[1]);
        } elseif (preg_match('/^#id\s+(.+)$/', $line, $m)) {
            $parts = explode(' ', $m[1]);
            $parsed->getMeta()->setAuthority($parts[0]);
            $parsed->getMeta()->setId($parts[1]);
        } elseif (preg_match('/^#lexicon\s+(.+)$/', $line, $m)) {
            $parsed->getMeta()->setLexicon($m[1]);
        } elseif (preg_match('/^#tile-distribution\s+(.+)$/', $line, $m)) {
            $parsed->getMeta()->setTileDistribution($m[1]);
        } elseif (preg_match('/^#character-encoding\s+(.+)$/', $line, $m)) {
            $parsed->getMeta()->setCharacterEncoding($m[1]);
        } elseif (preg_match('/^#note\s+(.+)$/', $line, $m)) {
            return $m[1]; // Attach to next move
        } else {
            if (preg_match('/^#(\w+)\s+(.+)$/', $line, $m)) {
                $parsed->getMeta()->addOther($m[2]);
            }
        }
        return $pendingNote;
    }

    /**
     * @throws InvalidGcgEventException
     */
    private function parseEvent(string $line, ParsedGcg $parsed): EventInterface
    {
        // Only match uppercase letters (and Polish letters, underscore, and ?) as the rack
        if (preg_match('/^>([^:]+):\s*([\p{L}?_]*)\s*(.+)$/u', $line, $m)) {
            $player = $m[1];
            $rack = $m[2];
            $rest = $m[3];
            // Regular play: pos word +score cumulative
            if (preg_match('/^([A-Za-z0-9]+)\s+([\p{L}?_.]+)\s*' . self::SIGNED_SCORE_PATTERN . '\s+([0-9]+)\s*((\/[\p{L}]+)+)?$/u', $rest, $mm)) {
                $words = [$mm[2]];
                // other words are concept existing only in Kurnik's GCG
                // not really GCG specification, but pretty useful extension
                // it can be allways assumed that the first word in $words is the GCG main word
                if ($mm[5] ?? null) {
                    $otherWords = explode('/', $mm[5]);
                    $otherWords = array_filter($otherWords);
                    $words = array_merge($words, $otherWords);
                }
                return new PlayEvent(
                    $player,
                    $rack,
                    $this->parseSignedScore($mm[3]),
                    (int)$mm[4],
                    $mm[1],
                    $words
                );
            }
            // Pass: - +0 cumulative
            if (preg_match('/^-\s*' . self::SIGNED_SCORE_PATTERN . '\s+([0-9]+)$/', $rest, $mm)) {
                return new PassEvent(
                    $player,
                    $rack,
                    $this->parseSignedScore($mm[1]),
                    (int)$mm[2],
                    'todo'
                );
            }
            // Exchange: -TILES +0 cumulative
            // Per GCG spec, exchanged tiles may be unknown and represented as a count (1-7), e.g. "-4".
            if (preg_match('/^-(?:([\p{L}?_]+)|([1-7]))\s*' . self::SIGNED_SCORE_PATTERN . '\s+([0-9]+)$/u', $rest, $mm)) {
                $exchanged = (string) ($mm[1] !== '' ? $mm[1] : $mm[2]);
                return new ExchangeEvent(
                    $player,
                    $rack,
                    $this->parseSignedScore($mm[3]),
                    (int)$mm[4],
                    $exchanged,
                );
            }
            // Withdrawal: -- -score cumulative
            if (preg_match('/^--\s*' . self::SIGNED_SCORE_PATTERN . '\s+([0-9]+)$/', $rest, $mm)) {
                return new WithdrawalEvent(
                    $player,
                    $rack,
                    $this->parseSignedScore($mm[1]),
                    (int)$mm[2],
                );
            }
            // Challenge: (challenge) +score cumulative
            if (preg_match('/^\(challenge\)\s*' . self::SIGNED_SCORE_PATTERN . '\s+([0-9]+)$/', $rest, $mm)) {
                throw new \Exception('Challenge event not implemented');
            }
            // Points for last rack: (TILES) +score cumulative (allow empty rack and whitespace)
            if (preg_match('/^\( *([\p{L}?_]+) *\)\s*' . self::SIGNED_SCORE_PATTERN . '\s+([0-9]+)$/u', $rest, $mm)) {
                return new EndgameEvent(
                    $player,
                    $rack,
                    $this->parseSignedScore($mm[2]),
                    (int)$mm[3],
                );
            }
            // Time penalty: (time) -score cumulative
            if (preg_match('/^\(time\)\s*' . self::SIGNED_SCORE_PATTERN . '\s+([0-9]+)$/', $rest, $mm)) {
                throw new \Exception('Time penalty not implemented');
            }
        }

        throw new InvalidGcgEventException(sprintf('Invalid event: %s', $line));
    } 

    private function parseSignedScore(string $scoreToken): int
    {
        if (str_starts_with($scoreToken, '+-')) {
            return -((int) substr($scoreToken, 2));
        }

        return (int) $scoreToken;
    }
} 
