<?php

namespace WPMCP\Tests\Free\Memory;

use WPMCP\Memory\Memory_Entry;

/**
 * The single validator every project-memory entry passes through (issue
 * #131), whether it arrived from an agent tool call or from the admin
 * metabox.
 *
 * The two rules worth pinning are the closed target grammar (a rule can only
 * ever be matched against server-derived data, never against free text) and
 * the refusal of an untargeted severity=block entry (which would deny every
 * write on the site).
 */
class MemoryEntryTest extends \WP_UnitTestCase
{
    public function test_minimal_entry_normalizes_to_defaults(): void
    {
        $entry = Memory_Entry::validate(['text' => 'The staging site uses the child theme.']);

        $this->assertIsArray($entry);
        $this->assertSame('fact', $entry['kind']);
        $this->assertSame('note', $entry['severity']);
        $this->assertSame([], $entry['targets']);
        $this->assertSame('The staging site uses the child theme.', $entry['text']);
    }

    public function test_missing_text_is_rejected(): void
    {
        $error = Memory_Entry::validate(['text' => '   ']);

        $this->assertWPError($error);
        $this->assertSame('wpmcp_memory_invalid', $error->get_error_code());
    }

    public function test_title_is_derived_from_the_text_when_absent(): void
    {
        $entry = Memory_Entry::validate([
            'text' => 'one two three four five six seven eight nine ten',
        ]);

        $this->assertSame('one two three four five six seven eight', $entry['title']);
    }

    public function test_unknown_kind_and_severity_are_rejected(): void
    {
        $this->assertWPError(Memory_Entry::validate(['text' => 'x', 'kind' => 'vibes']));
        $this->assertWPError(Memory_Entry::validate(['text' => 'x', 'severity' => 'nuke']));
    }

    public function test_block_severity_without_a_target_is_rejected(): void
    {
        $error = Memory_Entry::validate(['text' => 'never write anything', 'severity' => 'block']);

        $this->assertWPError($error);
        $this->assertStringContainsString('at least one target', $error->get_error_message());
    }

    public function test_block_severity_with_a_target_is_accepted(): void
    {
        $entry = Memory_Entry::validate([
            'text'     => 'Never delete the homepage.',
            'severity' => 'block',
            'targets'  => ['post_id:42'],
        ]);

        $this->assertIsArray($entry);
        $this->assertSame(['post_id:42'], $entry['targets']);
    }

    public function test_targets_are_normalized_deduplicated_and_sorted(): void
    {
        $entry = Memory_Entry::validate([
            'text'    => 'x',
            'targets' => [' Post_Type:Page ', 'post_type:page', 'tool:Delete-Post', 'post_id:007'],
        ]);

        $this->assertSame(['post_id:7', 'post_type:page', 'tool:delete-post'], $entry['targets']);
    }

    public function test_a_bare_target_string_is_accepted_as_a_single_target(): void
    {
        $this->assertSame(['tool:delete-post'], Memory_Entry::normalize_targets('tool:delete-post'));
        $this->assertSame([], Memory_Entry::normalize_targets('  '));
    }

    /**
     * The grammar is closed on purpose: an unknown prefix is an error rather
     * than an inert target, so a typo in a guardrail is loud instead of
     * silently unenforceable.
     */
    public function test_unknown_target_types_and_shapes_are_rejected(): void
    {
        $this->assertWPError(Memory_Entry::normalize_target('user:5'));
        $this->assertWPError(Memory_Entry::normalize_target('nocolonhere'));
        $this->assertWPError(Memory_Entry::normalize_target('tool:'));
        $this->assertWPError(Memory_Entry::normalize_target('post_id:abc'));
        $this->assertWPError(Memory_Entry::normalize_target('post_id:0'));
        $this->assertWPError(Memory_Entry::normalize_target('post_type:has spaces'));
        $this->assertWPError(Memory_Entry::normalize_target('tool:bad name!'));
    }

    /** Non-string list members are skipped rather than fataling. */
    public function test_non_string_target_members_are_ignored(): void
    {
        $this->assertSame(['tool:delete-post'], Memory_Entry::normalize_targets([42, null, 'tool:delete-post']));
    }

    public function test_non_array_targets_are_rejected(): void
    {
        $this->assertWPError(Memory_Entry::normalize_targets(17));
    }

    public function test_a_bad_target_fails_the_whole_entry(): void
    {
        $error = Memory_Entry::validate(['text' => 'x', 'targets' => ['user:5']]);

        $this->assertWPError($error);
        $this->assertStringContainsString('Unknown target type', $error->get_error_message());
    }

    public function test_too_many_targets_are_rejected(): void
    {
        $targets = [];
        for ($i = 1; $i <= Memory_Entry::MAX_TARGETS + 1; $i++) {
            $targets[] = 'post_id:' . $i;
        }

        $this->assertWPError(Memory_Entry::normalize_targets($targets));
    }

    /**
     * Entries travel as plain text into the MCP handshake, so markup is
     * stripped at validation time rather than escaped at render time.
     */
    public function test_markup_is_stripped_and_whitespace_collapsed(): void
    {
        $entry = Memory_Entry::validate([
            'text' => "Use   the\n<strong>child</strong> theme <script>alert(1)</script>",
        ]);

        $this->assertSame('Use the child theme', $entry['text']);
    }

    public function test_text_is_clamped_to_the_maximum_length(): void
    {
        $entry = Memory_Entry::validate(['text' => str_repeat('a', Memory_Entry::MAX_TEXT + 500)]);

        $this->assertSame(Memory_Entry::MAX_TEXT, mb_strlen($entry['text']));
    }
}
