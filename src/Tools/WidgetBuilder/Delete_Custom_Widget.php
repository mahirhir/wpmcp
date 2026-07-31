<?php

namespace WPMCP\Tools\WidgetBuilder;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Delete a custom widget by moving its wpmcp_widget post to the trash
 * (reversible through WordPress trash / restore-post).
 */
class Delete_Custom_Widget
{
    public function handle(array $args)
    {
        $id = (int) ($args['widget_id'] ?? 0);
        if (! Widget_Spec_Store::is_widget($id)) {
            return new \WP_Error('widget_not_found', "No custom widget found with id {$id}.");
        }

        wp_trash_post($id);

        return ['widget_id' => $id, 'deleted' => 'trashed'];
    }
}
