<?php

declare(strict_types=1);

namespace App\Ranking\Domain;

final readonly class BradleyTerryModel implements SkillModelInterface
{
    public function __construct(
        private float $sigmaPrior = 2.0,
        private int $maxIter = 30,
        private float $tol = 1e-6,
        private float $maxStep = 1.0,
        private int $maxFullCovariancePlayers = 120,
    ) {
    }

    public function fit(array $games, array $gamesCountByPlayer, float $ciLevel): SkillModelResult
    {
        if ($games === [] || $gamesCountByPlayer === []) {
            return new SkillModelResult([], 0, 0, 0, false);
        }

        $playerIds = array_keys($gamesCountByPlayer);
        sort($playerIds, SORT_NUMERIC);
        $playerCount = count($playerIds);

        $playerToIndex = [];
        foreach ($playerIds as $index => $playerId) {
            $playerToIndex[(int) $playerId] = $index;
        }

        $sigma2 = max($this->sigmaPrior * $this->sigmaPrior, 1e-9);
        $skills = array_fill(0, $playerCount, 0.0);
        $iterations = 0;

        for ($iter = 0; $iter < $this->maxIter; $iter++) {
            $iterations = $iter + 1;
            $gradient = array_fill(0, $playerCount, 0.0);
            $hDiag = array_fill(0, $playerCount, 1.0 / $sigma2);

            foreach ($games as $game) {
                $i = $playerToIndex[$game['player1Id']] ?? null;
                $j = $playerToIndex[$game['player2Id']] ?? null;
                if ($i === null || $j === null || $i === $j) {
                    continue;
                }

                $p = $this->sigmoid($skills[$i] - $skills[$j]);
                $y = $this->clamp($game['score1'], 0.0, 1.0);
                $err = $y - $p;
                $w = max($p * (1.0 - $p), 1e-8);

                $gradient[$i] += $err;
                $gradient[$j] -= $err;
                $hDiag[$i] += $w;
                $hDiag[$j] += $w;
            }

            $maxAbsDelta = 0.0;
            for ($i = 0; $i < $playerCount; $i++) {
                $gradient[$i] += -$skills[$i] / $sigma2;
                $delta = $gradient[$i] / max($hDiag[$i], 1e-12);
                $delta = $this->clamp($delta, -$this->maxStep, $this->maxStep);

                $skills[$i] += $delta;
                $maxAbsDelta = max($maxAbsDelta, abs($delta));
            }

            $skills = $this->recenter($skills);

            if ($maxAbsDelta < $this->tol) {
                break;
            }
        }

        $z = $this->zFromCiLevel($ciLevel);
        [$hDiagFinal, $covarianceDiag, $usedFullCovariance] = $this->estimateVarianceDiagonal(
            $games,
            $playerToIndex,
            $skills,
            $sigma2
        );

        $estimates = [];
        foreach ($playerIds as $index => $playerId) {
            $variance = $covarianceDiag[$index] ?? (1.0 / max($hDiagFinal[$index] ?? 1e-12, 1e-12));
            $variance = max($variance, 1e-12);
            $se = sqrt($variance);
            $sHat = $skills[$index];

            $estimates[(int) $playerId] = new SkillEstimate(
                playerId: (int) $playerId,
                sHat: $sHat,
                se: $se,
                ciLow: $sHat - ($z * $se),
                ciHigh: $sHat + ($z * $se),
                ciWidth: 2.0 * $z * $se,
                gamesCount: (int) ($gamesCountByPlayer[(int) $playerId] ?? 0),
            );
        }

        return new SkillModelResult(
            estimatesByPlayer: $estimates,
            playerCount: $playerCount,
            gameCount: count($games),
            iterations: $iterations,
            usedFullCovariance: $usedFullCovariance,
        );
    }

    /**
     * @param list<array{player1Id: int, player2Id: int, score1: float}> $games
     * @param array<int, int> $playerToIndex
     * @param list<float> $skills
     * @return array{0: list<float>, 1: list<float>, 2: bool}
     */
    private function estimateVarianceDiagonal(array $games, array $playerToIndex, array $skills, float $sigma2): array
    {
        $playerCount = count($playerToIndex);
        $hDiag = array_fill(0, $playerCount, 1.0 / $sigma2);

        foreach ($games as $game) {
            $i = $playerToIndex[$game['player1Id']] ?? null;
            $j = $playerToIndex[$game['player2Id']] ?? null;
            if ($i === null || $j === null || $i === $j) {
                continue;
            }

            $p = $this->sigmoid($skills[$i] - $skills[$j]);
            $w = max($p * (1.0 - $p), 1e-8);
            $hDiag[$i] += $w;
            $hDiag[$j] += $w;
        }

        if ($playerCount > $this->maxFullCovariancePlayers) {
            $diag = [];
            for ($i = 0; $i < $playerCount; $i++) {
                $diag[$i] = 1.0 / max($hDiag[$i], 1e-12);
            }

            return [$hDiag, $diag, false];
        }

        $hessian = [];
        for ($row = 0; $row < $playerCount; $row++) {
            $hessian[$row] = array_fill(0, $playerCount, 0.0);
            $hessian[$row][$row] = 1.0 / $sigma2;
        }

        foreach ($games as $game) {
            $i = $playerToIndex[$game['player1Id']] ?? null;
            $j = $playerToIndex[$game['player2Id']] ?? null;
            if ($i === null || $j === null || $i === $j) {
                continue;
            }

            $p = $this->sigmoid($skills[$i] - $skills[$j]);
            $w = max($p * (1.0 - $p), 1e-8);
            $hessian[$i][$i] += $w;
            $hessian[$j][$j] += $w;
            $hessian[$i][$j] -= $w;
            $hessian[$j][$i] -= $w;
        }

        $diag = $this->invertMatrixDiagonal($hessian);
        if ($diag === null) {
            $fallback = [];
            for ($i = 0; $i < $playerCount; $i++) {
                $fallback[$i] = 1.0 / max($hDiag[$i], 1e-12);
            }

            return [$hDiag, $fallback, false];
        }

        return [$hDiag, $diag, true];
    }

    /**
     * @param list<list<float>> $matrix
     * @return list<float>|null
     */
    private function invertMatrixDiagonal(array $matrix): ?array
    {
        $n = count($matrix);
        if ($n === 0) {
            return [];
        }

        $aug = [];
        for ($i = 0; $i < $n; $i++) {
            $row = [];
            for ($j = 0; $j < $n; $j++) {
                $row[] = (float) $matrix[$i][$j];
            }
            for ($j = 0; $j < $n; $j++) {
                $row[] = ($i === $j) ? 1.0 : 0.0;
            }
            $aug[$i] = $row;
        }

        for ($col = 0; $col < $n; $col++) {
            $pivotRow = $col;
            $pivotAbs = abs($aug[$pivotRow][$col]);
            for ($row = $col + 1; $row < $n; $row++) {
                $candidate = abs($aug[$row][$col]);
                if ($candidate > $pivotAbs) {
                    $pivotAbs = $candidate;
                    $pivotRow = $row;
                }
            }

            if ($pivotAbs < 1e-12) {
                return null;
            }

            if ($pivotRow !== $col) {
                [$aug[$col], $aug[$pivotRow]] = [$aug[$pivotRow], $aug[$col]];
            }

            $pivot = $aug[$col][$col];
            for ($k = 0; $k < (2 * $n); $k++) {
                $aug[$col][$k] /= $pivot;
            }

            for ($row = 0; $row < $n; $row++) {
                if ($row === $col) {
                    continue;
                }

                $factor = $aug[$row][$col];
                if (abs($factor) < 1e-14) {
                    continue;
                }

                for ($k = 0; $k < (2 * $n); $k++) {
                    $aug[$row][$k] -= $factor * $aug[$col][$k];
                }
            }
        }

        $diag = [];
        for ($i = 0; $i < $n; $i++) {
            $diag[$i] = $aug[$i][$n + $i];
        }

        return $diag;
    }

    /**
     * @param list<float> $skills
     * @return list<float>
     */
    private function recenter(array $skills): array
    {
        if ($skills === []) {
            return $skills;
        }

        $mean = array_sum($skills) / count($skills);
        foreach ($skills as $idx => $value) {
            $skills[$idx] = $value - $mean;
        }

        return $skills;
    }

    private function sigmoid(float $x): float
    {
        if ($x >= 0.0) {
            $z = exp(-$x);

            return 1.0 / (1.0 + $z);
        }

        $z = exp($x);

        return $z / (1.0 + $z);
    }

    private function clamp(float $value, float $min, float $max): float
    {
        if ($value < $min) {
            return $min;
        }
        if ($value > $max) {
            return $max;
        }

        return $value;
    }

    private function zFromCiLevel(float $ciLevel): float
    {
        if ($ciLevel >= 0.999) {
            return 3.291;
        }
        if ($ciLevel >= 0.99) {
            return 2.576;
        }
        if ($ciLevel >= 0.975) {
            return 2.241;
        }
        if ($ciLevel >= 0.95) {
            return 1.96;
        }
        if ($ciLevel >= 0.90) {
            return 1.645;
        }

        return 1.96;
    }
}

