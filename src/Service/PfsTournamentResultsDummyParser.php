<?php

namespace App\Service;

final readonly class PfsTournamentResultsDummyParser
{
    public function parseRawResultsText(string $html): string
    {
        if (!preg_match("~<div id=['\"]p_hh['\"][^>]*>\\s*<pre>(.*?)</pre>\\s*</div>~su", $html, $match)) {
            throw new \RuntimeException('Could not find the #hh results section in tournament HTML.');
        }

        $text = html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace("\u{FEFF}", '', $text);
        $text = str_replace("\u{00A0}", ' ', $text);

        return trim($text);
    }
}
