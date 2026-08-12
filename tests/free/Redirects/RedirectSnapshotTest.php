<?php

namespace WPMCP\Tests\Free\Redirects;

use WPMCP\Safety\Mutation_Failed;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot;
use WPMCP\Tools\Redirects\Redirect_Store;

/**
 * The 'redirect' snapshot object type (issue #128).
 *
 * A redirect is snapshotted by its SOURCE PATH, not its row id, for the same
 * reason an option is snapshotted by its name: the source path is the natural
 * key, it is UNIQUE, and unlike an auto-increment id it is known BEFORE the
 * write. That is what lets create-redirect go through Safe_Mutation like
 * every other write instead of recording an after-the-fact "I made this" row.
 */
class RedirectSnapshotTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        global $wpdb;
        $wpdb->query('DELETE FROM ' . Redirect_Store::table_name());
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    public function test_capturing_a_path_with_no_redirect_records_that_it_did_not_exist(): void
    {
        $snapshot = Snapshot::capture('redirect', 'http://example.org/Old-Page/');

        $this->assertSame('redirect', $snapshot['object_type']);
        $this->assertSame('/old-page', $snapshot['object_id']);
        $this->assertFalse($snapshot['data']['existed']);
        $this->assertNull($snapshot['data']['row']);
    }

    public function test_capturing_an_existing_redirect_records_the_whole_row(): void
    {
        $id = Redirect_Store::insert(['source_path' => '/old', 'target_url' => '/new']);

        $snapshot = Snapshot::capture('redirect', '/old');

        $this->assertTrue($snapshot['data']['existed']);
        $this->assertSame($id, $snapshot['data']['row']['id']);
    }

    public function test_restoring_a_did_not_exist_snapshot_removes_whatever_now_owns_the_path(): void
    {
        $snapshot = Snapshot::capture('redirect', '/old');
        Redirect_Store::insert(['source_path' => '/old', 'target_url' => '/new']);

        Rollback_Service::apply_snapshot($snapshot);

        $this->assertNull(Redirect_Store::find_by_source('/old'));
    }

    public function test_restoring_a_captured_row_reinserts_it_with_its_original_id(): void
    {
        $id       = Redirect_Store::insert(['source_path' => '/old', 'target_url' => '/new']);
        $snapshot = Snapshot::capture('redirect', '/old');
        Redirect_Store::delete($id);

        Rollback_Service::apply_snapshot($snapshot);

        $this->assertSame($id, Redirect_Store::find_by_source('/old')['id']);
    }

    public function test_restoring_clears_a_squatter_and_warns_about_it(): void
    {
        $id       = Redirect_Store::insert(['source_path' => '/old', 'target_url' => '/new']);
        $snapshot = Snapshot::capture('redirect', '/old');

        // The operation renamed this row's source, and something else then
        // took the path the snapshot promises to restore.
        Redirect_Store::update($id, ['source_path' => '/renamed']);
        $squatter = Redirect_Store::insert(['source_path' => '/old', 'target_url' => '/somewhere-else']);

        Rollback_Service::restore_operation('missing-op'); // clears stale warnings
        Rollback_Service::apply_snapshot($snapshot);

        $warnings = Rollback_Service::take_warnings();
        $this->assertNotEmpty($warnings);
        $this->assertStringContainsString('had been taken by redirect #' . $squatter, $warnings[0]);
        $this->assertSame($id, Redirect_Store::find_by_source('/old')['id']);
    }

    public function test_a_snapshot_with_no_source_path_is_a_no_op(): void
    {
        Redirect_Store::insert(['source_path' => '/old', 'target_url' => '/new']);

        Rollback_Service::apply_snapshot([
            'object_type' => 'redirect',
            'object_id'   => '',
            'data'        => ['source_path' => '', 'existed' => true, 'row' => []],
        ]);

        $this->assertNotNull(Redirect_Store::find_by_source('/old'));
    }

    public function test_a_captured_row_without_an_id_is_a_no_op(): void
    {
        Rollback_Service::apply_snapshot([
            'object_type' => 'redirect',
            'object_id'   => '/old',
            'data'        => ['source_path' => '/old', 'existed' => true, 'row' => ['source_path' => '/old']],
        ]);

        $this->assertNull(Redirect_Store::find_by_source('/old'));
    }

    public function test_restoring_a_redirect_requires_manage_options(): void
    {
        $snapshot = Snapshot::capture('redirect', '/old');
        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));

        $this->expectException(Mutation_Failed::class);
        $this->expectExceptionMessage('requires the manage_options capability');

        Rollback_Service::apply_snapshot($snapshot);
    }
}
