<?php

namespace WPMCP\Tests\Free\Redirects;

use WPMCP\Tools\Redirects\Redirect_Handler;
use WPMCP\Tools\Redirects\Redirect_Store;

/**
 * Front-end enforcement of managed redirects (issue #128).
 *
 * The decision (resolve) is tested directly and the side effect
 * (maybe_redirect) through an injected redirector, so the full hook path is
 * covered without a wp_redirect()/exit killing the test process.
 *
 * PRECEDENCE AGAINST CANONICAL REDIRECTS is the load-bearing case: WordPress
 * registers redirect_canonical on template_redirect at priority 10, and for a
 * renamed or removed post it will either guess a "close enough" destination
 * or fall through to a 404. An explicitly configured redirect is a stated
 * intention and has to win, which it only does by running first.
 */
class RedirectHandlerTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        global $wpdb;
        $wpdb->query('DELETE FROM ' . Redirect_Store::table_name());
    }

    /**
     * A handler whose redirector records instead of exiting. The recorder is
     * an ArrayObject rather than a by-reference array so the caller keeps the
     * same instance the closure writes into.
     *
     * @return array{0:Redirect_Handler,1:\ArrayObject}
     */
    private function recording_handler(): array
    {
        $captured = new \ArrayObject();
        $handler  = new Redirect_Handler(static function (string $target, int $code) use ($captured): void {
            $captured[] = [$target, $code];
        });

        return [$handler, $captured];
    }

    /** The template_redirect priority the plugin's own handler is hooked at, if any. */
    private function handler_priority(): ?int
    {
        global $wp_filter;
        if (! isset($wp_filter['template_redirect'])) {
            return null;
        }

        foreach ($wp_filter['template_redirect']->callbacks as $priority => $callbacks) {
            foreach ($callbacks as $callback) {
                $function = $callback['function'];
                if (is_array($function) && ($function[0] ?? null) instanceof Redirect_Handler) {
                    return (int) $priority;
                }
            }
        }

        return null;
    }

    public function test_the_hook_runs_ahead_of_core_redirect_canonical(): void
    {
        $ours = $this->handler_priority();

        $this->assertNotNull($ours, 'The managed-redirect handler must be hooked to template_redirect.');
        $this->assertSame(Redirect_Handler::PRIORITY, $ours);
        $this->assertLessThan(
            has_action('template_redirect', 'redirect_canonical'),
            $ours,
            'A managed redirect must be decided before core guesses a canonical destination.'
        );
    }

    public function test_a_matching_enabled_redirect_resolves(): void
    {
        Redirect_Store::insert(['source_path' => '/old-page', 'target_url' => '/new-page', 'status_code' => 302]);
        $handler = new Redirect_Handler();

        $decision = $handler->resolve('/old-page');

        $this->assertNotNull($decision);
        $this->assertSame('/new-page', $decision['target']);
        $this->assertSame(302, $decision['status_code']);
    }

    public function test_an_unmatched_path_leaves_the_request_alone(): void
    {
        $this->assertNull((new Redirect_Handler())->resolve('/nothing-here'));
    }

    public function test_a_disabled_redirect_does_not_fire(): void
    {
        Redirect_Store::insert(['source_path' => '/old', 'target_url' => '/new', 'enabled' => 0]);

        $this->assertNull((new Redirect_Handler())->resolve('/old'));
    }

    public function test_the_site_root_is_never_redirected(): void
    {
        Redirect_Store::insert(['source_path' => '/', 'target_url' => '/somewhere']);

        $this->assertNull((new Redirect_Handler())->resolve('/'));
    }

    public function test_a_row_whose_target_post_is_gone_is_inert(): void
    {
        $post_id = self::factory()->post->create(['post_status' => 'publish']);
        Redirect_Store::insert(['source_path' => '/old', 'target_post_id' => $post_id]);
        wp_delete_post($post_id, true);

        $this->assertNull((new Redirect_Handler())->resolve('/old'));
    }

    public function test_a_post_id_target_resolves_at_match_time_so_it_survives_slug_changes(): void
    {
        $this->set_permalink_structure('/%postname%/');
        $post_id = self::factory()->post->create(['post_name' => 'first', 'post_status' => 'publish']);
        Redirect_Store::insert(['source_path' => '/old', 'target_post_id' => $post_id]);

        wp_update_post(['ID' => $post_id, 'post_name' => 'renamed']);

        $decision = (new Redirect_Handler())->resolve('/old');
        $this->assertNotNull($decision);
        $this->assertStringContainsString('/renamed/', $decision['target']);
    }

    public function test_a_stale_row_pointing_at_its_own_source_is_ignored(): void
    {
        Redirect_Store::insert(['source_path' => '/loop', 'target_url' => 'http://example.org/loop/']);

        $this->assertNull((new Redirect_Handler())->resolve('/loop'));
    }

    public function test_the_original_query_string_is_forwarded(): void
    {
        Redirect_Store::insert(['source_path' => '/old', 'target_url' => '/new']);

        $decision = (new Redirect_Handler())->resolve('/old?utm_source=news&page=2');

        $this->assertSame('/new?utm_source=news&page=2', $decision['target']);
    }

    public function test_a_target_with_its_own_query_string_is_not_appended_to(): void
    {
        Redirect_Store::insert(['source_path' => '/old', 'target_url' => '/new?ref=1']);

        $decision = (new Redirect_Handler())->resolve('/old?utm_source=news');

        $this->assertSame('/new?ref=1', $decision['target']);
    }

    public function test_an_unsupported_stored_status_code_is_clamped(): void
    {
        global $wpdb;
        $id = Redirect_Store::insert(['source_path' => '/old', 'target_url' => '/new']);
        $wpdb->query($wpdb->prepare(
            'UPDATE ' . Redirect_Store::table_name() . ' SET status_code = %d WHERE id = %d',
            404,
            $id
        ));

        $this->assertSame(301, (new Redirect_Handler())->resolve('/old')['status_code']);
    }

    /** @return array<string, array{0:string}> */
    public function protected_paths(): array
    {
        return [
            'admin'    => ['/wp-admin/edit.php'],
            'rest'     => ['/wp-json/wp/v2/posts'],
            'login'    => ['/wp-login.php'],
            'wp-cron'  => ['/wp-cron.php'],
        ];
    }

    /** @dataProvider protected_paths */
    public function test_infrastructure_paths_can_never_be_redirected(string $path): void
    {
        Redirect_Store::insert([
            'source_path' => Redirect_Store::normalize_path($path),
            'target_url'  => '/somewhere',
        ]);

        $this->assertTrue((new Redirect_Handler())->should_skip($path));
        $this->assertNull((new Redirect_Handler())->resolve($path));
    }

    public function test_wp_admin_requests_are_skipped_whatever_the_uri_looks_like(): void
    {
        Redirect_Store::insert(['source_path' => '/old', 'target_url' => '/new']);
        set_current_screen('dashboard');

        $skipped = (new Redirect_Handler())->should_skip('/old');

        set_current_screen('front');

        $this->assertTrue($skipped, 'A redirect must never fire inside wp-admin.');
    }

    public function test_cron_requests_are_skipped(): void
    {
        Redirect_Store::insert(['source_path' => '/old', 'target_url' => '/new']);
        add_filter('wp_doing_cron', '__return_true');

        $skipped = (new Redirect_Handler())->should_skip('/old');

        remove_filter('wp_doing_cron', '__return_true');

        $this->assertTrue($skipped);
    }

    public function test_maybe_redirect_sends_the_visitor_and_records_the_hit(): void
    {
        $id = Redirect_Store::insert(['source_path' => '/old', 'target_url' => '/new', 'status_code' => 308]);
        [$handler, $captured] = $this->recording_handler();
        $_SERVER['REQUEST_URI'] = '/old';

        $handler->maybe_redirect();

        $this->assertSame([['/new', 308]], $captured->getArrayCopy());
        $this->assertSame(1, Redirect_Store::get($id)['hits']);
    }

    public function test_maybe_redirect_does_nothing_and_records_nothing_without_a_match(): void
    {
        $id = Redirect_Store::insert(['source_path' => '/old', 'target_url' => '/new']);
        [$handler, $captured] = $this->recording_handler();
        $_SERVER['REQUEST_URI'] = '/some-other-page';

        $handler->maybe_redirect();

        $this->assertSame([], $captured->getArrayCopy());
        $this->assertSame(0, Redirect_Store::get($id)['hits']);
    }
}
