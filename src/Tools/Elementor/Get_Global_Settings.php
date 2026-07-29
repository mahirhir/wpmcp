<?php

namespace WPMCP\Tools\Elementor;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Read the active Elementor kit's global design tokens: system + custom
 * colors and typography, plus a settings_hash for chaining a guarded write
 * with update-global-colors / update-global-typography. Read-only.
 */
class Get_Global_Settings
{
    public function handle(array $args)
    {
        $kit_id = Elementor_Kit_Data::active_kit_id();
        if ($kit_id <= 0) {
            return new \WP_Error('kit_not_found', 'No active Elementor kit was found.');
        }

        return array_merge(
            ['kit_id' => $kit_id],
            Elementor_Kit_Data::view($kit_id),
            ['settings_hash' => Elementor_Kit_Data::settings_hash($kit_id)]
        );
    }
}
