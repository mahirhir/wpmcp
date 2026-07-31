<?php

namespace WPMCP\Tools\WidgetBuilder;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * List the custom widgets stored on this site (id, machine name, title, and
 * active/inactive status). Read-only.
 */
class List_Custom_Widgets
{
    public function handle(array $args): array
    {
        return ['widgets' => Widget_Spec_Store::all()];
    }
}
