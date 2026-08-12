<?php

namespace WPMCP\Tests\Free\MCP;

use WPMCP\MCP\Handshake_Instructions;
use WPMCP\Memory\Memory_Store;

/**
 * Approved project memory reaches the agent through the EXISTING handshake
 * instructions channel (issue #131 on top of #80), so there is no second
 * discovery seam to secure or to keep in sync.
 *
 * The property under test is the trust gate: an agent proposal is inert. One
 * session cannot plant text that the next session reads as site policy; only
 * an administrator publishing an entry puts it in the handshake.
 */
class HandshakeMemoryBlockTest extends \WP_UnitTestCase
{
    public static function wpSetUpBeforeClass(): void
    {
        if (0 === did_action('wp_abilities_api_init')) {
            do_action('wp_abilities_api_init');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        Memory_Store::ensure_post_type();
        Memory_Store::flush_rules_cache();
        delete_option(Handshake_Instructions::OPTION);
        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));
    }

    protected function tearDown(): void
    {
        Memory_Store::flush_rules_cache();
        delete_option(Handshake_Instructions::OPTION);
        parent::tearDown();
    }

    public function test_no_published_entries_means_no_memory_block_at_all(): void
    {
        Memory_Store::propose(['text' => 'A pending suggestion.']);

        $handshake = new Handshake_Instructions();

        $this->assertSame('', $handshake->memory_block());
        $this->assertStringNotContainsString('Project memory', $handshake->build());
        $this->assertStringNotContainsString('A pending suggestion.', $handshake->build());
    }

    public function test_a_published_note_appears_in_the_handshake(): void
    {
        $id = Memory_Store::propose(['text' => 'Menus live in the customizer.']);
        Memory_Store::approve($id);

        $build = (new Handshake_Instructions())->build();

        $this->assertStringContainsString('Project memory', $build);
        $this->assertStringContainsString('Menus live in the customizer.', $build);
    }

    /**
     * Enforced guardrails are labelled as enforced because they are: an
     * agent that ignores the sentence still cannot perform the action.
     */
    public function test_a_published_block_entry_is_labelled_as_server_enforced(): void
    {
        $id = Memory_Store::propose([
            'text'     => 'Never delete the homepage.',
            'kind'     => 'guardrail',
            'severity' => 'block',
            'targets'  => ['post_id:5'],
        ]);
        Memory_Store::approve($id);

        $block = (new Handshake_Instructions())->memory_block();

        $this->assertStringContainsString('Enforced guardrails', $block);
        $this->assertStringContainsString('refused by the server', $block);
        $this->assertStringContainsString('Never delete the homepage.', $block);
        $this->assertStringContainsString('post_id:5', $block);
    }

    public function test_session_summaries_do_not_flood_the_handshake(): void
    {
        $summary = Memory_Store::propose([
            'text' => 'Session sess-a changed four posts.',
            'kind' => 'session-summary',
        ]);
        Memory_Store::approve($summary);

        $this->assertSame('', (new Handshake_Instructions())->memory_block());
    }

    public function test_the_memory_block_is_length_clamped(): void
    {
        for ($i = 0; $i < 40; $i++) {
            $id = Memory_Store::propose(['text' => 'Entry ' . $i . ' ' . str_repeat('padding ', 20)]);
            Memory_Store::approve($id);
        }

        $block = (new Handshake_Instructions())->memory_block();

        $this->assertSame(Handshake_Instructions::MAX_MEMORY_LENGTH, mb_strlen($block));
    }

    public function test_the_memory_block_is_appended_after_the_existing_handshake_parts(): void
    {
        update_option(Handshake_Instructions::OPTION, 'Admin-authored preamble.');
        $id = Memory_Store::propose(['text' => 'Menus live in the customizer.']);
        Memory_Store::approve($id);

        $build = (new Handshake_Instructions())->build();

        $this->assertLessThan(
            strpos($build, 'Project memory'),
            strpos($build, 'Admin-authored preamble.')
        );
        $this->assertStringContainsString('snapshotted', $build);
    }
}
