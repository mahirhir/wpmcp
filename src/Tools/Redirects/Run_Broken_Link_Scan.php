<?php

namespace WPMCP\Tools\Redirects;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * WP-Cron executor for a background broken-link scan (issue #128).
 *
 * Find_Broken_Links queues a scan and schedules a single event on self::HOOK
 * with the scan id; Plugin::boot() hooks it to this handler. Each invocation
 * processes ONE batch of posts, records the batch's findings and the new
 * progress, and reschedules itself if there is more to do.
 *
 * Batching is the point. A synchronous scan of a large site either times out
 * or is capped so low that it misses the links you cared about; either way
 * the agent gets a partial answer with no way to continue. Here progress is
 * durable between batches (offset/scanned/total live in the record), so an
 * interrupted scan resumes from where it stopped and the caller can poll a
 * real percentage instead of guessing.
 *
 * An unknown scan id, or one already in a terminal status, is a silent no-op:
 * WP-Cron has no return value to report to, and a duplicate event must never
 * double-count a batch.
 */
class Run_Broken_Link_Scan
{
    public const HOOK = 'wpmcp_run_broken_link_scan';

    /** @var callable(int[]):array<int, array<string,mixed>> */
    private $batch_scanner;

    /**
     * The per-batch scan is a constructor-injected callable defaulting to
     * Broken_Link_Scanner, matching Run_Backup_Job's producer seam. It lets
     * the failure path (a scan that dies mid-batch must land in 'failed' with
     * its reason recorded, not sit in 'running' forever) be exercised for
     * what this class is responsible for, without contriving a broken site.
     *
     * @param callable(int[]):array<int, array<string,mixed>>|null $batch_scanner
     */
    public function __construct(?callable $batch_scanner = null)
    {
        $this->batch_scanner = $batch_scanner
            ?? static fn (array $ids): array => Broken_Link_Scanner::scan_posts($ids);
    }

    public function handle(int $scan_id): void
    {
        $scan = Broken_Link_Scan_Store::get($scan_id);
        if (null === $scan || ! in_array($scan['status'], ['queued', 'running'], true)) {
            return;
        }

        Broken_Link_Scan_Store::update($scan_id, ['status' => 'running']);

        try {
            $remaining = max(0, (int) $scan['total'] - (int) $scan['offset']);
            if (0 === $remaining) {
                Broken_Link_Scan_Store::update($scan_id, ['status' => 'completed']);
                return;
            }

            $batch_size = min((int) $scan['batch_size'], $remaining);
            $ids        = Broken_Link_Scanner::scannable_ids(
                (array) $scan['post_types'],
                $batch_size,
                (int) $scan['offset']
            );

            Broken_Link_Scan_Store::append_findings($scan_id, ($this->batch_scanner)($ids));

            $offset  = (int) $scan['offset'] + count($ids);
            $scanned = (int) $scan['scanned'] + count($ids);
            // An empty batch means the content set shrank under us (posts
            // unpublished mid-scan); finish rather than reschedule forever.
            $done    = [] === $ids || $offset >= (int) $scan['total'];

            Broken_Link_Scan_Store::update($scan_id, [
                'offset'  => $offset,
                'scanned' => $scanned,
                'status'  => $done ? 'completed' : 'running',
            ]);

            if ($done) {
                // Drop any event still pending for this scan: a completed
                // scan must not leave WP-Cron holding a run that would find
                // nothing to do (or, worse, re-run a batch).
                wp_clear_scheduled_hook(self::HOOK, [$scan_id]);
            } else {
                wp_schedule_single_event(time(), self::HOOK, [$scan_id]);
            }
        } catch (\Throwable $e) {
            // A scan that dies mid-batch must not sit in "running" forever
            // with no explanation; record the failure and stop.
            Broken_Link_Scan_Store::update($scan_id, [
                'status' => 'failed',
                'error'  => $e->getMessage(),
            ]);
        }
    }
}
