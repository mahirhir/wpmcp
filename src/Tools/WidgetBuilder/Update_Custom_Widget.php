<?php

namespace WPMCP\Tools\WidgetBuilder;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Replace a custom widget's spec by id. The new spec is validated before it is
 * stored on the wpmcp_widget post.
 */
class Update_Custom_Widget
{
    public function handle(array $args)
    {
        $id = (int) ($args['widget_id'] ?? 0);
        if (! Widget_Spec_Store::is_widget($id)) {
            return new \WP_Error('widget_not_found', "No custom widget found with id {$id}.");
        }

        $spec  = is_array($args['spec'] ?? null) ? $args['spec'] : [];
        $valid = Widget_Spec::validate($spec);
        if (is_wp_error($valid)) {
            return $valid;
        }

        Widget_Spec_Store::update($id, $spec);
        $stored = Widget_Spec_Store::get($id);

        return [
            'widget_id' => $id,
            'name'      => (string) ($stored['name'] ?? ''),
            'title'     => (string) ($stored['title'] ?? ''),
        ];
    }
}
