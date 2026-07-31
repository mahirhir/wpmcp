<?php

namespace WPMCP\Tools\WidgetBuilder;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Statically validate a custom-widget spec without storing it: checks the
 * title, controls (names, supported types, labels), and template. Read-only.
 */
class Validate_Widget_Spec
{
    public function handle(array $args): array
    {
        $spec   = is_array($args['spec'] ?? null) ? $args['spec'] : [];
        $result = Widget_Spec::validate($spec);

        if (is_wp_error($result)) {
            return ['valid' => false, 'error' => $result->get_error_message(), 'code' => $result->get_error_code()];
        }
        return ['valid' => true];
    }
}
