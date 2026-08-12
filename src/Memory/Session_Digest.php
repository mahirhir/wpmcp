<?php

namespace WPMCP\Memory;

use WPMCP\Safety\Snapshot_Store;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Deterministic rollup of what actually changed in one agent session
 * (issue #131).
 *
 * The source is Snapshot_Store: wpmcp snapshots every write BEFORE it runs,
 * so the rows for a session are a complete, server-recorded list of the
 * mutations that happened, each with its tool name, object, timestamp, and a
 * full before-image. The digest is computed from those rows, never from what
 * the agent claims it did, and never from an LLM summarization pass. That
 * makes it reproducible (same rows in, same text out), testable, free, and
 * offline; it also means the digest can be trusted as evidence rather than
 * read as a narrative.
 */
class Session_Digest
{
    /** Ceiling on rows folded into one digest. */
    public const MAX_OPERATIONS = 200;

    /** Ceiling on distinct objects named in the digest sentence. */
    public const MAX_NAMED_OBJECTS = 10;

    /**
     * @return array{session_id:string,operation_count:int,tools:array<int,array{tool:string,count:int}>,
     *               objects:array<int,array{object_type:string,object_id:int,count:int}>,
     *               first_at:string,last_at:string,text:string}
     */
    public static function build(string $session_id): array
    {
        $rows = self::rows($session_id);

        $tools     = [];
        $objects   = [];
        $timestamps = [];

        foreach ($rows as $row) {
            $tool           = (string) ($row['tool_name'] ?? '');
            $tools[ $tool ] = ($tools[ $tool ] ?? 0) + 1;

            $key             = ((string) ($row['object_type'] ?? '')) . '#' . ((int) ($row['object_id'] ?? 0));
            $objects[ $key ] = ($objects[ $key ] ?? 0) + 1;

            $created = (string) ($row['created_at'] ?? '');
            if ('' !== $created) {
                $timestamps[] = $created;
            }
        }

        ksort($tools);
        ksort($objects);
        sort($timestamps);

        $digest = [
            'session_id'      => $session_id,
            'operation_count' => count($rows),
            'tools'           => array_map(
                static fn (string $tool, int $count): array => ['tool' => $tool, 'count' => $count],
                array_keys($tools),
                array_values($tools)
            ),
            'objects'         => array_map(
                static function (string $key, int $count): array {
                    [$type, $id] = explode('#', $key, 2);
                    return ['object_type' => $type, 'object_id' => (int) $id, 'count' => $count];
                },
                array_keys($objects),
                array_values($objects)
            ),
            'first_at'        => $timestamps[0] ?? '',
            'last_at'         => [] === $timestamps ? '' : $timestamps[ count($timestamps) - 1 ],
        ];

        $digest['text'] = self::text($digest);

        return $digest;
    }

    /** @param array<string, mixed> $digest */
    public static function text(array $digest): string
    {
        $count = (int) $digest['operation_count'];
        $id    = (string) $digest['session_id'];

        if (0 === $count) {
            return sprintf('Session %s: no snapshotted changes recorded.', $id);
        }

        $tools = [];
        foreach ((array) $digest['tools'] as $tool) {
            $tools[] = sprintf('%s x%d', $tool['tool'], (int) $tool['count']);
        }

        $objects = [];
        foreach (array_slice((array) $digest['objects'], 0, self::MAX_NAMED_OBJECTS) as $object) {
            $objects[] = sprintf('%s #%d', $object['object_type'], (int) $object['object_id']);
        }
        $more = count((array) $digest['objects']) - count($objects);
        if ($more > 0) {
            $objects[] = sprintf('and %d more', $more);
        }

        return sprintf(
            'Session %s: %d snapshotted change(s) between %s and %s UTC. Tools: %s. Objects: %s. '
            . 'Every one has a stored before-image and is reversible with rollback-session.',
            $id,
            $count,
            (string) $digest['first_at'],
            (string) $digest['last_at'],
            implode(', ', $tools),
            implode(', ', $objects)
        );
    }

    /** @return array<int, array<string, mixed>> the session's snapshot rows, oldest first. */
    private static function rows(string $session_id): array
    {
        if ('' === trim($session_id)) {
            return [];
        }

        $rows = Snapshot_Store::list_by_session($session_id);
        if (! is_array($rows)) {
            return [];
        }

        // Snapshot_Store returns newest-first; a digest reads chronologically.
        $rows = array_reverse($rows);

        return array_slice($rows, 0, self::MAX_OPERATIONS);
    }
}
