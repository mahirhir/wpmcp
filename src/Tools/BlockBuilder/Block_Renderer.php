<?php

namespace WPMCP\Tools\BlockBuilder;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Renders a custom block spec by interpolating attribute values into its
 * template. Pure and eval-free: each {{name}} placeholder is replaced with the
 * matching attribute, escaped by that attribute's type (string/color →
 * esc_html, richtext → wp_kses_post, url/image → esc_url, number → intval,
 * boolean → 'yes'/''). Unknown placeholders render empty. This is the single
 * output path the runtime render_callback uses, so a stored spec never executes
 * code.
 */
class Block_Renderer
{
    public static function render(array $spec, array $attributes): string
    {
        $attrs    = is_array($spec['attributes'] ?? null) ? $spec['attributes'] : [];
        $template = (string) ($spec['template'] ?? '');

        $values = [];
        foreach ($attrs as $attr) {
            $name = sanitize_key((string) ($attr['name'] ?? ''));
            if ('' === $name) {
                continue;
            }
            $raw = $attributes[$name] ?? ($attr['default'] ?? '');
            $values[$name] = self::escape((string) ($attr['type'] ?? 'string'), $raw);
        }

        return (string) preg_replace_callback(
            '/\{\{\s*([a-z0-9_\-]+)\s*\}\}/i',
            static function (array $m) use ($values): string {
                return $values[sanitize_key($m[1])] ?? '';
            },
            $template
        );
    }

    /** @param mixed $value */
    private static function escape(string $type, $value): string
    {
        switch ($type) {
            case 'richtext':
                return wp_kses_post((string) $value);
            case 'url':
            case 'image':
                return esc_url((string) $value);
            case 'number':
                return (string) (0 + (is_numeric($value) ? $value : 0));
            case 'boolean':
                return $value ? 'yes' : '';
            default:
                return esc_html((string) $value);
        }
    }
}
