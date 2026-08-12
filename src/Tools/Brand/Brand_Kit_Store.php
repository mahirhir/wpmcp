<?php

namespace WPMCP\Tools\Brand;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The brand-kit library: named design systems (a four-slot system palette,
 * extra named swatches, the four system typography tokens, and an optional
 * logo reference) that apply-brand-kit writes onto the active Elementor kit
 * in one guarded, snapshotted operation.
 *
 * Three sources, merged in increasing precedence:
 *  - BUNDLED presets defined in this class (always available, never edited);
 *  - the `wpmcp_brand_kits` option (site-authored kits, slug => definition);
 *  - the `wpmcp_brand_kits` filter (programmatic registration).
 * A site definition that reuses a bundled slug shadows the preset, so a site
 * can retune a preset without forking the plugin; its source is reported as
 * 'site' so an agent can tell curated data from site data.
 *
 * Every definition is normalized on read: friendly authoring keys
 * (font_family, font_size: "18px") are mapped to the exact Elementor control
 * fields that will be written, hex colors are validated, and anything that
 * fails validation is dropped from the kit AND recorded in the kit's
 * `invalid` list. apply-brand-kit refuses to apply a kit with a non-empty
 * `invalid` list, so a hand-edited option can never half-apply.
 *
 * The apply log (`wpmcp_brand_kit_applies` option) records the operation_id
 * of each apply so rollback-brand-kit can undo the most recent one without
 * the agent having to have kept the id around.
 */
class Brand_Kit_Store
{
    public const OPTION_KITS    = 'wpmcp_brand_kits';
    public const OPTION_APPLIES = 'wpmcp_brand_kit_applies';

    /** How many applies the log keeps (newest first). */
    private const APPLY_LOG_LIMIT = 20;

    /** Elementor's four reserved system slots, the ones a brand kit maps onto. */
    public const SYSTEM_SLOTS = ['primary', 'secondary', 'text', 'accent'];

    /** Friendly authoring key => Elementor typography control field. */
    private const TYPOGRAPHY_FIELDS = [
        'font_family'    => 'typography_font_family',
        'font_weight'    => 'typography_font_weight',
        'font_style'     => 'typography_font_style',
        'text_transform' => 'typography_text_transform',
        'font_size'      => 'typography_font_size',
        'line_height'    => 'typography_line_height',
        'letter_spacing' => 'typography_letter_spacing',
    ];

    /** Slider-shaped typography fields and the unit assumed when none is given. */
    private const TYPOGRAPHY_UNITS = [
        'typography_font_size'      => 'px',
        'typography_line_height'    => 'em',
        'typography_letter_spacing' => 'px',
    ];

    private const ALLOWED_UNITS = ['px', 'em', 'rem', '%', 'vw'];

