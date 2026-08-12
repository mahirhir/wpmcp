<?php

namespace WPMCP\Tools\Elementor;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Update an Elementor 4.0+ global class by id: rename it, and/or merge styles
 * into the variant for one breakpoint + state.
 *
 * Per-variant semantics match the Class Manager: styles are merged into the
 * matching variant so an unrelated responsive or hover rule survives, and
 * replace_variant:true swaps that variant's props wholesale. The whole class
 * is re-validated against Elementor's style schema before the write, and the
 * previous class set is snapshotted, so rollback-operation restores the class
 * exactly as it was.
 */
class Update_Global_Class
{
    public function handle(array $args)
    {
        $id = sanitize_text_field((string) ($args['id'] ?? ''));
        if ('' === $id) {
            return new \WP_Error('missing_id', 'A global class "id" is required.');
        }

        $has_styles = is_array($args['styles'] ?? null) || is_array($args['props'] ?? null);
        $has_label  = isset($args['label']);
        if (! $has_styles && ! $has_label) {
            return new \WP_Error('nothing_to_update', 'Provide a new "label" and/or "styles"/"props" to update.');
        }

        $meta = Global_Class_Schema::variant_meta($args);
        if (is_wp_error($meta)) {
            return $meta;
        }

        $props = Global_Class_Schema::props($args);
        if (is_wp_error($props)) {
            return $props;
        }

        $state = Global_Classes_Store::guard($args);
        if (is_wp_error($state)) {
            return $state;
        }

        $items = $state['items'];
        if (! isset($items[$id])) {
            return new \WP_Error('class_not_found', sprintf('No global class found with id "%s".', $id));
        }

        $item = $items[$id];

        if ($has_label) {
            $label = Global_Class_Schema::label((string) $args['label']);
            if (is_wp_error($label)) {
                return $label;
            }
            $item['label'] = $label;
        }

        if ($has_styles) {
            $item['variants'] = self::apply_variant(
                (array) ($item['variants'] ?? []),
                $meta,
                $props,
                ! empty($args['replace_variant'])
            );
        }

        $validated = Global_Class_Schema::validate_item($item);
        if (is_wp_error($validated)) {
            return $validated;
        }

        $items[$id] = $validated;

        $out = Global_Classes_Store::write($state, $items, $state['order'], 'update-global-class', $args);
        if (is_wp_error($out)) {
            return $out;
        }

        return $out + [
            'id'       => $id,
            'label'    => (string) ($validated['label'] ?? ''),
            'variants' => count((array) ($validated['variants'] ?? [])),
        ];
    }

    /**
     * Merge (or replace) the props of the variant matching $meta, appending a
     * new variant when this breakpoint + state has no styles yet.
     */
    private static function apply_variant(array $variants, array $meta, array $props, bool $replace): array
    {
        $variants = array_values($variants);

        foreach ($variants as $index => $variant) {
            $variant_meta = (array) (((array) $variant)['meta'] ?? []);
            $breakpoint   = $variant_meta['breakpoint'] ?? 'desktop';
            $state        = $variant_meta['state'] ?? null;

            if ($breakpoint === $meta['breakpoint'] && $state === $meta['state']) {
                $existing = (array) (((array) $variant)['props'] ?? []);
                $variants[$index]['props'] = $replace ? $props : array_merge($existing, $props);

                return $variants;
            }
        }

        $variants[] = ['meta' => $meta, 'props' => $props];

        return $variants;
    }
}
