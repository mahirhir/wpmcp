<?php

namespace WPMCP\Auth;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Scheduled and opportunistic garbage collection for the OAuth stores
 * (issue #133).
 *
 * Every OAuth store lives in a single wp_options row read and written in
 * full on each touch, and until now eviction was lazy and on-touch only:
 * an expired record was dropped the next time somebody happened to present
 * it. Records nobody ever presents again -- authorization codes from a
 * consent screen the user closed, access tokens from a laptop that was
 * reimaged, refresh tokens from an uninstalled client -- were therefore
 * immortal. Three consequences, in increasing order of severity: every
 * OAuth request pays to unserialize the dead weight; the autoloaded option
 * grows without bound; and Client_Store's MAX_CLIENTS cap is eventually
 * reached by dead rows, at which point the site refuses new connections
 * for no reason a site owner can see.
 *
 * Two triggers, both idempotent:
 *
 *  - a daily WP-Cron event (self::HOOK), scheduled on activation and
 *    re-ensured at boot so installs that predate this version pick it up;
 *  - an opportunistic throttled call from the two OAuth write paths (token
 *    exchange and dynamic client registration).
 *
 * The opportunistic path is deliberately NOT wired into bearer-token
 * validation, which is where the implementation we studied put it. That
 * runs on every single authenticated MCP request, so it charges a
 * transient read to the hottest path in the plugin to clean up a store
 * that only two much colder endpoints ever grow. Putting it on the write
 * paths collects exactly as often as there is anything new to collect.
 *
 * Ordering inside run() matters: tokens are swept before clients, because
 * a client only counts as an orphan when it holds no tokens, and a token
 * that expired an hour ago should not keep its dead client alive.
 */
class Oauth_Gc
{
    public const HOOK = 'wpmcp_oauth_gc';

    /** How long a token-less client is protected from the orphan sweep. */
    public const ORPHAN_CLIENT_GRACE = 86400; // 1 day.

    /** Shortest gap between opportunistic sweeps. */
    public const THROTTLE_SECONDS = 900; // 15 minutes.

    private const THROTTLE_TRANSIENT = 'wpmcp_oauth_gc_throttle';

    /** Register the cron callback. Cheap and unconditional; scheduling is separate. */
    public static function register(): void
    {
        add_action(self::HOOK, [self::class, 'run']);
    }

    /**
     * Make sure the daily sweep is on the schedule. Safe to call on every
     * request: wp_next_scheduled() short-circuits once it is.
     */
    public static function ensure_scheduled(): void
    {
        if (! function_exists('wp_next_scheduled') || wp_next_scheduled(self::HOOK)) {
            return;
        }

        wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::HOOK);
    }

    /** Remove the scheduled sweep (deactivation, or OAuth being switched off). */
    public static function unschedule(): void
    {
        if (! function_exists('wp_clear_scheduled_hook')) {
            return;
        }

        wp_clear_scheduled_hook(self::HOOK);
    }

    /**
     * Sweep every OAuth store once.
     *
     * @return array{codes: int, access_tokens: int, refresh_tokens: int, clients: int}
     *         How many records each store dropped, so the caller (and the
     *         tests) can assert on the work actually done.
     */
    public static function run(): array
    {
        $codes          = Code_Store::gc();
        $access_tokens  = Token_Store::gc();
        $refresh_tokens = Refresh_Token_Store::gc();
        $clients        = Client_Store::gc(self::orphan_grace());

        return [
            'codes'          => $codes,
            'access_tokens'  => $access_tokens,
            'refresh_tokens' => $refresh_tokens,
            'clients'        => $clients,
        ];
    }

    /**
     * Run the sweep at most once per THROTTLE_SECONDS, so it can be called
     * unconditionally from a request path without turning every OAuth
     * write into a full store rewrite.
     *
     * @return bool Whether this call actually swept.
     */
    public static function run_throttled(): bool
    {
        if (get_transient(self::THROTTLE_TRANSIENT)) {
            return false;
        }

        set_transient(self::THROTTLE_TRANSIENT, 1, self::throttle());
        self::run();

        return true;
    }

    /** Test seam: forget the throttle so the next opportunistic call sweeps. */
    public static function reset_throttle(): void
    {
        delete_transient(self::THROTTLE_TRANSIENT);
    }

    private static function orphan_grace(): int
    {
        return max(0, (int) apply_filters('wpmcp_oauth_orphan_client_grace', self::ORPHAN_CLIENT_GRACE));
    }

    private static function throttle(): int
    {
        return max(60, (int) apply_filters('wpmcp_oauth_gc_throttle', self::THROTTLE_SECONDS));
    }
}
