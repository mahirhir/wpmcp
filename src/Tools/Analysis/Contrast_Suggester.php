<?php

namespace WPMCP\Tools\Analysis;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Pure color math for the contrast fixer: given a failing foreground /
 * background pair, propose the SMALLEST lightness change that reaches a
 * target WCAG ratio while keeping the original hue and saturation.
 *
 * Why lightness-only: WCAG contrast is a function of relative luminance, and
 * for a fixed hue and saturation the HSL lightness channel is monotonic in
 * luminance. Adjusting only L therefore reaches any achievable ratio without
 * touching the designer's hue, which is the difference between "the fixer
 * respected the brand" and "the fixer turned the button text black".
 *
 * Contrast as a function of L is V-shaped: it falls to 1.0 where the
 * foreground luminance equals the background's, then rises again. So the set
 * of passing L values is [0, a] union [b, 1], and each direction can be found
 * with a plain binary search on the boundary. Both directions are evaluated
 * and the one with the smaller lightness delta wins.
 *
 * Contrast ratio / luminance math itself is reused from Color_Contrast; this
 * class only adds the color-space conversions and the search.
 */
final class Contrast_Suggester
{
    /** Binary-search steps; 24 halvings of the 0..1 lightness range is far below one 8-bit step. */
    private const SEARCH_STEPS = 24;

    /**
     * The CSS named colors that realistically appear in hand-written inline
     * styles inside post content. Deliberately not the full 148-name list:
     * an unrecognized name is reported as unresolvable rather than guessed,
     * and reporting "I could not read this color" is always safer than
     * rewriting the wrong one.
     */
    private const NAMED_COLORS = [
        'black'   => '#000000',
        'white'   => '#ffffff',
        'red'     => '#ff0000',
        'green'   => '#008000',
        'blue'    => '#0000ff',
        'yellow'  => '#ffff00',
        'orange'  => '#ffa500',
        'purple'  => '#800080',
        'gray'    => '#808080',
        'grey'    => '#808080',
        'silver'  => '#c0c0c0',
        'maroon'  => '#800000',
        'navy'    => '#000080',
        'teal'    => '#008080',
        'olive'   => '#808000',
        'lime'    => '#00ff00',
        'aqua'    => '#00ffff',
        'cyan'    => '#00ffff',
        'fuchsia' => '#ff00ff',
        'magenta' => '#ff00ff',
    ];

    /**
     * Normalize a CSS color value to '#rrggbb', or null when it is not a form
     * this fixer is willing to reason about (gradients, currentColor, var(),
     * hsl(), unknown names). Fully transparent colors return null: an
     * invisible foreground has no meaningful contrast to fix.
     */
    public static function parse_color(string $value): ?string
    {
        $value = strtolower(trim($value));
        if ('' === $value) {
            return null;
        }

        if (isset(self::NAMED_COLORS[$value])) {
            return self::NAMED_COLORS[$value];
        }

        if (str_starts_with($value, '#')) {
            $rgb = Color_Contrast::hex_to_rgb($value);
            return null === $rgb ? null : self::rgb_to_hex($rgb);
        }

        if (preg_match('/^rgba?\(([^)]*)\)$/', $value, $match)) {
            $parts = array_map('trim', preg_split('/[\s,\/]+/', trim($match[1])) ?: []);
            $parts = array_values(array_filter($parts, static fn (string $p): bool => '' !== $p));
            if (count($parts) < 3) {
                return null;
            }
            if (isset($parts[3]) && 0.0 === (float) rtrim($parts[3], '%')) {
                return null;
            }
            $rgb = [];
            foreach (array_slice($parts, 0, 3) as $channel) {
                if (! is_numeric(rtrim($channel, '%'))) {
                    return null;
                }
                $number = (float) rtrim($channel, '%');
                $rgb[]  = (int) round(str_ends_with($channel, '%') ? $number * 2.55 : $number);
            }
            return self::rgb_to_hex($rgb);
        }

        return null;
    }

