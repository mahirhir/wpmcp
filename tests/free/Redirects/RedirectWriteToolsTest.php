<?php

namespace WPMCP\Tests\Free\Redirects;

use WPMCP\Safety\Rollback_Service;
use WPMCP\Tools\Redirects\Create_Redirect;
use WPMCP\Tools\Redirects\Delete_Redirect;
use WPMCP\Tools\Redirects\List_Redirects;
use WPMCP\Tools\Redirects\Redirect_Store;
use WPMCP\Tools\Redirects\Redirect_Suggestions;
use WPMCP\Tools\Redirects\Update_Redirect;

/**
 * The redirect CRUD tools (issue #128).
 *
 * The point of every "rollback" case here: a redirect write is an ordinary
 * wpmcp operation. It goes through Safe_Mutation, lands in the same history
 * as a post edit, and rollback-operation undoes it. There is no parallel
 * ledger and no bespoke undo path.
 */
class RedirectWriteToolsTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        global $wpdb;
        $wpdb->query('DELETE FROM ' . Redirect_Store::table_name());
        delete_option(Redirect_Suggestions::OPTION);
        // Rolling a redirect back is a raw table write, so it is gated at
        // manage_options exactly like the database tools.
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    // -----------------------------------------------------------------
    // create-redirect
    // -----------------------------------------------------------------

    public function test_create_stores_a_normalized_source_and_returns_the_row(): void
    {
        $out = (new Create_Redirect())->handle([
            'source' => 'http://example.org/Old-Page/',
            'target' => '/new-page',
            'notes'  => 'seasonal campaign',
        ]);

        $this->assertNotEmpty($out['operation_id']);
        $this->assertSame('/old-page', $out['redirect']['source_path']);
        $this->assertSame('/new-page', $out['redirect']['target_url']);
        $this->assertSame(301, $out['redirect']['status_code']);
        $this->assertSame('seasonal campaign', $out['redirect']['notes']);
        $this->assertFalse($out['flattened']);
    }

    public function test_create_with_a_target_post_id_stores_the_id_not_a_frozen_permalink(): void
    {
        $this->set_permalink_structure('/%postname%/');
        $post_id = self::factory()->post->create(['post_name' => 'destination', 'post_status' => 'publish']);

        $out = (new Create_Redirect())->handle(['source' => '/old', 'target_post_id' => $post_id]);

        $this->assertSame($post_id, $out['redirect']['target_post_id']);
        $this->assertSame('', $out['redirect']['target_url']);
        $this->assertSame(get_permalink($post_id), $out['effective_target']);
    }

    public function test_create_flattens_a_chain_and_reports_what_it_stored(): void
    {
        Redirect_Store::insert(['source_path' => '/b', 'target_url' => '/c']);

        $out = (new Create_Redirect())->handle(['source' => '/a', 'target' => '/b']);

        $this->assertTrue($out['flattened']);
        $this->assertSame('/b', $out['requested_target']);
        $this->assertSame('/c', $out['redirect']['target_url']);
        $this->assertSame(['/b'], $out['flattened_through']);
    }

    public function test_create_refuses_a_loop(): void
    {
        Redirect_Store::insert(['source_path' => '/b', 'target_url' => '/a']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('redirect loop');

        (new Create_Redirect())->handle(['source' => '/a', 'target' => '/b']);
    }

    public function test_create_refuses_a_duplicate_source(): void
    {
        (new Create_Redirect())->handle(['source' => '/old', 'target' => '/new']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('use update-redirect');

        (new Create_Redirect())->handle(['source' => '/old', 'target' => '/other']);
    }

    public function test_create_refuses_the_site_root(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('site root cannot be redirected');

        (new Create_Redirect())->handle(['source' => '/', 'target' => '/new']);
    }

    public function test_create_requires_a_target(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('needs a "target"');

        (new Create_Redirect())->handle(['source' => '/old']);
    }

    public function test_create_refuses_both_target_forms_at_once(): void
    {
        $post_id = self::factory()->post->create(['post_status' => 'publish']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('not both');

        (new Create_Redirect())->handle(['source' => '/old', 'target' => '/new', 'target_post_id' => $post_id]);
    }

    public function test_create_refuses_a_missing_target_post(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('does not exist');

        (new Create_Redirect())->handle(['source' => '/old', 'target_post_id' => 999999]);
    }

    public function test_create_refuses_an_unsupported_status_code(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('not a supported redirect code');

        (new Create_Redirect())->handle(['source' => '/old', 'target' => '/new', 'status_code' => 418]);
    }

    public function test_create_clears_a_matching_pending_suggestion(): void
    {
        Redirect_Suggestions::push(['source' => '/old', 'reason' => 'post-deleted']);

        (new Create_Redirect())->handle(['source' => '/old', 'target' => '/new']);

        $this->assertSame([], Redirect_Suggestions::all());
    }

    public function test_rolling_back_a_create_removes_the_redirect(): void
    {
        $out = (new Create_Redirect())->handle(['source' => '/old', 'target' => '/new']);

        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));

        $this->assertNull(Redirect_Store::find_by_source('/old'));
    }

    // -----------------------------------------------------------------
    // update-redirect
    // -----------------------------------------------------------------

    public function test_update_changes_only_the_fields_passed(): void
    {
        $created = (new Create_Redirect())->handle(['source' => '/old', 'target' => '/new', 'notes' => 'keep me']);

        $out = (new Update_Redirect())->handle([
            'redirect_id' => $created['redirect']['id'],
            'enabled'     => false,
        ]);

        $this->assertFalse($out['redirect']['enabled']);
        $this->assertSame('/new', $out['redirect']['target_url']);
        $this->assertSame('keep me', $out['redirect']['notes']);
    }

    public function test_update_can_rename_the_source(): void
    {
        $created = (new Create_Redirect())->handle(['source' => '/old', 'target' => '/new']);

        (new Update_Redirect())->handle([
            'redirect_id' => $created['redirect']['id'],
            'source'      => '/older',
        ]);

        $this->assertNull(Redirect_Store::find_by_source('/old'));
        $this->assertNotNull(Redirect_Store::find_by_source('/older'));
    }

    public function test_rolling_back_a_source_rename_restores_the_same_row(): void
    {
        $created = (new Create_Redirect())->handle(['source' => '/old', 'target' => '/new']);
        $id      = $created['redirect']['id'];

        $out = (new Update_Redirect())->handle(['redirect_id' => $id, 'source' => '/older']);
        Rollback_Service::restore_operation($out['operation_id']);

        $this->assertNull(Redirect_Store::find_by_source('/older'));
        $restored = Redirect_Store::find_by_source('/old');
        $this->assertNotNull($restored);
        $this->assertSame($id, $restored['id'], 'A rollback must restore the row, not create a second one.');
    }

    public function test_update_refuses_a_source_another_redirect_already_owns(): void
    {
        (new Create_Redirect())->handle(['source' => '/taken', 'target' => '/x']);
        $created = (new Create_Redirect())->handle(['source' => '/mine', 'target' => '/y']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('already redirected');

        (new Update_Redirect())->handle(['redirect_id' => $created['redirect']['id'], 'source' => '/taken']);
    }

    public function test_update_flattens_a_new_target(): void
    {
        $created = (new Create_Redirect())->handle(['source' => '/a', 'target' => '/x']);
        Redirect_Store::insert(['source_path' => '/b', 'target_url' => '/c']);

        $out = (new Update_Redirect())->handle(['redirect_id' => $created['redirect']['id'], 'target' => '/b']);

        $this->assertTrue($out['flattened']);
        $this->assertSame('/c', $out['redirect']['target_url']);
    }

    public function test_update_refuses_a_retarget_that_would_loop_back_to_the_source(): void
    {
        $created = (new Create_Redirect())->handle(['source' => '/a', 'target' => '/x']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('point at itself');

        (new Update_Redirect())->handle(['redirect_id' => $created['redirect']['id'], 'target' => '/a']);
    }

    public function test_update_refuses_a_rename_that_would_make_the_row_point_at_itself(): void
    {
        $created = (new Create_Redirect())->handle(['source' => '/a', 'target' => '/b']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('point at itself');

        (new Update_Redirect())->handle(['redirect_id' => $created['redirect']['id'], 'source' => '/b']);
    }

    public function test_update_rejects_an_unknown_redirect(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('not found');

        (new Update_Redirect())->handle(['redirect_id' => 987654, 'enabled' => false]);
    }

    public function test_update_rejects_a_call_that_would_change_nothing(): void
    {
        $created = (new Create_Redirect())->handle(['source' => '/a', 'target' => '/b']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Nothing to update');

        (new Update_Redirect())->handle(['redirect_id' => $created['redirect']['id']]);
    }

    public function test_rolling_back_an_update_restores_the_previous_values(): void
    {
        $created = (new Create_Redirect())->handle(['source' => '/old', 'target' => '/new', 'status_code' => 301]);

        $out = (new Update_Redirect())->handle([
            'redirect_id' => $created['redirect']['id'],
            'target'      => '/newest',
            'status_code' => 302,
        ]);
        Rollback_Service::restore_operation($out['operation_id']);

        $row = Redirect_Store::get($created['redirect']['id']);
        $this->assertSame('/new', $row['target_url']);
        $this->assertSame(301, $row['status_code']);
    }

    // -----------------------------------------------------------------
    // delete-redirect
    // -----------------------------------------------------------------

    public function test_delete_removes_the_redirect(): void
    {
        $created = (new Create_Redirect())->handle(['source' => '/old', 'target' => '/new']);

        $out = (new Delete_Redirect())->handle(['redirect_id' => $created['redirect']['id']]);

        $this->assertTrue($out['deleted']);
        $this->assertSame('/old', $out['source_path']);
        $this->assertNull(Redirect_Store::find_by_source('/old'));
    }

    public function test_delete_rejects_an_unknown_redirect(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('not found');

        (new Delete_Redirect())->handle(['redirect_id' => 4242]);
    }

    public function test_rolling_back_a_delete_resurrects_the_same_row_id(): void
    {
        $created = (new Create_Redirect())->handle(['source' => '/old', 'target' => '/new', 'status_code' => 302]);
        $before  = Redirect_Store::get($created['redirect']['id']);

        $out = (new Delete_Redirect())->handle(['redirect_id' => $before['id']]);
        Rollback_Service::restore_operation($out['operation_id']);

        $this->assertSame($before, Redirect_Store::get($before['id']));
    }

    public function test_rollback_is_refused_for_a_caller_without_manage_options(): void
    {
        $out = (new Create_Redirect())->handle(['source' => '/old', 'target' => '/new']);
        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));

        $this->expectException(\WPMCP\Safety\Mutation_Failed::class);
        $this->expectExceptionMessage('requires the manage_options capability');

        Rollback_Service::restore_operation($out['operation_id']);
    }

    // -----------------------------------------------------------------
    // list-redirects
    // -----------------------------------------------------------------

    public function test_list_reports_the_resolved_target_and_whether_it_is_active(): void
    {
        $this->set_permalink_structure('/%postname%/');
        $post_id = self::factory()->post->create(['post_name' => 'destination', 'post_status' => 'publish']);
        (new Create_Redirect())->handle(['source' => '/live', 'target_post_id' => $post_id]);
        (new Create_Redirect())->handle(['source' => '/off', 'target' => '/x', 'enabled' => false]);

        $out = (new List_Redirects())->handle([]);
        $by_source = array_column($out['redirects'], null, 'source_path');

        $this->assertSame(2, $out['total']);
        $this->assertSame(get_permalink($post_id), $by_source['/live']['effective_target']);
        $this->assertTrue($by_source['/live']['active']);
        $this->assertFalse($by_source['/off']['active']);
    }

    public function test_list_marks_a_redirect_whose_target_post_vanished_as_inactive(): void
    {
        $post_id = self::factory()->post->create(['post_status' => 'publish']);
        (new Create_Redirect())->handle(['source' => '/gone', 'target_post_id' => $post_id]);
        wp_delete_post($post_id, true);

        $row = (new List_Redirects())->handle([])['redirects'][0];

        $this->assertSame('', $row['effective_target']);
        $this->assertFalse($row['active']);
    }

    public function test_list_includes_pending_suggestions(): void
    {
        Redirect_Suggestions::push(['source' => '/pending', 'reason' => 'slug-changed']);

        $out = (new List_Redirects())->handle([]);

        $this->assertSame('/pending', $out['pending_suggestions'][0]['source']);
    }

    public function test_list_passes_filters_through(): void
    {
        (new Create_Redirect())->handle(['source' => '/alpha', 'target' => '/x']);
        (new Create_Redirect())->handle(['source' => '/beta', 'target' => '/y', 'enabled' => false]);

        $this->assertCount(1, (new List_Redirects())->handle(['enabled' => true])['redirects']);
        $this->assertCount(1, (new List_Redirects())->handle(['search' => 'beta'])['redirects']);
    }
}
