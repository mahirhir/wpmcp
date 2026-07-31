<?php

namespace WPMCP\Tools\Cloud;

use WPMCP\Cloud\Cloud_Client;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * List the assets (widget/block specs) stored in this site's WP MCP Cloud
 * account. Read-only.
 */
class Cloud_List_Assets
{
    public function handle(array $args)
    {
        $result = (new Cloud_Client())->get('/assets');
        if (is_wp_error($result)) {
            return $result;
        }

        return ['assets' => is_array($result['assets'] ?? null) ? $result['assets'] : []];
    }
}
