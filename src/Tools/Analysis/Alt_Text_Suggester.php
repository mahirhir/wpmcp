<?php

namespace WPMCP\Tools\Analysis;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Pure alt-text proposal logic: derive a description for an image from what
 * the page already says about it, in a fixed, explainable order.
 *
 * 1. The filename, when it reads like words rather than a camera code.
 * 2. The nearest heading above the image, which is what a sighted reader
 *    would use as the image's caption context.
 * 3. The post title, as a last resort.
 *
 * Every proposal carries which of the three it came from, so a reviewer can
 * judge the guess instead of trusting it. There is no model call here on
 * purpose: an alt-text fixer that silently costs money per image, or that
 * produces a different answer on every dry run, is not something a caller can
 * preview and then apply with confidence.
 */
final class Alt_Text_Suggester
{
    /** Filename tokens that carry no meaning and are dropped before judging descriptiveness. */
    private const NOISE_TOKENS = [
        'img', 'image', 'images', 'dsc', 'dscn', 'pxl', 'pic', 'photo',
        'screenshot', 'untitled', 'final', 'copy', 'scaled', 'edited',
        'cropped', 'rotated',
    ];

    /** Longest alt text this proposes; screen readers truncate long alt and WCAG advises brevity. */
    private const MAX_LENGTH = 125;

    /**
     * Propose alt text for one image.
     *
     * @return array{alt:string,source:string}|null null when none of the
     *         three sources yields anything usable, so the caller reports the
     *         image as skipped rather than writing a meaningless string.
     */
    public static function propose(string $src, string $heading, string $post_title): ?array
    {
        $candidates = [
            'filename'   => self::from_filename($src),
            'heading'    => self::clean($heading),
            'post_title' => self::clean($post_title),
        ];

        foreach ($candidates as $source => $alt) {
            if ('' !== $alt) {
                return ['alt' => $alt, 'source' => $source];
            }
        }

        return null;
    }

    /**
     * Turn an image URL's filename into a readable phrase, or '' when the
     * filename is a camera code, a bare number, or otherwise too sparse to be
     * a real description. WordPress size and scale suffixes (-1024x768,
     * -scaled, -e1699999999) are stripped first so they never leak into alt
     * text.
     */
    public static function from_filename(string $src): string
    {
        $path = (string) (wp_parse_url($src, PHP_URL_PATH) ?: $src);
        $base = basename($path);
        $base = (string) preg_replace('/\.[a-z0-9]{2,5}$/i', '', $base);
        $base = (string) preg_replace('/\b\d{2,4}x\d{2,4}\b/i', ' ', $base);
        $base = (string) preg_replace('/\be\d{8,}\b/i', ' ', $base);
        $base = (string) preg_replace('/[^a-z0-9]+/i', ' ', $base);

        $words = [];
        foreach (preg_split('/\s+/', trim($base)) ?: [] as $token) {
            $token = strtolower(trim($token));
            if ('' === $token || preg_match('/^\d+$/', $token)) {
                continue;
            }
            if (in_array($token, self::NOISE_TOKENS, true) || preg_match('/^v\d+$/', $token)) {
                continue;
            }
            $words[] = $token;
        }

        // One surviving word is a label, not a description ("hero.jpg"), so
        // fall through to the heading/title sources instead.
        if (count($words) < 2) {
            return '';
        }

        return self::clean(ucfirst(implode(' ', $words)));
    }

    /** Collapse whitespace, strip markup, and cap the length at MAX_LENGTH on a word boundary. */
    private static function clean(string $text): string
    {
        $text = trim((string) preg_replace('/\s+/', ' ', wp_strip_all_tags($text)));
        if (strlen($text) <= self::MAX_LENGTH) {
            return $text;
        }
        $truncated = substr($text, 0, self::MAX_LENGTH);
        $space     = strrpos($truncated, ' ');
        return rtrim(false === $space ? $truncated : substr($truncated, 0, $space));
    }
}
