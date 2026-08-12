<?php

namespace WPMCP\Tests\Free\Redirects;

use WPMCP\Tools\Redirects\Broken_Link_Scanner;
use WPMCP\Tools\Redirects\Redirect_Input;
use WPMCP\Tools\Redirects\Redirect_Store;

/**
 * Argument validation shared by create-redirect and update-redirect, plus the
 * archive-path guard that keeps the scanner from reporting working URLs
 * (issue #128).
 *
 * Every rejection here carries a message an agent can act on. A silent clamp
 * would just get the same bad call retried.
 */
class RedirectInputTest extends \WP_UnitTestCase
{
    /**
     * The archive-guard cases register a post type and a taxonomy with their
     * own rewrite slugs. Unregistering them here (rather than leaving it to
     * the next test's reset) also tears down the rewrite rules they added, so
     * nothing they created can leak into a later test's permalink handling.
     */
    protected function tearDown(): void
    {
        if (post_type_exists('portfolio')) {
            unregister_post_type('portfolio');
        }
        if (taxonomy_exists('topic')) {
            unregister_taxonomy('topic');
        }
        parent::tearDown();
    }

    public function test_a_source_longer_than_the_column_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('the maximum is ' . Redirect_Store::MAX_SOURCE_LENGTH);

        Redirect_Input::source('/' . str_repeat('x', Redirect_Store::MAX_SOURCE_LENGTH + 10));
    }

    public function test_notes_fall_back_when_the_caller_omits_them(): void
    {
        $this->assertSame('previous note', Redirect_Input::notes([], 'previous note'));
        $this->assertSame('', Redirect_Input::notes([]));
    }

    public function test_notes_are_sanitized_and_capped_to_the_column(): void
    {
        $notes = Redirect_Input::notes(['notes' => '<b>' . str_repeat('n', 400) . '</b>']);

        $this->assertSame(255, strlen($notes));
        $this->assertStringNotContainsString('<b>', $notes);
    }

    public function test_a_status_code_given_as_an_empty_string_defaults_to_301(): void
    {
        $this->assertSame(301, Redirect_Input::status_code(['status_code' => '']));
        $this->assertSame(307, Redirect_Input::status_code(['status_code' => '307']));
    }

    public function test_a_target_given_as_whitespace_counts_as_missing(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('needs a "target"');

        Redirect_Input::target(['target' => '   ']);
    }

    public function test_a_custom_post_type_archive_url_is_not_reported_as_a_dead_link(): void
    {
        $this->set_permalink_structure('/%postname%/');
        register_post_type('portfolio', [
            'public'      => true,
            'has_archive' => true,
            'rewrite'     => ['slug' => 'work'],
        ]);
        $post = get_post(self::factory()->post->create([
            'post_status'  => 'publish',
            'post_content' => '<a href="/work">our work</a>',
        ]));

        $this->assertSame([], Broken_Link_Scanner::scan_post($post));
    }

    public function test_a_custom_taxonomy_archive_url_is_not_reported_as_a_dead_link(): void
    {
        $this->set_permalink_structure('/%postname%/');
        register_taxonomy('topic', 'post', ['public' => true, 'rewrite' => ['slug' => 'topics']]);
        $post = get_post(self::factory()->post->create([
            'post_status'  => 'publish',
            'post_content' => '<a href="/topics/design">design</a>',
        ]));

        $this->assertSame([], Broken_Link_Scanner::scan_post($post));
    }

    public function test_a_single_segment_path_that_is_not_an_archive_is_still_reported(): void
    {
        $this->set_permalink_structure('/%postname%/');
        register_post_type('portfolio', ['public' => true, 'has_archive' => true, 'rewrite' => ['slug' => 'work']]);
        $post = get_post(self::factory()->post->create([
            'post_status'  => 'publish',
            'post_content' => '<a href="/not-an-archive">gone</a>',
        ]));

        $findings = Broken_Link_Scanner::scan_post($post);

        $this->assertCount(1, $findings);
        $this->assertSame(Broken_Link_Scanner::ISSUE_DEAD, $findings[0]['issue']);
    }
}
