<?php

namespace WPMCP\Tools\Cloud;

use WPMCP\Cloud\Cloud_Client;
use WPMCP\Tools\BlockBuilder\Block_Spec;
use WPMCP\Tools\BlockBuilder\Block_Spec_Store;
use WPMCP\Tools\WidgetBuilder\Widget_Spec;
use WPMCP\Tools\WidgetBuilder\Widget_Spec_Store;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Pull the builder assets from this site's WP MCP Cloud account and recreate
 * them locally as custom widget / block specs. Each pulled spec is validated
 * before it is stored, so a malformed cloud asset is skipped rather than
 * creating a broken widget.
 */
class Cloud_Pull_Assets
{
    public function handle(array $args)
    {
        $result = (new Cloud_Client())->get('/assets');
        if (is_wp_error($result)) {
            return $result;
        }

        $assets = is_array($result['assets'] ?? null) ? $result['assets'] : [];
        $pulled = 0;

        foreach ($assets as $asset) {
            if (! is_array($asset) || ! is_array($asset['spec'] ?? null)) {
                continue;
            }
            $type = (string) ($asset['type'] ?? '');
            $spec = $asset['spec'];

            if ('widget' === $type && true === Widget_Spec::validate($spec)) {
                if (! is_wp_error(Widget_Spec_Store::create($spec))) {
                    $pulled++;
                }
            } elseif ('block' === $type && true === Block_Spec::validate($spec)) {
                if (! is_wp_error(Block_Spec_Store::create($spec))) {
                    $pulled++;
                }
            }
        }

        return ['pulled' => $pulled];
    }
}
