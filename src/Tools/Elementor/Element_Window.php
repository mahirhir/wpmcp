<?php

namespace WPMCP\Tools\Elementor;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Windowed / summarized projections of an `_elementor_data` element tree
 * (issue #137).
 *
 * A real Elementor landing page is routinely 300-800 elements, and every
 * settings blob on it is verbose. Handing an agent the whole tree for a
 * question like "which section holds the pricing table?" burns the context
 * window and often times the client out before any edit happens.
 *
 * This class is the read-side ergonomics layer: a pure projection over the
 * tree that never touches the database and never mutates anything.
 *
 *  - summary mode drops every settings blob and returns the skeleton
 *    (id, elType, widgetType, a short label, child and descendant counts),
 *    which is what an agent actually needs to pick the node to drill into.
 *  - max_depth stops the walk at a depth, replacing the cut children with a
 *    truncated_children count so nothing silently disappears.
 *  - root_id windows the projection onto one subtree, so a follow-up read
 *    can pull the full settings for just that branch.
 *
 * Every projection reports total_elements / returned_elements / truncated,
 * so a caller can always tell how much of the page it is looking at.
 */
class Element_Window
{
    /** Characters of extracted node text kept as a summary label. */
    public const LABEL_LENGTH = 80;

    /** Element counts above this are worth a "read this in summary mode" hint. */
    public const LARGE_TREE_ELEMENTS = 150;

    /**
     * Settings keys, in priority order, that carry human-readable node text.
     * Both classic (title, editor) and atomic v4 (typed prop) shapes are read.
     */
    private const LABEL_KEYS = ['title', 'text', 'editor', 'heading', 'paragraph', 'content', 'html', 'caption'];

    /**
     * Project a tree.
     *
     * @param array $elements Parsed `_elementor_data`.
     * @param array $opts     summary (bool), max_depth (?int >= 1), root_id (string).
     *
     * @return array{
     *   elements: array, total_elements: int, returned_elements: int,
     *   truncated: bool, root_id: ?string, max_depth: ?int, summary: bool
     * }|\WP_Error root_id that matches nothing is an error, not an empty window.
     */
    public static function project(array $elements, array $opts = [])
    {
        $summary   = ! empty($opts['summary']);
        $max_depth = isset($opts['max_depth']) && '' !== $opts['max_depth'] ? max(1, (int) $opts['max_depth']) : null;
        $root_id   = isset($opts['root_id']) ? (string) $opts['root_id'] : '';

        if ('' !== $root_id) {
            $found = self::find($elements, $root_id);
            if (null === $found) {
                return new \WP_Error('element_not_found', "No element found with id '{$root_id}' to window on.");
            }
            $elements = [$found];
        }

        $total     = Elementor_Page_Data::count_all($elements);
        $returned  = 0;
        $truncated = false;

        $projected = [];
        foreach ($elements as $node) {
            if (! is_array($node)) {
                continue;
            }
            $projected[] = self::node($node, 1, $max_depth, $summary, $returned, $truncated);
        }

        return [
            'elements'          => $projected,
            'total_elements'    => $total,
            'returned_elements' => $returned,
            'truncated'         => $truncated,
            'root_id'           => '' !== $root_id ? $root_id : null,
            'max_depth'         => $max_depth,
            'summary'           => $summary,
        ];
    }

    /**
     * Whether a full-tree read is big enough that the caller should be nudged
     * toward summary mode.
     */
    public static function is_large(int $total_elements): bool
    {
        return $total_elements > self::LARGE_TREE_ELEMENTS;
    }

    private static function node(array $node, int $depth, ?int $max_depth, bool $summary, int &$returned, bool &$truncated): array
    {
        $returned++;

        $children = [];
        foreach ((array) ($node['elements'] ?? []) as $child) {
            if (is_array($child)) {
                $children[] = $child;
            }
        }

        $out = $summary ? self::summarize($node, $children) : self::full($node);

        if (null !== $max_depth && $depth >= $max_depth && [] !== $children) {
            $truncated = true;
            unset($out['elements']);
            $out['truncated_children'] = count($children);

            return $out;
        }

        $out['elements'] = [];
        foreach ($children as $child) {
            $out['elements'][] = self::node($child, $depth + 1, $max_depth, $summary, $returned, $truncated);
        }

        return $out;
    }

    /** Skeleton view: identity plus shape, no settings. */
    private static function summarize(array $node, array $children): array
    {
        $out = [
            'id'     => (string) ($node['id'] ?? ''),
            'elType' => (string) ($node['elType'] ?? ''),
        ];

        if (isset($node['widgetType']) && '' !== (string) $node['widgetType']) {
            $out['widgetType'] = (string) $node['widgetType'];
        }

        $label = self::label($node);
        if ('' !== $label) {
            $out['label'] = $label;
        }

        $out['child_count']      = count($children);
        $out['descendant_count'] = Elementor_Page_Data::count_all($children);

        return $out;
    }

    /** Full view: the node exactly as stored, minus the children it owns. */
    private static function full(array $node): array
    {
        unset($node['elements']);

        return $node;
    }

    /**
     * Best-effort human label for a node: the first readable text setting,
     * stripped of markup and clipped. Reads both classic scalar settings and
     * atomic v4 typed props ({"$$type": "html-v3", "value": {"content": ...}}),
     * so summary mode is equally useful on a v3 and a v4 page.
     */
    private static function label(array $node): string
    {
        $settings = is_array($node['settings'] ?? null) ? $node['settings'] : [];

        if (isset($settings['_title']) && is_string($settings['_title']) && '' !== trim($settings['_title'])) {
            return self::clip($settings['_title']);
        }

        foreach (self::LABEL_KEYS as $key) {
            if (! array_key_exists($key, $settings)) {
                continue;
            }

            $text = self::text_of($settings[$key]);
            if ('' !== $text) {
                return self::clip($text);
            }
        }

        return '';
    }

    /** @param mixed $value */
    private static function text_of($value, int $depth = 0): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if (! is_array($value) || $depth > 4) {
            return '';
        }

        // Atomic typed prop: unwrap 'value', then the html-v3 'content' shape.
        $inner = array_key_exists('value', $value) ? $value['value'] : $value;
        if (is_array($inner) && array_key_exists('content', $inner)) {
            $inner = $inner['content'];
        }

        if ($inner === $value) {
            return '';
        }

        return self::text_of($inner, $depth + 1);
    }

    private static function clip(string $text): string
    {
        $clean = trim(preg_replace('/\s+/', ' ', wp_strip_all_tags($text)) ?? '');

        if (strlen($clean) <= self::LABEL_LENGTH) {
            return $clean;
        }

        return rtrim(substr($clean, 0, self::LABEL_LENGTH - 1)) . "\u{2026}";
    }

    /** Non-reference subtree lookup (Elementor_Page_Data::find returns by reference). */
    private static function find(array $elements, string $id): ?array
    {
        foreach ($elements as $element) {
            if (! is_array($element)) {
                continue;
            }
            if ((string) ($element['id'] ?? '') === $id) {
                return $element;
            }
            if (! empty($element['elements']) && is_array($element['elements'])) {
                $found = self::find($element['elements'], $id);
                if (null !== $found) {
                    return $found;
                }
            }
        }

        return null;
    }
}
