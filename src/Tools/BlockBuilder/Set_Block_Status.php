<?php

namespace WPMCP\Tools\BlockBuilder;

if (! defined('ABSPATH')) {
    exit;
}

/** Enable (publish) or disable (draft) a custom block by id. */
class Set_Block_Status
{
    public function handle(array $args)
    {
        $id = (int) ($args['block_id'] ?? 0);
        if (! Block_Spec_Store::is_block($id)) {
            return new \WP_Error('block_not_found', "No custom block found with id {$id}.");
        }

        $status = 'draft' === ($args['status'] ?? '') ? 'draft' : 'publish';
        wp_update_post(['ID' => $id, 'post_status' => $status]);

        return ['block_id' => $id, 'status' => $status];
    }
}
