<?php

namespace WPMCP\Tools\Brand;

use WPMCP\Tools\Elementor\Elementor_Kit_Data;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Turns a normalized brand kit into ONE `_elementor_page_settings` patch for
 * the active Elementor kit, plus the diff that patch would produce.
 *
 * This is the whole point of the feature: colors, extra swatches, typography
 * and the logo are folded into a single settings patch handed to
 * Elementor_Kit_Data::write(), which takes exactly one snapshot and returns
 * one operation_id. Driving the existing per-token tools in sequence instead
 * would leave three or four separate operations behind, so undoing an apply
 * would mean undoing each of them in the right order and a crash halfway
 * through would leave the site's design half-rebranded.
 *
 * Building the patch is also pure: the same plan feeds the preview an
 * unconfirmed call returns and the write a confirmed call performs, so the
 * preview cannot drift from what actually gets stored.
 */
class Brand_Kit_Applier
{
    /**
     * @param array<string, mixed> $kit a normalized kit from Brand_Kit_Store.
     * @return array{patch: array<string, mixed>, changes: array<string, mixed>, counts: array<string, mixed>}
     */
    public static function plan(int $kit_id, array $kit, bool $include_logo): array
    {
        $view     = Elementor_Kit_Data::view($kit_id);
        $settings = Elementor_Kit_Data::settings($kit_id);

        $patch   = [];
        $changes = [
            'system_colors' => [],
            'custom_colors' => [],
            'typography'    => [],
            'logo'          => null,
        ];

        // The four system slots are the fixed set Elementor_Kit_Data::view()
        // always reports (filling any the kit never customized from
        // defaults), so the system passes walk the view rather than
        // inventing entries.
        if ([] !== $kit['colors']) {
            $entries = $view['system_colors'];
            foreach ($entries as $index => $entry) {
                $slot = (string) ($entry['_id'] ?? '');
                if (! isset($kit['colors'][ $slot ])) {
                    continue;
                }
                $hex    = $kit['colors'][ $slot ];
                $before = $entry['color'] ?? null;
                if ($before !== $hex) {
                    $changes['system_colors'][] = ['_id' => $slot, 'before' => $before, 'after' => $hex];
                }
                $entries[ $index ]['color'] = $hex;
            }
            $patch['system_colors'] = array_values($entries);
        }

        if ([] !== $kit['custom_colors']) {
            $entries = $view['custom_colors'];
            foreach ($kit['custom_colors'] as $swatch) {
                $index = self::index_of($entries, $swatch['_id']);
                if (null === $index) {
                    $entries[]                  = $swatch;
                    $changes['custom_colors'][] = ['_id' => $swatch['_id'], 'before' => null, 'after' => $swatch['color']];
                    continue;
                }
                $before = $entries[ $index ]['color'] ?? null;
                if ($before !== $swatch['color']) {
                    $changes['custom_colors'][] = ['_id' => $swatch['_id'], 'before' => $before, 'after' => $swatch['color']];
                }
                $entries[ $index ] = array_merge($entries[ $index ], $swatch);
            }
            $patch['custom_colors'] = array_values($entries);
        }

        if ([] !== $kit['typography']) {
            $entries = $view['system_typography'];
            foreach ($entries as $index => $entry) {
                $slot = (string) ($entry['_id'] ?? '');
                if (! isset($kit['typography'][ $slot ])) {
                    continue;
                }
                $fields = $kit['typography'][ $slot ];
                $before = array_intersect_key($entry, $fields);
                if ($before != $fields) { // phpcs:ignore -- array value comparison, key order is irrelevant.
                    $changes['typography'][] = ['_id' => $slot, 'before' => $before, 'after' => $fields];
                }
                $entries[ $index ] = array_merge($entry, $fields);
            }
            $patch['system_typography'] = array_values($entries);
        }

        if ($include_logo && null !== $kit['logo']) {
            $before = isset($settings['site_logo']) && is_array($settings['site_logo']) ? $settings['site_logo'] : null;
            if ($before != $kit['logo']) { // phpcs:ignore -- array value comparison.
                $changes['logo'] = ['before' => $before, 'after' => $kit['logo']];
            }
            $patch['site_logo'] = $kit['logo'];
        }

        return [
            'patch'   => $patch,
            'changes' => $changes,
            'counts'  => [
                'colors_applied'         => count($kit['colors']),
                'custom_colors_upserted' => count($kit['custom_colors']),
                'typography_applied'     => count($kit['typography']),
                'logo_applied'           => isset($patch['site_logo']),
                'tokens_changed'         => count($changes['system_colors'])
                    + count($changes['custom_colors'])
                    + count($changes['typography'])
                    + (null === $changes['logo'] ? 0 : 1),
            ],
        ];
    }

    /** @param array<int, array<string, mixed>> $entries */
    private static function index_of(array $entries, string $id): ?int
    {
        foreach ($entries as $index => $entry) {
            if (is_array($entry) && ($entry['_id'] ?? null) === $id) {
                return (int) $index;
            }
        }

        return null;
    }
}
