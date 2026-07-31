<?php

namespace WPMCP\Tools\BlockBuilder;

if (! defined('ABSPATH')) {
    exit;
}

/** Replace a custom block's spec by id (re-validated before it is stored). */
class Update_Custom_Block
{
    public function handle(array $args)
    {
        $id = (int) ($args['block_id'] ?? 0);
        if (! Block_Spec_Store::is_block($id)) {
            return new \WP_Error('block_not_found', "No custom block found with id {$id}.");
        }

        $spec  = is_array($args['spec'] ?? null) ? $args['spec'] : [];
        $valid = Block_Spec::validate($spec);
        if (is_wp_error($valid)) {
            return $valid;
        }

        Block_Spec_Store::update($id, $spec);
        $stored = Block_Spec_Store::get($id);

        return [
            'block_id' => $id,
            'name'     => (string) ($stored['name'] ?? ''),
            'title'    => (string) ($stored['title'] ?? ''),
        ];
    }
}
