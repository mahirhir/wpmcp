<?php

namespace WPMCP\Tools\Elementor;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Shared helpers for the Elementor template library (`elementor_library` CPT).
 *
 * A template is an ordinary post whose element tree lives in `_elementor_data`,
 * tagged with `_elementor_template_type` and the `elementor_library_type`
 * taxonomy. Creating one destroys nothing, so template creation is not routed
 * through Safe_Mutation (mirroring create-post); applying a template mutates a
 * page and goes through Element_Tree::write snapshot-first.
 */
class Elementor_Template_Data
{
    public const POST_TYPE = 'elementor_library';

    /** Template types Elementor's library recognizes; anything else falls back to 'page'. */
    public const VALID_TYPES = [
        'page', 'section', 'container', 'widget', 'popup',
        'header', 'footer', 'single', 'single-post', 'single-page',
        'archive', 'loop-item', 'search-results', 'error-404',
    ];

    public static function is_template(int $id): bool
    {
        return $id > 0 && self::POST_TYPE === get_post_type($id);
    }

    public static function normalize_type(string $type): string
    {
        $type = sanitize_key($type);
        return in_array($type, self::VALID_TYPES, true) ? $type : 'page';
    }

    /** A template's element tree ([] when empty). */
    public static function data(int $id): array
    {
        $raw = get_post_meta($id, '_elementor_data', true);
        if (is_array($raw)) {
            return $raw;
        }
        $decoded = json_decode(is_string($raw) ? $raw : '', true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Create a library template from an element tree.
     *
     * @return int|\WP_Error the new template post id.
     */
    public static function create(string $title, string $type, array $elements)
    {
        $type  = self::normalize_type($type);
        $title = sanitize_text_field($title);

        $id = wp_insert_post(
            [
                'post_title'  => '' !== $title ? $title : __('Untitled template', 'wpmcp'),
                'post_status' => 'publish',
                'post_type'   => self::POST_TYPE,
                'meta_input'  => [
                    '_elementor_edit_mode'     => 'builder',
                    '_elementor_template_type' => $type,
                ],
            ],
            true
        );

        if (is_wp_error($id)) {
            return $id;
        }
        $id = (int) $id;

        if (taxonomy_exists('elementor_library_type')) {
            wp_set_object_terms($id, $type, 'elementor_library_type');
        }

        Elementor_Page_Data::save($id, $elements);

        if (defined('ELEMENTOR_VERSION')) {
            update_post_meta($id, '_elementor_version', ELEMENTOR_VERSION);
        }

        return $id;
    }

    /**
     * Recursively assign fresh 7-char Elementor ids to every element, avoiding
     * any id already present in $taken (so applied content never collides with
     * the target page). $taken is mutated as ids are claimed.
     *
     * @param array<string,bool> $taken
     */
    public static function regenerate_ids(array $elements, array &$taken): array
    {
        return array_map(
            static function (array $element) use (&$taken) {
                do {
                    $id = Element_Id::generate();
                } while (isset($taken[$id]));
                $taken[$id] = true;

                $element['id'] = $id;

                if (! empty($element['elements']) && is_array($element['elements'])) {
                    $element['elements'] = self::regenerate_ids($element['elements'], $taken);
                }

                return $element;
            },
            $elements
        );
    }

    /** Every element id in a tree, recursively. */
    public static function collect_ids(array $elements): array
    {
        $ids = [];
        foreach ($elements as $element) {
            $ids[] = (string) ($element['id'] ?? '');
            if (! empty($element['elements']) && is_array($element['elements'])) {
                $ids = array_merge($ids, self::collect_ids($element['elements']));
            }
        }
        return $ids;
    }
}
