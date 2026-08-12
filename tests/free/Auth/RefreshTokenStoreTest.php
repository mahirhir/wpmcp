<?php

namespace WPMCP\Tests\Free\Auth;

use WPMCP\Auth\Refresh_Token_Store;
use WPMCP\Auth\Token_Store;

/**
 * Refresh tokens with rotation, an idempotent-refresh grace window, and
 * post-grace reuse detection (issue #133). The three-state model under
 * test: FRESH rotates, IN GRACE forgives a dropped-response retry, BURNED
 * revokes the entire grant chain including the access tokens issued along
 * it.
 */
class RefreshTokenStoreTest extends \WP_UnitTestCase
{
    private int $clock = 1000000;

    protected function setUp(): void
    {
        parent::setUp();
        delete_option(Refresh_Token_Store::OPTION);
        delete_option(Token_Store::OPTION);
        $this->clock = 1000000;
        Refresh_Token_Store::set_clock_override(fn () => $this->clock);
        Token_Store::set_clock_override(fn () => $this->clock);
    }

    protected function tearDown(): void
    {
        Refresh_Token_Store::set_clock_override(null);
        Token_Store::set_clock_override(null);
        delete_option(Refresh_Token_Store::OPTION);
        delete_option(Token_Store::OPTION);
        parent::tearDown();
    }

    public function test_issue_returns_a_plaintext_token_that_is_never_stored(): void
    {
        $token = Refresh_Token_Store::issue('client_a', 7, 'read');

        $this->assertStringStartsWith('rt_', $token);
        $this->assertStringNotContainsString(
            $token,
            (string) wp_json_encode(get_option(Refresh_Token_Store::OPTION))
        );
    }

    public function test_a_fresh_token_redeems_and_is_marked_rotated(): void
    {
        $token   = Refresh_Token_Store::issue('client_a', 7, 'read write');
        $outcome = Refresh_Token_Store::redeem($token);

        $this->assertSame('ok', $outcome['status']);
        $this->assertSame('client_a', $outcome['record']['client_id']);
        $this->assertSame(7, $outcome['record']['user_id']);
        $this->assertSame('read write', $outcome['record']['scope']);
    }

    public function test_an_unknown_token_is_rejected(): void
    {
        $this->assertSame('unknown', Refresh_Token_Store::redeem('rt_nope')['status']);
    }

    public function test_an_expired_token_is_rejected_and_evicted(): void
    {
        $token = Refresh_Token_Store::issue('client_a', 7, 'read');

        $this->clock += Refresh_Token_Store::TTL_SECONDS + 1;

        $this->assertSame('expired', Refresh_Token_Store::redeem($token)['status']);
        $this->assertSame([], get_option(Refresh_Token_Store::OPTION));
    }

    public function test_a_replay_inside_the_grace_window_is_forgiven(): void
    {
        // The production race: the rotation response is dropped in transit,
        // so the client retries with the token it still holds.
        $token = Refresh_Token_Store::issue('client_a', 7, 'read');

        $this->assertSame('ok', Refresh_Token_Store::redeem($token)['status']);

        $this->clock += Refresh_Token_Store::GRACE_SECONDS - 1;

        $replay = Refresh_Token_Store::redeem($token);

        $this->assertSame('grace', $replay['status']);
        $this->assertSame('client_a', $replay['record']['client_id']);
    }

    public function test_the_grace_window_is_anchored_to_the_first_rotation_and_cannot_be_walked_forward(): void
    {
        $token = Refresh_Token_Store::issue('client_a', 7, 'read');
        Refresh_Token_Store::redeem($token);

        // Replay repeatedly inside the window: each is forgiven, but none
        // extends the deadline.
        $this->clock += Refresh_Token_Store::GRACE_SECONDS - 1;
        $this->assertSame('grace', Refresh_Token_Store::redeem($token)['status']);

        $this->clock += 2;
        $this->assertSame('reuse_detected', Refresh_Token_Store::redeem($token)['status']);
    }

