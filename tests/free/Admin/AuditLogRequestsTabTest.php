<?php

namespace WPMCP\Tests\Free\Admin;

use WPMCP\Admin\Audit_Log_Page;
use WPMCP\Admin\History_Page;
use WPMCP\MCP\Request_Log;
use WPMCP\Safety\Snapshot_Store;

/**
 * The Requests tab on the existing audit screen (issue #134): one
 * observability page, with a row-level link from an outcome to its undo point
 * in History.
 */
class AuditLogRequestsTabTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        delete_option(Request_Log::OPTION);
        delete_option(Request_Log::CAPTURE_OPTION);
        unset($_GET['tab'], $_GET['page']);
    }

    protected function tearDown(): void
    {
        delete_option(Request_Log::OPTION);
        delete_option(Request_Log::CAPTURE_OPTION);
        unset($_GET['tab'], $_GET['page']);
        parent::tearDown();
    }

    private function render(?string $tab = null): string
    {
        if (null !== $tab) {
            $_GET['tab'] = $tab;
        }
        ob_start();
        (new Audit_Log_Page())->render();
        return (string) ob_get_clean();
    }

    public function test_get_requests_returns_newest_first_rows(): void
    {
        Request_Log::record(['tool' => 'wpmcp/get-page', 'ok' => true]);
        Request_Log::record(['tool' => 'wpmcp/update-post', 'ok' => true]);

        $rows = (new Audit_Log_Page())->get_requests();

        $this->assertSame('wpmcp/update-post', $rows[0]['tool']);
        $this->assertSame('wpmcp/get-page', $rows[1]['tool']);
    }

    public function test_get_requests_honors_its_limit(): void
    {
        Request_Log::record(['tool' => 'a', 'ok' => true]);
        Request_Log::record(['tool' => 'b', 'ok' => true]);

        $this->assertCount(1, (new Audit_Log_Page())->get_requests(1));
    }

    public function test_both_tabs_are_offered_and_mutations_is_the_default(): void
    {
        $html = $this->render();

        $this->assertStringContainsString('tab=mutations', $html);
        $this->assertStringContainsString('tab=requests', $html);
        $this->assertStringContainsString('nav-tab-active', $html);
        // The default tab still renders the mutation table's Restore column.
        $this->assertStringContainsString('wpmcp-restore', $html);
    }

    public function test_an_unknown_tab_falls_back_to_mutations(): void
    {
        $html = $this->render('bogus');

        $this->assertStringContainsString('wpmcp-restore', $html);
    }

    public function test_the_requests_tab_renders_a_row_per_outcome(): void
    {
        Request_Log::set_clock_for_tests(1700000000);
        Request_Log::record([
            'tool'        => 'wpmcp/get-page',
            'client'      => 'user:5',
            'ok'          => true,
            'duration_ms' => 42,
        ]);
        Request_Log::record([
            'tool'        => 'wpmcp/query',
            'client'      => 'ip:10.0.0.1',
            'ok'          => false,
            'error_code'  => 'wpmcp_rate_limited',
            'duration_ms' => 0,
        ]);
        Request_Log::set_clock_for_tests(null);

        $html = $this->render(Audit_Log_Page::TAB_REQUESTS);

        $this->assertStringContainsString('wpmcp/get-page', $html);
        $this->assertStringContainsString('user:5', $html);
        $this->assertStringContainsString('42 ms', $html);
        $this->assertStringContainsString('2023-11-14', $html);
        $this->assertStringContainsString('wpmcp_rate_limited', $html);
        $this->assertStringContainsString('ip:10.0.0.1', $html);
        // The mutation table is not rendered alongside it.
        $this->assertStringNotContainsString('wpmcp-restore', $html);
    }

    public function test_a_row_with_an_operation_id_links_to_its_undo_point_in_history(): void
    {
        Request_Log::record([
            'tool'         => 'wpmcp/update-post',
            'ok'           => true,
            'operation_id' => 'op-abc-123',
        ]);

        $html = $this->render(Audit_Log_Page::TAB_REQUESTS);

        $this->assertStringContainsString('page=wpmcp#' . History_Page::row_anchor('op-abc-123'), $html);
    }

    public function test_a_row_without_an_operation_id_offers_no_link(): void
    {
        Request_Log::record(['tool' => 'wpmcp/get-page', 'ok' => true]);

        $html = $this->render(Audit_Log_Page::TAB_REQUESTS);

        $this->assertStringNotContainsString('View in History', $html);
    }

    public function test_the_history_row_anchor_matches_the_link_target(): void
    {
        $snapshot = ['object_type' => 'post', 'object_id' => 1, 'data' => ['post' => null, 'meta' => []]];
        Snapshot_Store::save('op-abc-123', 'sess', $snapshot, 'update-post', str_repeat('a', 64));
        Request_Log::record(['tool' => 'wpmcp/update-post', 'ok' => true, 'operation_id' => 'op-abc-123']);

        ob_start();
        (new History_Page())->render();
        $history = (string) ob_get_clean();

        $this->assertStringContainsString('id="' . History_Page::row_anchor('op-abc-123') . '"', $history);
    }

    public function test_the_row_anchor_strips_characters_that_are_unsafe_in_an_id(): void
    {
        $anchor = History_Page::row_anchor('abc"><script>123');

        $this->assertSame('wpmcp-op-abcscript123', $anchor);
        $this->assertMatchesRegularExpression('/^wpmcp-op-[A-Za-z0-9_-]*$/', $anchor);
    }

    public function test_the_requests_tab_escapes_attacker_influenced_values(): void
    {
        Request_Log::record([
            'tool'       => '<script>alert(1)</script>',
            'client'     => 'ip:<script>alert(2)</script>',
            'ok'         => false,
            'error_code' => '<script>alert(3)</script>',
        ]);

        $html = $this->render(Audit_Log_Page::TAB_REQUESTS);

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringNotContainsString('<script>alert(2)</script>', $html);
        $this->assertStringNotContainsString('<script>alert(3)</script>', $html);
    }

    public function test_the_tab_states_whether_argument_capture_is_on(): void
    {
        $this->assertStringContainsString(
            'Tool arguments are not recorded',
            $this->render(Audit_Log_Page::TAB_REQUESTS)
        );

        update_option(Request_Log::CAPTURE_OPTION, true);

        $this->assertStringContainsString(
            'Argument capture is ON',
            $this->render(Audit_Log_Page::TAB_REQUESTS)
        );
    }
}
