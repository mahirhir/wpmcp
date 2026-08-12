<?php

namespace WPMCP\Tools\Cli;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Read-only: return a CLI job's current record (status, captured
 * stdout/stderr/exit code or error, timestamps) by id. This is the poll half
 * of dispatch-and-poll.
 *
 * reap_abandoned() runs first so a job whose worker died mid-run polls as an
 * honest 'failed' rather than reporting 'running' forever: the reaper has to
 * be reachable from the read path, because on a low-traffic site the next
 * dispatch (which is what runs the full purge) may never come. Deliberately
 * reap_abandoned() and not purge_stale(): a read-only tool corrects a status
 * that has become a lie, but must never delete a record out from under the
 * caller reading it.
 */
class Get_Cli_Job
{
    public function handle(array $args)
    {
        Cli_Job_Store::reap_abandoned();

        $job_id = (int) ($args['job_id'] ?? 0);
        $job    = Cli_Job_Store::get($job_id);

        if (null === $job) {
            return new \WP_Error('wpmcp_cli_job_not_found', "No CLI job found with id \"{$job_id}\".");
        }

        return $job;
    }
}
