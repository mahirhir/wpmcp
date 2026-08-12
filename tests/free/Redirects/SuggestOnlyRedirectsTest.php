<?php

namespace WPMCP\Tests\Free\Redirects;

use WPMCP\Tools\Content\Delete_Post;
use WPMCP\Tools\Content\Update_Post;
use WPMCP\Tools\Redirects\Redirect_Store;
use WPMCP\Tools\Redirects\Redirect_Suggestions;

/**
 * Suggest-only redirects (issue #128).
 *
 * The invariant these tests exist to protect: deleting a post or moving a
 * published URL PROPOSES a redirect and never creates one. Routing is a
 * site-wide decision, and an agent editing content must not be able to change
 * it as an invisible side effect. Every case that asserts a suggestion also
 * asserts that the redirect table is still empty.
 */
class SuggestOnlyRedirectsTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        global $wpdb;
        $wpdb->query('DELETE FROM ' . Redirect_Store::table_name());
        delete_option(Redirect_Suggestions::OPTION);
        $this->set_permalink_structure('/%postname%/');
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    private function assertNoRedirectsExist(): void
    {
        $this->assertSame(
            0,
            Redirect_Store::count(),
            'A content edit must never create a redirect on its own.'
        );
    }

    // -----------------------------------------------------------------
    // the queue itself
    // -----------------------------------------------------------------

    public function test_propose_normalizes_and_queues_a_suggestion(): void
    {
        $suggestion = Redirect_Suggestions::propose(
            'http://example.org/Old-Page/',
            Redirect_Suggestions::REASON_POST_DELETED
        );

        $this->assertSame('/old-page', $suggestion['source']);
        $this->assertSame('post-deleted', $suggestion['reason']);
        $this->assertSame([$suggestion], Redirect_Suggestions::all());
        $this->assertNoRedirectsExist();
    }

    public function test_propose_declines_when_the_path_is_already_redirected(): void
    {
        Redirect_Store::insert(['source_path' => '/old', 'target_url' => '/new']);

        $this->assertNull(Redirect_Suggestions::propose('/old', Redirect_Suggestions::REASON_POST_DELETED));
        $this->assertSame([], Redirect_Suggestions::all());
    }

    public function test_propose_declines_for_a_path_that_is_not_a_real_source(): void
    {
        $this->assertNull(Redirect_Suggestions::propose('/', Redirect_Suggestions::REASON_POST_DELETED));
        $this->assertNull(Redirect_Suggestions::propose(
            '/' . str_repeat('x', Redirect_Store::MAX_SOURCE_LENGTH + 1),
            Redirect_Suggestions::REASON_POST_DELETED
        ));
    }

    public function test_the_queue_is_deduped_by_source_newest_first_and_capped(): void
    {
        for ($i = 0; $i < Redirect_Suggestions::CAP + 5; $i++) {
            Redirect_Suggestions::push(['source' => '/path-' . $i, 'reason' => 'post-deleted']);
        }
        Redirect_Suggestions::push(['source' => '/path-0', 'reason' => 'slug-changed']);

        $all = Redirect_Suggestions::all();

        $this->assertCount(Redirect_Suggestions::CAP, $all);
        $this->assertSame('/path-0', $all[0]['source']);
        $this->assertCount(1, array_filter($all, static fn (array $s): bool => '/path-0' === $s['source']));
    }

    public function test_find_and_remove_address_a_suggestion_by_normalized_source(): void
    {
        Redirect_Suggestions::push(['source' => '/old', 'reason' => 'post-deleted']);

        $this->assertNotNull(Redirect_Suggestions::find('http://example.org/OLD/'));
        $this->assertTrue(Redirect_Suggestions::remove('/old/'));
        $this->assertFalse(Redirect_Suggestions::remove('/old'));
        $this->assertNull(Redirect_Suggestions::find('/old'));
    }

    public function test_a_corrupt_option_never_breaks_the_queue(): void
    {
        update_option(Redirect_Suggestions::OPTION, 'not an array', false);

        $this->assertSame([], Redirect_Suggestions::all());
    }

    // -----------------------------------------------------------------
    // delete-post
    // -----------------------------------------------------------------

    public function test_trashing_a_published_post_suggests_a_redirect_without_creating_one(): void
    {
        $post_id = self::factory()->post->create(['post_name' => 'gone', 'post_status' => 'publish']);

        $out = (new Delete_Post())->handle(['post_id' => $post_id]);

        $this->assertSame('/gone', $out['suggested_redirect']['source']);
        $this->assertSame('post-deleted', $out['suggested_redirect']['reason']);
        $this->assertSame('/gone', Redirect_Suggestions::all()[0]['source']);
        $this->assertNoRedirectsExist();
    }

    public function test_force_deleting_a_published_post_suggests_a_redirect(): void
    {
        add_filter('wpmcp_enable_delete_post', '__return_true');
        $post_id = self::factory()->post->create(['post_name' => 'gone', 'post_status' => 'publish']);

        $out = (new Delete_Post())->handle(['post_id' => $post_id, 'force' => true, 'confirm' => true]);

        remove_filter('wpmcp_enable_delete_post', '__return_true');

        $this->assertSame('/gone', $out['suggested_redirect']['source']);
        $this->assertNoRedirectsExist();
    }

    public function test_deleting_a_draft_suggests_nothing(): void
    {
        $post_id = self::factory()->post->create(['post_name' => 'never-public', 'post_status' => 'draft']);

        $out = (new Delete_Post())->handle(['post_id' => $post_id]);

        $this->assertArrayNotHasKey('suggested_redirect', $out);
        $this->assertSame([], Redirect_Suggestions::all());
    }

    public function test_deleting_a_post_whose_url_is_already_redirected_suggests_nothing(): void
    {
        $post_id = self::factory()->post->create(['post_name' => 'gone', 'post_status' => 'publish']);
        Redirect_Store::insert(['source_path' => '/gone', 'target_url' => '/somewhere']);

        $out = (new Delete_Post())->handle(['post_id' => $post_id]);

        $this->assertArrayNotHasKey('suggested_redirect', $out);
    }

    // -----------------------------------------------------------------
    // update-post
    // -----------------------------------------------------------------

    public function test_renaming_a_published_slug_suggests_a_redirect_to_the_same_post(): void
    {
        $post_id = self::factory()->post->create(['post_name' => 'before', 'post_status' => 'publish']);

        $out = (new Update_Post())->handle(['post_id' => $post_id, 'slug' => 'after']);

        $this->assertSame('/before', $out['suggested_redirect']['source']);
        $this->assertSame('slug-changed', $out['suggested_redirect']['reason']);
        $this->assertSame($post_id, $out['suggested_redirect']['target_post_id']);
        $this->assertNoRedirectsExist();
    }

    public function test_editing_a_published_post_without_moving_it_suggests_nothing(): void
    {
        $post_id = self::factory()->post->create(['post_name' => 'stable', 'post_status' => 'publish']);

        $out = (new Update_Post())->handle(['post_id' => $post_id, 'title' => 'New title']);

        $this->assertArrayNotHasKey('suggested_redirect', $out);
    }

    public function test_renaming_a_draft_slug_suggests_nothing(): void
    {
        $post_id = self::factory()->post->create(['post_name' => 'before', 'post_status' => 'draft']);

        $out = (new Update_Post())->handle(['post_id' => $post_id, 'slug' => 'after']);

        $this->assertArrayNotHasKey('suggested_redirect', $out);
    }

    public function test_unpublishing_a_post_suggests_nothing_from_the_slug_path(): void
    {
        $post_id = self::factory()->post->create(['post_name' => 'before', 'post_status' => 'publish']);

        $out = (new Update_Post())->handle(['post_id' => $post_id, 'slug' => 'after', 'status' => 'draft']);

        $this->assertArrayNotHasKey('suggested_redirect', $out);
    }
}
