<?php

namespace WPMCP\Memory;

use WPMCP\MCP\Ability;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Turns published severity=block memory entries into actual denials
 * (issue #131).
 *
 * Placement is the whole point. This is consulted from
 * Registrar::is_permitted(), the single choke point EVERY wpmcp ability
 * passes through before it executes (the Abilities API runs the
 * permission_callback ahead of every call, and Call_Tool re-checks the same
 * method). A guardrail therefore applies to abilities that shipped before it
 * existed and to abilities that do not exist yet, with no per-tool opt-in and
 * nothing for a future write path to "remember to call". Contrast a
 * before-write veto filter, which only guards the writes whose authors
 * remembered to fire it.
 *
 * Matching rules:
 *  - Read-only abilities are never blocked. A guardrail restricts what an
 *    agent may CHANGE; denying reads would break recall and diagnostics
 *    without protecting anything.
 *  - A rule matches when ANY of its targets matches. Targets are:
 *      tool:<ability>       exact ability name, wpmcp/ prefix optional, and
 *                           a single trailing * matches by prefix.
 *      post_id:<int>        an id the call is operating on.
 *      post_type:<slug>     an explicit post_type argument, or the resolved
 *                           post type of an id the call is operating on.
 *  - First match wins, in ascending entry id order, so the reported entry id
 *    is deterministic for a given rule set.
 *
 * Honest limitation, stated rather than hidden: post_id/post_type targets can
 * only match against arguments the server can see, so a tool that addresses
 * content some other way (a slug-only API, a bulk operation with no id list)
 * is not caught by those two target types. tool: targets have no such gap and
 * are the airtight form; the admin UI says so.
 */
class Memory_Guard
{
    /** Input keys that carry a single post id. */
    private const ID_KEYS = ['id', 'post_id', 'ID', 'page_id', 'parent_id', 'attachment_id', 'product_id'];

    /** Input keys that carry a list of post ids. */
    private const ID_LIST_KEYS = ['ids', 'post_ids', 'page_ids'];

    /**
     * The published block entry denying this call, or null when nothing
     * blocks it.
     *
     * @param array<string, mixed> $input The ability's invocation arguments.
     * @return array<string, mixed>|null
     */
    public static function blocking_rule(Ability $ability, array $input = []): ?array
    {
        if ($ability->read_only_hint) {
            return null;
        }
        if (! Memory_Config::enforcement_enabled()) {
            return null;
        }

        foreach (Memory_Store::block_rules() as $rule) {
            if (self::rule_matches($rule, $ability, $input)) {
                return $rule;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $rule
     * @param array<string, mixed> $input
     */
    public static function rule_matches(array $rule, Ability $ability, array $input): bool
    {
        foreach ((array) ($rule['targets'] ?? []) as $target) {
            if (! is_string($target)) {
                continue;
            }
            $parts = explode(':', $target, 2);
            if (2 !== count($parts)) {
                continue;
            }
            if (self::target_matches($parts[0], $parts[1], $ability, $input)) {
                return true;
            }
        }
        return false;
    }

    /** @param array<string, mixed> $input */
    private static function target_matches(string $type, string $value, Ability $ability, array $input): bool
    {
        switch ($type) {
            case 'tool':
                return self::tool_matches($value, $ability->name);
            case 'post_id':
                return in_array((int) $value, self::candidate_ids($input), true);
            case 'post_type':
                return in_array(strtolower($value), self::candidate_post_types($input), true);
        }
        return false;
    }

    /**
     * "delete-post", "wpmcp/delete-post" and "wpmcp/delete-*" all match the
     * wpmcp/delete-post ability. Comparison is case-insensitive; ability
     * names are lowercase by convention.
     */
    private static function tool_matches(string $target, string $ability_name): bool
    {
        $name   = strtolower($ability_name);
        $target = strtolower($target);

        if ('*' === substr($target, -1)) {
            $prefix = substr($target, 0, -1);
            if ('' === $prefix) {
                return false;
            }
            return 0 === strpos($name, $prefix) || 0 === strpos($name, 'wpmcp/' . $prefix);
        }

        return $name === $target || $name === 'wpmcp/' . $target;
    }

    /**
     * Every post id this invocation plausibly touches, read from the
     * conventional argument names wpmcp tools use.
     *
     * @param array<string, mixed> $input
     * @return int[]
     */
    public static function candidate_ids(array $input): array
    {
        $ids = [];

        foreach (self::ID_KEYS as $key) {
            if (isset($input[ $key ]) && is_numeric($input[ $key ])) {
                $ids[] = (int) $input[ $key ];
            }
        }

        foreach (self::ID_LIST_KEYS as $key) {
            if (! isset($input[ $key ]) || ! is_array($input[ $key ])) {
                continue;
            }
            foreach ($input[ $key ] as $candidate) {
                if (is_numeric($candidate)) {
                    $ids[] = (int) $candidate;
                }
            }
        }

        return array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
    }

    /**
     * Post types in play: an explicit post_type argument plus the resolved
     * type of every candidate id, so "post_type:page" blocks a call that
     * names only the page's id.
     *
     * @param array<string, mixed> $input
     * @return string[]
     */
    public static function candidate_post_types(array $input): array
    {
        $types = [];

        $declared = $input['post_type'] ?? ($input['type'] ?? null);
        foreach ((array) $declared as $value) {
            if (is_string($value) && '' !== $value) {
                $types[] = strtolower($value);
            }
        }

        foreach (self::candidate_ids($input) as $id) {
            $resolved = get_post_type($id);
            if (is_string($resolved) && '' !== $resolved) {
                $types[] = strtolower($resolved);
            }
        }

        return array_values(array_unique($types));
    }
}
