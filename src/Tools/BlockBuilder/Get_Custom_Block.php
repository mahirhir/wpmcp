<?php

namespace WPMCP\Tools\BlockBuilder;

if (! defined('ABSPATH')) {
    exit;
}

/** Read one custom block's stored spec by id. Read-only. */
class Get_Custom_Block
{
    public function handle(array $args)
    {
        $id   = (int) ($args['block_id'] ?? 0);
        $spec = Block_Spec_Store::get($id);
        if (null === $spec) {
            return new \WP_Error('block_not_found', "No custom block found with id {$id}.");
        }

        return [
            'block_id' => $id,
            'status'   => get_post_status($id),
            'spec'     => $spec,
        ];
    }
}
