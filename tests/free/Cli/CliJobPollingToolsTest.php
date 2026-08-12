<?php

namespace WPMCP\Tests\Free\Cli;

use WPMCP\Tools\Cli\Cancel_Cli_Job;
use WPMCP\Tools\Cli\Cli_Job_Store;
use WPMCP\Tools\Cli\Get_Cli_Job;
use WPMCP\Tools\Cli\List_Cli_Jobs;
use WPMCP\Tools\Cli\Run_Cli_Job;

/**
 * The three job-management tools that sit around dispatch-cli-job:
 * get-cli-job and list-cli-jobs (read-only polling) and cancel-cli-job
 * (withdraw a job that has not started).
 *
 * None of them can run a command, which is why they are not listed in
 * Opt_In_Gates: they only read and transition job records.
 */
class CliJobPollingToolsTest extends \WP_UnitTestCase
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
        delete_option(Cli_Job_Store::OPTION);
        wp_clear_scheduled_hook(Run_Cli_Job::HOOK);
        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // get-cli-job
    // ---------------------------------------------------------------

    public function test_get_returns_the_full_job_record(): void
    {
        Cli_Job_Store::set_clock_for_tests(1700000000);
        $job = Cli_Job_Store::create(['plugin', 'list'], 300);
        Cli_Job_Store::update($job['id'], [
            'status' => 'completed',
            'result' => ['stdout' => 'ok', 'stderr' => '', 'exit_code' => 0],
        ]);

        $out = (new Get_Cli_Job())->handle(['job_id' => $job['id']]);

        $this->assertSame($job['id'], $out['id']);
        $this->assertSame('plugin list', $out['command']);
        $this->assertSame('completed', $out['status']);
        $this->assertSame('ok', $out['result']['stdout']);
    }

    public function test_get_returns_wp_error_for_an_unknown_job_id(): void
    {
        $out = (new Get_Cli_Job())->handle(['job_id' => 999999]);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('wpmcp_cli_job_not_found', $out->get_error_code());
    }

    public function test_get_reaps_an_abandoned_job_rather_than_reporting_it_running_forever(): void
    {
        Cli_Job_Store::set_clock_for_tests(1700000000);
        $job = Cli_Job_Store::create(['plugin', 'list'], 60);
        Cli_Job_Store::update($job['id'], ['status' => 'running']);

        // The worker died with its request; on a quiet site nothing else
        // will ever touch this record, so the poll path must reap it.
        Cli_Job_Store::set_clock_for_tests(1700000000 + 60 + Cli_Job_Store::ABANDONED_GRACE_SECONDS + 1);

        $out = (new Get_Cli_Job())->handle(['job_id' => $job['id']]);

        $this->assertSame('failed', $out['status']);
    }

    // ---------------------------------------------------------------
    // list-cli-jobs
    // ---------------------------------------------------------------

    public function test_list_returns_jobs_newest_first(): void
    {
        Cli_Job_Store::set_clock_for_tests(1700000000);
        $first  = Cli_Job_Store::create(['core', 'version'], 300);
        $second = Cli_Job_Store::create(['plugin', 'list'], 300);

        $out = (new List_Cli_Jobs())->handle([]);

        $this->assertSame([$second['id'], $first['id']], array_column($out['jobs'], 'id'));
    }

    public function test_list_filters_by_status(): void
    {
        Cli_Job_Store::set_clock_for_tests(1700000000);
        Cli_Job_Store::create(['core', 'version'], 300);
        $done = Cli_Job_Store::create(['plugin', 'list'], 300);
        Cli_Job_Store::update($done['id'], ['status' => 'completed']);

        $out = (new List_Cli_Jobs())->handle(['status' => 'completed']);

        $this->assertCount(1, $out['jobs']);
        $this->assertSame($done['id'], $out['jobs'][0]['id']);
    }

    public function test_list_is_empty_when_nothing_has_been_dispatched(): void
    {
        $this->assertSame([], (new List_Cli_Jobs())->handle([])['jobs']);
    }

    public function test_list_corrects_an_abandoned_job_without_deleting_records(): void
    {
        // Retention is long past for the completed record, but a read-only
        // tool must not delete it: only the write paths (dispatch, post-run)
        // expire records, so polling can never destroy the history the
        // caller is polling for. The abandoned running job is still
        // corrected, because reporting it as "running" would be a lie.
        add_filter('wpmcp_cli_job_retention_seconds', fn() => 10);

        Cli_Job_Store::set_clock_for_tests(1700000000);
        $done      = Cli_Job_Store::create(['core', 'version'], 300);
        Cli_Job_Store::update($done['id'], ['status' => 'completed']);
        $abandoned = Cli_Job_Store::create(['plugin', 'list'], 60);
        Cli_Job_Store::update($abandoned['id'], ['status' => 'running']);

        Cli_Job_Store::set_clock_for_tests(1700000000 + 60 + Cli_Job_Store::ABANDONED_GRACE_SECONDS + 1);

        $jobs = (new List_Cli_Jobs())->handle([])['jobs'];

        $this->assertCount(2, $jobs);
        $this->assertSame('failed', Cli_Job_Store::get($abandoned['id'])['status']);
        $this->assertSame('completed', Cli_Job_Store::get($done['id'])['status']);
    }

    // ---------------------------------------------------------------
    // cancel-cli-job
    // ---------------------------------------------------------------

    public function test_cancels_a_queued_job_and_unschedules_its_cron_event(): void
    {
        $job = Cli_Job_Store::create(['plugin', 'list'], 300);
        wp_schedule_single_event(time(), Run_Cli_Job::HOOK, [$job['id']]);
        $this->assertNotFalse(wp_next_scheduled(Run_Cli_Job::HOOK, [$job['id']]));

        $out = (new Cancel_Cli_Job())->handle(['job_id' => $job['id']]);

        $this->assertSame('canceled', $out['status']);
        $this->assertSame('canceled', Cli_Job_Store::get($job['id'])['status']);
        $this->assertFalse(wp_next_scheduled(Run_Cli_Job::HOOK, [$job['id']]));
    }

    public function test_refuses_to_cancel_a_running_job(): void
    {
        $job = Cli_Job_Store::create(['plugin', 'list'], 300);
        Cli_Job_Store::update($job['id'], ['status' => 'running']);

        $out = (new Cancel_Cli_Job())->handle(['job_id' => $job['id']]);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('wpmcp_cli_job_not_cancelable', $out->get_error_code());
        $this->assertSame('running', Cli_Job_Store::get($job['id'])['status']);
    }

    public function test_refuses_to_cancel_a_completed_job(): void
    {
        $job = Cli_Job_Store::create(['plugin', 'list'], 300);
        Cli_Job_Store::update($job['id'], ['status' => 'completed']);

        $out = (new Cancel_Cli_Job())->handle(['job_id' => $job['id']]);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('wpmcp_cli_job_not_cancelable', $out->get_error_code());
    }

    public function test_cancel_returns_wp_error_for_an_unknown_job_id(): void
    {
        $out = (new Cancel_Cli_Job())->handle(['job_id' => 999999]);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('wpmcp_cli_job_not_found', $out->get_error_code());
    }
}
