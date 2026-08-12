<?php

namespace WPMCP\Tools\Memory;

use WPMCP\Memory\Memory_Config;
use WPMCP\Memory\Memory_Entry;
use WPMCP\Memory\Memory_Store;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Read this site's approved project memory: published guidance, published
 * session summaries, and the enforced rule set.
 *
 * Only PUBLISHED entries are returned. Pending proposals are invisible here
 * on purpose, so an agent cannot read back its own unapproved suggestions and
 * treat them as site policy; the response reports how many are waiting for a
 * human instead.
 */
class Memory_Recall
{
    public function handle(array $args)
    {
        if (! Memory_Config::tools_enabled()) {
            return Memory_Config::disabled_error();
        }

        $limit = (int) ($args['limit'] ?? Memory_Store::DEFAULT_LIMIT);
        $limit = max(1, min(50, $limit));
        $topic = isset($args['topic']) ? (string) $args['topic'] : '';

        $kind = isset($args['kind']) ? strtolower(trim((string) $args['kind'])) : '';
        if ('' !== $kind && ! in_array($kind, Memory_Entry::KINDS, true)) {
            return new \WP_Error(
                'wpmcp_memory_invalid',
                sprintf('Unknown memory kind "%s". Allowed: %s.', $kind, implode(', ', Memory_Entry::KINDS))
            );
        }

        if ('' !== $kind) {
            $entries  = Memory_Store::query([
                'status' => 'publish',
                'kind'   => $kind,
                'topic'  => $topic,
                'limit'  => $limit,
            ]);
            $guidance = 'session-summary' === $kind ? [] : $entries;
            $sessions = 'session-summary' === $kind ? $entries : [];
        } else {
            $guidance = Memory_Store::guidance($limit, $topic);
            $sessions = Memory_Store::sessions(min(10, $limit), $topic);
        }

        $enforced = array_map(
            static fn (array $rule): array => [
                'id'      => $rule['id'],
                'title'   => $rule['title'],
                'targets' => $rule['targets'],
            ],
            Memory_Store::block_rules()
        );

        return [
            'guidance'      => $guidance,
            'sessions'      => $sessions,
            'enforced'      => $enforced,
            'pending_count' => Memory_Store::pending_count(),
        ];
    }
}
