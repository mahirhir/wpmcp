<?php

namespace WPMCP\Tools\Cloud;

use WPMCP\Cloud\Cloud_Client;
use WPMCP\Tools\BlockBuilder\Block_Spec_Store;
use WPMCP\Tools\WidgetBuilder\Widget_Spec_Store;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Push this site's builder assets (custom widget + block specs) up to WP MCP
 * Cloud, so they are backed up and reusable across sites. Optionally filter by
 * type (widget|block). Each spec is sent as one asset (POST /assets).
 */
class Cloud_Push_Assets
{
    public function handle(array $args)
    {
        $client = new Cloud_Client();
        $types  = self::types($args);

        $pushed  = 0;
        $results = [];

        foreach (self::local_assets($types) as $asset) {
            $out = $client->post('/assets', $asset);
            if (is_wp_error($out)) {
                return $out;
            }
            $pushed++;
            $results[] = [
                'type'      => $asset['type'],
                'name'      => $asset['name'],
                'remote_id' => (string) ($out['asset']['id'] ?? ''),
            ];
        }

        return ['pushed' => $pushed, 'results' => $results];
    }

    /** @return array<int,array{type:string,name:string,title:string,spec:array}> */
    private static function local_assets(array $types): array
    {
        $assets = [];

        if (in_array('widget', $types, true)) {
            foreach (Widget_Spec_Store::all() as $row) {
                $spec = Widget_Spec_Store::get((int) $row['widget_id']);
                if (is_array($spec)) {
                    $assets[] = ['type' => 'widget', 'name' => (string) $row['name'], 'title' => (string) $row['title'], 'spec' => $spec];
                }
            }
        }
        if (in_array('block', $types, true)) {
            foreach (Block_Spec_Store::all() as $row) {
                $spec = Block_Spec_Store::get((int) $row['block_id']);
                if (is_array($spec)) {
                    $assets[] = ['type' => 'block', 'name' => (string) $row['name'], 'title' => (string) $row['title'], 'spec' => $spec];
                }
            }
        }

        return $assets;
    }

    /** @return array<int,string> */
    private static function types(array $args): array
    {
        $types = is_array($args['types'] ?? null) ? array_map('strval', $args['types']) : ['widget', 'block'];
        $types = array_values(array_intersect(['widget', 'block'], $types));
        return [] === $types ? ['widget', 'block'] : $types;
    }
}