    /**
     * Best-effort lightness adjustment of $foreground against $background.
     *
     * @return array{hex:string,ratio:float,direction:string,hue_preserved:bool}|null
     *         null when no lightness of this hue reaches the target (both
     *         pure-black and pure-white variants of the hue still fail), in
     *         which case the caller reports the pair as unfixable rather than
     *         inventing a different color.
     */
    public static function suggest(string $foreground, string $background, float $target): ?array
    {
        $fg = self::parse_color($foreground);
        $bg = self::parse_color($background);
        if (null === $fg || null === $bg) {
            return null;
        }

        $hsl       = self::hex_to_hsl($fg);
        [$h, $s, $l] = $hsl;

        $ratio_at = static function (float $lightness) use ($h, $s, $bg): float {
            $candidate = self::hsl_to_hex($h, $s, $lightness);
            return (float) Color_Contrast::contrast_ratio($candidate, $bg);
        };

        $candidates = [];

        // Darker: passing set is [0, a]; find the largest passing L <= $l.
        if ($ratio_at(0.0) >= $target) {
            $low  = 0.0;
            $high = $l;
            for ($i = 0; $i < self::SEARCH_STEPS; $i++) {
                $mid = ($low + $high) / 2;
                if ($ratio_at($mid) >= $target) {
                    $low = $mid;
                } else {
                    $high = $mid;
                }
            }
            $candidates[] = ['lightness' => $low, 'direction' => 'darker', 'delta' => $l - $low];
        }

        // Lighter: passing set is [b, 1]; find the smallest passing L >= $l.
        if ($ratio_at(1.0) >= $target) {
            $low  = $l;
            $high = 1.0;
            for ($i = 0; $i < self::SEARCH_STEPS; $i++) {
                $mid = ($low + $high) / 2;
                if ($ratio_at($mid) >= $target) {
                    $high = $mid;
                } else {
                    $low = $mid;
                }
            }
            $candidates[] = ['lightness' => $high, 'direction' => 'lighter', 'delta' => $high - $l];
        }

        if ([] === $candidates) {
            return null;
        }

        usort($candidates, static fn (array $a, array $b): int => $a['delta'] <=> $b['delta']);
        $winner = $candidates[0];

        // The binary search converges on the boundary from the passing side,
        // but the 8-bit hex rounding that follows can nudge the result back
        // under the target, so walk the last fraction of a step if needed.
        $hex   = self::hsl_to_hex($h, $s, $winner['lightness']);
        $ratio = (float) Color_Contrast::contrast_ratio($hex, $bg);
        $step  = 'darker' === $winner['direction'] ? -0.004 : 0.004;
        for ($i = 0; $i < 8 && $ratio < $target; $i++) {
            $winner['lightness'] = max(0.0, min(1.0, $winner['lightness'] + $step));
            $hex                 = self::hsl_to_hex($h, $s, $winner['lightness']);
            $ratio               = (float) Color_Contrast::contrast_ratio($hex, $bg);
        }

        if ($ratio < $target) {
            return null;
        }

        return [
            'hex'           => $hex,
            'ratio'         => round($ratio, 2),
            'direction'     => $winner['direction'],
            // Hue survives by construction unless the search bottomed out at
            // pure black or white, where RGB has no hue left to keep.
            'hue_preserved' => $winner['lightness'] > 0.0005 && $winner['lightness'] < 0.9995,
        ];
    }

    /** The strongest WCAG label a ratio earns: AAA, AA, AA_large, or fail. */
    public static function achieved_level(float $ratio): string
    {
        if (Color_Contrast::passes($ratio, false, 'AAA')) {
            return 'AAA';
        }
        if (Color_Contrast::passes($ratio, false, 'AA')) {
            return 'AA';
        }
        if (Color_Contrast::passes($ratio, true, 'AA')) {
            return 'AA_large';
        }
        return 'fail';
    }

    /** @param array{0:int,1:int,2:int} $rgb */
    public static function rgb_to_hex(array $rgb): string
    {
        $out = '#';
        foreach ($rgb as $channel) {
            $out .= str_pad(dechex(max(0, min(255, (int) $channel))), 2, '0', STR_PAD_LEFT);
        }
        return $out;
    }

    /** @return array{0:float,1:float,2:float} hue in 0..1, saturation 0..1, lightness 0..1 */
    public static function hex_to_hsl(string $hex): array
    {
        $rgb = Color_Contrast::hex_to_rgb($hex) ?? [0, 0, 0];
        [$r, $g, $b] = array_map(static fn (int $c): float => $c / 255, $rgb);

        $max   = max($r, $g, $b);
        $min   = min($r, $g, $b);
        $l     = ($max + $min) / 2;
        $delta = $max - $min;

        if (0.0 === $delta) {
            return [0.0, 0.0, $l];
        }

        $s = $l > 0.5 ? $delta / (2 - $max - $min) : $delta / ($max + $min);

        if ($max === $r) {
            $h = (($g - $b) / $delta) + ($g < $b ? 6 : 0);
        } elseif ($max === $g) {
            $h = (($b - $r) / $delta) + 2;
        } else {
            $h = (($r - $g) / $delta) + 4;
        }

        return [$h / 6, $s, $l];
    }

    /** Inverse of hex_to_hsl(); hue and saturation in 0..1. */
    public static function hsl_to_hex(float $h, float $s, float $l): string
    {
        $l = max(0.0, min(1.0, $l));

        if (0.0 === $s) {
            $channel = (int) round($l * 255);
            return self::rgb_to_hex([$channel, $channel, $channel]);
        }

        $q = $l < 0.5 ? $l * (1 + $s) : $l + $s - ($l * $s);
        $p = (2 * $l) - $q;

        return self::rgb_to_hex([
            (int) round(self::hue_to_channel($p, $q, $h + 1 / 3) * 255),
            (int) round(self::hue_to_channel($p, $q, $h) * 255),
            (int) round(self::hue_to_channel($p, $q, $h - 1 / 3) * 255),
        ]);
    }

    private static function hue_to_channel(float $p, float $q, float $t): float
    {
        if ($t < 0) {
            $t += 1;
        }
        if ($t > 1) {
            $t -= 1;
        }
        if ($t < 1 / 6) {
            return $p + (($q - $p) * 6 * $t);
        }
        if ($t < 1 / 2) {
            return $q;
        }
        if ($t < 2 / 3) {
            return $p + (($q - $p) * ((2 / 3) - $t) * 6);
        }
        return $p;
    }
}