    /**
     * The curated presets. Deliberately small and opinionated: these are
     * samples that prove the shape, not a 50-kit library, and they cost the
     * tools/list payload nothing because they are data behind four generic
     * tools rather than a tool per kit.
     *
     * @return array<string, array<string, mixed>> slug => raw definition.
     */
    public static function bundled(): array
    {
        return [
            'modern-saas' => [
                'title'         => 'Modern SaaS',
                'description'   => 'Cool blue on slate with a geometric sans; the default look for product and dashboard sites.',
                'category'      => 'product',
                'colors'        => [
                    'primary'   => '#2563eb',
                    'secondary' => '#1e293b',
                    'text'      => '#334155',
                    'accent'    => '#0ea5e9',
                ],
                'custom_colors' => [
                    ['title' => 'Surface', 'color' => '#f8fafc'],
                    ['title' => 'Border', 'color' => '#e2e8f0'],
                ],
                'typography'    => [
                    'primary'   => ['font_family' => 'Inter', 'font_weight' => '700', 'font_size' => '40px', 'line_height' => '1.2em'],
                    'secondary' => ['font_family' => 'Inter', 'font_weight' => '600', 'font_size' => '24px'],
                    'text'      => ['font_family' => 'Inter', 'font_weight' => '400', 'font_size' => '17px', 'line_height' => '1.7em'],
                    'accent'    => ['font_family' => 'Inter', 'font_weight' => '600', 'text_transform' => 'uppercase', 'letter_spacing' => '1px'],
                ],
            ],
            'editorial-serif' => [
                'title'         => 'Editorial Serif',
                'description'   => 'Warm paper neutrals with a display serif over a reading serif; for magazines and long-form writing.',
                'category'      => 'editorial',
                'colors'        => [
                    'primary'   => '#1f2933',
                    'secondary' => '#8a6a3d',
                    'text'      => '#3e4c59',
                    'accent'    => '#b44c1f',
                ],
                'custom_colors' => [
                    ['title' => 'Paper', 'color' => '#faf7f2'],
                    ['title' => 'Rule', 'color' => '#ded6c9'],
                ],
                'typography'    => [
                    'primary'   => ['font_family' => 'Playfair Display', 'font_weight' => '700', 'font_size' => '44px', 'line_height' => '1.15em'],
                    'secondary' => ['font_family' => 'Playfair Display', 'font_weight' => '600', 'font_size' => '26px'],
                    'text'      => ['font_family' => 'Source Serif 4', 'font_weight' => '400', 'font_size' => '19px', 'line_height' => '1.75em'],
                    'accent'    => ['font_family' => 'Source Serif 4', 'font_weight' => '600', 'font_style' => 'italic'],
                ],
            ],
            'bold-agency' => [
                'title'         => 'Bold Agency',
                'description'   => 'Near-black with an electric accent and a wide grotesque; high-contrast portfolio and agency sites.',
                'category'      => 'agency',
                'colors'        => [
                    'primary'   => '#0b0b0f',
                    'secondary' => '#6f6f7a',
                    'text'      => '#2a2a33',
                    'accent'    => '#ff3d2e',
                ],
                'custom_colors' => [
                    ['title' => 'Canvas', 'color' => '#f4f4f2'],
                ],
                'typography'    => [
                    'primary'   => ['font_family' => 'Space Grotesk', 'font_weight' => '700', 'font_size' => '56px', 'line_height' => '1.05em', 'letter_spacing' => '-1px'],
                    'secondary' => ['font_family' => 'Space Grotesk', 'font_weight' => '500', 'font_size' => '28px'],
                    'text'      => ['font_family' => 'Inter', 'font_weight' => '400', 'font_size' => '18px', 'line_height' => '1.6em'],
                    'accent'    => ['font_family' => 'Space Grotesk', 'font_weight' => '700', 'text_transform' => 'uppercase', 'letter_spacing' => '2px'],
                ],
            ],
            'calm-wellness' => [
                'title'         => 'Calm Wellness',
                'description'   => 'Sage and sand with a soft serif headline; spas, clinics, coaching and retreat sites.',
                'category'      => 'wellness',
                'colors'        => [
                    'primary'   => '#4f6f52',
                    'secondary' => '#a4886b',
                    'text'      => '#4a4a45',
                    'accent'    => '#d8a25b',
                ],
                'custom_colors' => [
                    ['title' => 'Sand', 'color' => '#f3ede4'],
                ],
                'typography'    => [
                    'primary'   => ['font_family' => 'Lora', 'font_weight' => '600', 'font_size' => '38px', 'line_height' => '1.25em'],
                    'secondary' => ['font_family' => 'Lora', 'font_weight' => '500', 'font_size' => '24px'],
                    'text'      => ['font_family' => 'Nunito Sans', 'font_weight' => '400', 'font_size' => '18px', 'line_height' => '1.8em'],
                    'accent'    => ['font_family' => 'Nunito Sans', 'font_weight' => '700', 'letter_spacing' => '1px'],
                ],
            ],
            'dark-product' => [
                'title'         => 'Dark Product',
                'description'   => 'Dark-first developer palette with a violet accent; docs, tooling and launch pages.',
                'category'      => 'product',
                'colors'        => [
                    'primary'   => '#8b5cf6',
                    'secondary' => '#94a3b8',
                    'text'      => '#cbd5e1',
                    'accent'    => '#22d3ee',
                ],
                'custom_colors' => [
                    ['title' => 'Base', 'color' => '#0b1020'],
                    ['title' => 'Panel', 'color' => '#151c30'],
                ],
                'typography'    => [
                    'primary'   => ['font_family' => 'Inter', 'font_weight' => '700', 'font_size' => '42px', 'line_height' => '1.15em'],
                    'secondary' => ['font_family' => 'Inter', 'font_weight' => '600', 'font_size' => '24px'],
                    'text'      => ['font_family' => 'Inter', 'font_weight' => '400', 'font_size' => '17px', 'line_height' => '1.7em'],
                    'accent'    => ['font_family' => 'JetBrains Mono', 'font_weight' => '500', 'letter_spacing' => '0.5px'],
                ],
            ],
            'classic-corporate' => [
                'title'         => 'Classic Corporate',
                'description'   => 'Navy and steel with a humanist sans; conservative B2B, finance and professional-services sites.',
                'category'      => 'corporate',
                'colors'        => [
                    'primary'   => '#123a6b',
                    'secondary' => '#4c6280',
                    'text'      => '#333f4d',
                    'accent'    => '#c8951a',
                ],
                'custom_colors' => [
                    ['title' => 'Mist', 'color' => '#eef2f6'],
                ],
                'typography'    => [
                    'primary'   => ['font_family' => 'Source Sans 3', 'font_weight' => '700', 'font_size' => '36px', 'line_height' => '1.25em'],
                    'secondary' => ['font_family' => 'Source Sans 3', 'font_weight' => '600', 'font_size' => '22px'],
                    'text'      => ['font_family' => 'Source Sans 3', 'font_weight' => '400', 'font_size' => '17px', 'line_height' => '1.65em'],
                    'accent'    => ['font_family' => 'Source Sans 3', 'font_weight' => '700', 'text_transform' => 'uppercase', 'letter_spacing' => '1px'],
                ],
            ],
        ];
    }

