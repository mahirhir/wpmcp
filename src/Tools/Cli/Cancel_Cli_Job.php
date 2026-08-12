<?php

namespace WPMCP\Tools\Cli;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Cancel a queued CLI job: unschedule its WP-Cron event and mark it canceled
 * so Run_Cli_Job skips it even if the event still fires (the executor only
 * acts on a job still in 'queued' status, so the status flip is the real
 * guarantee and the unschedule is the tidy-up).
 *
 * Only a job still in 'queued' status can be canceled, matching
 * Cancel_Backup_Job. A running wp-cli process belongs to another request
 * that this one cannot signal, and a job in a terminal status has already
 * had its outcome, so in both cases "canceled" would be a lie; this refuses
 * with a clear WP_Error naming the actual status instead of silently
 * no-op'ing.
 */
class Cancel_Cli_Job
{
    public function handle(array $args)
    {
        $job_id = (int) ($args['job_id'] ?? 0);
        $job    = Cli_Job_Store::get($job_id);

        if (null === $job) {
            return new \WP_Error('wpmcp_cli_job_not_found', "No CLI job found with id \"{$job_id}\".");
        }

        if ('queued' !== $job['status']) {
            return new \WP_Error(
                'wpmcp_cli_job_not_cancelable',
                "CLI job \"{$job_id}\" cannot be canceled: its status is \"{$job['status']}\", not \"queued\"."
            );
        }

        wp_clear_scheduled_hook(Run_Cli_Job::HOOK, [$job_id]);

        $updated = Cli_Job_Store::update($job_id, ['status' => 'canceled']);

        return [
            'job_id' => $job_id,
            'status' => $updated['status'],
        ];
    }
}
