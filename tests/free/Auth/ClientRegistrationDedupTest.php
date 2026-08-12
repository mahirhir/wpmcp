<?php

namespace WPMCP\Tests\Free\Auth;

use WPMCP\Auth\Client_Registration;
use WPMCP\Auth\Client_Store;
use WPMCP\Auth\Oauth_Gc;
use WPMCP\Auth\Refresh_Token_Store;
use WPMCP\Auth\Token_Store;
use WPMCP\Governance\Governance_Audit_Log;

/**
 * Dynamic Client Registration dedup (issue #133).
 *
 * MCP clients re-run DCR on every connect. Without dedup one user
 * reconnecting a few dozen times fills the client store with dead rows
 * until MAX_CLIENTS is hit, at which point the site refuses new
 * connections for a reason no site owner can diagnose.
 *
 * Reuse rotates the client secret, so the dedup key deliberately includes
 * the registering caller and reuse is refused for any client that still
 * holds a token. Those two conditions are what keep an open registration
 * endpoint from becoming a way to break someone else's live connection,
 * and they are the tests that matter most here.
 */
class ClientRegistrationDedupTest extends \WP_UnitTestCase
{
    private const ARGS = [
        'client_name'   => 'Claude Desktop',
        'redirect_uris' => ['https://claude.ai/api/mcp/auth_callback', 'http://localhost:33418/cb'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        delete_option(Client_Store::OPTION);
        delete_option(Token_Store::OPTION);
        delete_option(Refresh_Token_Store::OPTION);
        delete_option(Governance_Audit_Log::OPTION);
        Oauth_Gc::reset_throttle();
        add_filter('wpmcp_oauth_registration_rate_limit', fn () => 1000);
    }

    protected function tearDown(): void
    {
        delete_option(Client_Store::OPTION);
        delete_option(Token_Store::OPTION);
        delete_option(Refresh_Token_Store::OPTION);
        delete_option(Governance_Audit_Log::OPTION);
        parent::tearDown();
    }

    public function test_a_reconnect_from_the_same_caller_reuses_the_client_row(): void
    {
        $first  = Client_Registration::register(self::ARGS, 'ip:198.51.100.10');
        $second = Client_Registration::register(self::ARGS, 'ip:198.51.100.10');

        $this->assertSame($first['client_id'], $second['client_id']);
        $this->assertSame(1, Client_Store::count());
    }

    public function test_reuse_issues_a_fresh_working_secret_and_retires_the_old_one(): void
    {
        $first  = Client_Registration::register(self::ARGS, 'ip:198.51.100.10');
        $second = Client_Registration::register(self::ARGS, 'ip:198.51.100.10');

        $this->assertNotSame($first['client_secret'], $second['client_secret']);
        $this->assertTrue(Client_Store::verify_secret($second['client_id'], $second['client_secret']));
        $this->assertFalse(Client_Store::verify_secret($first['client_id'], $first['client_secret']));
    }

    public function test_redirect_uri_order_does_not_defeat_dedup(): void
    {
        $reordered = [
            'client_name'   => self::ARGS['client_name'],
            'redirect_uris' => array_reverse(self::ARGS['redirect_uris']),
        ];

        $first  = Client_Registration::register(self::ARGS, 'ip:198.51.100.10');
        $second = Client_Registration::register($reordered, 'ip:198.51.100.10');

        $this->assertSame($first['client_id'], $second['client_id']);
    }

    public function test_different_metadata_still_registers_a_separate_client(): void
    {
        $other = [
            'client_name'   => 'Cursor',
            'redirect_uris' => self::ARGS['redirect_uris'],
        ];

        $first  = Client_Registration::register(self::ARGS, 'ip:198.51.100.10');
        $second = Client_Registration::register($other, 'ip:198.51.100.10');

        $this->assertNotSame($first['client_id'], $second['client_id']);
        $this->assertSame(2, Client_Store::count());
    }

    public function test_a_different_caller_cannot_rotate_an_existing_clients_secret(): void
    {
        // Client metadata is publicly guessable, so if dedup keyed on it
        // alone any anonymous caller could mint a new secret for someone
        // else's client_id and break their next token exchange.
        $legit    = Client_Registration::register(self::ARGS, 'ip:198.51.100.10');
        $intruder = Client_Registration::register(self::ARGS, 'ip:203.0.113.99');

        $this->assertNotSame($legit['client_id'], $intruder['client_id']);
        $this->assertTrue(Client_Store::verify_secret($legit['client_id'], $legit['client_secret']));
    }

    public function test_a_client_holding_an_access_token_is_never_recycled(): void
    {
        $first = Client_Registration::register(self::ARGS, 'ip:198.51.100.10');
        Token_Store::issue($first['client_id'], self::factory()->user->create(), 'read');

        $second = Client_Registration::register(self::ARGS, 'ip:198.51.100.10');

        $this->assertNotSame($first['client_id'], $second['client_id']);
        // The working connection keeps its secret.
        $this->assertTrue(Client_Store::verify_secret($first['client_id'], $first['client_secret']));
    }

    public function test_a_client_holding_a_refresh_token_is_never_recycled(): void
    {
        $first = Client_Registration::register(self::ARGS, 'ip:198.51.100.10');
        Refresh_Token_Store::issue($first['client_id'], self::factory()->user->create(), 'read');

        $second = Client_Registration::register(self::ARGS, 'ip:198.51.100.10');

        $this->assertNotSame($first['client_id'], $second['client_id']);
        $this->assertTrue(Client_Store::verify_secret($first['client_id'], $first['client_secret']));
    }

    public function test_repeated_reconnects_do_not_walk_the_client_cap(): void
    {
        add_filter('wpmcp_oauth_max_clients', fn () => 3);

        for ($i = 0; $i < 25; $i++) {
            $result = Client_Registration::register(self::ARGS, 'ip:198.51.100.10');
            $this->assertIsArray($result, "Reconnect {$i} was refused.");
        }

        $this->assertSame(1, Client_Store::count());
    }
}
