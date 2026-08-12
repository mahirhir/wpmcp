<?php

namespace WPMCP\Tools\Redirects;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The pending redirect-suggestion queue (issue #128) - the "suggest only"
 * half of the redirect manager.
 *
 * When a published post is deleted, or a published post's slug changes, the
 * URL it used to live at starts 404ing. The plugin knows that, and knows
 * where the content went, but it does NOT act on it: it records a suggestion
 * here and returns the same suggestion inline in the tool's own response.
 * Turning a suggestion into a live redirect always takes a separate,
 * explicit create-redirect call - from the agent, or from a human clicking
 * Create on the Redirects admin screen (which calls the same tool).
 *
 * That split is the whole point. An agent that renames a slug should not be
 * able to change site-wide routing as an invisible side effect of a content
 * edit; a human should be able to look at a list of proposed routing changes
 * and approve them one at a time.
 *
 * Storage is a single capped option keyed by source path (newest wins), not
 * a table: the queue is a short, ephemeral to-do list, and an unbounded
 * queue on a site doing bulk slug edits would be a liability rather than a
 * feature.
 */
class Redirect_Suggestions
{
    public const OPTION = 'wpmcp_redirect_suggestions';

    /** Most suggestions kept; the oldest fall off the end. */
    public const CAP = 50;

    public const REASON_POST_DELETED = 'post-deleted';
    public const REASON_SLUG_CHANGED = 'slug-changed';

    /**
     * Build (and queue) a suggestion for a source path that has just stopped
     * resolving. Returns the suggestion so the calling tool can include it in
     * its own response; returns null when there is nothing worth suggesting
     * (no usable source path, or the path is already redirected).
     *
     * @return array<string,mixed>|null
     */
    public static function propose(string $source_url, string $reason, int $target_post_id = 0, string $note = ''): ?array
    {
        $source = Redirect_Store::normalize_path($source_url);
        if ('/' === $source || strlen($source) > Redirect_Store::MAX_SOURCE_LENGTH) {
            return null;
        }
        if (null !== Redirect_Store::find_by_source($source)) {
            return null; // Already handled; nothing to propose.
        }

        $suggestion = [
            'source'         => $source,
            'reason'         => $reason,
            'target_post_id' => $target_post_id,
            'note'           => $note,
            'suggested_at'   => current_time('mysql', true),
        ];

        self::push($suggestion);

        return $suggestion;
    }

    /** @param array<string,mixed> $suggestion */
    public static function push(array $suggestion): void
    {
        $queue = self::all();
        $queue = array_values(array_filter(
            $queue,
            static fn (array $item): bool => ($item['source'] ?? '') !== $suggestion['source']
        ));
        array_unshift($queue, $suggestion);

        update_option(self::OPTION, array_slice($queue, 0, self::CAP), false);
    }

    /** @return array<int, array<string,mixed>> Newest first. */
    public static function all(): array
    {
        $stored = get_option(self::OPTION, []);
        if (! is_array($stored)) {
            return [];
        }

        return array_values(array_filter($stored, 'is_array'));
    }

    /** @return array<string,mixed>|null */
    public static function find(string $source): ?array
    {
        $source = Redirect_Store::normalize_path($source);
        foreach (self::all() as $item) {
            if (($item['source'] ?? '') === $source) {
                return $item;
            }
        }
        return null;
    }

    /** Drop a suggestion (it was acted on, or dismissed). True when one was removed. */
    public static function remove(string $source): bool
    {
        $source = Redirect_Store::normalize_path($source);
        $queue  = self::all();
        $kept   = array_values(array_filter(
            $queue,
            static fn (array $item): bool => ($item['source'] ?? '') !== $source
        ));

        if (count($kept) === count($queue)) {
            return false;
        }

        update_option(self::OPTION, $kept, false);
        return true;
    }
}
