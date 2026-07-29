<?php

namespace WPMCP\Tools\Elementor;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Read one Elementor library template: its type, display conditions, and how
 * many elements it holds. Read-only.
 */
class Get_Theme_Template
{
    public function handle(array $args)
    {
        $post_id = (int) ($args['post_id'] ?? 0);
        if ($post_id <= 0) {
            return new \WP_Error('missing_post_id', 'A post_id is required.');
        }
        if (! Elementor_Template_Data::is_template($post_id)) {
            return new \WP_Error('not_a_template', "Post {$post_id} is not an elementor_library template.");
        }

        return [
            'template_id'   => $post_id,
            'title'         => get_the_title($post_id),
            'template_type' => (string) get_post_meta($post_id, '_elementor_template_type', true),
            'conditions'    => Elementor_Template_Data::conditions($post_id),
            'element_count' => count(Elementor_Template_Data::data($post_id)),
        ];
    }
}
