<?php

namespace WPMCP\Tools\Elementor;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Read-only: return a page's parsed `_elementor_data` element tree (id,
 * elType, widgetType, settings, and nested elements for every node), read
 * straight from postmeta. Never mutates anything, so this is not routed
 * through the safety core.
 *
 * Large-page ergonomics (issue #137): the full tree is still the default so
 * existing callers are unaffected, but three optional projections make a
 * 500-element page workable without blowing the context window:
 *
 *   summary=true   skeleton only (id, elType, widgetType, label, child and
 *                  descendant counts), every settings blob dropped
 *   max_depth=N    stop at depth N; cut nodes report truncated_children
 *   element_id=ID  window the read onto one subtree
 *
 * Every response reports total_elements / returned_elements / truncated, and
 * a full read of a large page carries a hint pointing at summary mode. The
 * hashes are unaffected by the projection: data_hash always covers the whole
 * stored page, so a windowed read is still a valid basis for a guarded write.
 *
 * Also the read half of the structural suite's concurrency contract
 * (issue #58): data_hash (sha256 of the raw `_elementor_data` JSON) and
 * settings_hash (sha256 of the JSON-encoded `_elementor_page_settings`)
 * are what the structural mutations require back as expected_hash, so a
 * write can prove the page did not change between read and write.
 */
class Get_Elementor_Data
{
    public function handle(array $args)
    {
        $post_id = (int) ($args['post_id'] ?? 0);

        if ($post_id <= 0) {
            return new \WP_Error('missing_post_id', 'A post_id is required.');
        }

        $window = Element_Window::project(
            Elementor_Page_Data::get($post_id),
            [
                'summary'   => ! empty($args['summary']),
                'max_depth' => $args['max_depth'] ?? null,
                'root_id'   => (string) ($args['element_id'] ?? ''),
            ]
        );

        if (is_wp_error($window)) {
            return $window;
        }

        $out = [
            'post_id'           => $post_id,
            'elements'          => $window['elements'],
            'total_elements'    => $window['total_elements'],
            'returned_elements' => $window['returned_elements'],
            'truncated'         => $window['truncated'],
            'summary'           => $window['summary'],
            'data_hash'         => Element_Tree::data_hash($post_id),
            'page_settings'     => Element_Tree::page_settings($post_id),
            'settings_hash'     => Element_Tree::settings_hash($post_id),
        ];

        if (null !== $window['max_depth']) {
            $out['max_depth'] = $window['max_depth'];
        }
        if (null !== $window['root_id']) {
            $out['element_id'] = $window['root_id'];
        }

        $hint = self::hint($window);
        if ('' !== $hint) {
            $out['hint'] = $hint;
        }

        return $out;
    }

    /** Nudge toward the cheaper projection when a full read is big, or toward the next read when it was cut. */
    private static function hint(array $window): string
    {
        if ($window['truncated']) {
            return 'Some branches were cut by max_depth; read a cut branch with element_id set to its id '
                . '(truncated_children shows how many children were withheld).';
        }

        if (! $window['summary'] && Element_Window::is_large($window['total_elements'])) {
            return sprintf(
                'This page has %d elements. Re-read with summary=true (skeleton plus child counts) '
                . 'or max_depth to keep the payload small, then element_id to pull one subtree in full.',
                $window['total_elements']
            );
        }

        return '';
    }
}
