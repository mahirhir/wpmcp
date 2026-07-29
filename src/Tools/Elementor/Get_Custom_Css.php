<?php

namespace WPMCP\Tools\Elementor;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Read the site's WordPress core Additional CSS. Read-only.
 */
class Get_Custom_Css
{
    public function handle(array $args): array
    {
        return ['css' => (string) wp_get_custom_css()];
    }
}
