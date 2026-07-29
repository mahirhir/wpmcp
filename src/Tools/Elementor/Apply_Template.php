<?php

namespace WPMCP\Tools\Elementor;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Apply a library template's content to a page. The template's element tree is
 * copied with freshly regenerated ids (so it never collides with the page) and
 * either appended (default, optionally under parent_id at position) or used to
 * replace the whole page (mode = replace). Requires expected_hash from
 * get-elementor-data and writes snapshot-first through Element_Tree, so the
 * change is undoable via rollback-operation.
 */
class Apply_Template
{
    public function handle(array $args)
    {
        $template_id = (int) ($args['template_id'] ?? 0);
        if ($template_id <= 0) {
            return new \WP_Error('missing_template_id', 'A template_id is required.');
        }
        if (! Elementor_Template_Data::is_template($template_id)) {
            return new \WP_Error('not_a_template', "Post {$template_id} is not an elementor_library template.");
        }

        $template_tree = Elementor_Template_Data::data($template_id);
        if ([] === $template_tree) {
            return new \WP_Error('empty_template', "Template {$template_id} has no Elementor content.");
        }

        $read = Element_Tree::read_for_edit($args);
        if (is_wp_error($read)) {
            return $read;
        }
        [$post_id, $elements] = $read;

        $mode = 'replace' === ($args['mode'] ?? 'append') ? 'replace' : 'append';

        $taken = [];
        if ('append' === $mode) {
            foreach (Elementor_Template_Data::collect_ids($elements) as $id) {
                $taken[$id] = true;
            }
        }
        $copy  = Elementor_Template_Data::regenerate_ids($template_tree, $taken);
        $added = count($copy);

        if ('replace' === $mode) {
            $elements = $copy;
        } else {
            $parent_id = (string) ($args['parent_id'] ?? '');
            if ('' === $parent_id) {
                foreach ($copy as $element) {
                    $elements[] = $element;
                }
            } else {
                foreach ($copy as $element) {
                    if (! Elementor_Page_Data::insert($elements, $parent_id, $element)) {
                        return new \WP_Error('parent_not_found', "No element found with id '{$parent_id}' to insert under.");
                    }
                }
            }
        }

        $out = Element_Tree::write($post_id, $elements, 'apply-template', $args);
        if (is_wp_error($out)) {
            return $out;
        }

        return $out + [
            'template_id'    => $template_id,
            'mode'           => $mode,
            'elements_added' => $added,
        ];
    }
}
