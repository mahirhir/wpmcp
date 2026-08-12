<?php

namespace WPMCP\Tests\Free\Cli;

use WPMCP\Tools\Cli\Cli_Job_Store;

/**
 * Cli_Job_Store is a CRUD layer over a single wpmcp_cli_jobs option, shaped
 * like Backup_Job_Store (deterministic incrementing ids, injectable clock)
 * plus the stale-record reaper the CLI surface needs and the backup one does
 * not: CLI job records retain captured command output, so they must be
 * bounded by both a retention TTL and a hard record cap, and a job whose
 * worker died mid-run must eventually stop reporting itself as "running".
 */
class CliJobStoreTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        delete_option(Cli_Job_Store::OPTION);
        Cli_Job_Store::set_clock_for_tests(null);
    }

    protected function tearDown(): void
    {
        Cli_Job_Store::set_clock_for_tests(null);
        remove_all_filters('wpmcp_cli_job_retention_seconds');
        remove_all_filters('wpmcp_cli_job_max_records');
        delete_option(Cli_Job_Store::OPTION);
        parent::tearDown();
    }

    public function test_create_returns_a_queued_job_with_a_deterministic_id(): void
    {
        Cli_Job_Store::set_clock_for_tests(1700000000);

        $job = Cli_Job_Store::create(['plugin', 'list'], 300);

        $this->assertSame(1, $job['id']);
        $this->assertSame('plugin list', $job['command']);
        $this->assertSame(['plugin', 'list'], $job['argv']);
        $this->assertSame(300, $job['timeout']);
        $this->assertSame('queued', $job['status']);
        $this->assertSame(1700000000, $job['created_at']);
        $this->assertSame(1700000000, $job['updated_at']);
        $this->assertNull($job['result']);
        $this->assertNull($job['error']);
    }

    public function test_ids_increment_deterministically_across_creates(): void
    {
        Cli_Job_Store::set_clock_for_tests(1700000000);

        $first  = Cli_Job_Store::create(['core', 'version'], 300);
        $second = Cli_Job_Store::create(['core', 'version'], 300);

        $this->assertSame(1, $first['id']);
        $this->assertSame(2, $second['id']);
    }

    public function test_get_returns_the_stored_job_and_null_for_an_unknown_id(): void
    {
        Cli_Job_Store::set_clock_for_tests(1700000000);
        $created = Cli_Job_Store::create(['core', 'version'], 300);

        $this->assertSame($created, Cli_Job_Store::get($created['id']));
        $this->assertNull(Cli_Job_Store::get(999));
    }

    public function test_list_returns_jobs_newest_first(): void
    {
        Cli_Job_Store::set_clock_for_tests(1700000000);
        $first  = Cli_Job_Store::create(['core', 'version'], 300);
        $second = Cli_Job_Store::create(['core', 'version'], 300);
        $third  = Cli_Job_Store::create(['core', 'version'], 300);

        $ids = array_column(Cli_Job_Store::list(), 'id');

        $this->assertSame([$third['id'], $second['id'], $first['id']], $ids);
    }

    public function test_list_filters_by_status(): void
    {
        Cli_Job_Store::set_clock_for_tests(1700000000);
        Cli_Job_Store::create(['core', 'version'], 300);
        $other = Cli_Job_Store::create(['core', 'version'], 300);
        Cli_Job_Store::update($other['id'], ['status' => 'completed']);

        $filtered = Cli_Job_Store::list('completed');

        $this->assertCount(1, $filtered);
        $this->assertSame($other['id'], $filtered[0]['id']);
    }

    public function test_update_merges_fields_and_bumps_updated_at(): void
    {
        Cli_Job_Store::set_clock_for_tests(1700000000);
        $job = Cli_Job_Store::create(['core', 'version'], 300);

        Cli_Job_Store::set_clock_for_tests(1700000500);
        $updated = Cli_Job_Store::update($job['id'], [
            'status' => 'completed',
            'result' => ['stdout' => 'ok'],
        ]);

        $this->assertSame('completed', $updated['status']);
        $this->assertSame(['stdout' => 'ok'], $updated['result']);
        $this->assertSame(1700000000, $updated['created_at']);
        $this->assertSame(1700000500, $updated['updated_at']);
        $this->assertSame($updated, Cli_Job_Store::get($job['id']));
    }

    public function test_update_returns_null_for_an_unknown_job_id(): void
    {
        $this->assertNull(Cli_Job_Store::update(999, ['status' => 'completed']));
    }

    // ---------------------------------------------------------------
    // purge_stale()
    // ---------------------------------------------------------------

    public function test_purge_marks_a_running_job_past_its_timeout_as_failed(): void
    {
        Cli_Job_Store::set_clock_for_tests(1700000000);
        $job = Cli_Job_Store::create(['core', 'version'], 100);
        Cli_Job_Store::update($job['id'], ['status' => 'running']);

        // timeout 100 + 60s grace = still alive at +160, dead after it.
        Cli_Job_Store::set_clock_for_tests(1700000160);
        $this->assertSame(0, Cli_Job_Store::purge_stale()['abandoned']);
        $this->assertSame('running', Cli_Job_Store::get($job['id'])['status']);

        Cli_Job_Store::set_clock_for_tests(1700000161);
        $this->assertSame(1, Cli_Job_Store::purge_stale()['abandoned']);

        $reaped = Cli_Job_Store::get($job['id']);
        $this->assertSame('failed', $reaped['status']);
        $this->assertStringContainsString('past its timeout', $reaped['error']);
    }

    public function test_purge_leaves_a_queued_job_alone_however_old(): void
    {
        Cli_Job_Store::set_clock_for_tests(1700000000);
        $job = Cli_Job_Store::create(['core', 'version'], 100);

        Cli_Job_Store::set_clock_for_tests(1700000000 + 100000);
        Cli_Job_Store::purge_stale();

        // A queued job has not started, so it cannot have been abandoned;
        // reaping it would delete work that WP-Cron is still going to run.
        $this->assertSame('queued', Cli_Job_Store::get($job['id'])['status']);
    }

    public function test_purge_drops_terminal_records_older_than_the_retention_window(): void
    {
        Cli_Job_Store::set_clock_for_tests(1700000000);
        $old   = Cli_Job_Store::create(['core', 'version'], 300);
        Cli_Job_Store::update($old['id'], ['status' => 'completed']);

        Cli_Job_Store::set_clock_for_tests(1700000000 + Cli_Job_Store::DEFAULT_RETENTION_SECONDS - 1);
        $this->assertSame(0, Cli_Job_Store::purge_stale()['expired']);
        $this->assertNotNull(Cli_Job_Store::get($old['id']));

        Cli_Job_Store::set_clock_for_tests(1700000000 + Cli_Job_Store::DEFAULT_RETENTION_SECONDS);
        $this->assertSame(1, Cli_Job_Store::purge_stale()['expired']);
        $this->assertNull(Cli_Job_Store::get($old['id']));
    }

    public function test_purge_never_expires_a_queued_or_running_job(): void
    {
        Cli_Job_Store::set_clock_for_tests(1700000000);
        $queued  = Cli_Job_Store::create(['core', 'version'], 300);
        $running = Cli_Job_Store::create(['core', 'version'], 999999);
        Cli_Job_Store::update($running['id'], ['status' => 'running']);

        Cli_Job_Store::set_clock_for_tests(1700000000 + (Cli_Job_Store::DEFAULT_RETENTION_SECONDS * 10));
        Cli_Job_Store::purge_stale();

        $this->assertNotNull(Cli_Job_Store::get($queued['id']));
        $this->assertNotNull(Cli_Job_Store::get($running['id']));
    }

    public function test_retention_is_filterable(): void
    {
        add_filter('wpmcp_cli_job_retention_seconds', fn() => 10);

        Cli_Job_Store::set_clock_for_tests(1700000000);
        $job = Cli_Job_Store::create(['core', 'version'], 300);
        Cli_Job_Store::update($job['id'], ['status' => 'completed']);

        Cli_Job_Store::set_clock_for_tests(1700000011);
        Cli_Job_Store::purge_stale();

        $this->assertNull(Cli_Job_Store::get($job['id']));
    }

    public function test_purge_trims_the_oldest_terminal_records_past_the_cap(): void
    {
        add_filter('wpmcp_cli_job_max_records', fn() => 3);
        // Retention must not be what removes these, or the trim pass would
        // never be reached: keep every record well inside the TTL.
        add_filter('wpmcp_cli_job_retention_seconds', fn() => 1000000);

        Cli_Job_Store::set_clock_for_tests(1700000000);
        $ids = [];
        for ($i = 0; $i < 5; $i++) {
            $job   = Cli_Job_Store::create(['core', 'version'], 300);
            $ids[] = $job['id'];
            Cli_Job_Store::update($job['id'], ['status' => 'completed']);
        }

        $this->assertSame(2, Cli_Job_Store::purge_stale()['trimmed']);

        $remaining = array_column(Cli_Job_Store::list(), 'id');
        sort($remaining);
        $this->assertSame([$ids[2], $ids[3], $ids[4]], $remaining);
    }

    public function test_trim_never_evicts_an_in_flight_job_to_meet_the_cap(): void
    {
        add_filter('wpmcp_cli_job_max_records', fn() => 1);
        add_filter('wpmcp_cli_job_retention_seconds', fn() => 1000000);

        Cli_Job_Store::set_clock_for_tests(1700000000);
        $queued = Cli_Job_Store::create(['core', 'version'], 300);
        $done   = Cli_Job_Store::create(['core', 'version'], 300);
        Cli_Job_Store::update($done['id'], ['status' => 'completed']);

        Cli_Job_Store::purge_stale();

        // The cap cannot be met without evicting the queued job, so the
        // store stays over cap: an in-flight job's record is the only handle
        // its dispatcher has on it.
        $this->assertNotNull(Cli_Job_Store::get($queued['id']));
        $this->assertNull(Cli_Job_Store::get($done['id']));
    }

    public function test_reap_abandoned_corrects_status_without_deleting_anything(): void
    {
        add_filter('wpmcp_cli_job_retention_seconds', fn() => 1);

        Cli_Job_Store::set_clock_for_tests(1700000000);
        $done    = Cli_Job_Store::create(['core', 'version'], 300);
        Cli_Job_Store::update($done['id'], ['status' => 'completed']);
        $running = Cli_Job_Store::create(['plugin', 'list'], 60);
        Cli_Job_Store::update($running['id'], ['status' => 'running']);

        Cli_Job_Store::set_clock_for_tests(1700000000 + 60 + Cli_Job_Store::ABANDONED_GRACE_SECONDS + 1);

        $this->assertSame(1, Cli_Job_Store::reap_abandoned());
        $this->assertSame('failed', Cli_Job_Store::get($running['id'])['status']);
        // Long past retention, but reap_abandoned() is not the purge: the
        // read-only poll tools call it, and a read must not delete records.
        $this->assertNotNull(Cli_Job_Store::get($done['id']));
    }

    public function test_in_flight_count_counts_queued_and_running_only(): void
    {
        Cli_Job_Store::set_clock_for_tests(1700000000);
        Cli_Job_Store::create(['core', 'version'], 300);
        $running = Cli_Job_Store::create(['core', 'version'], 300);
        Cli_Job_Store::update($running['id'], ['status' => 'running']);

        foreach (Cli_Job_Store::TERMINAL_STATUSES as $status) {
            $terminal = Cli_Job_Store::create(['core', 'version'], 300);
            Cli_Job_Store::update($terminal['id'], ['status' => $status]);
        }

        $this->assertSame(2, Cli_Job_Store::in_flight_count());
    }

    public function test_purge_writes_nothing_when_there_is_nothing_to_reap(): void
    {
        Cli_Job_Store::set_clock_for_tests(1700000000);
        Cli_Job_Store::create(['core', 'version'], 300);
        $before = get_option(Cli_Job_Store::OPTION);

        $this->assertSame(
            ['abandoned' => 0, 'expired' => 0, 'trimmed' => 0],
            Cli_Job_Store::purge_stale()
        );
        $this->assertSame($before, get_option(Cli_Job_Store::OPTION));
    }

    public function test_a_corrupt_option_value_is_treated_as_an_empty_store(): void
    {
        update_option(Cli_Job_Store::OPTION, 'not-an-array');

        $this->assertSame([], Cli_Job_Store::list());
        $this->assertSame(1, Cli_Job_Store::create(['core', 'version'], 300)['id']);
    }
}
