<?php

declare(strict_types=1);

namespace App\Ranking\Domain;

final readonly class Glicko2Service
{
    private const float SCALE = 173.7178;

    public function __construct(
        private float $tau = 0.5,
        private float $rdMax = 350.0,
        private float $epsilon = 0.000001,
        private float $daysPerRatingPeriod = 1.0,
    ) {
    }

    public function applyInactivity(PlayerRatingState $state, int $elapsedDays): PlayerRatingState
    {
        if ($elapsedDays <= 0) {
            return $state;
        }

        $periods = max(1.0, $elapsedDays / max(0.0001, $this->daysPerRatingPeriod));
        $phi = $state->rd / self::SCALE;
        $inflatedPhi = sqrt(($phi * $phi) + ($periods * $state->sigma * $state->sigma));
        $inflatedRd = min($this->rdMax, $inflatedPhi * self::SCALE);

        return new PlayerRatingState(
            $state->rating,
            $inflatedRd,
            $state->sigma,
            $state->lastPlayedAt,
        );
    }

    /**
     * @param list<Glicko2OpponentResult> $results
     */
    public function updateAfterRatingPeriod(PlayerRatingState $state, array $results): PlayerRatingState
    {
        if ($results === []) {
            return $state;
        }

        $mu = ($state->rating - 1500.0) / self::SCALE;
        $phi = $state->rd / self::SCALE;

        $vInv = 0.0;
        $deltaSum = 0.0;

        foreach ($results as $result) {
            $muJ = ($result->rating - 1500.0) / self::SCALE;
            $phiJ = $result->rd / self::SCALE;
            $g = $this->g($phiJ);
            $e = $this->e($mu, $muJ, $phiJ);

            $vInv += ($g * $g) * $e * (1.0 - $e);
            $deltaSum += $g * ($result->score - $e);
        }

        if ($vInv <= 0.0) {
            return $state;
        }

        $v = 1.0 / $vInv;
        $delta = $v * $deltaSum;
        $sigmaPrime = $this->solveSigmaPrime($phi, $state->sigma, $delta, $v);

        $phiStar = sqrt(($phi * $phi) + ($sigmaPrime * $sigmaPrime));
        $phiPrime = 1.0 / sqrt((1.0 / ($phiStar * $phiStar)) + (1.0 / $v));
        $muPrime = $mu + ($phiPrime * $phiPrime * $deltaSum);

        return new PlayerRatingState(
            $muPrime * self::SCALE + 1500.0,
            min($this->rdMax, $phiPrime * self::SCALE),
            $sigmaPrime,
            $state->lastPlayedAt,
        );
    }

    private function g(float $phi): float
    {
        return 1.0 / sqrt(1.0 + (3.0 * $phi * $phi) / (M_PI * M_PI));
    }

    private function e(float $mu, float $muJ, float $phiJ): float
    {
        return 1.0 / (1.0 + exp(-$this->g($phiJ) * ($mu - $muJ)));
    }

    private function solveSigmaPrime(float $phi, float $sigma, float $delta, float $v): float
    {
        $tauSquared = $this->tau * $this->tau;
        $a = log($sigma * $sigma);
        $f = function (float $x) use ($delta, $phi, $v, $a, $tauSquared): float {
            $expX = exp($x);
            $num = $expX * (($delta * $delta) - ($phi * $phi) - $v - $expX);
            $den = 2.0 * (($phi * $phi) + $v + $expX) * (($phi * $phi) + $v + $expX);

            return ($num / $den) - (($x - $a) / $tauSquared);
        };

        $aCurrent = $a;
        if (($delta * $delta) > (($phi * $phi) + $v)) {
            $bCurrent = log(($delta * $delta) - ($phi * $phi) - $v);
        } else {
            $k = 1.0;
            while ($f($a - ($k * $this->tau)) < 0.0) {
                $k += 1.0;
            }
            $bCurrent = $a - ($k * $this->tau);
        }

        $fA = $f($aCurrent);
        $fB = $f($bCurrent);

        while (abs($bCurrent - $aCurrent) > $this->epsilon) {
            $denominator = $fB - $fA;
            if (abs($denominator) < 1e-12) {
                break;
            }
            $c = $aCurrent + (($aCurrent - $bCurrent) * $fA / $denominator);
            $fC = $f($c);

            if (($fC * $fB) < 0.0) {
                $aCurrent = $bCurrent;
                $fA = $fB;
            } else {
                $fA /= 2.0;
            }

            $bCurrent = $c;
            $fB = $fC;
        }

        return exp($aCurrent / 2.0);
    }
}
