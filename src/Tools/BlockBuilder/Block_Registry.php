<?php

namespace WPMCP\Tools\BlockBuilder;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Registers active custom block specs as real Gutenberg blocks at runtime via
 * register_block_type, each with a render_callback that interprets the spec's
 * template (Block_Renderer). Data-driven, no code generation, no eval.
 */
class Block_Registry
{
    /** Hooked on init (after the CPT is registered). */
    public static function register(): void
    {
        if (! function_exists('register_block_type')) {
            return;
        }

        foreach (Block_Spec_Store::all(true) as $row) {
            $spec = Block_Spec_Store::get((int) $row['block_id']);
            if (! is_array($spec) || true !== Block_Spec::validate($spec)) {
                continue;
            }
            $name = (string) $spec['name'];
            if (\WP_Block_Type_Registry::get_instance()->is_registered($name)) {
                continue;
            }

            register_block_type($name, [
                'attributes'      => self::attributes($spec),
                'render_callback' => static function (array $attributes) use ($spec): string {
                    return Block_Renderer::render($spec, $attributes);
                },
            ]);
        }
    }

    /** Build the block.json attribute map from the spec. */
    private static function attributes(array $spec): array
    {
        $out = [];
        foreach ((array) ($spec['attributes'] ?? []) as $attr) {
            if (! is_array($attr)) {
                continue;
            }
            $name = sanitize_key((string) ($attr['name'] ?? ''));
            $type = (string) ($attr['type'] ?? 'string');
            if ('' === $name || ! isset(Block_Spec::ATTRIBUTE_TYPES[$type])) {
                continue;
            }
            $out[$name] = [
                'type'    => Block_Spec::ATTRIBUTE_TYPES[$type]['json'],
                'default' => $attr['default'] ?? '',
            ];
        }
        return $out;
    }
}
