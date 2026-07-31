<?php

namespace WPMCP\Tools\WidgetBuilder;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * List the control types a custom-widget spec may use, with the Elementor
 * control each maps to and a short description. Read-only.
 */
class List_Control_Types
{
    public function handle(array $args): array
    {
        $types = [];
        foreach (Widget_Spec::CONTROL_TYPES as $type => $meta) {
            $types[] = [
                'type'        => $type,
                'elementor'   => $meta['elementor'],
                'description' => $meta['desc'],
            ];
        }
        return ['control_types' => $types];
    }
}
