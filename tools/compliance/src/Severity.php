<?php

/**
 * Dev-only tooling. Not shipped in any plugin build, so no ABSPATH guard:
 * these files run under the PHP CLI, outside WordPress.
 */

namespace WPMCP\Compliance;

/**
 * The four severities used across the rulebook.
 *
 * blocker             wp.org Plugin Check error and/or an explicit guideline
 *                     prohibition. Will not pass review. Fails CI.
 * likely-reject       guideline text prohibits it, no automated wp.org check,
 *                     human reviewers catch it reliably.
 * reviewer-discretion outcome depends on the reviewer.
 * best-practice       Plugin Check warning or handbook recommendation only.
 */
final class Severity
{
    public const BLOCKER = 'blocker';
    public const LIKELY_REJECT = 'likely-reject';
    public const REVIEWER_DISCRETION = 'reviewer-discretion';
    public const BEST_PRACTICE = 'best-practice';

    private const RANK = [
        self::BEST_PRACTICE => 1,
        self::REVIEWER_DISCRETION => 2,
        self::LIKELY_REJECT => 3,
        self::BLOCKER => 4,
    ];

    public static function all(): array
    {
        return array_keys(self::RANK);
    }

    public static function is_valid(string $severity): bool
    {
        return isset(self::RANK[$severity]);
    }

    public static function rank(string $severity): int
    {
        return self::RANK[$severity] ?? 0;
    }

    /**
     * True when $severity is at least as severe as $floor.
     */
    public static function at_least(string $severity, string $floor): bool
    {
        return self::rank($severity) >= self::rank($floor);
    }

    /**
     * Sort comparator: most severe first, then by rule id, then file, then line.
     */
    public static function compare(Finding $a, Finding $b): int
    {
        $by_rank = self::rank($b->severity()) <=> self::rank($a->severity());
        if (0 !== $by_rank) {
            return $by_rank;
        }
        $by_rule = strcmp($a->rule_id(), $b->rule_id());
        if (0 !== $by_rule) {
            return $by_rule;
        }
        $by_file = strcmp($a->file(), $b->file());
        if (0 !== $by_file) {
            return $by_file;
        }
        return $a->line() <=> $b->line();
    }
}
