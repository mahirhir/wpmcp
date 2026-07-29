<?php

namespace WPMCP\Tools\Elementor;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Add an Elementor 4.0+ atomic flexbox container (elType e-flexbox) to a page,
 * under parent_id (or top level) at an optional position. Requires
 * expected_hash from get-elementor-data; written snapshot-first so it is
 * undoable via rollback-operation.
 */
class Add_Flexbox
{
    public function handle(array $args)
    {
        return Atomic_Layout::add($args, 'e-flexbox', 'add-flexbox');
    }
}
