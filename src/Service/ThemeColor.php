<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Service;

// The theme's colours as GD draws them, whichever notation the site's config holds them in
final class ThemeColor
{
    // A #rrggbb, #rgb or rgb() value as its three channels, null for any other notation or none
    /** @return ?array{0: int, 1: int, 2: int} */
    public static function parse(?string $value): ?array
    {
        $value = trim((string) $value);
        if (1 === preg_match('/^rgb\(\s*(\d{1,3})\s*[, ]\s*(\d{1,3})\s*[, ]\s*(\d{1,3})\s*\)$/i', $value, $matches)) {
            return [min(255, (int) $matches[1]), min(255, (int) $matches[2]), min(255, (int) $matches[3])];
        }

        $hex = ltrim($value, '#');
        if (1 === preg_match('/^[0-9a-f]{3}$/i', $hex)) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }

        return 1 === preg_match('/^[0-9a-f]{6}$/i', $hex) ? [(int) hexdec(substr($hex, 0, 2)), (int) hexdec(substr($hex, 2, 2)), (int) hexdec(substr($hex, 4, 2))] : null;
    }

    // The WCAG contrast ratio of two colours, from 1 (the same) to 21 (black on white)
    /**
     * @param array{0: int, 1: int, 2: int} $first
     * @param array{0: int, 1: int, 2: int} $second
     */
    public static function contrast(array $first, array $second): float
    {
        $lightest = max(self::luminance($first), self::luminance($second));
        $darkest = min(self::luminance($first), self::luminance($second));

        return ($lightest + 0.05) / ($darkest + 0.05);
    }

    // The WCAG relative luminance of a colour, from 0 (black) to 1 (white)
    /** @param array{0: int, 1: int, 2: int} $color */
    private static function luminance(array $color): float
    {
        [$red, $green, $blue] = array_map(static function (int $channel): float {
            $value = $channel / 255;

            return $value <= 0.03928 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
        }, $color);

        return 0.2126 * $red + 0.7152 * $green + 0.0722 * $blue;
    }
}
