<?php

namespace WPMCP\Tools\Analysis;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Auto-fixer: raise failing inline text/background color pairs in a post's
 * stored content to a target WCAG contrast ratio.
 *
 * Dry run by default (see Fix_Pass). A dry run returns every pair it looked
 * at: the proposed before/after color, the before and after ratios, the WCAG
 * level each new pair achieves, and whether the original hue survived. Apply
 * mode rewrites only the `color` declaration inside each offending element's
 * existing style attribute, splices those exact byte ranges back into the
 * original content, and writes the whole pass in one Safe_Mutation snapshot.
 *
 * Scope, stated honestly:
 * - Only INLINE colors are considered. A theme stylesheet's colors are not in
 *   the post's content and cannot be fixed by editing the post; those belong
 *   to a theme-level tool, and pretending otherwise would produce a report
 *   full of changes that do not affect the rendered page.
 * - A background is resolved from the element, then its nearest styled
 *   ancestor, then the `default_background` argument (white unless the caller
 *   says otherwise), and every proposal reports which of the three it used.
 * - Colors this fixer cannot read (gradients, var(), currentColor, unknown
 *   names) are reported as skipped, never guessed at. We do not "fix" what we
 *   cannot measure.
 */
class Fix_Color_Contrast
{
    /** WCAG AA for normal text. */
    private const DEFAULT_TARGET = 4.5;

    /** Guard rail: below 1.0 is impossible and above 21.0 is unreachable for any pair. */
    private const MIN_TARGET = 1.0;
    private const MAX_TARGET = 21.0;

    public function handle(array $args): array
    {
        $post   = Fix_Pass::post($args);
        $target = $this->target_ratio($args);
        $tags   = Markup_Scanner::tags((string) $post->post_content);

        $default_background = Contrast_Suggester::parse_color(
            (string) ($args['default_background'] ?? '#ffffff')
        );
        if (null === $default_background) {
            throw new \InvalidArgumentException('default_background must be a readable color (hex, rgb(), or a basic CSS color name).');
        }

        $proposed = [];
        $skipped  = [];
        $edits    = [];

        foreach ($tags as $index => $tag) {
            $style = (string) ($tag['attributes']['style'] ?? '');
            if ('' === $style) {
                continue;
            }
            $declarations = Markup_Scanner::parse_style($style);
            if (! isset($declarations['color'])) {
                continue;
            }

            $foreground = Contrast_Suggester::parse_color($declarations['color']);
            if (null === $foreground) {
                $skipped[] = $this->skip($tag, 'unreadable_color');
                continue;
            }

            $text = trim(wp_strip_all_tags((string) $tag['inner']));
            if ('' === $text) {
                $skipped[] = $this->skip($tag, 'no_text');
                continue;
            }

            $background = $this->resolve_background($tags, $index, $default_background);
            if (null === $background) {
                $skipped[] = $this->skip($tag, 'unreadable_background');
                continue;
            }

            $before = (float) Color_Contrast::contrast_ratio($foreground, $background['color']);
            if ($before >= $target) {
                $skipped[] = $this->skip($tag, 'already_meets_target');
                continue;
            }

            $suggestion = Contrast_Suggester::suggest($foreground, $background['color'], $target);
            if (null === $suggestion) {
                $skipped[] = $this->skip($tag, 'no_reachable_color');
                continue;
            }

            $proposed[] = [
                'location'          => $tag['location'],
                'element'           => $tag['name'],
                'text_sample'       => $this->sample($text),
                'background'        => $background['color'],
                'background_source' => $background['source'],
                'from'              => $foreground,
                'to'                => $suggestion['hex'],
                'before_ratio'      => round($before, 2),
                'after_ratio'       => $suggestion['ratio'],
                'before_level'      => Contrast_Suggester::achieved_level($before),
                'achieved_level'    => Contrast_Suggester::achieved_level($suggestion['ratio']),
                'direction'         => $suggestion['direction'],
                'hue_preserved'     => $suggestion['hue_preserved'],
            ];

            $edits[] = [
                'offset'      => (int) $tag['offset'],
                'length'      => (int) $tag['length'],
                'replacement' => Markup_Scanner::with_attribute(
                    (string) $tag['source'],
                    'style',
                    Markup_Scanner::with_style_property($style, 'color', $suggestion['hex'])
                ),
            ];
        }

        $out                 = Fix_Pass::finish('fix-color-contrast', $post, $args, $proposed, $skipped, $edits);
        $out['target_ratio'] = $target;

        return $out;
    }

    private function target_ratio(array $args): float
    {
        if (! isset($args['target_ratio'])) {
            return self::DEFAULT_TARGET;
        }
        $target = (float) $args['target_ratio'];
        if ($target < self::MIN_TARGET || $target > self::MAX_TARGET) {
            throw new \InvalidArgumentException('target_ratio must be between 1 and 21.');
        }
        return $target;
    }

    /**
     * Walk the element and then its ancestors for the first readable
     * background color, falling back to the caller's page background.
     *
     * Returns null (rather than falling through to the default) when a
     * background IS declared but cannot be parsed: a gradient or a CSS
     * variable means the real backdrop is unknown, and silently substituting
     * white would produce a confidently wrong "fix".
     *
     * @param array<int,array<string,mixed>> $tags
     * @return array{color:string,source:string}|null
     */
    private function resolve_background(array $tags, int $index, string $default): ?array
    {
        $cursor = $index;
        while (null !== $cursor) {
            $tag         = $tags[$cursor];
            $declared    = Markup_Scanner::parse_style((string) ($tag['attributes']['style'] ?? ''));
            $declaration = $declared['background-color'] ?? ($declared['background'] ?? null);

            if (null !== $declaration && '' !== trim($declaration)) {
                $color = $this->color_from_background($declaration);
                if (null === $color) {
                    return null;
                }
                return [
                    'color'  => $color,
                    'source' => $cursor === $index ? 'element' : 'ancestor',
                ];
            }

            $cursor = $tags[$cursor]['parent'];
        }

        return ['color' => $default, 'source' => 'default'];
    }

    /**
     * Pull a color out of a background declaration. `background-color` is
     * already a bare color; the `background` shorthand may carry a color plus
     * other layers, so its first readable token wins and anything with no
     * readable token at all (a gradient, an image URL) is unreadable.
     */
    private function color_from_background(string $declaration): ?string
    {
        $direct = Contrast_Suggester::parse_color($declaration);
        if (null !== $direct) {
            return $direct;
        }

        foreach (preg_split('/\s+(?![^(]*\))/', trim($declaration)) ?: [] as $token) {
            $color = Contrast_Suggester::parse_color($token);
            if (null !== $color) {
                return $color;
            }
        }

        return null;
    }

    /** @param array<string,mixed> $tag */
    private function skip(array $tag, string $reason): array
    {
        return [
            'location' => (string) $tag['location'],
            'reason'   => $reason,
        ];
    }

    private function sample(string $text): string
    {
        return strlen($text) <= 60 ? $text : rtrim(substr($text, 0, 60)) . '...';
    }
}
