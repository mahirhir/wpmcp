<?php

namespace WPMCP\Tools\Elementor;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Set the order of the Elementor 4.0+ global classes.
 *
 * The Class Manager order IS the CSS source order, so it decides which class
 * wins when two apply at equal specificity. The contract is append-never-drop:
 * the ids you pass go first, in the order you pass them, and every class you
 * omitted keeps its current relative order behind them, so a partial order can
 * never make a class disappear. An id that does not exist is refused rather
 * than ignored. Snapshotted, so rollback-operation restores the old order.
 */
class Reorder_Global_Classes
{
    public function handle(array $args)
    {
        $requested = is_array($args['order'] ?? null) ? $args['order'] : null;
        if (null === $requested || [] === $requested) {
            return new \WP_Error('missing_order', 'Provide an "order" array of global class ids.');
        }

        $state = Global_Classes_Store::guard($args);
        if (is_wp_error($state)) {
            return $state;
        }

        $requested = array_map(
            static fn ($id) => sanitize_text_field((string) $id),
            array_values($requested)
        );

        $unknown = array_values(array_unique(array_filter(
            $requested,
            static fn ($id) => ! isset($state['items'][$id])
        )));
        if ([] !== $unknown) {
            return new \WP_Error(
                'unknown_class',
                sprintf('Unknown global class id(s): %s. Nothing was reordered.', implode(', ', $unknown))
            );
        }

        $order = [];
        foreach (array_merge($requested, $state['order']) as $id) {
            $id = (string) $id;
            if (isset($state['items'][$id]) && ! in_array($id, $order, true)) {
                $order[] = $id;
            }
        }

        $out = Global_Classes_Store::write($state, $state['items'], $order, 'reorder-global-classes', $args);
        if (is_wp_error($out)) {
            return $out;
        }

        return $out + ['order' => $order];
    }
}
