<?php

namespace WPMCP\Tests\Free\Safety;

use WPMCP\Safety\Operation_Context;
use WPMCP\Safety\Safe_Mutation;
use WPMCP\Safety\Snapshot_Store;

/**
 * The per-request bridge that lets an observer around a tool call learn the
 * undo point that call produced (issue #134).
 */
class OperationContextTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        Operation_Context::reset();
    }

    protected function tearDown(): void
    {
        Operation_Context::reset();
        parent::tearDown();
    }

    public function test_since_returns_the_first_id_noted_after_the_mark(): void
    {
        Operation_Context::note('earlier');

        $mark = Operation_Context::mark();
        Operation_Context::note('first');
        Operation_Context::note('second');

        $this->assertSame('first', Operation_Context::since($mark));
        $this->assertSame(['earlier', 'first', 'second'], Operation_Context::all());
    }

    public function test_since_returns_null_when_nothing_was_noted(): void
    {
        $mark = Operation_Context::mark();

        $this->assertNull(Operation_Context::since($mark));
    }

    public function test_empty_ids_are_ignored(): void
    {
        Operation_Context::note('');

        $this->assertSame([], Operation_Context::all());
    }

    /**
     * A nested call must not be able to steal or clear the outer call's undo
     * point: both marks stay valid.
     */
    public function test_a_nested_bracket_does_not_disturb_the_outer_one(): void
    {
        $outer = Operation_Context::mark();
        Operation_Context::note('outer-op');

        $inner = Operation_Context::mark();
        Operation_Context::note('inner-op');

        $this->assertSame('inner-op', Operation_Context::since($inner));
        $this->assertSame('outer-op', Operation_Context::since($outer));
    }

    public function test_reset_clears_the_list(): void
    {
        Operation_Context::note('op');

        Operation_Context::reset();

        $this->assertSame([], Operation_Context::all());
    }

    public function test_safe_mutation_notes_the_operation_id_it_persisted(): void
    {
        $post_id = self::factory()->post->create(['post_title' => 'Before']);

        $mark = Operation_Context::mark();
        $out  = Safe_Mutation::run(
            [
                'object_type' => 'post',
                'object_id'   => $post_id,
                'session_id'  => 'sess',
                'tool_name'   => 'update-post',
                'args'        => [],
            ],
            fn() => wp_update_post(['ID' => $post_id, 'post_title' => 'After'])
        );

        $this->assertSame($out['operation_id'], Operation_Context::since($mark));
        $this->assertNotNull(Snapshot_Store::get_by_operation($out['operation_id']));
    }
}
