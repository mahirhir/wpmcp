<?php

namespace WPMCP\Tools\Elementor;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Patch the active Elementor kit's global colors. `system_colors` entries are
 * matched to the four system tokens by _id and update their color/title;
 * `custom_colors` entries update an existing custom color by _id or append a
 * new one (with a generated _id). Requires expected_hash from
 * get-global-settings and is undoable via rollback-operation (the kit's
 * `_elementor_page_settings` is captured by the post snapshot).
 */
class Update_Global_Colors
{
    public function handle(array $args)
    {
        $kit_id = Elementor_Kit_Data::guard($args);
        if (is_wp_error($kit_id)) {
            return $kit_id;
        }

        $system_patch = is_array($args['system_colors'] ?? null) ? $args['system_colors'] : [];
        $custom_patch = is_array($args['custom_colors'] ?? null) ? $args['custom_colors'] : [];

        if ([] === $system_patch && [] === $custom_patch) {
            return new \WP_Error('missing_colors', 'Provide system_colors and/or custom_colors to update.');
        }

        $view   = Elementor_Kit_Data::view($kit_id);
        $system = $view['system_colors'];
        $custom = $view['custom_colors'];

        $patched = self::patch_by_id($system, $system_patch, false);
        if (is_wp_error($patched)) {
            return $patched;
        }
        $system = $patched;

        $patched = self::patch_by_id($custom, $custom_patch, true);
        if (is_wp_error($patched)) {
            return $patched;
        }
        $custom = $patched;

        $result = Elementor_Kit_Data::write(
            $kit_id,
            ['system_colors' => array_values($system), 'custom_colors' => array_values($custom)],
            'update-global-colors',
            $args
        );
        if (is_wp_error($result)) {
            return $result;
        }

        return array_merge($result, ['system_colors' => array_values($system), 'custom_colors' => array_values($custom)]);
    }

    /**
     * Apply each patch entry to $entries by _id. Colors are validated as hex.
     * When $allow_append is true an entry with no matching _id is appended
     * (custom colors); otherwise an unmatched _id is ignored (system tokens
     * are a fixed set).
     *
     * @return array|\WP_Error
     */
    private static function patch_by_id(array $entries, array $patch, bool $allow_append)
    {
        foreach ($patch as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $color = null;
            if (array_key_exists('color', $entry)) {
                $color = sanitize_hex_color((string) $entry['color']);
                if (empty($color)) {
                    return new \WP_Error('invalid_color', sprintf('"%s" is not a valid hex color.', (string) $entry['color']));
                }
            }

            $id      = isset($entry['_id']) ? (string) $entry['_id'] : '';
            $matched = false;

            foreach ($entries as &$existing) {
                if ('' !== $id && ($existing['_id'] ?? '') === $id) {
                    if (null !== $color) {
                        $existing['color'] = $color;
                    }
                    if (isset($entry['title'])) {
                        $existing['title'] = sanitize_text_field((string) $entry['title']);
                    }
                    $matched = true;
                    break;
                }
            }
            unset($existing);

            if (! $matched && $allow_append) {
                if (null === $color) {
                    return new \WP_Error('missing_color', 'A new custom color requires a color.');
                }
                $title      = sanitize_text_field((string) ($entry['title'] ?? 'Custom'));
                $entries[] = [
                    '_id'   => '' !== $id ? $id : self::generate_id($title, $entries),
                    'title' => '' !== $title ? $title : 'Custom',
                    'color' => $color,
                ];
            }
        }

        return $entries;
    }

    private static function generate_id(string $title, array $entries): string
    {
        $base   = sanitize_key($title) ?: 'color';
        $ids    = array_column($entries, '_id');
        $id     = $base;
        $suffix = 1;
        while (in_array($id, $ids, true)) {
            $id = $base . '_' . (++$suffix);
        }
        return $id;
    }
}
