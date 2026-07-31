<?php

namespace WPMCP\Tools\WidgetBuilder;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Read one custom widget's stored spec by id. Read-only.
 */
class Get_Custom_Widget
{
    public function handle(array $args)
    {
        $id   = (int) ($args['widget_id'] ?? 0);
        $spec = Widget_Spec_Store::get($id);
        if (null === $spec) {
            return new \WP_Error('widget_not_found', "No custom widget found with id {$id}.");
        }

        return [
            'widget_id' => $id,
            'status'    => get_post_status($id),
            'spec'      => $spec,
        ];
    }
}
