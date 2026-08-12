<?php

namespace WPMCP\Tests\Free\Redirects;

use WPMCP\Tools\Redirects\Broken_Link_Scan_Store;
use WPMCP\Tools\Redirects\Find_Broken_Links;
use WPMCP\Tools\Redirects\Redirect_Store;
use WPMCP\Tools\Redirects\Run_Broken_Link_Scan;

/**
 * The background broken-link scan: store, WP-Cron executor, and the three
 * call shapes of find-broken-links (issue #128).
 *
 * The behavior that matters is that progress is DURABLE between batches. A
 * scan that has to finish inside one request either times out or is capped so
 * low it misses the links you cared about; here each cron run does one batch,
 * records where it got to, and reschedules, so a caller can poll a real
 * percentage and an interrupted scan resumes.
 */
class BackgroundScanTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        global $wpdb;
        $wpdb->query('DELETE FROM ' . Redirect_Store::table_name());
        delete_option(Broken_Link_Scan_Store::OPTION);
        Broken_Link_Scan_Store::set_clock_for_tests(1700000000);
        $this->set_permalink_structure('/%postname%/');
    }

    protected function tearDown(): void
    {
        Broken_Link_Scan_Store::set_clock_for_tests(null);
        delete_option(Broken_Link_Scan_Store::OPTION);
        // The custom-post-type case registers 'doc'; unregistering it here
        // also tears down the rewrite rules it added, so nothing it created
        // can leak into a later test's permalink handling.
        if (post_type_exists('doc')) {
            unregister_post_type('doc');
        }
        parent::tearDown();
    }

    private function post_with_dead_link(string $path): int
    {
        return self::factory()->post->create([
            'post_status'  => 'publish',
            'post_content' => sprintf('<a href="%s">gone</a>', $path),
        ]);
    }

    // -----------------------------------------------------------------
    // store
    // -----------------------------------------------------------------

    public function test_create_returns_a_queued_scan_with_a_deterministic_id(): void
    {
        $scan = Broken_Link_Scan_Store::create(['post'], 500, 25, 42);

        $this->assertSame(1, $scan['id']);
        $this->assertSame('queued', $scan['status']);
        $this->assertSame(['post'], $scan['post_types']);
        $this->assertSame(42, $scan['total']);
        $this->assertSame(0, $scan['scanned']);
        $this->assertSame(1700000000, $scan['created_at']);
    }

    public function test_scans_are_listed_newest_first_and_capped(): void
    {
        for ($i = 0; $i < Broken_Link_Scan_Store::MAX_SCANS + 3; $i++) {
            Broken_Link_Scan_Store::create(['post'], 10, 5, 1);
        }

        $all = Broken_Link_Scan_Store::all();

        $this->assertCount(Broken_Link_Scan_Store::MAX_SCANS, $all);
        $this->assertSame(Broken_Link_Scan_Store::MAX_SCANS + 3, $all[0]['id']);
    }

    public function test_update_and_get_round_trip_and_unknown_ids_are_null(): void
    {
        $scan = Broken_Link_Scan_Store::create(['post'], 10, 5, 1);

        $this->assertSame('running', Broken_Link_Scan_Store::update($scan['id'], ['status' => 'running'])['status']);
        $this->assertSame('running', Broken_Link_Scan_Store::get($scan['id'])['status']);
        $this->assertNull(Broken_Link_Scan_Store::get(9999));
        $this->assertNull(Broken_Link_Scan_Store::update(9999, ['status' => 'running']));
    }

    public function test_findings_are_capped_and_the_record_says_so(): void
    {
        $scan     = Broken_Link_Scan_Store::create(['post'], 10, 5, 1);
        $findings = array_fill(0, Broken_Link_Scan_Store::MAX_FINDINGS + 5, ['issue' => 'dead']);

        $updated = Broken_Link_Scan_Store::append_findings($scan['id'], $findings);

        $this->assertCount(Broken_Link_Scan_Store::MAX_FINDINGS, $updated['findings']);
        $this->assertTrue($updated['truncated']);
        $this->assertNull(Broken_Link_Scan_Store::append_findings(9999, []));
    }

    // -----------------------------------------------------------------
    // executor
    // -----------------------------------------------------------------

    public function test_the_executor_processes_one_batch_and_reschedules_itself(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->post_with_dead_link('/missing-' . $i);
        }
        $scan = Broken_Link_Scan_Store::create(['post'], 3, 1, 3);

        (new Run_Broken_Link_Scan())->handle($scan['id']);

        $after = Broken_Link_Scan_Store::get($scan['id']);
        $this->assertSame('running', $after['status']);
        $this->assertSame(1, $after['scanned']);
        $this->assertCount(1, $after['findings']);
        $this->assertNotFalse(wp_next_scheduled(Run_Broken_Link_Scan::HOOK, [$scan['id']]));
    }

    public function test_repeated_runs_finish_the_scan_and_stop_rescheduling(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->post_with_dead_link('/missing-' . $i);
        }
        $scan   = Broken_Link_Scan_Store::create(['post'], 3, 2, 3);
        $runner = new Run_Broken_Link_Scan();

        $runner->handle($scan['id']);
        $runner->handle($scan['id']);

        $after = Broken_Link_Scan_Store::get($scan['id']);
        $this->assertSame('completed', $after['status']);
        $this->assertSame(3, $after['scanned']);
        $this->assertCount(3, $after['findings']);
        $this->assertFalse(wp_next_scheduled(Run_Broken_Link_Scan::HOOK, [$scan['id']]));
    }

    public function test_the_executor_ignores_an_unknown_or_finished_scan(): void
    {
        $scan = Broken_Link_Scan_Store::create(['post'], 3, 1, 3);
        Broken_Link_Scan_Store::update($scan['id'], ['status' => 'completed']);

        (new Run_Broken_Link_Scan())->handle($scan['id']);
        (new Run_Broken_Link_Scan())->handle(9999);

        $this->assertSame(0, Broken_Link_Scan_Store::get($scan['id'])['scanned']);
    }

    public function test_a_scan_with_nothing_left_to_do_completes_immediately(): void
    {
        $scan = Broken_Link_Scan_Store::create(['post'], 5, 5, 0);

        (new Run_Broken_Link_Scan())->handle($scan['id']);

        $this->assertSame('completed', Broken_Link_Scan_Store::get($scan['id'])['status']);
    }

    public function test_a_batch_that_throws_lands_the_scan_in_failed_with_its_reason(): void
    {
        $this->post_with_dead_link('/missing');
        $scan   = Broken_Link_Scan_Store::create(['post'], 5, 5, 1);
        $runner = new Run_Broken_Link_Scan(static function (array $ids): array {
            throw new \RuntimeException('scanner exploded');
        });

        $runner->handle($scan['id']);

        $after = Broken_Link_Scan_Store::get($scan['id']);
        $this->assertSame('failed', $after['status']);
        $this->assertSame('scanner exploded', $after['error']);
        $this->assertFalse(wp_next_scheduled(Run_Broken_Link_Scan::HOOK, [$scan['id']]));
    }

    public function test_a_content_set_that_shrinks_mid_scan_finishes_instead_of_looping(): void
    {
        $post_id = $this->post_with_dead_link('/missing');
        $scan    = Broken_Link_Scan_Store::create(['post'], 5, 5, 5);
        wp_delete_post($post_id, true);

        (new Run_Broken_Link_Scan())->handle($scan['id']);

        $this->assertSame('completed', Broken_Link_Scan_Store::get($scan['id'])['status']);
        $this->assertFalse(wp_next_scheduled(Run_Broken_Link_Scan::HOOK, [$scan['id']]));
    }

    // -----------------------------------------------------------------
    // find-broken-links
    // -----------------------------------------------------------------

    public function test_inline_mode_returns_findings_without_queueing_anything(): void
    {
        $this->post_with_dead_link('/missing');

        $out = (new Find_Broken_Links())->handle([]);

        $this->assertSame('inline', $out['mode']);
        $this->assertSame(1, $out['scanned']);
        $this->assertFalse($out['partial']);
        $this->assertSame('/missing', $out['findings'][0]['path']);
        $this->assertSame([], Broken_Link_Scan_Store::all());
    }

    public function test_inline_mode_flags_a_partial_result_when_it_hits_its_limit(): void
    {
        $this->post_with_dead_link('/one');
        $this->post_with_dead_link('/two');

        $out = (new Find_Broken_Links())->handle(['limit' => 1]);

        $this->assertSame(1, $out['scanned']);
        $this->assertSame(2, $out['total']);
        $this->assertTrue($out['partial']);
    }

    public function test_background_mode_queues_a_scan_and_schedules_the_first_batch(): void
    {
        $this->post_with_dead_link('/missing');

        $out = (new Find_Broken_Links())->handle(['background' => true, 'batch_size' => 5]);

        $this->assertSame('background', $out['mode']);
        $this->assertSame('queued', $out['status']);
        $this->assertSame(1, $out['total']);
        $this->assertNotFalse(wp_next_scheduled(Run_Broken_Link_Scan::HOOK, [$out['scan_id']]));
    }

    public function test_polling_a_scan_reports_progress_as_a_percentage(): void
    {
        for ($i = 0; $i < 4; $i++) {
            $this->post_with_dead_link('/missing-' . $i);
        }
        $queued = (new Find_Broken_Links())->handle(['background' => true, 'batch_size' => 1]);
        (new Run_Broken_Link_Scan())->handle($queued['scan_id']);

        $status = (new Find_Broken_Links())->handle(['scan_id' => $queued['scan_id']]);

        $this->assertSame('running', $status['status']);
        $this->assertSame(1, $status['scanned']);
        $this->assertSame(4, $status['total']);
        $this->assertSame(25, $status['percent']);
        $this->assertCount(1, $status['findings']);
    }

    public function test_polling_an_unknown_scan_is_an_actionable_error(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('No broken-link scan found');

        (new Find_Broken_Links())->handle(['scan_id' => 4242]);
    }

    public function test_custom_post_types_can_be_targeted(): void
    {
        register_post_type('doc', ['public' => true, 'has_archive' => false]);
        self::factory()->post->create([
            'post_type'    => 'doc',
            'post_status'  => 'publish',
            'post_content' => '<a href="/missing-doc">gone</a>',
        ]);
        $this->post_with_dead_link('/missing-post');

        $out = (new Find_Broken_Links())->handle(['post_types' => ['doc']]);

        $this->assertSame(1, $out['scanned']);
        $this->assertSame('/missing-doc', $out['findings'][0]['path']);
    }
}
