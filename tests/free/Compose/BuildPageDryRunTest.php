<?php

namespace WPMCP\Tests\Free\Compose;

use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Compose\Build_Page;

/**
 * build-page dry_run (issue #137): validate, inspect and compose, write nothing.
 *
 * A dry run must be genuinely free of side effects (no page, no attachment
 * link, no menu item, no history row) while still answering the two questions
 * an agent cannot answer on its own: what the composition would produce, and
 * everything the live site would object to. Unlike the real build, which stops
 * at the first referential problem, the dry run reports them all at once.
 */
class BuildPageDryRunTest extends \WP_UnitTestCase
{
    private array $menus = [];

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
    }

    protected function tearDown(): void
    {
        foreach ($this->menus as $id) {
            wp_delete_nav_menu($id);
        }
        $this->menus = [];
        parent::tearDown();
    }

    private function spec(array $overrides = []): array
    {
        return array_merge([
            'title'   => 'Dry Run Page',
            'content' => [
                ['type' => 'group', 'children' => [
                    ['type' => 'heading', 'settings' => ['text' => 'Welcome', 'level' => 2]],
                    ['type' => 'paragraph', 'settings' => ['text' => 'Built in one call.']],
                ]],
                ['type' => 'separator'],
            ],
        ], $overrides);
    }

    private function page_count(): int
    {
        return count(get_posts(['post_type' => 'page', 'post_status' => 'any', 'numberposts' => -1]));
    }

    private function snapshot_rows(): int
    {
        global $wpdb;
        return (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Snapshot_Store::table_name());
    }

    public function test_dry_run_writes_nothing(): void
    {
        $pages = $this->page_count();
        $rows  = $this->snapshot_rows();

        $out = (new Build_Page())->handle(['spec' => $this->spec(), 'dry_run' => true]);

        $this->assertTrue($out['dry_run']);
        $this->assertTrue($out['valid']);
        $this->assertArrayNotHasKey('post_id', $out);
        $this->assertArrayNotHasKey('operation_id', $out);
        $this->assertSame($pages, $this->page_count());
        $this->assertSame($rows, $this->snapshot_rows());
    }

    public function test_dry_run_reports_element_counts_and_depth(): void
    {
        $out = (new Build_Page())->handle(['spec' => $this->spec(), 'dry_run' => true]);

        $this->assertSame(4, $out['created_elements']);
        $this->assertSame([
            'group'     => 1,
            'heading'   => 1,
            'paragraph' => 1,
            'separator' => 1,
        ], $out['element_counts']);
        $this->assertSame(2, $out['max_depth']);
        $this->assertGreaterThan(0, $out['markup_bytes']);
        $this->assertSame(['page' => 1, 'menu_item' => 0, 'featured' => 0], $out['would_create']);
    }

    public function test_dry_run_reports_every_referential_problem_at_once(): void
    {
        $spec = $this->spec([
            'media'   => ['featured' => 987654321],
            'menu'    => ['menu_id' => 987654322],
            'content' => [
                ['type' => 'pattern', 'settings' => ['slug' => 'wpmcp-tests/not-registered']],
                ['type' => 'image', 'settings' => ['attachment_id' => 987654323]],
            ],
        ]);

        $out = (new Build_Page())->handle(['spec' => $spec, 'dry_run' => true]);

        $this->assertFalse($out['valid']);
        $this->assertCount(4, $out['warnings']);
        $joined = implode(' | ', $out['warnings']);
        $this->assertStringContainsString('987654321', $joined);
        $this->assertStringContainsString('987654322', $joined);
        $this->assertStringContainsString('987654323', $joined);
        $this->assertStringContainsString('not-registered', $joined);
        $this->assertStringContainsString('content[0]', $joined, 'Problems stay node-path addressed.');
    }

    public function test_dry_run_still_rejects_a_structurally_invalid_spec(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new Build_Page())->handle([
            'spec'    => ['title' => 'Bad', 'content' => [['type' => 'not-a-block']]],
            'dry_run' => true,
        ]);
    }

    public function test_dry_run_warns_about_very_large_pages(): void
    {
        $children = [];
        for ($i = 0; $i <= Build_Page::LARGE_PAGE_ELEMENTS; $i++) {
            $children[] = ['type' => 'paragraph', 'settings' => ['text' => 'Row ' . $i]];
        }

        $out = (new Build_Page())->handle([
            'spec'    => $this->spec(['content' => [['type' => 'group', 'children' => $children]]]),
            'dry_run' => true,
        ]);

        $this->assertSame(count($children) + 1, $out['created_elements']);
        $this->assertStringContainsString('sections', implode(' ', $out['warnings']));
    }

    public function test_dry_run_accepts_a_valid_menu_and_attachment(): void
    {
        $menu_id       = wp_create_nav_menu('Dry Run Menu');
        $this->menus[] = $menu_id;
        $attachment_id = self::factory()->attachment->create_object('img.png', 0, [
            'post_mime_type' => 'image/png',
            'post_type'      => 'attachment',
        ]);

        $out = (new Build_Page())->handle([
            'spec'    => $this->spec([
                'media' => ['featured' => $attachment_id],
                'menu'  => ['menu_id' => $menu_id],
            ]),
            'dry_run' => true,
        ]);

        $this->assertTrue($out['valid']);
        $this->assertSame([], $out['warnings']);
        $this->assertSame(1, $out['would_create']['menu_item']);
        $this->assertSame($attachment_id, $out['would_create']['featured']);
    }

    public function test_a_real_build_after_a_clean_dry_run_matches_the_report(): void
    {
        $spec = $this->spec();

        $dry  = (new Build_Page())->handle(['spec' => $spec, 'dry_run' => true]);
        $real = (new Build_Page())->handle(['spec' => $spec]);

        $this->assertSame($dry['created_elements'], $real['created_elements']);
        $this->assertGreaterThan(0, $real['post_id']);
    }
}
