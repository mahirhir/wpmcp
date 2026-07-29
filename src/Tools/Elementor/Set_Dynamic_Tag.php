<?php

namespace WPMCP\Tools\Elementor;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Bind an Elementor dynamic tag to an element setting. Writes the tag into the
 * element's `settings['__dynamic__'][setting_key]` in Elementor's
 * `[elementor-tag ...]` format, preserving any other dynamic bindings on the
 * element. Requires expected_hash from get-elementor-data; snapshot-first and
 * undoable via rollback-operation.
 */
class Set_Dynamic_Tag
{
    public function handle(array $args)
    {
        $element_id  = (string) ($args['element_id'] ?? '');
        $setting_key = (string) ($args['setting_key'] ?? '');
        $tag_name    = (string) ($args['tag_name'] ?? '');

        if ('' === $element_id || '' === $setting_key || '' === $tag_name) {
            return new \WP_Error('missing_params', 'element_id, setting_key, and tag_name are all required.');
        }

        $read = Element_Tree::read_for_edit($args);
        if (is_wp_error($read)) {
            return $read;
        }
        [$post_id, $elements] = $read;

        $element = Elementor_Page_Data::find($elements, $element_id);
        if (null === $element) {
            return new \WP_Error('element_not_found', "No element found with id '{$element_id}'.");
        }

        $tag_settings = is_array($args['tag_settings'] ?? null) ? $args['tag_settings'] : [];
        $encoded      = self::encode($tag_name, $tag_settings);

        $dynamic = [];
        if (isset($element['settings']['__dynamic__']) && is_array($element['settings']['__dynamic__'])) {
            $dynamic = $element['settings']['__dynamic__'];
        }
        $dynamic[$setting_key] = $encoded;

        Elementor_Page_Data::update_settings($elements, $element_id, ['__dynamic__' => $dynamic]);

        // Raw snapshot write (not Document::save): Elementor's save path drops a
        // __dynamic__ binding whose tag is not registered (e.g. Pro-only tags on
        // a free install), which would fail the write. The raw path stores the
        // binding verbatim so Elementor reads it when the tag becomes available.
        $out = Atomic_Element::write($post_id, $elements, 'set-dynamic-tag', $args);
        if (is_wp_error($out)) {
            return $out;
        }

        return $out + ['element_id' => $element_id, 'setting_key' => $setting_key, 'tag_name' => $tag_name];
    }

    /** Build Elementor's dynamic-tag shortcode value. */
    private static function encode(string $tag_name, array $tag_settings): string
    {
        $id = substr(bin2hex(random_bytes(4)), 0, 7);

        return sprintf(
            '[elementor-tag id="%s" name="%s" settings="%s"]',
            $id,
            $tag_name,
            rawurlencode((string) wp_json_encode($tag_settings, JSON_FORCE_OBJECT))
        );
    }
}
