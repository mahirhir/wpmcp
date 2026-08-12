<?php

namespace WPMCP\Tools\Brand;

use WPMCP\Tools\Elementor\Elementor_Kit_Data;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Apply a brand kit to the active Elementor kit: system palette, extra
 * swatches, the four system typography tokens and the logo, all written in
 * ONE snapshotted operation so the whole rebrand is undone by a single
 * rollback (rollback-brand-kit, or rollback-operation with the returned
 * operation_id).
 *
 * Two guards stand in front of the write, both of which fail closed:
 *  - no `confirm: true` returns a PREVIEW: the exact per-token diff the
 *    write would produce plus the settings_hash to pass back, and nothing is
 *    written. Replacing a site's palette and fonts is site-wide and visible
 *    on every page, so the default has to be "show me first";
 *  - `expected_hash` (Elementor_Kit_Data::guard) refuses the write when the
 *    kit changed since it was read, so two agents cannot interleave.
 * A kit carrying any validation error is refused outright rather than
 * applied minus its bad tokens.
 */
class Apply_Brand_Kit
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

        if ([] !== $kit['invalid']) {
            return new \WP_Error(
                'invalid_brand_kit',
                sprintf(
                    'Brand kit "%s" has invalid entries and was not applied (a partly-valid kit would leave a half-rebranded site): %s',
                    $slug,
                    implode(' ', $kit['invalid'])
                )
            );
        }

        $kit_id = Elementor_Kit_Data::active_kit_id();
        if ($kit_id <= 0) {
            return new \WP_Error('kit_not_found', 'No active Elementor kit was found.');
        }

        $include_logo = ! array_key_exists('include_logo', $args) || (bool) $args['include_logo'];
        if ($include_logo && null !== $kit['logo'] && $kit['logo']['id'] > 0 && 'attachment' !== get_post_type($kit['logo']['id'])) {
            return new \WP_Error(
                'logo_not_found',
                sprintf('Brand kit "%s" points at attachment %d, which is not in this media library. Nothing was written.', $slug, $kit['logo']['id'])
            );
        }

        $plan = Brand_Kit_Applier::plan($kit_id, $kit, $include_logo);

        if (true !== ($args['confirm'] ?? null)) {
            return [
                'applied'       => false,
                'preview'       => true,
                'slug'          => $kit['slug'],
                'title'         => $kit['title'],
                'kit_id'        => $kit_id,
                'changes'       => $plan['changes'],
                'counts'        => $plan['counts'],
                'settings_hash' => Elementor_Kit_Data::settings_hash($kit_id),
                'next_step'     => 'Nothing was written. Applying a brand kit replaces the site-wide palette and typography, '
                    . 'so re-call with confirm:true and expected_hash set to the settings_hash above to write it as one snapshotted operation.',
            ];
        }

        $guarded = Elementor_Kit_Data::guard($args);
        if (is_wp_error($guarded)) {
            return $guarded;
        }

        $result = Elementor_Kit_Data::write($kit_id, $plan['patch'], 'apply-brand-kit', $args);
        if (is_wp_error($result)) {
            return $result;
        }

        Brand_Kit_Store::record_apply([
            'operation_id' => $result['operation_id'],
            'slug'         => $kit['slug'],
            'title'        => $kit['title'],
            'kit_id'       => $kit_id,
            'session_id'   => (string) ($args['session_id'] ?? 'default'),
            'applied_at'   => gmdate('c'),
        ]);

        return array_merge($result, [
            'applied'  => true,
            'preview'  => false,
            'slug'     => $kit['slug'],
            'title'    => $kit['title'],
            'changes'  => $plan['changes'],
            'counts'   => $plan['counts'],
            'rollback' => 'One snapshot covers every token this apply wrote: undo the whole rebrand with rollback-brand-kit, '
                . 'or with rollback-operation using this operation_id.',
        ]);
    }
}
