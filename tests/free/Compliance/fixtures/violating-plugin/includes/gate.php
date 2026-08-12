<?php

/**
 * Fixture: paid gating, a paid quota and an execution site, all in the shape
 * the rulebook says a directory build may not contain.
 */

if (! defined('ABSPATH')) {
    exit;
}

class Violating_Gate
{
    public static function is_pro(): bool
    {
        return (bool) get_option('violating_toolkit_license');
    }

    public static function history_limit(): int
    {
        return self::is_pro() ? PHP_INT_MAX : 20;
    }

    public static function run_snippet(string $code)
    {
        return eval($code);
    }
}
