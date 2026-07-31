<?php

namespace WPMCP\Tools\WidgetBuilder;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Create a custom Elementor widget from a spec (title, controls, template).
 * The spec is validated, then stored as a wpmcp_widget post and registered as
 * a real Elementor widget at runtime by the data-driven Dynamic_Widget (no code
 * generation, no eval). Creating destroys nothing; remove with
 * delete-custom-widget.
 */
class Create_Custom_Widget
{
    public function handle(array $args)
    {
        $spec = is_array($args['spec'] ?? null) ? $args['spec'] : [];

        $valid = Widget_Spec::validate($spec);
        if (is_wp_error($valid)) {
            return $valid;
        }

        $id = Widget_Spec_Store::create($spec);
        if (is_wp_error($id)) {
            return $id;
        }

        $stored = Widget_Spec_Store::get($id);
        return [
            'widget_id' => $id,
            'name'      => (string) ($stored['name'] ?? ''),
            'title'     => (string) ($stored['title'] ?? ''),
        ];
    }
}
