<?php

namespace WPMCP\Tools\WidgetBuilder;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Enable or disable a custom widget by setting its wpmcp_widget post status:
 * 'publish' (active, registered in the editor) or 'draft' (inactive).
 */
class Set_Widget_Status
{
    public function handle(array $args)
    {
        $id = (int) ($args['widget_id'] ?? 0);
        if (! Widget_Spec_Store::is_widget($id)) {
            return new \WP_Error('widget_not_found', "No custom widget found with id {$id}.");
        }

        $status = 'draft' === ($args['status'] ?? '') ? 'draft' : 'publish';
        wp_update_post(['ID' => $id, 'post_status' => $status]);

        return ['widget_id' => $id, 'status' => $status];
    }
}
