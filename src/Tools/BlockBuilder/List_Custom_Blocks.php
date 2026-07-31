<?php

namespace WPMCP\Tools\BlockBuilder;

if (! defined('ABSPATH')) {
    exit;
}

/** List the custom blocks on this site (id, name, title, active/inactive). Read-only. */
class List_Custom_Blocks
{
    public function handle(array $args): array
    {
        return ['blocks' => Block_Spec_Store::all()];
    }
}
