<?php

namespace WPMCP\Tests\Free\Memory;

use WPMCP\Memory\Session_Digest;
use WPMCP\Safety\Snapshot_Store;

/**
 * The session digest (issue #131) is a deterministic rollup of the session's
 * snapshot rows, not an LLM summary and not the agent's own account of what
 * it did.
 *
 * That is what makes it evidence: wpmcp writes a before-image ahead of every
 * mutation, so the rows ARE the record. Same rows in, same text out, with no
 * network call, no API key and no nondeterminism, which is also why it can be
 * asserted exactly here.
 */
class SessionDigestTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
    }

    /** @return array<string, mixed> */
    private function snapshot(int $object_id = 1, string $type = 'post'): array
    {
        return ['object_type' => $type, 'object_id' => $object_id, 'data' => ['post' => null, 'meta' => []]];
    }

    public function test_an_empty_session_reports_no_changes(): void
    {
        $digest = Session_Digest::build('sess-empty');

        $this->assertSame(0, $digest['operation_count']);
        $this->assertSame([], $digest['tools']);
        $this->assertSame([], $digest['objects']);
        $this->assertStringContainsString('no snapshotted changes recorded', $digest['text']);
    }

    public function test_a_blank_session_id_is_not_queried(): void
    {
        $this->assertSame(0, Session_Digest::build('   ')['operation_count']);
    }

    public function test_operations_are_rolled_up_per_tool_and_per_object(): void
    {
        Snapshot_Store::save('op-1', 'sess-a', $this->snapshot(10), 'update-post', str_repeat('a', 64));
        Snapshot_Store::save('op-2', 'sess-a', $this->snapshot(10), 'update-post', str_repeat('a', 64));
        Snapshot_Store::save('op-3', 'sess-a', $this->snapshot(11), 'delete-post', str_repeat('a', 64));

        $digest = Session_Digest::build('sess-a');

        $this->assertSame(3, $digest['operation_count']);
        $this->assertSame(
            [['tool' => 'delete-post', 'count' => 1], ['tool' => 'update-post', 'count' => 2]],
            $digest['tools']
        );
        $this->assertSame(
            [
                ['object_type' => 'post', 'object_id' => 10, 'count' => 2],
                ['object_type' => 'post', 'object_id' => 11, 'count' => 1],
            ],
            $digest['objects']
        );
    }

    public function test_another_sessions_rows_are_not_counted(): void
    {
        Snapshot_Store::save('op-1', 'sess-a', $this->snapshot(10), 'update-post', str_repeat('a', 64));
        Snapshot_Store::save('op-2', 'sess-b', $this->snapshot(20), 'update-post', str_repeat('a', 64));

        $this->assertSame(1, Session_Digest::build('sess-a')['operation_count']);
    }

    public function test_the_text_names_the_tools_the_objects_and_the_reversibility(): void
    {
        Snapshot_Store::save('op-1', 'sess-a', $this->snapshot(10), 'update-post', str_repeat('a', 64));

        $text = Session_Digest::build('sess-a')['text'];

        $this->assertStringContainsString('sess-a', $text);
        $this->assertStringContainsString('update-post x1', $text);
        $this->assertStringContainsString('post #10', $text);
        $this->assertStringContainsString('rollback-session', $text);
    }

    public function test_the_digest_is_reproducible_for_the_same_rows(): void
    {
        Snapshot_Store::save('op-1', 'sess-a', $this->snapshot(10), 'update-post', str_repeat('a', 64));
        Snapshot_Store::save('op-2', 'sess-a', $this->snapshot(11), 'delete-post', str_repeat('a', 64));

        $this->assertSame(Session_Digest::build('sess-a'), Session_Digest::build('sess-a'));
    }

    public function test_named_objects_are_capped_and_the_remainder_is_counted(): void
    {
        $total = Session_Digest::MAX_NAMED_OBJECTS + 3;
        for ($i = 1; $i <= $total; $i++) {
            Snapshot_Store::save('op-' . $i, 'sess-big', $this->snapshot($i), 'update-post', str_repeat('a', 64));
        }

        $digest = Session_Digest::build('sess-big');

        $this->assertCount($total, $digest['objects']);
        $this->assertStringContainsString('and 3 more', $digest['text']);
    }

    public function test_first_and_last_timestamps_bracket_the_session(): void
    {
        Snapshot_Store::save('op-1', 'sess-a', $this->snapshot(10), 'update-post', str_repeat('a', 64));

        $digest = Session_Digest::build('sess-a');

        $this->assertNotSame('', $digest['first_at']);
        $this->assertLessThanOrEqual($digest['last_at'], $digest['first_at']);
    }

    public function test_object_types_other_than_posts_are_rolled_up_too(): void
    {
        Snapshot_Store::save('op-1', 'sess-opt', $this->snapshot(0, 'option'), 'update-option', str_repeat('a', 64));

        $digest = Session_Digest::build('sess-opt');

        $this->assertSame([['object_type' => 'option', 'object_id' => 0, 'count' => 1]], $digest['objects']);
    }
}
