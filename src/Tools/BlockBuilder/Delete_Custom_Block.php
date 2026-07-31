<?php

namespace WPMCP\Tools\BlockBuilder;

if (! defined('ABSPATH')) {
    exit;
}

/** Delete a custom block by moving its wpmcp_block post to the trash (reversible). */
class Delete_Custom_Block
{
    public function handle(array $args)
    {
        $id = (int) ($args['block_id'] ?? 0);
        if (! Block_Spec_Store::is_block($id)) {
            return new \WP_Error('block_not_found', "No custom block found with id {$id}.");
        }

        wp_trash_post($id);

        return ['block_id' => $id, 'deleted' => 'trashed'];
    }
}