    /**
     * Every available kit, normalized, keyed by slug and sorted by slug.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function all(): array
    {
        $bundled = self::bundled();
        $raw     = $bundled;

        foreach (self::option_kits() as $slug => $definition) {
            $raw[ $slug ] = $definition;
        }

        /**
         * Filter the raw brand-kit definitions before normalization.
         *
         * @param array<string, array<string, mixed>> $raw slug => definition.
         */
        $filtered = apply_filters('wpmcp_brand_kits', $raw);
        if (! is_array($filtered)) {
            $filtered = $raw;
        }

        $kits = [];
        foreach ($filtered as $slug => $definition) {
            if (! is_array($definition)) {
                continue;
            }
            // A preset only counts as bundled while it is byte-identical to
            // the shipped definition; anything a site changed is site data.
            $source     = (isset($bundled[ $slug ]) && $bundled[ $slug ] === $definition) ? 'bundled' : 'site';
            $normalized = self::normalize((string) $slug, $definition, $source);
            if (null !== $normalized) {
                $kits[ $normalized['slug'] ] = $normalized;
            }
        }

        ksort($kits);

        return $kits;
    }

    /** @return array<string, mixed>|null the normalized kit, or null when unknown. */
    public static function get(string $slug): ?array
    {
        $slug = sanitize_key($slug);
        $kits = self::all();

        return $kits[ $slug ] ?? null;
    }

    /** @return array<string, array<string, mixed>> site-authored kits from the option. */
    private static function option_kits(): array
    {
        $stored = get_option(self::OPTION_KITS, []);

        return is_array($stored) ? $stored : [];
    }

    /**
     * Validate and canonicalize one raw definition. Invalid pieces are
     * dropped and reported in `invalid` rather than silently applied.
     *
     * @return array<string, mixed>|null null when the slug or the whole kit is unusable.
     */
    public static function normalize(string $slug, array $raw, string $source): ?array
    {
        $slug = sanitize_key($slug);
        if ('' === $slug) {
            return null;
        }

        $invalid = [];

        $colors        = self::normalize_system_colors(is_array($raw['colors'] ?? null) ? $raw['colors'] : [], $invalid);
        $custom_colors = self::normalize_custom_colors(is_array($raw['custom_colors'] ?? null) ? $raw['custom_colors'] : [], $invalid);
        $typography    = self::normalize_typography(is_array($raw['typography'] ?? null) ? $raw['typography'] : [], $invalid);
        $logo          = self::normalize_logo($raw['logo'] ?? null, $invalid);

        if ([] === $colors && [] === $custom_colors && [] === $typography && null === $logo) {
            // Nothing this kit could ever write: not a kit.
            return null;
        }

        $title = sanitize_text_field((string) ($raw['title'] ?? ''));

        return [
            'slug'          => $slug,
            'title'         => '' !== $title ? $title : ucwords(str_replace(['-', '_'], ' ', $slug)),
            'description'   => sanitize_text_field((string) ($raw['description'] ?? '')),
            'category'      => sanitize_key((string) ($raw['category'] ?? 'general')) ?: 'general',
            'source'        => $source,
            'colors'        => $colors,
            'custom_colors' => $custom_colors,
            'typography'    => $typography,
            'logo'          => $logo,
            'invalid'       => $invalid,
        ];
    }

    /**
     * @param array<string, mixed> $raw
     * @param array<int, string>   $invalid collected by reference.
     * @return array<string, string> slot => hex.
     */
    private static function normalize_system_colors(array $raw, array &$invalid): array
    {
        $colors = [];
        foreach ($raw as $slot => $value) {
            $slot = sanitize_key((string) $slot);
            if (! in_array($slot, self::SYSTEM_SLOTS, true)) {
                $invalid[] = sprintf('colors.%s: not one of the four Elementor system slots.', (string) $slot);
                continue;
            }
            $hex = is_scalar($value) ? sanitize_hex_color((string) $value) : null;
            if (empty($hex)) {
                $invalid[] = sprintf('colors.%s: "%s" is not a valid hex color.', $slot, is_scalar($value) ? (string) $value : gettype($value));
                continue;
            }
            $colors[ $slot ] = $hex;
        }

        return $colors;
    }

    /**
     * @param array<int, mixed>  $raw
     * @param array<int, string> $invalid collected by reference.
     * @return array<int, array{_id: string, title: string, color: string}>
     */
    private static function normalize_custom_colors(array $raw, array &$invalid): array
    {
        $entries = [];
        foreach ($raw as $index => $entry) {
            if (! is_array($entry)) {
                $invalid[] = sprintf('custom_colors[%s]: expected an object.', (string) $index);
                continue;
            }
            $title = sanitize_text_field((string) ($entry['title'] ?? ''));
            $id    = sanitize_key((string) ($entry['_id'] ?? $title));
            $hex   = isset($entry['color']) && is_scalar($entry['color']) ? sanitize_hex_color((string) $entry['color']) : null;

            if ('' === $id) {
                $invalid[] = sprintf('custom_colors[%s]: needs a title or _id.', (string) $index);
                continue;
            }
            if (empty($hex)) {
                $invalid[] = sprintf('custom_colors.%s: "%s" is not a valid hex color.', $id, isset($entry['color']) && is_scalar($entry['color']) ? (string) $entry['color'] : '');
                continue;
            }

            $entries[ $id ] = [
                '_id'   => $id,
                'title' => '' !== $title ? $title : ucwords(str_replace(['-', '_'], ' ', $id)),
                'color' => $hex,
            ];
        }

        return array_values($entries);
    }

    /**
     * Map friendly typography keys onto the exact Elementor control fields
     * that will be written, so get-brand-kit shows the agent the real shape.
     * Raw `typography_*` keys pass through as an escape hatch.
     *
     * @param array<string, mixed> $raw
     * @param array<int, string>   $invalid collected by reference.
     * @return array<string, array<string, mixed>> slot => Elementor fields.
     */
    private static function normalize_typography(array $raw, array &$invalid): array
    {
        $tokens = [];
        foreach ($raw as $slot => $spec) {
            $slot = sanitize_key((string) $slot);
            if (! in_array($slot, self::SYSTEM_SLOTS, true)) {
                $invalid[] = sprintf('typography.%s: not one of the four Elementor system slots.', (string) $slot);
                continue;
            }
            if (! is_array($spec)) {
                $invalid[] = sprintf('typography.%s: expected an object of font fields.', $slot);
                continue;
            }

            $fields = [];
            foreach ($spec as $key => $value) {
                $key   = (string) $key;
                $field = self::TYPOGRAPHY_FIELDS[ $key ] ?? (0 === strpos($key, 'typography_') ? $key : null);
                if (null === $field) {
                    $invalid[] = sprintf('typography.%s.%s: unknown font field.', $slot, $key);
                    continue;
                }

                if (isset(self::TYPOGRAPHY_UNITS[ $field ])) {
                    $measure = self::normalize_measure($value, self::TYPOGRAPHY_UNITS[ $field ]);
                    if (null === $measure) {
                        $invalid[] = sprintf('typography.%s.%s: "%s" is not a size (try 18, "18px" or "1.6em").', $slot, $key, is_scalar($value) ? (string) $value : gettype($value));
                        continue;
                    }
                    $fields[ $field ] = $measure;
                    continue;
                }

                if (! is_scalar($value)) {
                    $invalid[] = sprintf('typography.%s.%s: expected a string.', $slot, $key);
                    continue;
                }
                $fields[ $field ] = sanitize_text_field((string) $value);
            }

            if ([] === $fields) {
                continue;
            }

            // A font only renders once the token is switched to custom.
            $fields['typography_typography'] = 'custom';
            $tokens[ $slot ]                 = $fields;
        }

        return $tokens;
    }

    /**
     * Canonicalize a size into Elementor's slider control shape.
     *
     * @param mixed $value 18, "18px", "1.6em" or ['size' => 18, 'unit' => 'px'].
     * @return array{unit: string, size: float, sizes: array}|null
     */
    private static function normalize_measure($value, string $default_unit): ?array
    {
        if (is_array($value)) {
            $size = $value['size'] ?? null;
            $unit = (string) ($value['unit'] ?? $default_unit);
            if (! is_numeric($size) || ! in_array($unit, self::ALLOWED_UNITS, true)) {
                return null;
            }
            return ['unit' => $unit, 'size' => (float) $size, 'sizes' => []];
        }

        if (! is_scalar($value)) {
            return null;
        }

        $matches = [];
        if (! preg_match('/^\s*(-?\d+(?:\.\d+)?)\s*(px|em|rem|%|vw)?\s*$/i', (string) $value, $matches)) {
            return null;
        }

        $unit = isset($matches[2]) && '' !== $matches[2] ? strtolower($matches[2]) : $default_unit;

        return ['unit' => $unit, 'size' => (float) $matches[1], 'sizes' => []];
    }

    /**
     * @param mixed              $raw
     * @param array<int, string> $invalid collected by reference.
     * @return array{id: int, url: string}|null Elementor media-control shape.
     */
    private static function normalize_logo($raw, array &$invalid): ?array
    {
        if (null === $raw || '' === $raw || [] === $raw) {
            return null;
        }
        if (! is_array($raw)) {
            $invalid[] = 'logo: expected an object with attachment_id and/or url.';
            return null;
        }

        $id  = isset($raw['attachment_id']) ? absint($raw['attachment_id']) : absint($raw['id'] ?? 0);
        $url = isset($raw['url']) && is_scalar($raw['url']) ? esc_url_raw((string) $raw['url']) : '';

        if ($id <= 0 && '' === $url) {
            $invalid[] = 'logo: needs an attachment_id or a url.';
            return null;
        }

        return ['id' => $id, 'url' => $url];
    }

    // ---- apply log ----------------------------------------------------------

    /** @return array<int, array<string, mixed>> newest first. */
    public static function applies(): array
    {
        $stored = get_option(self::OPTION_APPLIES, []);

        return is_array($stored) ? array_values(array_filter($stored, 'is_array')) : [];
    }

    /** Record a completed apply so rollback-brand-kit can find it later. */
    public static function record_apply(array $record): void
    {
        $applies = self::applies();
        array_unshift($applies, array_merge(['rolled_back_at' => null], $record));
        update_option(self::OPTION_APPLIES, array_slice($applies, 0, self::APPLY_LOG_LIMIT), false);
    }

    /**
     * Find the apply to undo: the given operation_id when one is named
     * (whatever its state, so a restore can be repeated), otherwise the most
     * recent apply that has not already been rolled back.
     *
     * @return array<string, mixed>|null
     */
    public static function find_apply(?string $operation_id): ?array
    {
        foreach (self::applies() as $record) {
            if (null !== $operation_id) {
                if (($record['operation_id'] ?? null) === $operation_id) {
                    return $record;
                }
                continue;
            }
            if (null === ($record['rolled_back_at'] ?? null)) {
                return $record;
            }
        }

        return null;
    }

    public static function mark_rolled_back(string $operation_id): void
    {
        $applies = self::applies();
        foreach ($applies as $index => $record) {
            if (($record['operation_id'] ?? null) === $operation_id) {
                $applies[ $index ]['rolled_back_at'] = gmdate('c');
            }
        }
        update_option(self::OPTION_APPLIES, $applies, false);
    }
}
