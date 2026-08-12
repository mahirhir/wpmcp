<?php

namespace WPMCP\Tools\Cli;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Read-only: list CLI jobs, newest first, with an optional 'status' filter
 * (queued|running|completed|failed|canceled). Corrects abandoned records
 * first for the same reason Get_Cli_Job does, so the listing never shows a
 * dead job as 'running', and for the same reason uses reap_abandoned()
 * rather than the full purge: a read must not delete records.
 */
class List_Cli_Jobs
{
    public function handle(array $args): array
    {
        Cli_Job_Store::reap_abandoned();

        $status = isset($args['status']) ? (string) $args['status'] : '';

        return ['jobs' => Cli_Job_Store::list($status)];
    }
}
