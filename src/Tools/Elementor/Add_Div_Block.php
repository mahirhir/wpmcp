<?php

namespace WPMCP\Tools\Elementor;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Add an Elementor 4.0+ atomic div-block container (elType e-div-block) to a
 * page, under parent_id (or top level) at an optional position. Requires
 * expected_hash from get-elementor-data; written snapshot-first so it is
 * undoable via rollback-operation.
 */
class Add_Div_Block
{
    public function handle(array $args)
    {
        return Atomic_Layout::add($args, 'e-div-block', 'add-div-block');
    }
}
