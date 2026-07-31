<?php

namespace WPMCP\Tools\BlockBuilder;

if (! defined('ABSPATH')) {
    exit;
}

/** Statically validate a custom block spec without storing it. Read-only. */
class Validate_Block_Spec
{
    public function handle(array $args): array
    {
        $spec   = is_array($args['spec'] ?? null) ? $args['spec'] : [];
        $result = Block_Spec::validate($spec);

        if (is_wp_error($result)) {
            return ['valid' => false, 'error' => $result->get_error_message(), 'code' => $result->get_error_code()];
        }
        return ['valid' => true];
    }
}
