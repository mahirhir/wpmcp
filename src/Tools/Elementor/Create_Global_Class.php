<?php

namespace WPMCP\Tools\Elementor;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Create an Elementor 4.0+ global class (Class Manager entry) and return the
 * new `g-` id to apply to elements.
 *
 * Friendly flat `styles` are converted to typed v4 props and the finished
 * class is validated against Elementor's own style schema before anything is
 * written; a raw `props` object is available for anything the friendly set
 * does not cover. Requires expected_hash from list-global-classes, and is
 * written snapshot-first so rollback-operation removes the class again.
 */
class Create_Global_Class
{
    public function handle(array $args)
    {
        $label = Global_Class_Schema::label((string) ($args['label'] ?? ''));
        if (is_wp_error($label)) {
            return $label;
        }

        $meta = Global_Class_Schema::variant_meta($args);
        if (is_wp_error($meta)) {
            return $meta;
        }

        $props = Global_Class_Schema::props($args);
        if (is_wp_error($props)) {
            return $props;
        }

        $state = Global_Classes_Store::guard($args);
        if (is_wp_error($state)) {
            return $state;
        }

        foreach ($state['items'] as $existing) {
            if (isset($existing['label']) && $existing['label'] === $label) {
                return new \WP_Error(
                    'duplicate_label',
                    sprintf('A global class labelled "%s" already exists (%s). Update it instead.', $label, (string) ($existing['id'] ?? '?'))
                );
            }
        }

        $id   = Global_Class_Schema::mint_id($state['items']);
        $item = Global_Class_Schema::validate_item([
            'id'       => $id,
            'type'     => 'class',
            'label'    => $label,
            'variants' => [['meta' => $meta, 'props' => $props]],
        ]);
        if (is_wp_error($item)) {
            return $item;
        }

        $items       = $state['items'];
        $items[$id]  = $item;
        $order       = $state['order'];
        $order[]     = $id;

        $out = Global_Classes_Store::write($state, $items, $order, 'create-global-class', $args);
        if (is_wp_error($out)) {
            return $out;
        }

        return $out + ['id' => $id, 'label' => $label];
    }
}
