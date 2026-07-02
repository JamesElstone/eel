<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

namespace eel_accounts\Service;

final class ColourService
{
    private const SEED_COLOURS = [
        '#828146',
        '#825746',
        '#614682',
        '#468278',
        '#D7D7BC',
        '#550182',
        '#017C82',
        '#698207',
        '#823401',
        '#A64AD7',
        '#018240',
        '#825601',
        '#82FFBF',
        '#688201',
        '#311142',
        '#BAD74A',
        '#D382FF',
    ];

    /**
     * @return array<int, string>
     */
    public function generateColours(int $count): array
    {
        if ($count <= 0) {
            return [];
        }

        return array_slice($this->contrastOrder($this->candidateColours($count)), 0, $count);
    }

    /**
     * @return array<int, string>
     */
    private function candidateColours(int $count): array
    {
        $colours = $this->uniqueColours(self::SEED_COLOURS);
        $seedCount = count($colours);
        $variantStep = 1;

        while (count($colours) < $count) {
            foreach (self::SEED_COLOURS as $index => $from) {
                $to = self::SEED_COLOURS[($index + $variantStep) % $seedCount];
                $ratio = ($variantStep % 4) / 5;
                if ($ratio <= 0.0) {
                    $ratio = 0.5;
                }

                $colours[] = $this->interpolate($from, $to, $ratio);
                $colours = $this->uniqueColours($colours);

                if (count($colours) >= $count) {
                    break;
                }
            }

            $variantStep++;
        }

        return $colours;
    }

    /**
     * @param array<int, string> $colours
     * @return array<int, string>
     */
    private function contrastOrder(array $colours): array
    {
        $remaining = $this->colourMetrics($this->uniqueColours($colours));
        if ($remaining === []) {
            return [];
        }

        if (count($remaining) === 1) {
            return [(string)$remaining[0]['colour']];
        }

        [$firstIndex, $secondIndex] = $this->highestContrastPair($remaining);
        $ordered = [$remaining[$firstIndex], $remaining[$secondIndex]];
        foreach ([$firstIndex, $secondIndex] as $index) {
            array_splice($remaining, $index, 1);
        }

        while ($remaining !== []) {
            $bestCandidateIndex = 0;
            $bestPosition = 0;
            $bestMinimumScore = -1.0;
            $bestTotalScore = -1.0;

            foreach ($remaining as $index => $candidate) {
                $orderedCount = count($ordered);

                for ($position = 0; $position < $orderedCount; $position++) {
                    $nextPosition = ($position + 1) % $orderedCount;
                    $leftScore = $this->contrastScore($ordered[$position], $candidate);
                    $rightScore = $this->contrastScore($candidate, $ordered[$nextPosition]);
                    $minimumScore = min($leftScore, $rightScore);
                    $totalScore = $leftScore + $rightScore;

                    if (
                        $minimumScore > $bestMinimumScore
                        || ($minimumScore === $bestMinimumScore && $totalScore > $bestTotalScore)
                    ) {
                        $bestCandidateIndex = $index;
                        $bestPosition = $position;
                        $bestMinimumScore = $minimumScore;
                        $bestTotalScore = $totalScore;
                    }
                }
            }

            array_splice($ordered, $bestPosition + 1, 0, [$remaining[$bestCandidateIndex]]);
            array_splice($remaining, $bestCandidateIndex, 1);
        }

        $darkestIndex = $this->darkestIndex($ordered);
        $ordered = array_merge(array_slice($ordered, $darkestIndex), array_slice($ordered, 0, $darkestIndex));

        return array_map(static fn(array $colour): string => (string)$colour['colour'], $ordered);
    }

    /**
     * @param array<int, string> $colours
     * @return array<int, string>
     */
    private function uniqueColours(array $colours): array
    {
        $unique = [];

        foreach ($colours as $colour) {
            $colour = strtoupper(trim($colour));
            if (!preg_match('/^#[0-9A-F]{6}$/', $colour)) {
                continue;
            }

            $unique[$colour] = $colour;
        }

        return array_values($unique);
    }

    /**
     * @param array<int, string> $colours
     * @return array<int, array{colour: string, rgb: array{0: int, 1: int, 2: int}, luminance: float}>
     */
    private function colourMetrics(array $colours): array
    {
        $metrics = [];

        foreach ($colours as $colour) {
            $rgb = $this->rgb($colour);
            $metrics[] = [
                'colour' => $colour,
                'rgb' => $rgb,
                'luminance' => (0.2126 * $rgb[0]) + (0.7152 * $rgb[1]) + (0.0722 * $rgb[2]),
            ];
        }

        return $metrics;
    }

    /**
     * @param array<int, array{colour: string, rgb: array{0: int, 1: int, 2: int}, luminance: float}> $colours
     */
    private function darkestIndex(array $colours): int
    {
        $darkestIndex = 0;

        foreach ($colours as $index => $colour) {
            if ($colour['luminance'] < $colours[$darkestIndex]['luminance']) {
                $darkestIndex = $index;
            }
        }

        return $darkestIndex;
    }

    /**
     * @param array<int, array{colour: string, rgb: array{0: int, 1: int, 2: int}, luminance: float}> $colours
     * @return array{0: int, 1: int}
     */
    private function highestContrastPair(array $colours): array
    {
        $bestFirstIndex = 0;
        $bestSecondIndex = 1;
        $bestScore = -1.0;

        foreach ($colours as $firstIndex => $firstColour) {
            foreach ($colours as $secondIndex => $secondColour) {
                if ($secondIndex <= $firstIndex) {
                    continue;
                }

                $score = $this->contrastScore($firstColour, $secondColour);
                if ($score > $bestScore) {
                    $bestFirstIndex = $firstIndex;
                    $bestSecondIndex = $secondIndex;
                    $bestScore = $score;
                }
            }
        }

        return [$bestSecondIndex, $bestFirstIndex];
    }

    /**
     * @param array{colour: string, rgb: array{0: int, 1: int, 2: int}, luminance: float} $left
     * @param array{colour: string, rgb: array{0: int, 1: int, 2: int}, luminance: float} $right
     */
    private function contrastScore(array $left, array $right): float
    {
        $rgbDistance = sqrt(
            (($left['rgb'][0] - $right['rgb'][0]) ** 2)
            + (($left['rgb'][1] - $right['rgb'][1]) ** 2)
            + (($left['rgb'][2] - $right['rgb'][2]) ** 2)
        );
        $luminanceDistance = abs($left['luminance'] - $right['luminance']);

        return $rgbDistance + ($luminanceDistance * 0.8);
    }

    private function interpolate(string $from, string $to, float $ratio): string
    {
        $fromRgb = $this->rgb($from);
        $toRgb = $this->rgb($to);

        return sprintf(
            '#%02X%02X%02X',
            $this->channel($fromRgb[0], $toRgb[0], $ratio),
            $this->channel($fromRgb[1], $toRgb[1], $ratio),
            $this->channel($fromRgb[2], $toRgb[2], $ratio)
        );
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    private function rgb(string $colour): array
    {
        return [
            hexdec(substr($colour, 1, 2)),
            hexdec(substr($colour, 3, 2)),
            hexdec(substr($colour, 5, 2)),
        ];
    }

    private function channel(int $from, int $to, float $ratio): int
    {
        return max(0, min(255, (int)round($from + (($to - $from) * $ratio))));
    }
}
