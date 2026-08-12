<?php

namespace WPMCP\Governance;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Append-only log of governance-decision outcomes, stored under a single
 * wpmcp_governance_audit_log option as a plain list of entries. This is a
 * separate concern from Tools\List_Operations (the Safety\Snapshot_Store
 * mutation trail): this log records every allow/deny permission-check
 * outcome, not mutations, and has no rollback semantics.
 *
 * Each entry is { ability (string), identity (string, 'none' when no
 * identity is active), allowed (bool), timestamp (int), reason (string,
 * empty unless a specific rule produced the outcome, e.g.
 * "memory-block:42", the id of the published project-memory entry that
 * denied the call, issue #131) }.
 *
 * The log is capped at CAP entries (500): once full, the oldest entry is
 * dropped for every new one recorded, so a busy site's option never grows
 * unbounded. 500 was chosen as a reasonable balance between "enough history
 * to audit recent activity" and "small enough that a single option row
 * stays cheap to read/write on every permission check."
 *
 * Timestamps come from an injectable clock (set_clock_for_tests), mirroring
 * Backup_Job_Store, for deterministic tests; production falls back to
 * time().
 */
class Governance_Audit_Log
{
    public const OPTION = 'wpmcp_governance_audit_log';
    public const CAP    = 500;

    private static ?int $clock_override = null;

    public static function set_clock_for_tests(?int $timestamp): void
    {
        self::$clock_override = $timestamp;
    }

    private static function now(): int
    {
        return self::$clock_override ?? time();
    }

    /**
     * Append a new entry, evicting the oldest one if the log is at capacity.
     *
     * $reason is an optional machine-readable marker for WHY a decision came
     * out the way it did, for the cases where the ability name alone does not
     * say. It defaults to '' so every pre-existing call site keeps recording
     * exactly the entry it always did.
     */
    public static function record(string $ability, string $identity, bool $allowed, string $reason = ''): void
    {
        $entries = self::load();

        $entries[] = [
            'ability'   => $ability,
            'identity'  => $identity,
            'allowed'   => $allowed,
            'timestamp' => self::now(),
            'reason'    => $reason,
        ];

        if (count($entries) > self::CAP) {
            $entries = array_slice($entries, -self::CAP);
        }

        update_option(self::OPTION, $entries);
    }

    /** Newest-first entries, limited to $limit (default: the entire log). */
    public static function list(int $limit = self::CAP): array
    {
        $entries = array_reverse(self::load());
        return array_slice($entries, 0, $limit);
    }

    private static function load(): array
    {
        $stored = get_option(self::OPTION, []);
        return is_array($stored) ? $stored : [];
    }
}
