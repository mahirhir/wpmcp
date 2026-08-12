<?php

namespace WPMCP\Tools\Redirects;

use WPMCP\Tools\Linking\Link_Graph;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Classifies the internal links inside published content (issue #128).
 *
 * Built on Link_Graph rather than a second, parallel link parser:
 * extract_hrefs() and resolve_href() are the same already-tested extraction
 * and post-resolution the internal-link tools use, so "what counts as an
 * internal link" cannot drift between the link map and the broken-link
 * report.
 *
 * Three findings are produced, and each is a different piece of advice:
 *  - dead:        the link resolves to no post at all. Somebody has to decide
 *                 where it should go, so the advice is create-redirect.
 *  - unpublished: the target post exists but is not public (draft, pending,
 *                 private). The link is not broken so much as premature.
 *  - redirected:  the link points at a path that IS managed by a redirect, so
 *                 every visitor pays an extra hop. The advice is to edit the
 *                 link to the final destination, not to add another redirect.
 *
 * FALSE POSITIVES ARE THE FAILURE MODE THAT MATTERS. A scanner that reports
 * every category, tag, author, date, or post-type archive URL as a dead link
 * is worse than no scanner: an agent will "fix" working links. Any path whose
 * first segment is a known archive base is therefore skipped outright rather
 * than reported, and non-HTTP schemes (mailto:, tel:, javascript:) and pure
 * fragments never enter the classifier at all.
 *
 * Read-only from end to end: it proposes, and never writes.
 */
class Broken_Link_Scanner
{
    public const ISSUE_DEAD        = 'dead';
    public const ISSUE_UNPUBLISHED = 'unpublished';
    public const ISSUE_REDIRECTED  = 'redirected';

    /**
     * Classify every internal link in one post.
     *
     * @return array<int, array<string,mixed>>
     */
    public static function scan_post(\WP_Post $post): array
    {
        $findings = [];
        $seen     = [];

        foreach (Link_Graph::extract_hrefs((string) $post->post_content) as $href) {
            if (! self::is_scannable($href)) {
                continue;
            }

            $path = Redirect_Store::normalize_path($href);
            if ('/' === $path || isset($seen[ $path ]) || self::is_archive_path($path)) {
                continue;
            }
            $seen[ $path ] = true;

            $finding = self::classify($href, $path);
            if (null === $finding) {
                continue;
            }

            $findings[] = array_merge([
                'post_id'    => (int) $post->ID,
                'post_title' => (string) $post->post_title,
                'href'       => $href,
                'path'       => $path,
            ], $finding);
        }

        return $findings;
    }

    /**
     * Scan a batch of post ids.
     *
     * @param int[] $post_ids
     * @return array<int, array<string,mixed>>
     */
    public static function scan_posts(array $post_ids): array
    {
        $findings = [];
        foreach ($post_ids as $post_id) {
            $post = get_post((int) $post_id);
            if ($post instanceof \WP_Post) {
                $findings = array_merge($findings, self::scan_post($post));
            }
        }
        return $findings;
    }

    /**
     * The ids of the published posts a scan covers, oldest id first so paging
     * through them with an offset is stable while the scan runs.
     *
     * @param string[] $post_types
     * @return int[]
     */
    public static function scannable_ids(array $post_types, int $limit, int $offset = 0): array
    {
        $query = new \WP_Query([
            'post_type'      => array_values($post_types),
            'post_status'    => 'publish',
            'posts_per_page' => max(1, $limit),
            'offset'         => max(0, $offset),
            'orderby'        => 'ID',
            'order'          => 'ASC',
            'no_found_rows'  => true,
            'fields'         => 'ids',
        ]);

        return array_map('intval', $query->posts);
    }

    /** @param string[] $post_types */
    public static function scannable_total(array $post_types): int
    {
        $query = new \WP_Query([
            'post_type'      => array_values($post_types),
            'post_status'    => 'publish',
            'posts_per_page' => 1,
            'fields'         => 'ids',
        ]);

        return (int) $query->found_posts;
    }

    /** Links worth classifying: same-site http(s) or relative, not a bare fragment. */
    private static function is_scannable(string $href): bool
    {
        $href = trim($href);
        if ('' === $href || '#' === $href[0]) {
            return false;
        }

        $scheme = strtolower((string) wp_parse_url($href, PHP_URL_SCHEME));
        if ('' !== $scheme && ! in_array($scheme, ['http', 'https'], true)) {
            return false; // mailto:, tel:, javascript:, data: ...
        }

        return Redirect_Store::is_internal($href);
    }

    /**
     * @return array<string,mixed>|null Null when the link is fine.
     */
    private static function classify(string $href, string $path): ?array
    {
        $redirect = Redirect_Store::find_by_source($path);
        if (null !== $redirect && $redirect['enabled']) {
            $target = Redirect_Store::resolve_target($redirect);
            if ('' !== $target) {
                return [
                    'issue'            => self::ISSUE_REDIRECTED,
                    'redirect_id'      => $redirect['id'],
                    'target'           => $target,
                    'suggested_action' => 'update-link',
                ];
            }
        }

        $post_id = Link_Graph::resolve_href($href);
        if ($post_id > 0 && 'publish' === get_post_status($post_id)) {
            return null;
        }

        $existing = $post_id > 0 ? get_post($post_id) : self::find_post_by_path($path);
        if ($existing instanceof \WP_Post) {
            return [
                'issue'            => self::ISSUE_UNPUBLISHED,
                'target_post_id'   => (int) $existing->ID,
                'target_status'    => (string) $existing->post_status,
                'suggested_action' => 'publish-or-relink',
            ];
        }

        return [
            'issue'            => self::ISSUE_DEAD,
            'suggested_action' => 'create-redirect',
        ];
    }

    /**
     * Find a post at this path regardless of status, so a link to a draft or
     * pending post is reported as "not public yet" instead of "dead". A
     * trashed post is deliberately NOT found here: WordPress renames its slug
     * on trash, so the old path really does resolve to nothing and "dead" is
     * the honest answer.
     */
    private static function find_post_by_path(string $path): ?\WP_Post
    {
        $types = get_post_types(['public' => true], 'names');
        $found = get_page_by_path(trim($path, '/'), OBJECT, array_values($types));

        return $found instanceof \WP_Post ? $found : null;
    }

    /**
     * True for paths that are archive listings rather than single pieces of
     * content: category/tag/custom-taxonomy bases, author and date archives,
     * paged and feed URLs, and single-segment post-type archive slugs. These
     * resolve to nothing via url_to_postid() but are perfectly valid URLs, so
     * reporting them would be a false positive.
     */
    private static function is_archive_path(string $path): bool
    {
        $segments = array_values(array_filter(explode('/', trim($path, '/')), 'strlen'));
        if ([] === $segments) {
            return true;
        }
        $first = $segments[0];

        if (preg_match('/^\d{4}$/', $first)) {
            return true; // /2026/07/... date archive.
        }

        $bases = ['author', 'page', 'feed', 'comments', 'search', 'tag', 'category'];

        $category_base = (string) get_option('category_base');
        if ('' !== $category_base) {
            $bases[] = trim($category_base, '/');
        }
        $tag_base = (string) get_option('tag_base');
        if ('' !== $tag_base) {
            $bases[] = trim($tag_base, '/');
        }

        foreach (get_taxonomies(['public' => true], 'objects') as $taxonomy) {
            if (is_array($taxonomy->rewrite) && ! empty($taxonomy->rewrite['slug'])) {
                $bases[] = trim(explode('/', (string) $taxonomy->rewrite['slug'])[0], '/');
            }
        }

        if (in_array($first, $bases, true)) {
            return true;
        }

        if (1 === count($segments)) {
            foreach (get_post_types(['public' => true], 'objects') as $post_type) {
                if (! $post_type->has_archive) {
                    continue;
                }
                $slug = is_string($post_type->has_archive) ? $post_type->has_archive : $post_type->name;
                if (is_array($post_type->rewrite) && ! empty($post_type->rewrite['slug'])) {
                    $slug = (string) $post_type->rewrite['slug'];
                }
                if ($first === trim(explode('/', $slug)[0], '/')) {
                    return true;
                }
            }
        }

        return false;
    }
}
