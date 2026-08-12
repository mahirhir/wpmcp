<?php

namespace WPMCP\Tests\Free\Redirects;

use WPMCP\Tools\Redirects\Redirect_Store;

/**
 * The persistence + pure-helper layer of the redirect manager (issue #128).
 *
 * normalize_path() is where most of the risk lives: it decides whether two
 * URLs are "the same URL", so every write, the front-end lookup, and the
 * snapshot key all depend on it agreeing with itself.
 */
class RedirectStoreTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        global $wpdb;
        $wpdb->query('DELETE FROM ' . Redirect_Store::table_name());
    }

    /** @return array<string, array{0:string,1:string}> */
    public function normalization_cases(): array
    {
        return [
            'bare path'              => ['/old-page', '/old-page'],
            'no leading slash'       => ['old-page', '/old-page'],
            'trailing slash dropped' => ['/old-page/', '/old-page'],
            'query dropped'          => ['/old-page?utm=x', '/old-page'],
            'fragment dropped'       => ['/old-page#section', '/old-page'],
            'absolute url'           => ['http://example.org/old-page', '/old-page'],
            'uppercase folded'       => ['/Old-Page', '/old-page'],
            'duplicate slashes'      => ['/a//b///c', '/a/b/c'],
            'percent decoded'        => ['/caf%C3%A9', '/café'],
            'empty is root'          => ['', '/'],
            'root stays root'        => ['/', '/'],
            'whitespace trimmed'     => ['  /old-page  ', '/old-page'],
        ];
    }

    /** @dataProvider normalization_cases */
    public function test_normalize_path_reduces_urls_to_a_comparable_source(string $input, string $expected): void
    {
        $this->assertSame($expected, Redirect_Store::normalize_path($input));
    }

    public function test_is_internal_accepts_relative_and_same_host_targets(): void
    {
        $this->assertTrue(Redirect_Store::is_internal('/somewhere'));
        $this->assertTrue(Redirect_Store::is_internal('http://example.org/somewhere'));
        $this->assertFalse(Redirect_Store::is_internal('https://elsewhere.test/somewhere'));
    }

    public function test_clamp_status_code_falls_back_to_a_permanent_redirect(): void
    {
        $this->assertSame(302, Redirect_Store::clamp_status_code(302));
        $this->assertSame(308, Redirect_Store::clamp_status_code('308'));
        $this->assertSame(301, Redirect_Store::clamp_status_code(404));
        $this->assertSame(301, Redirect_Store::clamp_status_code(0));
    }

    public function test_insert_and_find_by_source_round_trip_with_typed_columns(): void
    {
        $id = Redirect_Store::insert([
            'source_path' => '/old-page',
            'target_url'  => '/new-page',
            'status_code' => 302,
            'notes'       => 'moved',
        ]);

        $row = Redirect_Store::find_by_source('/OLD-PAGE/');

        $this->assertNotNull($row);
        $this->assertSame($id, $row['id']);
        $this->assertSame('/old-page', $row['source_path']);
        $this->assertSame('/new-page', $row['target_url']);
        $this->assertSame(302, $row['status_code']);
        $this->assertTrue($row['enabled']);
        $this->assertSame(0, $row['hits']);
        $this->assertNull($row['last_hit_at']);
    }

    public function test_find_by_source_returns_null_when_nothing_matches(): void
    {
        $this->assertNull(Redirect_Store::find_by_source('/never-existed'));
        $this->assertNull(Redirect_Store::get(4321));
    }

    public function test_resolve_target_prefers_the_live_permalink_of_a_target_post(): void
    {
        $this->set_permalink_structure('/%postname%/');
        $post_id = self::factory()->post->create(['post_name' => 'destination', 'post_status' => 'publish']);

        $target = Redirect_Store::resolve_target(['target_post_id' => $post_id, 'target_url' => '/stale']);

        $this->assertSame(get_permalink($post_id), $target);
    }

    public function test_resolve_target_is_empty_when_the_target_post_is_gone(): void
    {
        $post_id = self::factory()->post->create(['post_status' => 'publish']);
        wp_delete_post($post_id, true);

        $this->assertSame('', Redirect_Store::resolve_target(['target_post_id' => $post_id, 'target_url' => '/x']));
    }

    public function test_resolve_target_falls_back_to_the_stored_url(): void
    {
        $this->assertSame('/plain', Redirect_Store::resolve_target(['target_post_id' => 0, 'target_url' => '/plain']));
    }

    public function test_all_filters_by_enabled_state_and_search(): void
    {
        Redirect_Store::insert(['source_path' => '/one', 'target_url' => '/alpha']);
        Redirect_Store::insert(['source_path' => '/two', 'target_url' => '/beta', 'enabled' => 0]);
        Redirect_Store::insert(['source_path' => '/three', 'target_url' => '/alpha-two']);

        $this->assertCount(3, Redirect_Store::all());
        $this->assertCount(2, Redirect_Store::all(['enabled' => true]));
        $this->assertCount(1, Redirect_Store::all(['enabled' => false]));
        $this->assertCount(2, Redirect_Store::all(['search' => 'alpha']));
        $this->assertSame(2, Redirect_Store::count(['search' => 'alpha']));
        $this->assertSame(1, Redirect_Store::count(['enabled' => false]));
    }

    public function test_all_returns_newest_first_and_honors_paging(): void
    {
        $first  = Redirect_Store::insert(['source_path' => '/one', 'target_url' => '/a']);
        $second = Redirect_Store::insert(['source_path' => '/two', 'target_url' => '/b']);

        $this->assertSame([$second, $first], array_column(Redirect_Store::all(), 'id'));
        $this->assertSame([$first], array_column(Redirect_Store::all(['limit' => 1, 'offset' => 1]), 'id'));
    }

    public function test_update_and_delete_change_the_stored_row(): void
    {
        $id = Redirect_Store::insert(['source_path' => '/old', 'target_url' => '/new']);

        Redirect_Store::update($id, ['enabled' => 0, 'target_url' => '/newer']);
        $row = Redirect_Store::get($id);
        $this->assertFalse($row['enabled']);
        $this->assertSame('/newer', $row['target_url']);

        Redirect_Store::delete($id);
        $this->assertNull(Redirect_Store::get($id));
    }

    public function test_delete_by_source_normalizes_before_deleting(): void
    {
        Redirect_Store::insert(['source_path' => '/old', 'target_url' => '/new']);

        Redirect_Store::delete_by_source('http://example.org/OLD/');

        $this->assertNull(Redirect_Store::find_by_source('/old'));
    }

    public function test_record_hit_increments_the_counter_and_stamps_the_time(): void
    {
        $id = Redirect_Store::insert(['source_path' => '/old', 'target_url' => '/new']);

        Redirect_Store::record_hit($id);
        Redirect_Store::record_hit($id);

        $row = Redirect_Store::get($id);
        $this->assertSame(2, $row['hits']);
        $this->assertNotNull($row['last_hit_at']);
    }

    public function test_insert_raw_preserves_the_original_id_for_a_rollback(): void
    {
        $id = Redirect_Store::insert(['source_path' => '/old', 'target_url' => '/new']);
        $row = Redirect_Store::get($id);
        Redirect_Store::delete($id);

        Redirect_Store::insert_raw($row);

        $restored = Redirect_Store::get($id);
        $this->assertNotNull($restored);
        $this->assertSame($row, $restored);
    }

    public function test_overwrite_restores_captured_values_onto_an_existing_row(): void
    {
        $id  = Redirect_Store::insert(['source_path' => '/old', 'target_url' => '/new']);
        $row = Redirect_Store::get($id);

        Redirect_Store::update($id, ['source_path' => '/renamed', 'enabled' => 0]);
        Redirect_Store::overwrite($id, $row);

        $restored = Redirect_Store::get($id);
        $this->assertSame('/old', $restored['source_path']);
        $this->assertTrue($restored['enabled']);
    }

    public function test_overwrite_ignores_unknown_columns_from_a_stale_snapshot(): void
    {
        $id  = Redirect_Store::insert(['source_path' => '/old', 'target_url' => '/new']);
        $row = Redirect_Store::get($id);
        $row['dropped_column'] = 'boom';

        Redirect_Store::overwrite($id, $row);

        $this->assertSame('/old', Redirect_Store::get($id)['source_path']);
    }

    public function test_maybe_install_is_a_no_op_once_the_version_is_recorded(): void
    {
        update_option(Redirect_Store::DB_VERSION_OPTION, Redirect_Store::DB_VERSION, false);
        $id = Redirect_Store::insert(['source_path' => '/kept', 'target_url' => '/x']);

        Redirect_Store::maybe_install();

        $this->assertNotNull(Redirect_Store::get($id));
    }
}
