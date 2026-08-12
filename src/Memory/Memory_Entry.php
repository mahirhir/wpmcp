<?php

namespace WPMCP\Memory;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Validation + normalization for one project-memory entry (issue #131).
 *
 * An entry is { title, text, kind, severity, targets }. Everything that
 * reaches the store goes through validate(), whether it came from an agent
 * tool call or from the admin metabox, so there is exactly one definition of
 * a well-formed entry.
 *
 * Two rules matter for safety, both enforced here rather than at the call
 * sites:
 *
 *  - Targets are a closed grammar: "tool:<name>", "post_id:<int>", or
 *    "post_type:<slug>", nothing else. A rule can therefore only ever be
 *    matched by Memory_Guard against data the server itself derives, never
 *    against free text an agent wrote.
 *  - severity=block REQUIRES at least one target. A blanket block rule with
 *    no target would deny every write on the site, which is a footgun
 *    dressed as a guardrail, so it is rejected at the door.
 *
 * Normalized targets are deduplicated and sorted, so two entries expressing
 * the same rule store byte-identical target lists and matching is
 * deterministic.
 */
class Memory_Entry
{
    public const KINDS = ['guardrail', 'fact', 'convention', 'instruction', 'session-summary'];

    public const SEVERITIES = ['note', 'block'];

    public const TARGET_TYPES = ['tool', 'post_id', 'post_type'];

    public const MAX_TITLE   = 120;
    public const MAX_TEXT    = 2000;
    public const MAX_TARGETS = 20;

    /**
     * @param array<string, mixed> $raw
     * @return array{title:string,text:string,kind:string,severity:string,targets:string[]}|\WP_Error
     */
    public static function validate(array $raw)
    {
        $text = self::clean_text((string) ($raw['text'] ?? ''), self::MAX_TEXT);
        if ('' === $text) {
            return new \WP_Error('wpmcp_memory_invalid', 'A memory entry needs non-empty text.');
        }

        $kind = strtolower(trim((string) ($raw['kind'] ?? 'fact')));
        if (! in_array($kind, self::KINDS, true)) {
            return new \WP_Error(
                'wpmcp_memory_invalid',
                sprintf('Unknown memory kind "%s". Allowed: %s.', $kind, implode(', ', self::KINDS))
            );
        }

        $severity = strtolower(trim((string) ($raw['severity'] ?? 'note')));
        if (! in_array($severity, self::SEVERITIES, true)) {
            return new \WP_Error(
                'wpmcp_memory_invalid',
                sprintf('Unknown severity "%s". Allowed: %s.', $severity, implode(', ', self::SEVERITIES))
            );
        }

        $targets = self::normalize_targets($raw['targets'] ?? []);
        if (is_wp_error($targets)) {
            return $targets;
        }

        if ('block' === $severity && [] === $targets) {
            return new \WP_Error(
                'wpmcp_memory_invalid',
                'A severity=block entry must name at least one target (tool:, post_id:, or post_type:); '
                . 'an untargeted block rule would deny every write on the site.'
            );
        }

        $title = self::clean_text((string) ($raw['title'] ?? ''), self::MAX_TITLE);
        if ('' === $title) {
            $title = self::derive_title($text);
        }

        return [
            'title'    => $title,
            'text'     => $text,
            'kind'     => $kind,
            'severity' => $severity,
            'targets'  => $targets,
        ];
    }

    /**
     * @param mixed $raw A list of "type:value" strings (or one such string).
     * @return string[]|\WP_Error normalized, deduplicated, sorted targets.
     */
    public static function normalize_targets($raw)
    {
        if (is_string($raw)) {
            $raw = '' === trim($raw) ? [] : [$raw];
        }
        if (! is_array($raw)) {
            return new \WP_Error('wpmcp_memory_invalid', 'targets must be an array of "type:value" strings.');
        }

        $out = [];
        foreach ($raw as $candidate) {
            if (! is_string($candidate) || '' === trim($candidate)) {
                continue;
            }
            $target = self::normalize_target(trim($candidate));
            if (is_wp_error($target)) {
                return $target;
            }
            $out[ $target ] = true;
        }

        if (count($out) > self::MAX_TARGETS) {
            return new \WP_Error(
                'wpmcp_memory_invalid',
                sprintf('A memory entry may carry at most %d targets.', self::MAX_TARGETS)
            );
        }

        $targets = array_keys($out);
        sort($targets);
        return $targets;
    }

    /** @return string|\WP_Error one canonical "type:value" target. */
    public static function normalize_target(string $raw)
    {
        $parts = explode(':', $raw, 2);
        if (2 !== count($parts)) {
            return new \WP_Error(
                'wpmcp_memory_invalid',
                sprintf('Target "%s" must be written as type:value (%s).', $raw, implode('|', self::TARGET_TYPES))
            );
        }

        $type  = strtolower(trim($parts[0]));
        $value = trim($parts[1]);

        if (! in_array($type, self::TARGET_TYPES, true)) {
            return new \WP_Error(
                'wpmcp_memory_invalid',
                sprintf('Unknown target type "%s". Allowed: %s.', $type, implode(', ', self::TARGET_TYPES))
            );
        }
        if ('' === $value) {
            return new \WP_Error('wpmcp_memory_invalid', sprintf('Target "%s" has no value.', $raw));
        }

        if ('tool' === $type) {
            // Ability names, optionally with a single trailing * wildcard
            // ("tool:wpmcp/delete-*"). The wpmcp/ prefix is optional on
            // input; matching accepts both forms.
            if (! preg_match('#^[a-z0-9][a-z0-9/_-]{0,62}\*?$#i', $value)) {
                return new \WP_Error('wpmcp_memory_invalid', sprintf('Invalid tool target "%s".', $value));
            }
            return 'tool:' . strtolower($value);
        }

        if ('post_id' === $type) {
            if (! preg_match('/^[0-9]{1,19}$/', $value) || (int) $value <= 0) {
                return new \WP_Error('wpmcp_memory_invalid', sprintf('Invalid post_id target "%s".', $value));
            }
            return 'post_id:' . (int) $value;
        }

        if (! preg_match('/^[a-z0-9_-]{1,32}$/i', $value)) {
            return new \WP_Error('wpmcp_memory_invalid', sprintf('Invalid post_type target "%s".', $value));
        }
        return 'post_type:' . strtolower($value);
    }

    /**
     * Plain-text cleanup shared by every field: tags stripped (entries travel
     * as plain text into the MCP handshake, never as HTML), whitespace
     * collapsed, and a multibyte-safe length clamp.
     */
    public static function clean_text(string $raw, int $max): string
    {
        $clean = wp_strip_all_tags($raw, true);
        $clean = trim((string) preg_replace('/\s+/u', ' ', $clean));
        return mb_substr($clean, 0, $max);
    }

    /** First 8 words of the text, used when no title was supplied. */
    private static function derive_title(string $text): string
    {
        $words = preg_split('/\s+/u', $text) ?: [];
        return mb_substr(implode(' ', array_slice($words, 0, 8)), 0, self::MAX_TITLE);
    }
}
