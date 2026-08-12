<?php

namespace WPMCP\Tools\Brand;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * List the brand kits available on this site (bundled presets plus anything
 * the site added through the `wpmcp_brand_kits` option or filter), with a
 * palette and font summary so an agent can choose without a second call.
 * Read-only.
 */
class List_Brand_Kits
{
    public function handle(array $args): array
    {
        $search   = strtolower(trim((string) ($args['search'] ?? '')));
        $category = sanitize_key((string) ($args['category'] ?? ''));
        $source   = sanitize_key((string) ($args['source'] ?? ''));

        $kits = [];
        foreach (Brand_Kit_Store::all() as $kit) {
            if ('' !== $category && $kit['category'] !== $category) {
                continue;
            }
            if ('' !== $source && $kit['source'] !== $source) {
                continue;
            }
            if ('' !== $search) {
                $haystack = strtolower($kit['slug'] . ' ' . $kit['title'] . ' ' . $kit['description'] . ' ' . $kit['category']);
                if (false === strpos($haystack, $search)) {
                    continue;
                }
            }

            $kits[] = [
                'slug'        => $kit['slug'],
                'title'       => $kit['title'],
                'description' => $kit['description'],
                'category'    => $kit['category'],
                'source'      => $kit['source'],
                'colors'      => $kit['colors'],
                'fonts'       => self::fonts($kit['typography']),
                'has_logo'    => null !== $kit['logo'],
                'invalid'     => $kit['invalid'],
            ];
        }

        return ['kits' => $kits, 'count' => count($kits)];
    }

    /**
     * @param array<string, array<string, mixed>> $typography
     * @return array<int, string> distinct font families, in slot order.
     */
    private static function fonts(array $typography): array
    {
        $fonts = [];
        foreach ($typography as $fields) {
            $family = (string) ($fields['typography_font_family'] ?? '');
            if ('' !== $family && ! in_array($family, $fonts, true)) {
                $fonts[] = $family;
            }
        }

        return $fonts;
    }
}
