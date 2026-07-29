<?php

namespace WPMCP\Tools\Elementor;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * List the Elementor global CSS classes stored on the active kit
 * (`_elementor_global_classes` meta: an { items, order } structure). Returns
 * the classes in stored order, or an empty list when the feature has not been
 * used. Read-only.
 */
class List_Global_Classes
{
    public function handle(array $args)
    {
        $kit_id = Elementor_Kit_Data::active_kit_id();
        if ($kit_id <= 0) {
            return new \WP_Error('kit_not_found', 'No active Elementor kit was found.');
        }

        $store = get_post_meta($kit_id, '_elementor_global_classes', true);
        $items = is_array($store) && is_array($store['items'] ?? null) ? $store['items'] : [];
        $order = is_array($store['order'] ?? null) ? $store['order'] : array_keys($items);

        $classes = [];
        foreach ($order as $id) {
            if (isset($items[$id]) && is_array($items[$id])) {
                $classes[] = $items[$id];
            }
        }
        // Include any item missing from the order array, preserving coverage.
        foreach ($items as $id => $item) {
            if (! in_array($id, $order, true) && is_array($item)) {
                $classes[] = $item;
            }
        }

        return [
            'kit_id'  => $kit_id,
            'classes' => $classes,
        ];
    }
}
