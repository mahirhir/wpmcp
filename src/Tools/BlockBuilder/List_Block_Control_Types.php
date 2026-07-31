<?php

namespace WPMCP\Tools\BlockBuilder;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * List the attribute types a custom block spec may use, with the block.json
 * type each maps to and a short description. Read-only.
 */
class List_Block_Control_Types
{
    public function handle(array $args): array
    {
        $types = [];
        foreach (Block_Spec::ATTRIBUTE_TYPES as $type => $meta) {
            $types[] = [
                'type'        => $type,
                'json'        => $meta['json'],
                'description' => $meta['desc'],
            ];
        }
        return ['attribute_types' => $types];
    }
}
