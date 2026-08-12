<?php

namespace WPMCP\Tests\Free\Auth;

use WPMCP\Auth\Client_Store;
use WPMCP\Auth\Code_Store;
use WPMCP\Auth\Oauth_Gc;
use WPMCP\Auth\Refresh_Token_Store;
use WPMCP\Auth\Token_Store;

/**
 * Scheduled + opportunistic garbage collection for the OAuth stores
 * (issue #133). Eviction used to be lazy and on-touch only, so records
 * nobody ever presented again -- codes from an abandoned consent screen,
 * tokens from a reimaged laptop, clients from a connector that was
 * uninstalled -- were immortal, and eventually Client_Store's MAX_CLIENTS
 * cap was reached by dead rows and the site began refusing new
 * connections for no visible reason.
 */
class OauthGcTest extends \WP_UnitTestCase
{
    private int $clock = 3000000;

    protected function setUp(): void
    {
        parent::setUp();
        foreach ([Client_Store::OPTION, Code_Store::OPTION, Token_Store::OPTION, Refresh_Token_Store::OPTION] as $option) {
            delete_option($option);
        }
        Oauth_Gc::reset_throttle();
        Oauth_Gc::unschedule();
        $this->clock = 3000000;
        Code_Store::set_clock_override(fn () => $this->clock);
        Token_Store::set_clock_override(fn () => $this->clock);
        Refresh_Token_Store::set_clock_override(fn () => $this->clock);
    }

    protected function tearDown(): void
    {
        Code_Store::set_clock_override(null);
        Token_Store::set_clock_override(null);
        Refresh_Token_Store::set_clock_override(null);
        Oauth_Gc::unschedule();
        foreach ([Client_Store::OPTION, Code_Store::OPTION, Token_Store::OPTION, Refresh_Token_Store::OPTION] as $option) {
            delete_option($option);
        }
        parent::tearDown();
    }

    private function issue_code(): string
    {
        return Code_Store::issue([
            'client_id'             => 'client_a',
            'user_id'               => 1,
            'redirect_uri'          => 'https://example.com/cb',
            'code_challenge'        => 'x',
            'code_challenge_method' => 'S256',
            'scope'                 => 'read',
        ]);
    }

    public function test_abandoned_authorization_codes_are_swept(): void
    {
        $this->issue_code();
        $this->issue_code();

        $this->assertCount(2, get_option(Code_Store::OPTION));

        $this->clock += Code_Store::TTL_SECONDS + 1;

        $this->assertSame(2, Code_Store::gc());
        $this->assertSame([], get_option(Code_Store::OPTION));
    }

    public function test_a_live_code_survives_the_sweep(): void
    {
        $code = $this->issue_code();

        $this->assertSame(0, Code_Store::gc());
        $this->assertNotNull(Code_Store::consume($code));
    }

    public function test_expired_access_tokens_are_swept(): void
    {
        $user = self::factory()->user->create();
        Token_Store::issue('client_a', $user, 'read');

        $this->clock += Token_Store::TTL_SECONDS + 1;
        $live = Token_Store::issue('client_a', $user, 'read');

        $this->assertSame(1, Token_Store::gc());
        $this->assertCount(1, get_option(Token_Store::OPTION));
        $this->assertNotNull(Token_Store::validate($live));
    }

    public function test_orphan_clients_are_swept_once_past_the_grace_window(): void
    {
        $orphan = Client_Store::create(['Abandoned'], ['https://example.com/cb']);
        $recent = Client_Store::create(['Just Registered'], ['https://example.com/cb2']);

        // Age only the first registration past the orphan grace window.
        $stored = get_option(Client_Store::OPTION);
        $stored[ $orphan['client_id'] ]['created_at'] -= Oauth_Gc::ORPHAN_CLIENT_GRACE + 1;
        update_option(Client_Store::OPTION, $stored);

        $this->assertSame(1, Client_Store::gc(Oauth_Gc::ORPHAN_CLIENT_GRACE));
        $this->assertNull(Client_Store::get($orphan['client_id']));
        $this->assertNotNull(Client_Store::get($recent['client_id']));
    }

