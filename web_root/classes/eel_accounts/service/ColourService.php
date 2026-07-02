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
    private const BASE_COLOURS = [
        '#828146',
        '#825746',
        '#614682',
        '#468278',
        '#D7D7BC',
    ];

    /**
     * @return array<int, string>
     */
    public function generateColours(int $count): array
    {
        if ($count <= 0) {
            return [];
        }

        if ($count <= count(self::BASE_COLOURS)) {
            return array_slice(self::BASE_COLOURS, 0, $count);
        }

        return $this->contrastOrder($this->interpolatedColours($count));
    }

    /**
     * @return array<int, string>
     */
    private function interpolatedColours(int $count): array
    {
        $colours = [];
        $baseCount = count(self::BASE_COLOURS);

        for ($index = 0; $index < $count; $index++) {
            $position = ($index / $count) * $baseCount;
            $fromIndex = (int)floor($position);
            $toIndex = ($fromIndex + 1) % $baseCount;
            $ratio = $position - $fromIndex;

            $colours[] = $this->interpolate(
                self::BASE_COLOURS[$fromIndex],
                self::BASE_COLOURS[$toIndex],
                $ratio
            );
        }

        return $colours;
    }

    /**
     * @param array<int, string> $colours
     * @return array<int, string>
     */
    private function contrastOrder(array $colours): array
    {
        $indexed = [];
        foreach ($colours as $colour) {
            $rgb = $this->rgb($colour);
            $indexed[] = [
                'colour' => $colour,
                'luminance' => (0.2126 * $rgb[0]) + (0.7152 * $rgb[1]) + (0.0722 * $rgb[2]),
            ];
        }

        usort(
            $indexed,
            static fn(array $left, array $right): int => $left['luminance'] <=> $right['luminance']
                ?: strcmp((string)$left['colour'], (string)$right['colour'])
        );

        $ordered = [];
        $takeDark = true;

        while ($indexed !== []) {
            $candidate = $takeDark ? array_shift($indexed) : array_pop($indexed);
            if ($candidate === null) {
                break;
            }

            $colour = (string)$candidate['colour'];
            if ($ordered !== [] && end($ordered) === $colour && $indexed !== []) {
                $alternate = $takeDark ? array_pop($indexed) : array_shift($indexed);
                if ($alternate !== null) {
                    $ordered[] = (string)$alternate['colour'];
                    $indexed[] = $candidate;
                    $takeDark = !$takeDark;
                    continue;
                }
            }

            $ordered[] = $colour;
            $takeDark = !$takeDark;
        }

        return $ordered;
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