    public function test_reuse_after_the_grace_window_revokes_the_whole_chain(): void
    {
        $user   = self::factory()->user->create();
        $chain  = Refresh_Token_Store::new_chain_id();
        $first  = Refresh_Token_Store::issue('client_a', $user, 'read', $chain);
        $access = Token_Store::issue('client_a', $user, 'read', $chain);

        // The access token is genuinely usable before the breach is seen.
        $this->assertNotNull(Token_Store::validate($access));

        $this->assertSame('ok', Refresh_Token_Store::redeem($first)['status']);
        $second = Refresh_Token_Store::issue('client_a', $user, 'read', $chain);

        $this->clock += Refresh_Token_Store::GRACE_SECONDS + 1;

        $this->assertSame('reuse_detected', Refresh_Token_Store::redeem($first)['status']);

        // Everything descended from the compromised grant is gone: the
        // successor refresh token AND the access token already minted along
        // the chain, which is what stops a thief keeping an hour of access.
        $this->assertSame('unknown', Refresh_Token_Store::redeem($second)['status']);
        $this->assertNull(Token_Store::validate($access));
    }

    public function test_revocation_is_scoped_to_the_chain_and_leaves_other_grants_alone(): void
    {
        $victim_chain = Refresh_Token_Store::new_chain_id();
        $victim       = Refresh_Token_Store::issue('client_a', 7, 'read', $victim_chain);
        $bystander    = Refresh_Token_Store::issue('client_a', 7, 'read');
        $user         = self::factory()->user->create();
        $other_access = Token_Store::issue('client_a', $user, 'read');

        Refresh_Token_Store::redeem($victim);
        $this->clock += Refresh_Token_Store::GRACE_SECONDS + 1;
        Refresh_Token_Store::redeem($victim);

        $this->assertSame('ok', Refresh_Token_Store::redeem($bystander)['status']);
        $this->assertNotNull(Token_Store::validate($other_access));
    }

    public function test_a_token_presented_by_the_wrong_client_is_rejected_without_being_burned(): void
    {
        $token = Refresh_Token_Store::issue('client_a', 7, 'read');

        $this->assertSame('client_mismatch', Refresh_Token_Store::redeem($token, 'client_b')['status']);

        // Crucially the rightful owner can still use it: a caller who only
        // guessed the token must not be able to invalidate it.
        $this->assertSame('ok', Refresh_Token_Store::redeem($token, 'client_a')['status']);
    }

    public function test_a_zero_grace_window_makes_rotation_strictly_single_use(): void
    {
        add_filter('wpmcp_oauth_refresh_grace', '__return_zero');

        $token = Refresh_Token_Store::issue('client_a', 7, 'read');
        Refresh_Token_Store::redeem($token);

        $this->clock += 1;

        $this->assertSame('reuse_detected', Refresh_Token_Store::redeem($token)['status']);
    }

    public function test_gc_removes_expired_records_but_keeps_the_reuse_tripwire(): void
    {
        $rotated = Refresh_Token_Store::issue('client_a', 7, 'read');
        Refresh_Token_Store::redeem($rotated);
        $stale = Refresh_Token_Store::issue('client_a', 7, 'read');

        // Past the grace window but well inside the TTL.
        $this->clock += Refresh_Token_Store::GRACE_SECONDS + 60;

        $this->assertSame(0, Refresh_Token_Store::gc());
        $this->assertSame('reuse_detected', Refresh_Token_Store::redeem($rotated)['status']);

        $this->clock += Refresh_Token_Store::TTL_SECONDS;

        $this->assertSame(1, Refresh_Token_Store::gc());
        $this->assertSame('unknown', Refresh_Token_Store::redeem($stale)['status']);
    }

    public function test_revoke_for_client_drops_only_that_clients_tokens(): void
    {
        Refresh_Token_Store::issue('client_a', 7, 'read');
        Refresh_Token_Store::issue('client_a', 7, 'read');
        $keep = Refresh_Token_Store::issue('client_b', 7, 'read');

        $this->assertSame(2, Refresh_Token_Store::revoke_for_client('client_a'));
        $this->assertFalse(Refresh_Token_Store::has_tokens_for_client('client_a'));
        $this->assertTrue(Refresh_Token_Store::has_tokens_for_client('client_b'));
        $this->assertSame('ok', Refresh_Token_Store::redeem($keep)['status']);
    }

    public function test_revoking_an_empty_chain_id_is_a_no_op(): void
    {
        Refresh_Token_Store::issue('client_a', 7, 'read');

        $this->assertSame(0, Refresh_Token_Store::revoke_chain(''));
        $this->assertSame(0, Token_Store::revoke_chain(''));
        $this->assertTrue(Refresh_Token_Store::has_tokens_for_client('client_a'));
    }

    public function test_the_ttl_is_filterable_and_floored(): void
    {
        add_filter('wpmcp_oauth_refresh_ttl', fn () => 1);

        $this->assertSame(60, Refresh_Token_Store::ttl());
    }
}
