<?php

namespace App\Ranking\Domain;

final readonly class RankingType
{
    public const string FUCKED = 'f';
    public const string FUCKED_WAITING = 'w';
    public const string NORMAL = 'n';
    public const string NORMAL_WAITING = 'u';
}
