<?php

namespace WPMCP\Tools\Elementor;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * List the Elementor global CSS classes of the active kit, in stored order
 * (empty when the feature has not been used).
 *
 * Reads through Global_Classes_Store, so it reports the same set the write
 * tools operate on across Elementor's 4.2 storage change (classes moved from
 * the kit's `_elementor_global_classes` meta into their own post type), with
 * the legacy kit meta still read as a fallback. The returned `state_hash` is
 * the optimistic lock every global class write requires as expected_hash.
 * Read-only.
 */
class List_Global_Classes
{
    public function handle(array $args)
    {
        $state = Global_Classes_Store::read();
        if (is_wp_error($state)) {
            return $state;
        }

        $classes = [];
        foreach ($state['order'] as $id) {
            $classes[] = $state['items'][$id];
        }

        return [
            'kit_id'     => $state['kit_id'],
            'classes'    => $classes,
            'order'      => $state['order'],
            'state_hash' => Global_Classes_Store::state_hash($state['items'], $state['order']),
        ];
    }
}