    public function test_a_client_holding_tokens_is_never_treated_as_an_orphan(): void
    {
        $user   = self::factory()->user->create();
        $with_access  = Client_Store::create(['Has Access'], ['https://example.com/a']);
        $with_refresh = Client_Store::create(['Has Refresh'], ['https://example.com/b']);

        Token_Store::issue($with_access['client_id'], $user, 'read');
        Refresh_Token_Store::issue($with_refresh['client_id'], $user, 'read');

        $stored = get_option(Client_Store::OPTION);
        foreach (array_keys($stored) as $client_id) {
            $stored[ $client_id ]['created_at'] -= Oauth_Gc::ORPHAN_CLIENT_GRACE * 10;
        }
        update_option(Client_Store::OPTION, $stored);

        $this->assertSame(0, Client_Store::gc(Oauth_Gc::ORPHAN_CLIENT_GRACE));
        $this->assertNotNull(Client_Store::get($with_access['client_id']));
        $this->assertNotNull(Client_Store::get($with_refresh['client_id']));
    }

    public function test_run_reports_what_each_store_dropped(): void
    {
        $user = self::factory()->user->create();
        $this->issue_code();
        Token_Store::issue('client_a', $user, 'read');
        Refresh_Token_Store::issue('client_a', $user, 'read');

        $this->clock += Refresh_Token_Store::TTL_SECONDS + 1;

        $swept = Oauth_Gc::run();

        $this->assertSame(1, $swept['codes']);
        $this->assertSame(1, $swept['access_tokens']);
        $this->assertSame(1, $swept['refresh_tokens']);
        $this->assertSame(0, $swept['clients']);
    }

    public function test_the_sweep_is_idempotent(): void
    {
        $this->issue_code();
        $this->clock += Code_Store::TTL_SECONDS + 1;

        $this->assertSame(1, Oauth_Gc::run()['codes']);
        $this->assertSame(0, Oauth_Gc::run()['codes']);
    }

    public function test_the_opportunistic_sweep_is_throttled(): void
    {
        $this->issue_code();
        $this->clock += Code_Store::TTL_SECONDS + 1;

        $this->assertTrue(Oauth_Gc::run_throttled());

        // A second issued-then-expired code is NOT collected until the
        // throttle lapses: that is the whole point of putting this on a
        // request path.
        $this->issue_code();
        $this->clock += Code_Store::TTL_SECONDS + 1;

        $this->assertFalse(Oauth_Gc::run_throttled());
        $this->assertCount(1, get_option(Code_Store::OPTION));

        Oauth_Gc::reset_throttle();

        $this->assertTrue(Oauth_Gc::run_throttled());
        $this->assertSame([], get_option(Code_Store::OPTION));
    }

    public function test_the_daily_event_is_scheduled_once_and_can_be_cleared(): void
    {
        $this->assertFalse(wp_next_scheduled(Oauth_Gc::HOOK));

        Oauth_Gc::ensure_scheduled();
        $first = wp_next_scheduled(Oauth_Gc::HOOK);
        $this->assertIsInt($first);

        // Idempotent: calling it again on every request must not pile up
        // duplicate events.
        Oauth_Gc::ensure_scheduled();
        $this->assertSame($first, wp_next_scheduled(Oauth_Gc::HOOK));

        Oauth_Gc::unschedule();
        $this->assertFalse(wp_next_scheduled(Oauth_Gc::HOOK));
    }

    public function test_the_cron_hook_runs_the_sweep(): void
    {
        Oauth_Gc::register();
        $this->issue_code();
        $this->clock += Code_Store::TTL_SECONDS + 1;

        do_action(Oauth_Gc::HOOK);

        $this->assertSame([], get_option(Code_Store::OPTION));

        remove_action(Oauth_Gc::HOOK, [Oauth_Gc::class, 'run']);
    }

    public function test_activation_schedules_the_sweep_only_when_oauth_is_enabled(): void
    {
        \WPMCP\Activator::activate();
        $this->assertFalse(wp_next_scheduled(Oauth_Gc::HOOK), 'OAuth is off by default, so nothing should be scheduled.');

        add_filter('wpmcp_oauth_enabled', '__return_true');
        \WPMCP\Activator::activate();

        $this->assertIsInt(wp_next_scheduled(Oauth_Gc::HOOK));
    }

    public function test_the_orphan_grace_and_throttle_are_filterable(): void
    {
        add_filter('wpmcp_oauth_gc_throttle', fn () => 1);
        add_filter('wpmcp_oauth_orphan_client_grace', fn () => 0);

        $client = Client_Store::create(['Immediately Orphaned'], ['https://example.com/cb']);
        $stored = get_option(Client_Store::OPTION);
        $stored[ $client['client_id'] ]['created_at'] -= 1;
        update_option(Client_Store::OPTION, $stored);

        $this->assertTrue(Oauth_Gc::run_throttled());
        $this->assertNull(Client_Store::get($client['client_id']));
    }
}
