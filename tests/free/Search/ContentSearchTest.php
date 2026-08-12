<?php

namespace WPMCP\Tests\Free\Search;

use WPMCP\Tools\Search\Content_Indexer;
use WPMCP\Tools\Search\Reindex_Search;
use WPMCP\Tools\Search\Search_Content;
use WPMCP\Tools\Search\Search_Index_Store;

/**
 * Cross-content search over the materialized index (issue #83).
 *
 * The behaviour that matters, and that plain post_content search cannot do:
 * find a string that lives inside a BUILDER ELEMENT SETTING and hand back the
 * element id to edit. Everything else here (block paths, menus, incremental
 * maintenance, per-result read gating) exists to make that answer trustworthy.
 */
class ContentSearchTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Search_Index_Store::ensure_installed();
        Search_Index_Store::truncate();
    }

    // ------------------------------------------------------------------
    // The headline capability: hits inside builder element settings.
    // ------------------------------------------------------------------

    public function test_search_finds_text_inside_elementor_element_settings(): void
    {
        $post_id = self::factory()->post->create([
            'post_type'    => 'page',
            'post_title'   => 'Home',
            'post_content' => '',
            'post_status'  => 'publish',
        ]);
        $this->make_elementor_page($post_id, [
            [
                'id'       => 'sec1',
                'elType'   => 'section',
                'settings' => [],
                'elements' => [
                    [
                        'id'         => 'wdg42',
                        'elType'     => 'widget',
                        'widgetType' => 'heading',
                        'settings'   => [ 'title' => 'Quarterly Sasquatch Retreat' ],
                        'elements'   => [],
                    ],
                ],
            ],
        ]);

        $out = (new Search_Content())->handle(['query' => 'sasquatch retreat']);

        $this->assertSame(1, $out['count']);
        $result = $out['results'][0];
        $this->assertSame($post_id, $result['post_id']);
        $this->assertSame('elementor', $result['builder']);
        $this->assertSame('elementor/element/wdg42', $result['location']);
        $this->assertSame('elementor', $result['hits'][0]['source']);
        $this->assertSame('heading', $result['hits'][0]['node']);
        $this->assertSame('title', $result['hits'][0]['field']);
        $this->assertStringContainsString('Sasquatch', $result['snippet']);
    }

    public function test_elementor_settings_hit_is_invisible_to_plain_post_content_search(): void
    {
        $post_id = self::factory()->post->create([
            'post_type'    => 'page',
            'post_content' => '',
            'post_status'  => 'publish',
        ]);
        $this->make_elementor_page($post_id, [
            [
                'id'         => 'btn9',
                'elType'     => 'widget',
                'widgetType' => 'button',
                'settings'   => [ 'text' => 'Book a Zeppelin Tour' ],
                'elements'   => [],
            ],
        ]);

        // The proof the index is doing real work: WP_Query cannot see it.
        $wp_query = new \WP_Query(['s' => 'Zeppelin', 'post_type' => 'page', 'fields' => 'ids']);
        $this->assertSame([], $wp_query->posts);

        $out = (new Search_Content())->handle(['query' => 'zeppelin']);
        $this->assertSame([$post_id], array_column($out['results'], 'post_id'));
    }

    public function test_search_finds_text_inside_bricks_element_settings(): void
    {
        $post_id = self::factory()->post->create(['post_type' => 'page', 'post_status' => 'publish']);
        update_post_meta($post_id, '_bricks_page_content_2', [
            [
                'id'       => 'brxab',
                'name'     => 'text-basic',
                'settings' => [ 'text' => 'Handmade Ceramic Kettles' ],
            ],
        ]);
        Content_Indexer::index_post($post_id);

        $out = (new Search_Content())->handle(['query' => 'ceramic kettles']);

        $this->assertSame(1, $out['count']);
        $this->assertSame('bricks/element/brxab', $out['results'][0]['location']);
        $this->assertSame('bricks', $out['results'][0]['hits'][0]['source']);
    }

    // ------------------------------------------------------------------
    // Gutenberg block paths and menus.
    // ------------------------------------------------------------------

    public function test_gutenberg_hit_returns_a_block_path(): void
    {
        $content = "<!-- wp:paragraph -->\n<p>Nothing here</p>\n<!-- /wp:paragraph -->\n"
            . "<!-- wp:group -->\n<div class=\"wp-block-group\">"
            . "<!-- wp:heading -->\n<h2>Refund Policy</h2>\n<!-- /wp:heading -->"
            . "</div>\n<!-- /wp:group -->";
        $post_id = self::factory()->post->create([
            'post_type'    => 'page',
            'post_content' => $content,
            'post_status'  => 'publish',
        ]);

        $out = (new Search_Content())->handle(['query' => 'refund policy']);

        $this->assertSame(1, $out['count']);
        $hit = $out['results'][0]['hits'][0];
        $this->assertSame('core/heading', $hit['node']);
        $this->assertMatchesRegularExpression('#^content/block/\d+(/\d+)*$#', $hit['location']);
        $this->assertStringContainsString('Refund Policy', $hit['snippet']);
    }

    public function test_search_finds_a_nav_menu_item(): void
    {
        $menu_id = wp_create_nav_menu('Footer Nav ' . wp_generate_password(6, false));
        $item_id = wp_update_nav_menu_item($menu_id, 0, [
            'menu-item-title'  => 'Wholesale Enquiries',
            'menu-item-url'    => 'https://example.com/wholesale',
            'menu-item-status' => 'publish',
        ]);
        Content_Indexer::index_menu((int) $menu_id);

        $out = (new Search_Content())->handle(['query' => 'wholesale enquiries']);

        $this->assertSame(1, $out['count']);
        $result = $out['results'][0];
        $this->assertSame('menu', $result['object_type']);
        $this->assertNull($result['post_id']);
        $this->assertSame('menu/item/' . (int) $item_id, $result['location']);
    }

    // ------------------------------------------------------------------
    // Incremental maintenance.
    // ------------------------------------------------------------------

    public function test_index_is_maintained_incrementally_on_save(): void
    {
        $post_id = self::factory()->post->create([
            'post_title'   => 'Rhubarb Festival',
            'post_content' => 'Come for the crumble.',
            'post_status'  => 'publish',
        ]);

        // No reindex call anywhere: the save hook did the work.
        $this->assertSame([$post_id], $this->ids_for('rhubarb'));

        wp_update_post(['ID' => $post_id, 'post_title' => 'Parsnip Festival']);

        $this->assertSame([], $this->ids_for('rhubarb'));
        $this->assertSame([$post_id], $this->ids_for('parsnip'));
    }

    public function test_deleting_a_post_purges_its_fragments(): void
    {
        $post_id = self::factory()->post->create([
            'post_title'  => 'Ephemeral Gazette',
            'post_status' => 'publish',
        ]);
        $this->assertSame([$post_id], $this->ids_for('ephemeral'));

        wp_delete_post($post_id, true);

        $this->assertSame([], $this->ids_for('ephemeral'));
    }

    public function test_a_builder_meta_write_without_a_post_save_reindexes_the_page(): void
    {
        $post_id = self::factory()->post->create(['post_type' => 'page', 'post_status' => 'publish']);
        update_post_meta($post_id, '_elementor_edit_mode', 'builder');

        // Written the way the Elementor tools write it: postmeta only, no
        // wp_update_post, so `save_post` never fires for this change.
        update_post_meta($post_id, '_elementor_data', wp_slash((string) wp_json_encode([
            [
                'id'         => 'later1',
                'elType'     => 'widget',
                'widgetType' => 'text-editor',
                'settings'   => [ 'editor' => 'Pomegranate molasses recipe' ],
                'elements'   => [],
            ],
        ])));

        $this->assertSame([$post_id], $this->ids_for('pomegranate'));
    }

    public function test_unindexable_status_is_purged_rather_than_kept(): void
    {
        $post_id = self::factory()->post->create([
            'post_title'  => 'Kumquat Weekly',
            'post_status' => 'publish',
        ]);
        $this->assertSame([$post_id], $this->ids_for('kumquat'));

        wp_update_post(['ID' => $post_id, 'post_status' => 'trash']);

        $this->assertSame([], $this->ids_for('kumquat'));
    }

    // ------------------------------------------------------------------
    // Ranking, shaping, and bounds.
    // ------------------------------------------------------------------

    public function test_title_match_outranks_a_body_match(): void
    {
        $body_hit = self::factory()->post->create([
            'post_title'   => 'Unrelated',
            'post_content' => 'A passing mention of marmalade somewhere in the body copy.',
            'post_status'  => 'publish',
        ]);
        $title_hit = self::factory()->post->create([
            'post_title'   => 'Marmalade',
            'post_content' => 'Nothing relevant.',
            'post_status'  => 'publish',
        ]);

        $out = (new Search_Content())->handle(['query' => 'marmalade']);

        $this->assertSame([$title_hit, $body_hit], array_column($out['results'], 'post_id'));
        $this->assertGreaterThan($out['results'][1]['score'], $out['results'][0]['score']);
    }

    public function test_limit_offset_and_hits_per_result_bound_the_payload(): void
    {
        for ($i = 0; $i < 5; $i++) {
            self::factory()->post->create([
                'post_title'   => "Tangerine {$i}",
                'post_content' => 'tangerine tangerine tangerine',
                'post_status'  => 'publish',
            ]);
        }

        $page_one = (new Search_Content())->handle(['query' => 'tangerine', 'limit' => 2, 'hits_per_result' => 1]);
        $page_two = (new Search_Content())->handle(['query' => 'tangerine', 'limit' => 2, 'offset' => 2]);

        $this->assertSame(2, $page_one['count']);
        $this->assertSame(5, $page_one['total']);
        $this->assertCount(1, $page_one['results'][0]['hits']);
        $this->assertSame(2, $page_two['count']);
        $this->assertSame(
            [],
            array_intersect(array_column($page_one['results'], 'post_id'), array_column($page_two['results'], 'post_id'))
        );
    }

    public function test_results_can_be_filtered_by_post_type_and_source(): void
    {
        $page = self::factory()->post->create([
            'post_type'   => 'page',
            'post_title'  => 'Loganberry',
            'post_status' => 'publish',
        ]);
        self::factory()->post->create([
            'post_type'   => 'post',
            'post_title'  => 'Loganberry',
            'post_status' => 'publish',
        ]);

        $filtered = (new Search_Content())->handle(['query' => 'loganberry', 'post_types' => ['page']]);
        $this->assertSame([$page], array_column($filtered['results'], 'post_id'));

        $by_source = (new Search_Content())->handle(['query' => 'loganberry', 'sources' => ['menu']]);
        $this->assertSame([], $by_source['results']);
    }

    public function test_snippet_is_bounded_and_centred_on_the_match(): void
    {
        $filler = str_repeat('lorem ipsum dolor sit amet ', 60);
        self::factory()->post->create([
            'post_title'   => 'Long one',
            'post_content' => $filler . ' persimmon marker ' . $filler,
            'post_status'  => 'publish',
        ]);

        $out     = (new Search_Content())->handle(['query' => 'persimmon']);
        $snippet = $out['results'][0]['snippet'];

        $this->assertStringContainsString('persimmon', $snippet);
        $this->assertLessThanOrEqual(200, strlen($snippet));
    }

    public function test_a_pathological_page_cannot_flood_the_index(): void
    {
        $elements = [];
        for ($i = 0; $i < 600; $i++) {
            $elements[] = [
                'id'         => 'el' . $i,
                'elType'     => 'widget',
                'widgetType' => 'text-editor',
                'settings'   => [ 'editor' => 'boundless boundless boundless' ],
                'elements'   => [],
            ];
        }
        $post_id = self::factory()->post->create(['post_type' => 'page', 'post_status' => 'publish']);
        $this->make_elementor_page($post_id, $elements);

        global $wpdb;
        $table = Search_Index_Store::table_name();
        $rows  = (int) $wpdb->get_var(
            $wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE object_id = %d", $post_id)
        );

        $this->assertLessThanOrEqual(Content_Indexer::MAX_FRAGMENTS_PER_OBJECT, $rows);
        $this->assertGreaterThan(0, $rows);
    }

    public function test_long_fragment_text_is_clamped_before_storage(): void
    {
        $post_id = self::factory()->post->create([
            'post_title'   => 'Clamp me',
            'post_content' => str_repeat('a ', 4000) . 'needle',
            'post_status'  => 'publish',
        ]);

        global $wpdb;
        $table  = Search_Index_Store::table_name();
        $length = (int) $wpdb->get_var(
            $wpdb->prepare("SELECT MAX(CHAR_LENGTH(content)) FROM {$table} WHERE object_id = %d", $post_id)
        );

        $this->assertLessThanOrEqual(Search_Index_Store::MAX_FRAGMENT_CHARS, $length);
    }

    // ------------------------------------------------------------------
    // Authorization: the index is never a read bypass.
    // ------------------------------------------------------------------

    public function test_another_authors_private_post_is_not_returned_to_a_contributor(): void
    {
        $owner  = self::factory()->user->create(['role' => 'editor']);
        $reader = self::factory()->user->create(['role' => 'contributor']);

        self::factory()->post->create([
            'post_title'   => 'Salary Bands',
            'post_content' => 'confidential',
            'post_status'  => 'private',
            'post_author'  => $owner,
        ]);

        wp_set_current_user($reader);
        $out = (new Search_Content())->handle(['query' => 'salary bands']);
        $this->assertSame([], $out['results']);
        $this->assertSame(1, $out['hidden']);

        wp_set_current_user($owner);
        $allowed = (new Search_Content())->handle(['query' => 'salary bands']);
        $this->assertSame(1, $allowed['count']);
    }

    public function test_a_stale_row_for_a_deleted_post_is_dropped_from_results(): void
    {
        $post_id = self::factory()->post->create([
            'post_title'  => 'Vanishing Act',
            'post_status' => 'publish',
        ]);
        // Delete straight from the posts table so the purge hook cannot run:
        // simulates an index that outlived its source row.
        global $wpdb;
        $wpdb->delete($wpdb->posts, ['ID' => $post_id]);
        clean_post_cache($post_id);

        $out = (new Search_Content())->handle(['query' => 'vanishing act']);

        $this->assertSame([], $out['results']);
        $this->assertSame(1, $out['hidden']);
    }

    // ------------------------------------------------------------------
    // Input handling and index status.
    // ------------------------------------------------------------------

    public function test_empty_query_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Search_Content())->handle(['query' => '   ']);
    }

    public function test_query_with_no_searchable_term_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Search_Content())->handle(['query' => 'a ! ?']);
    }

    public function test_empty_index_reports_itself_with_a_repair_hint(): void
    {
        Search_Index_Store::truncate();

        $out = (new Search_Content())->handle(['query' => 'anything']);

        $this->assertTrue($out['index']['empty']);
        $this->assertStringContainsString('reindex-search', (string) $out['hint']);
        $this->assertSame([], $out['results']);
    }

    public function test_index_stats_are_reported_alongside_results(): void
    {
        self::factory()->post->create(['post_title' => 'Statistical', 'post_status' => 'publish']);

        $out = (new Search_Content())->handle(['query' => 'statistical']);

        $this->assertGreaterThan(0, $out['index']['documents']);
        $this->assertGreaterThan(0, $out['index']['objects']);
        $this->assertNotNull($out['index']['last_indexed_at']);
        $this->assertArrayHasKey('title', $out['index']['by_source']);
        $this->assertFalse($out['index']['empty']);
    }

    // ------------------------------------------------------------------
    // Reindex.
    // ------------------------------------------------------------------

    public function test_full_rebuild_restores_an_index_wiped_out_of_band(): void
    {
        self::factory()->post->create([
            'post_title'  => 'Cassoulet Notes',
            'post_status' => 'publish',
        ]);
        Search_Index_Store::truncate();
        $this->assertSame([], $this->ids_for('cassoulet'));

        $out = (new Reindex_Search())->handle([]);

        $this->assertTrue($out['full_rebuild']);
        $this->assertTrue($out['complete']);
        $this->assertGreaterThan(0, $out['indexed']['posts']);
        $this->assertNotEmpty($this->ids_for('cassoulet'));
    }

    public function test_rebuild_is_cursor_based_and_a_later_page_does_not_wipe_the_first(): void
    {
        for ($i = 0; $i < 4; $i++) {
            self::factory()->post->create([
                'post_title'  => "Batchable {$i}",
                'post_status' => 'publish',
            ]);
        }
        Search_Index_Store::truncate();

        $first = (new Reindex_Search())->handle(['batch_size' => 2, 'post_types' => ['post']]);
        $this->assertSame(2, $first['next_offset']);
        $this->assertFalse($first['complete']);
        $after_first = count($this->ids_for('batchable'));

        $second = (new Reindex_Search())->handle(['batch_size' => 2, 'offset' => 2, 'post_types' => ['post']]);

        $this->assertNull($second['next_offset']);
        $this->assertTrue($second['complete']);
        $this->assertFalse($second['full_rebuild']);
        $this->assertGreaterThan($after_first, count($this->ids_for('batchable')));
        $this->assertSame(4, count($this->ids_for('batchable')));
    }

    public function test_reindex_reports_unknown_post_types_instead_of_indexing_them(): void
    {
        $out = (new Reindex_Search())->handle(['post_types' => ['page', 'no_such_type']]);

        $this->assertSame(['page'], $out['post_types']);
        $this->assertSame(['no_such_type'], $out['unknown_post_types']);
    }

    public function test_reindex_covers_menus_and_can_skip_them(): void
    {
        $menu_id = wp_create_nav_menu('Reindexed ' . wp_generate_password(6, false));
        wp_update_nav_menu_item($menu_id, 0, [
            'menu-item-title'  => 'Sponsorship Deck',
            'menu-item-url'    => 'https://example.com/deck',
            'menu-item-status' => 'publish',
        ]);
        Search_Index_Store::truncate();

        $with = (new Reindex_Search())->handle([]);
        $this->assertGreaterThan(0, $with['indexed']['menus']);
        $this->assertNotEmpty($this->results_for('sponsorship'));

        Search_Index_Store::truncate();
        $without = (new Reindex_Search())->handle(['include_menus' => false]);
        $this->assertSame(0, $without['indexed']['menus']);
        $this->assertSame([], $this->results_for('sponsorship'));
    }

    public function test_reusable_blocks_and_template_parts_are_in_scope(): void
    {
        $types = Content_Indexer::indexable_post_types();

        $this->assertContains('page', $types);
        $this->assertContains('wp_block', $types);
        $this->assertContains('wp_template_part', $types);
        $this->assertNotContains('attachment', $types);
        $this->assertNotContains('revision', $types);
    }

    public function test_indexable_post_types_are_filterable(): void
    {
        $filter = static fn (array $types): array => array_values(array_diff($types, ['page']));
        add_filter('wpmcp_search_indexable_post_types', $filter);
        try {
            $this->assertNotContains('page', Content_Indexer::indexable_post_types());
        } finally {
            remove_filter('wpmcp_search_indexable_post_types', $filter);
        }
    }

    public function test_untrashing_a_post_puts_it_back_in_the_index(): void
    {
        $post_id = self::factory()->post->create([
            'post_title'  => 'Boomerang Digest',
            'post_status' => 'publish',
        ]);
        wp_trash_post($post_id);
        $this->assertSame([], $this->ids_for('boomerang'));

        wp_untrash_post($post_id);
        wp_update_post(['ID' => $post_id, 'post_status' => 'publish']);

        $this->assertSame([$post_id], $this->ids_for('boomerang'));
    }

    public function test_deleting_a_menu_purges_its_fragments(): void
    {
        $menu_id = wp_create_nav_menu('Disposable ' . wp_generate_password(6, false));
        wp_update_nav_menu_item($menu_id, 0, [
            'menu-item-title'  => 'Escutcheon Gallery',
            'menu-item-url'    => 'https://example.com/escutcheon',
            'menu-item-status' => 'publish',
        ]);
        $this->assertNotEmpty($this->results_for('escutcheon'));

        wp_delete_nav_menu($menu_id);

        $this->assertSame([], $this->results_for('escutcheon'));
    }

    public function test_a_menu_edit_reindexes_without_an_explicit_call(): void
    {
        $menu_id = wp_create_nav_menu('Live ' . wp_generate_password(6, false));
        wp_update_nav_menu_item($menu_id, 0, [
            'menu-item-title'  => 'Bandoneon Lessons',
            'menu-item-url'    => 'https://example.com/bandoneon',
            'menu-item-status' => 'publish',
        ]);

        // wp_update_nav_menu_item fires wp_update_nav_menu; no manual index call.
        $this->assertNotEmpty($this->results_for('bandoneon'));
    }

    public function test_css_noise_is_kept_out_of_the_index(): void
    {
        $post_id = self::factory()->post->create(['post_type' => 'page', 'post_status' => 'publish']);
        $this->make_elementor_page($post_id, [
            [
                'id'         => 'noisy',
                'elType'     => 'widget',
                'widgetType' => 'heading',
                'settings'   => [
                    'title'            => 'Actual Copy',
                    'title_color'      => '#ff8800',
                    'typography_size'  => '48px',
                    '_element_id'      => 'skip-me',
                    'link'             => ['url' => 'https://example.com/target'],
                ],
                'elements'   => [],
            ],
        ]);

        global $wpdb;
        $table  = Search_Index_Store::table_name();
        $fields = $wpdb->get_col(
            $wpdb->prepare("SELECT field FROM {$table} WHERE object_id = %d AND source = 'elementor'", $post_id)
        );

        $this->assertContains('title', $fields);
        $this->assertContains('link.url', $fields);
        $this->assertNotContains('title_color', $fields);
        $this->assertNotContains('typography_size', $fields);
        $this->assertNotContains('_element_id', $fields);
    }

    public function test_a_url_setting_scores_below_a_title_setting(): void
    {
        $post_id = self::factory()->post->create(['post_type' => 'page', 'post_status' => 'publish']);
        $this->make_elementor_page($post_id, [
            [
                'id'         => 'weights',
                'elType'     => 'widget',
                'widgetType' => 'button',
                'settings'   => [
                    'text' => 'Alpaca',
                    'link' => ['url' => 'https://example.com/alpaca'],
                ],
                'elements'   => [],
            ],
        ]);

        $result = (new Search_Content())->handle(['query' => 'alpaca', 'hits_per_result' => 5])['results'][0];
        $fields = array_column($result['hits'], 'field');

        $this->assertSame('text', $fields[0]);
        $this->assertContains('link.url', $fields);
    }

    public function test_source_filter_narrows_to_builder_fragments(): void
    {
        $post_id = self::factory()->post->create([
            'post_type'   => 'page',
            'post_title'  => 'Zither',
            'post_status' => 'publish',
        ]);
        $this->make_elementor_page($post_id, [
            [
                'id'         => 'zith1',
                'elType'     => 'widget',
                'widgetType' => 'heading',
                'settings'   => [ 'title' => 'Zither Repairs' ],
                'elements'   => [],
            ],
        ]);

        $out = (new Search_Content())->handle(['query' => 'zither', 'sources' => ['elementor']]);

        $this->assertSame(1, $out['count']);
        foreach ($out['results'][0]['hits'] as $hit) {
            $this->assertSame('elementor', $hit['source']);
        }
    }

    public function test_a_single_post_type_may_be_passed_as_a_string(): void
    {
        $page = self::factory()->post->create([
            'post_type'   => 'page',
            'post_title'  => 'Quokka',
            'post_status' => 'publish',
        ]);
        self::factory()->post->create([
            'post_type'   => 'post',
            'post_title'  => 'Quokka',
            'post_status' => 'publish',
        ]);

        $out = (new Search_Content())->handle(['query' => 'quokka', 'post_type' => 'page']);

        $this->assertSame([$page], array_column($out['results'], 'post_id'));
    }

    public function test_incremental_reindex_leaves_other_objects_alone(): void
    {
        self::factory()->post->create(['post_title' => 'Keeper', 'post_status' => 'publish']);
        $page = self::factory()->post->create([
            'post_type'   => 'page',
            'post_title'  => 'Refreshed',
            'post_status' => 'publish',
        ]);

        $out = (new Reindex_Search())->handle(['full' => false, 'post_types' => ['page']]);

        $this->assertFalse($out['full_rebuild']);
        $this->assertSame([$page], $this->ids_for('refreshed'));
        $this->assertNotEmpty($this->ids_for('keeper'));
    }

    public function test_an_upgrade_that_never_ran_activation_repairs_its_own_schema(): void
    {
        // What a plugin update looks like from the storage layer's point of
        // view: the recorded schema version is gone (or old), so the next
        // touch must run install() again rather than fail on a missing table.
        delete_option(Search_Index_Store::SCHEMA_OPTION);

        Search_Index_Store::ensure_installed();

        $this->assertTrue(Search_Index_Store::table_exists());
        $this->assertSame(Search_Index_Store::SCHEMA_VERSION, get_option(Search_Index_Store::SCHEMA_OPTION));

        // Second call is a pure no-op: the version now matches.
        Search_Index_Store::ensure_installed();
        $this->assertTrue(Search_Index_Store::table_exists());
    }

    public function test_indexing_a_missing_post_or_menu_is_a_no_op(): void
    {
        $this->assertSame(0, Content_Indexer::index_post(0));
        $this->assertSame(0, Content_Indexer::index_post(999999));
        $this->assertSame(0, Content_Indexer::index_menu(999999));
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /** @param array<int,array<string,mixed>> $elements */
    private function make_elementor_page(int $post_id, array $elements): void
    {
        update_post_meta($post_id, '_elementor_edit_mode', 'builder');
        update_post_meta($post_id, '_elementor_data', wp_slash((string) wp_json_encode($elements)));
        Content_Indexer::index_post($post_id);
    }

    /** @return int[] post ids matching $query, in rank order. */
    private function ids_for(string $query): array
    {
        return array_values(array_filter(array_column($this->results_for($query), 'post_id')));
    }

    /** @return array<int,array<string,mixed>> every result for $query, post or menu. */
    private function results_for(string $query): array
    {
        return (new Search_Content())->handle(['query' => $query, 'limit' => 50])['results'];
    }
}
