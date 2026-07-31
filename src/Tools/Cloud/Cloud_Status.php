<?php

namespace WPMCP\Tools\Cloud;

use WPMCP\Cloud\Cloud_Config;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Report whether this site is connected to WP MCP Cloud and where. Read-only.
 */
class Cloud_Status
{
    public function handle(array $args): array
    {
        return [
            'connected' => Cloud_Config::is_configured(),
            'url'       => Cloud_Config::base_url(),
        ];
    }
}
