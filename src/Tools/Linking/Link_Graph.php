<?php

namespace WPMCP\Tools\Linking;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Builds the internal-link graph for a bounded set of published posts.
 *
 * For each of the most-recent N published posts of the requested post types
 * it scans the stored content for <a href> values and resolves each href to
 * a local post ID (permalink, ?p=<id>, or slug). The result is a per-post map
 * of outgoing edges plus an incoming count, shared by all three Linking tools
 * so the resolution logic lives in exactly one place.
 *
 * Read-only: nothing here writes, so it never touches the safety core.
 */
class Link_Graph
{
    /** Hard ceiling on posts scanned, so a huge site cannot stall a build. */
    public const MAX_SCAN = 500;

    /**
     * @param string[] $post_types Post types to include in the graph.
     * @param int      $limit      Most-recent-N cap on posts scanned.
     * @return array<int, array{title: string, post_type: string, outgoing: int[], incoming: int}>
     */
    public static function build(array $post_types, int $limit): array
    {
        $limit = max(1, min($limit, self::MAX_SCAN));

        $query = new \WP_Query([
            'post_type'      => array_values($post_types),
            'post_status'    => 'publish',
            'posts_per_page' => $limit,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'no_found_rows'  => true,
            'fields'         => 'all',
        ]);

        $posts = $query->posts;
        $graph = [];
        $known = [];
        foreach ($posts as $post) {
            $graph[$post->ID] = [
                'title'     => (string) $post->post_title,
                'post_type' => (string) $post->post_type,
                'outgoing'  => [],
                'incoming'  => 0,
            ];
            $known[$post->ID] = true;
        }

        foreach ($posts as $post) {
            $targets = self::resolve_targets((string) $post->post_content, $post->ID);
            foreach ($targets as $target_id) {
                if (! isset($known[$target_id])) {
                    continue;
                }
                if (! in_array($target_id, $graph[$post->ID]['outgoing'], true)) {
                    $graph[$post->ID]['outgoing'][] = $target_id;
                    $graph[$target_id]['incoming']++;
                }
            }
        }

        return $graph;
    }

    /**
     * Extract <a href> values from content and resolve each to a local post ID.
     *
     * @return int[] Unique local post IDs the content links to (self excluded).
     */
    private static function resolve_targets(string $content, int $self_id): array
    {
        $ids = [];
        foreach (self::extract_hrefs($content) as $href) {
            $id = self::resolve_href($href);
            if ($id > 0 && $id !== $self_id) {
                $ids[$id] = true;
            }
        }

        return array_keys($ids);
    }

    /**
     * Every <a href> value in a chunk of stored content, in document order.
     *
     * Public because the broken-link scanner (issue #128) needs the raw
     * hrefs, not just the ones that resolve: an href that resolves to
     * nothing is precisely what it is looking for. Keeping the extraction
     * here means both features see exactly the same set of links.
     *
     * @return string[]
     */
    public static function extract_hrefs(string $content): array
    {
        if ('' === $content || false === strpos($content, 'href')) {
            return [];
        }

        if (! preg_match_all('/<a\b[^>]*\bhref\s*=\s*["\']([^"\']+)["\']/i', $content, $matches)) {
            return [];
        }

        return array_map('strval', $matches[1]);
    }

    /**
     * Resolve a single href to a local post ID, or 0 if it is not an internal
     * post link. Public for the same reason as extract_hrefs(): the
     * broken-link scanner classifies a link by whether this resolves it.
     */
    public static function resolve_href(string $href): int
    {
        $href = trim($href);
        if ('' === $href || '#' === $href[0]) {
            return 0;
        }

        $home_host = strtolower((string) wp_parse_url(home_url(), PHP_URL_HOST));
        $href_host = strtolower((string) wp_parse_url($href, PHP_URL_HOST));
        if ('' !== $href_host && $href_host !== $home_host) {
            return 0;
        }

        $id = (int) url_to_postid($href);
        if ($id > 0) {
            return $id;
        }

        $query = (string) wp_parse_url($href, PHP_URL_QUERY);
        if ('' !== $query) {
            parse_str($query, $args);
            if (isset($args['p']) && is_numeric($args['p'])) {
                return (int) $args['p'];
            }
        }

        return 0;
    }
}
