<?php

namespace WPMCP\Tools\Elementor;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Delete an Elementor 4.0+ global class, with the blast radius reported first.
 *
 * Without confirm:true this is a dry run: it scans `_elementor_data` for every
 * post that actually applies the class and returns that usage report instead
 * of deleting, so the agent can see what loses its styling before it commits.
 * With confirm:true the class set is snapshotted and then written, so
 * rollback-operation resurrects the class, its variants and its position.
 */
class Delete_Global_Class
{
    public function handle(array $args)
    {
        $id = sanitize_text_field((string) ($args['id'] ?? ''));
        if ('' === $id) {
            return new \WP_Error('missing_id', 'A global class "id" is required.');
        }

        $state = Global_Classes_Store::guard($args);
        if (is_wp_error($state)) {
            return $state;
        }

        $items = $state['items'];
        if (! isset($items[$id])) {
            return new \WP_Error('class_not_found', sprintf('No global class found with id "%s".', $id));
        }

        $label = (string) ($items[$id]['label'] ?? '');
        $usage = Global_Class_Usage::scan($id);

        if (true !== ($args['confirm'] ?? null)) {
            return [
                'deleted'          => false,
                'confirm_required' => true,
                'id'               => $id,
                'label'            => $label,
                'usage'            => $usage,
                'message'          => sprintf(
                    'Nothing was deleted. "%s" is applied on %d post(s); pass confirm:true to delete it (the write is snapshotted, so rollback-operation brings it back).',
                    $label,
                    $usage['total']
                ),
            ];
        }

        unset($items[$id]);
        $order = array_values(array_filter(
            $state['order'],
            static fn ($existing) => (string) $existing !== $id
        ));

        $out = Global_Classes_Store::write($state, $items, $order, 'delete-global-class', $args);
        if (is_wp_error($out)) {
            return $out;
        }

        return $out + ['deleted' => true, 'id' => $id, 'label' => $label, 'usage' => $usage];
    }
}
