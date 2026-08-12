<?php

// phpcs:disable PSR1.Files.SideEffects.FoundWithSymbols -- ABSPATH guard is an intentional side effect.
// phpcs:disable Squiz.Classes.ValidClassName.NotCamelCaps -- WP-style snake_case class name is intentional.
// phpcs:disable PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- WP-style snake_case method names are intentional.

namespace WPMCP\Tools\Search;

use WPMCP\Tools\Builders\Bricks_Content;
use WPMCP\Tools\Builders\Builder_Detector;
use WPMCP\Tools\Elementor\Elementor_Page_Data;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Turns site content into addressable index fragments (issue #83).
 *
 * The gap this closes: `post_content` search cannot see text that lives in
 * builder element settings, in template parts, or in menus, which is exactly
 * where a large share of a modern WordPress site's copy actually lives. This
 * indexer reads the SAME plain storage the rest of the plugin already reads
 * (post columns, `_elementor_data`, `_bricks_page_content_2`, Divi's
 * post_content shortcodes, nav menu items), so nothing here depends on a
 * builder plugin being loaded, and nothing shells out.
 *
 * Every fragment carries a location path so a hit is actionable:
 *   - post/title, post/excerpt
 *   - content/block/0/2       (Gutenberg block path, root index then child indexes)
 *   - elementor/element/<id>  (the element id update-element takes)
 *   - bricks/element/<id>
 *   - content/text            (classic / Divi shortcode body)
 *   - menu/item/<menu_item_id>
 */
class Content_Indexer
{
    /** Post statuses worth indexing. Trash and auto-drafts are excluded. */
    public const INDEXABLE_STATUSES = ['publish', 'future', 'draft', 'pending', 'private'];

    /** Hard ceiling on fragments per object, so one pathological page cannot flood the table. */
    public const MAX_FRAGMENTS_PER_OBJECT = 400;

    /** Max recursion depth when walking a builder settings array. */
    private const MAX_SETTING_DEPTH = 6;

    /**
     * Builder setting keys that never hold human-readable copy. Skipping them
     * keeps the index (and therefore result relevance) free of CSS noise.
     */
    private const SKIPPED_SETTING_KEYS = [
        '_element_id', '_css_classes', 'css_classes', '_id', 'id', 'elType', 'widgetType',
        'isInner', 'shape', 'view', 'align', 'size', 'unit', 'sizes', 'source', 'library',
        'template', 'templateId', 'attachment_id', 'post_id', 'ids',
    ];

    // ------------------------------------------------------------------
    // Entry points
    // ------------------------------------------------------------------

    /**
     * (Re)index one post. Returns the number of fragments written; 0 when the
     * post is not indexable, in which case any previously stored fragments are
     * purged so the index never keeps stale rows for content that left scope.
     */
    public static function index_post(int $post_id): int
    {
        Search_Index_Store::ensure_installed();

        $post = $post_id > 0 ? get_post($post_id) : null;
        if (! $post instanceof \WP_Post || ! self::is_indexable_post($post)) {
            Search_Index_Store::purge_object('post', $post_id);
            return 0;
        }

        return Search_Index_Store::replace_object('post', (int) $post->ID, self::documents_for_post($post));
    }

    /** (Re)index one nav menu (a `nav_menu` term) and all of its items. */
    public static function index_menu(int $menu_id): int
    {
        Search_Index_Store::ensure_installed();

        $menu = $menu_id > 0 ? wp_get_nav_menu_object($menu_id) : false;
        if (! $menu) {
            Search_Index_Store::purge_object('menu', $menu_id);
            return 0;
        }

        return Search_Index_Store::replace_object('menu', (int) $menu->term_id, self::documents_for_menu($menu));
    }

    public static function purge_post(int $post_id): void
    {
        Search_Index_Store::purge_object('post', $post_id);
    }

    public static function purge_menu(int $menu_id): void
    {
        Search_Index_Store::purge_object('menu', $menu_id);
    }

    // ------------------------------------------------------------------
    // Scope
    // ------------------------------------------------------------------

    /**
     * Post types the index covers: every public type, plus the non-public
     * types that hold reusable site copy (template parts, block templates,
     * reusable blocks, navigation, Elementor's template library). The plugin's
     * own spec CPTs are excluded: they are configuration, not site content.
     *
     * @return string[]
     */
    public static function indexable_post_types(): array
    {
        $types = array_values(get_post_types(['public' => true], 'names'));
        foreach (['wp_block', 'wp_template', 'wp_template_part', 'wp_navigation', 'elementor_library'] as $extra) {
            if (post_type_exists($extra)) {
                $types[] = $extra;
            }
        }
        $types = array_values(array_diff(array_unique($types), ['attachment', 'wpmcp_widget', 'wpmcp_block']));
        sort($types);

        /**
         * Filter the post types the content search index covers.
         *
         * @param string[] $types
         */
        $filtered = apply_filters('wpmcp_search_indexable_post_types', $types);

        return array_values(array_filter(array_map('strval', (array) $filtered)));
    }

    public static function is_indexable_post(\WP_Post $post): bool
    {
        if (wp_is_post_revision($post->ID) || wp_is_post_autosave($post->ID)) {
            return false;
        }
        if (! in_array((string) $post->post_status, self::INDEXABLE_STATUSES, true)) {
            return false;
        }
        return in_array((string) $post->post_type, self::indexable_post_types(), true);
    }

    // ------------------------------------------------------------------
    // Document builders
    // ------------------------------------------------------------------

    /** @return array<int,array<string,mixed>> */
    public static function documents_for_post(\WP_Post $post): array
    {
        $subtype = (string) $post->post_type;
        $docs    = [];

        $title = self::normalize((string) $post->post_title);
        if ('' !== $title) {
            $docs[] = self::doc($subtype, 'title', 'post', 'post/title', '', $title, 50);
        }

        $excerpt = self::normalize((string) $post->post_excerpt);
        if ('' !== $excerpt) {
            $docs[] = self::doc($subtype, 'excerpt', 'post', 'post/excerpt', '', $excerpt, 20);
        }

        $builder = Builder_Detector::detect((int) $post->ID);
        switch ($builder) {
            case 'elementor':
                $docs = array_merge($docs, self::elementor_documents((int) $post->ID, $subtype));
                break;
            case 'bricks':
                $docs = array_merge($docs, self::bricks_documents((int) $post->ID, $subtype));
                break;
            case 'gutenberg':
                $docs = array_merge($docs, self::block_documents((string) $post->post_content, $subtype));
                break;
            case 'divi':
            case 'classic':
            default:
                $body = self::normalize((string) $post->post_content);
                if ('' !== $body) {
                    $docs[] = self::doc($subtype, 'divi' === $builder ? 'divi' : 'content', 'post_content', 'content/text', '', $body, 10);
                }
                break;
        }

        // A builder page keeps its rendered fallback in post_content. Index it
        // too (at low weight) so a phrase only present in the rendered copy is
        // still findable; the builder fragments above outrank it.
        if (in_array($builder, ['elementor', 'bricks'], true)) {
            $fallback = self::normalize((string) $post->post_content);
            if ('' !== $fallback) {
                $docs[] = self::doc($subtype, 'content', 'post_content', 'content/text', '', $fallback, 5);
            }
        }

        return array_slice($docs, 0, self::MAX_FRAGMENTS_PER_OBJECT);
    }

    /** @return array<int,array<string,mixed>> */
    public static function documents_for_menu(\WP_Term $menu): array
    {
        $docs   = [];
        $docs[] = self::doc('nav_menu', 'menu', 'nav_menu', 'menu/' . (int) $menu->term_id, 'name', self::normalize((string) $menu->name), 25);

        $items = wp_get_nav_menu_items($menu->term_id);
        foreach (is_array($items) ? $items : [] as $item) {
            $location = 'menu/item/' . (int) $item->ID;
            foreach (
                [
                'title'       => [(string) $item->title, 25],
                'attr_title'  => [(string) $item->attr_title, 10],
                'description' => [(string) $item->description, 10],
                'url'         => [(string) $item->url, 5],
                ] as $field => [$value, $weight]
            ) {
                $text = self::normalize($value);
                if ('' !== $text) {
                    $docs[] = self::doc('nav_menu', 'menu', 'nav_menu_item', $location, $field, $text, $weight);
                }
            }
        }

        return array_slice($docs, 0, self::MAX_FRAGMENTS_PER_OBJECT);
    }

    // ------------------------------------------------------------------
    // Per-builder walkers
    // ------------------------------------------------------------------

    /** @return array<int,array<string,mixed>> */
    private static function block_documents(string $content, string $subtype): array
    {
        if ('' === trim($content) || ! function_exists('parse_blocks')) {
            return [];
        }
        $docs = [];
        self::walk_blocks(parse_blocks($content), [], $subtype, $docs);
        return $docs;
    }

    /**
     * @param array<int,array<string,mixed>> $blocks
     * @param int[]                          $path
     * @param array<int,array<string,mixed>> $docs
     */
    private static function walk_blocks(array $blocks, array $path, string $subtype, array &$docs): void
    {
        foreach ($blocks as $index => $block) {
            if (count($docs) >= self::MAX_FRAGMENTS_PER_OBJECT) {
                return;
            }
            $name = (string) ($block['blockName'] ?? '');
            if ('' === $name) {
                // A freeform/classic chunk between blocks: still real copy.
                $text = self::normalize((string) ($block['innerHTML'] ?? ''));
                if ('' !== $text) {
                    $docs[] = self::doc($subtype, 'content', 'core/freeform', self::block_location($path, (int) $index), 'html', $text, 10);
                }
                continue;
            }

            $location = self::block_location($path, (int) $index);
            $weight   = 0 === strpos($name, 'core/heading') ? 30 : 10;

            $text = self::normalize((string) ($block['innerHTML'] ?? ''));
            if ('' !== $text) {
                $docs[] = self::doc($subtype, 'content', $name, $location, 'html', $text, $weight);
            }

            foreach ((array) ($block['attrs'] ?? []) as $key => $value) {
                if (! is_string($value)) {
                    continue;
                }
                $attr = self::normalize($value);
                if ('' === $attr || $attr === $text) {
                    continue;
                }
                $docs[] = self::doc($subtype, 'content', $name, $location, 'attrs.' . (string) $key, $attr, $weight);
            }

            if (! empty($block['innerBlocks']) && is_array($block['innerBlocks'])) {
                $child = $path;
                $child[] = (int) $index;
                self::walk_blocks($block['innerBlocks'], $child, $subtype, $docs);
            }
        }
    }

    /**
     * @param  int[] $path
     */
    private static function block_location(array $path, int $index): string
    {
        $segments = $path;
        $segments[] = $index;
        return 'content/block/' . implode('/', $segments);
    }

    /** @return array<int,array<string,mixed>> */
    private static function elementor_documents(int $post_id, string $subtype): array
    {
        $docs = [];
        self::walk_builder_elements(
            Elementor_Page_Data::get($post_id),
            $subtype,
            'elementor',
            'elementor/element/',
            'widgetType',
            'elements',
            $docs
        );
        return $docs;
    }

    /** @return array<int,array<string,mixed>> */
    private static function bricks_documents(int $post_id, string $subtype): array
    {
        $elements = Bricks_Content::get($post_id);
        if (null === $elements) {
            return [];
        }
        $docs = [];
        // Bricks stores a FLAT element list keyed by id with a parent pointer,
        // so there is no nested 'children' array of element structures to walk.
        self::walk_builder_elements($elements, $subtype, 'bricks', 'bricks/element/', 'name', null, $docs);
        return $docs;
    }

    /**
     * Shared walker for a builder element tree: emit one fragment per textual
     * setting, addressed by the element id the corresponding edit tool takes.
     *
     * @param array<int|string,mixed>        $elements
     * @param array<int,array<string,mixed>> $docs
     */
    private static function walk_builder_elements(
        array $elements,
        string $subtype,
        string $source,
        string $location_prefix,
        string $node_key,
        ?string $children_key,
        array &$docs
    ): void {
        foreach ($elements as $element) {
            if (count($docs) >= self::MAX_FRAGMENTS_PER_OBJECT) {
                return;
            }
            if (! is_array($element)) {
                continue;
            }

            $id       = (string) ($element['id'] ?? '');
            $node     = (string) ($element[ $node_key ] ?? ($element['elType'] ?? ''));
            $location = $location_prefix . ('' === $id ? 'unknown' : $id);
            $settings = isset($element['settings']) && is_array($element['settings']) ? $element['settings'] : [];

            foreach (self::flatten_settings($settings, '', 0) as $field => $value) {
                if (count($docs) >= self::MAX_FRAGMENTS_PER_OBJECT) {
                    return;
                }
                $docs[] = self::doc($subtype, $source, $node, $location, $field, $value, self::setting_weight($node, $field));
            }

            if (null !== $children_key && ! empty($element[ $children_key ]) && is_array($element[ $children_key ])) {
                self::walk_builder_elements(
                    $element[ $children_key ],
                    $subtype,
                    $source,
                    $location_prefix,
                    $node_key,
                    $children_key,
                    $docs
                );
            }
        }
    }

    /**
     * Flatten a settings array to `key.path => normalized text`, keeping only
     * values that actually read as copy (see looks_like_copy()).
     *
     * @param  array<int|string,mixed> $settings
     * @return array<string,string>
     */
    private static function flatten_settings(array $settings, string $prefix, int $depth): array
    {
        if ($depth > self::MAX_SETTING_DEPTH) {
            return [];
        }
        $out = [];
        foreach ($settings as $key => $value) {
            $key_string = (string) $key;
            if (in_array($key_string, self::SKIPPED_SETTING_KEYS, true)) {
                continue;
            }
            $path = '' === $prefix ? $key_string : $prefix . '.' . $key_string;

            if (is_array($value)) {
                $out += self::flatten_settings($value, $path, $depth + 1);
                continue;
            }
            if (! is_string($value)) {
                continue;
            }
            $text = self::normalize($value);
            if (self::looks_like_copy($text)) {
                $out[ $path ] = $text;
            }
        }
        return $out;
    }

    /**
     * Reject the values that make a builder index useless: hex colours, bare
     * numbers with CSS units, slugs with no letters, and one-character noise.
     * A URL is kept (agents legitimately search for a link target) but scored
     * low by setting_weight().
     */
    private static function looks_like_copy(string $text): bool
    {
        if (strlen($text) < 2) {
            return false;
        }
        if (! preg_match('/\p{L}/u', $text)) {
            return false;
        }
        if (preg_match('/^#[0-9a-fA-F]{3,8}$/', $text)) {
            return false;
        }
        if (preg_match('/^-?\d+(\.\d+)?(px|em|rem|%|vh|vw|pt)$/i', $text)) {
            return false;
        }
        return true;
    }

    private static function setting_weight(string $node, string $field): int
    {
        if (preg_match('#(^|\.)(url|link|href)($|\.)#i', $field)) {
            return 5;
        }
        if ('heading' === $node && 'title' === $field) {
            return 30;
        }
        if (in_array($field, ['title', 'title_text', 'heading', 'label'], true)) {
            return 25;
        }
        return 10;
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /** @return array<string,mixed> */
    private static function doc(
        string $subtype,
        string $source,
        string $node,
        string $location,
        string $field,
        string $content,
        int $weight
    ): array {
        return [
            'subtype'  => $subtype,
            'source'   => $source,
            'node'     => $node,
            'location' => $location,
            'field'    => $field,
            'content'  => $content,
            'weight'   => $weight,
        ];
    }

    /** Strip markup/shortcodes and collapse whitespace to a single searchable line. */
    public static function normalize(string $raw): string
    {
        $text = str_replace(['<br>', '<br/>', '<br />', '</p>', '</div>', '</li>'], ' ', $raw);
        $text = wp_strip_all_tags($text, true);
        $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
        $text = preg_replace('/\[[^\]]*\]/', ' ', $text) ?? $text;
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim((string) $text);
    }
}
