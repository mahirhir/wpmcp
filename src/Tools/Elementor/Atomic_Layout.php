<?php

namespace WPMCP\Tools\Elementor;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Shared insert path for the atomic container tools (add-flexbox, add-div-block):
 * read the page under the expected_hash guard, build the atomic container, place
 * it under parent_id (or top level) at an optional position, and write it back
 * snapshot-first.
 */
class Atomic_Layout
{
    public static function add(array $args, string $el_type, string $tool_name)
    {
        $read = Element_Tree::read_for_edit($args);
        if (is_wp_error($read)) {
            return $read;
        }
        [$post_id, $elements] = $read;

        $settings = is_array($args['settings'] ?? null) ? $args['settings'] : [];
        $mapped   = Atomic_Props::map($el_type, $settings);
        $element  = Atomic_Element::container($el_type, $mapped['settings']);

        $parent_id = (string) ($args['parent_id'] ?? '');
        $position  = isset($args['position']) ? (int) $args['position'] : null;

        if (! Element_Tree::insert_at($elements, $parent_id, $element, $position)) {
            return new \WP_Error('parent_not_found', "No element found with id '{$parent_id}' to insert under.");
        }

        $out = Atomic_Element::write($post_id, $elements, $tool_name, $args);
        if (is_wp_error($out)) {
            return $out;
        }

        return $out + ['element_id' => $element['id'], 'elType' => $el_type] + Atomic_Element::report($mapped);
    }
}
