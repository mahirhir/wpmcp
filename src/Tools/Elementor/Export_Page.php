<?php

namespace WPMCP\Tools\Elementor;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Export a page's Elementor content to a portable structure (content +
 * page_settings + type + version), the shape import-template accepts. This is
 * the same envelope Elementor's own template export uses, so an agent can move
 * a design between pages or sites. Read-only.
 */
class Export_Page
{
    public function handle(array $args)
    {
        $post_id = (int) ($args['post_id'] ?? 0);
        if ($post_id <= 0) {
            return new \WP_Error('missing_post_id', 'A post_id is required.');
        }
        if (! get_post($post_id)) {
            return new \WP_Error('post_not_found', "No post found with id {$post_id}.");
        }

        return [
            'title'         => get_the_title($post_id),
            'type'          => 'page',
            'version'       => defined('ELEMENTOR_VERSION') ? ELEMENTOR_VERSION : '',
            'content'       => Elementor_Page_Data::get($post_id),
            'page_settings' => Element_Tree::page_settings($post_id),
        ];
    }
}
