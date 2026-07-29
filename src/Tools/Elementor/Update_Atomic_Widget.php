<?php

namespace WPMCP\Tools\Elementor;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Update an Elementor 4.0+ atomic widget's settings by element id. Friendly
 * params are mapped to typed $$type props for known types (only the params you
 * pass are changed, untouched props survive); raw $$type-wrapped settings are
 * also accepted. Requires expected_hash; written snapshot-first, undoable.
 */
class Update_Atomic_Widget
{
    public function handle(array $args)
    {
        $element_id = (string) ($args['element_id'] ?? '');
        if ('' === $element_id) {
            return new \WP_Error('missing_element_id', 'An element_id is required.');
        }

        $read = Element_Tree::read_for_edit($args);
        if (is_wp_error($read)) {
            return $read;
        }
        [$post_id, $elements] = $read;

        $element = Elementor_Page_Data::find($elements, $element_id);
        if (null === $element) {
            return new \WP_Error('element_not_found', "No element found with id '{$element_id}'.");
        }

        if (is_array($args['settings'] ?? null) && [] !== $args['settings']) {
            $patch = $args['settings'];
        } else {
            $widget_type = (string) ($element['widgetType'] ?? '');
            $params      = is_array($args['params'] ?? null) ? $args['params'] : [];
            $patch       = Atomic_Widget_Map::partial($widget_type, $params);
        }

        if ([] === $patch) {
            return new \WP_Error('missing_settings', 'Provide params (for a known atomic type) or raw settings to update.');
        }

        Elementor_Page_Data::update_settings($elements, $element_id, $patch);

        $out = Atomic_Element::write($post_id, $elements, 'update-atomic-widget', $args);
        if (is_wp_error($out)) {
            return $out;
        }

        return $out + ['element_id' => $element_id];
    }
}
