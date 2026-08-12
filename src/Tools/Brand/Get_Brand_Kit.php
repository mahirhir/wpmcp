<?php

namespace WPMCP\Tools\Brand;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Return one brand kit's full definition in the exact shape apply-brand-kit
 * will write: system slots as hex, extra swatches with their generated _id,
 * and typography already mapped to Elementor's `typography_*` control fields.
 * Anything that failed validation is reported in `invalid` (a kit with a
 * non-empty `invalid` list is refused by apply-brand-kit). Read-only.
 */
class Get_Brand_Kit
{
    public function handle(array $args)
    {
        $slug = sanitize_key((string) ($args['slug'] ?? ''));
        if ('' === $slug) {
            return new \WP_Error('missing_slug', '"slug" is required: pick one from list-brand-kits.');
        }

        $kit = Brand_Kit_Store::get($slug);
        if (null === $kit) {
            return new \WP_Error(
                'brand_kit_not_found',
                sprintf('No brand kit is registered under the slug "%s". Call list-brand-kits to see what is available.', $slug)
            );
        }

        return $kit;
    }
}
