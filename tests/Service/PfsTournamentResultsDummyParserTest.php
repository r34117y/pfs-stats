<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\PfsTournamentResultsDummyParser;
use PHPUnit\Framework\TestCase;

final class PfsTournamentResultsDummyParserTest extends TestCase
{
    public function testExtractsRawTextFromHhSection(): void
    {
        $parser = new PfsTournamentResultsDummyParser();

        $html = <<<'HTML'
<div id='p_info' class='page'>ignored</div>
<div id='p_hh' class='page'><pre>﻿Wyniki&nbsp;gracza

Runda 1
Jan Kowalski</pre></div>
HTML;

        $results = $parser->parseRawResultsText($html);

        self::assertSame("Wyniki gracza\n\nRunda 1\nJan Kowalski", $results);
    }
}
