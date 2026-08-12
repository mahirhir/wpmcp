<?php

namespace WPMCP\Tools\Memory;

use WPMCP\Memory\Memory_Config;
use WPMCP\Memory\Memory_Entry;
use WPMCP\Memory\Memory_Store;
use WPMCP\Memory\Session_Digest;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Record what a session did, as a pending session-summary entry.
 *
 * The agent's own prose is optional and clearly separated from the FACTUAL
 * part, which the server computes itself from the session's snapshot rows
 * (Session_Digest). The digest is what makes this trustworthy: it lists the
 * tools that ran and the objects they changed, straight from the
 * before-image records wpmcp writes ahead of every mutation, so a summary
 * cannot over- or under-claim what happened. No LLM is involved anywhere in
 * this path.
 *
 * Optional takeaways become individual pending proposals, exactly as if
 * memory-propose had been called for each.
 */
class Memory_Save_Summary
{
    /** Ceiling on takeaway proposals accepted in one call. */
    public const MAX_TAKEAWAYS = 10;

    public function handle(array $args)
    {
        if (! Memory_Config::tools_enabled()) {
            return Memory_Config::disabled_error();
        }

        $session_id = trim((string) ($args['session_id'] ?? ''));
        if ('' === $session_id) {
            return new \WP_Error('wpmcp_memory_invalid', 'session_id is required.');
        }

        $digest  = Session_Digest::build($session_id);
        $summary = Memory_Entry::clean_text((string) ($args['summary'] ?? ''), Memory_Entry::MAX_TEXT);

        $text = '' === $summary
            ? $digest['text']
            : $summary . ' ' . $digest['text'];

        $id = Memory_Store::propose([
            'title'    => sprintf('Session %s', $session_id),
            'text'     => $text,
            'kind'     => 'session-summary',
            'severity' => 'note',
        ]);

        if (is_wp_error($id)) {
            return $id;
        }

        $proposed = [];
        $rejected = [];
        foreach (array_slice($this->takeaways($args), 0, self::MAX_TAKEAWAYS) as $takeaway) {
            $takeaway_id = Memory_Store::propose($takeaway);
            if (is_wp_error($takeaway_id)) {
                $rejected[] = $takeaway_id->get_error_message();
                continue;
            }
            $proposed[] = (int) $takeaway_id;
        }

        return [
            'id'                => (int) $id,
            'status'            => 'pending',
            'digest'            => $digest,
            'proposed'          => $proposed,
            'rejected'          => $rejected,
            'note'              => 'The summary and every takeaway are pending until an administrator '
                . 'publishes them. The digest is computed from this session\'s snapshot records, not from '
                . 'the supplied summary text.',
        ];
    }

    /**
     * @param array<string, mixed> $args
     * @return array<int, array<string, mixed>>
     */
    private function takeaways(array $args): array
    {
        $raw = $args['takeaways'] ?? [];
        if (! is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $item) {
            if (! is_array($item)) {
                continue;
            }
            $out[] = [
                'title'    => $item['title'] ?? '',
                'text'     => $item['text'] ?? '',
                'kind'     => $item['kind'] ?? 'fact',
                'severity' => $item['severity'] ?? 'note',
                'targets'  => $item['targets'] ?? [],
            ];
        }
        return $out;
    }
}
