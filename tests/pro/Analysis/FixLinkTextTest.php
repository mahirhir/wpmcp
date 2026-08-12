<?php

namespace WPMCP\Tests\Pro\Analysis;

use WPMCP\MCP\{Ability, Registrar};
use WPMCP\Pro\Gate;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Tools\Analysis\Fix_Link_Text;

class FixLinkTextTest extends \WP_UnitTestCase
{
    private array $created = [];
    private int $target;
    private string $target_url;

    protected function setUp(): void
    {
        parent::setUp();
        Gate::set_pro_for_tests(true);

        $this->target = $this->factory()->post->create(
            ['post_title' => 'How To Service A Bicycle Chain']
        );
        $this->created[]  = $this->target;
        $this->target_url = (string) get_permalink($this->target);
    }

    protected function tearDown(): void
    {
        foreach ($this->created as $id) {
            wp_delete_post($id, true);
        }
        $this->created = [];
        Gate::set_pro_for_tests(null);
        parent::tearDown();
    }

    private function post(string $content): int
    {
        $id = $this->factory()->post->create(['post_content' => $content]);
        $this->created[] = $id;
        return $id;
    }

    private function fix(array $args): array
    {
        return (new Fix_Link_Text())->handle($args);
    }

    private function link(string $text): string
    {
        return '<a href="' . esc_url($this->target_url) . '">' . $text . '</a>';
    }

    // -- The dry-run contract -------------------------------------------------

    public function test_dry_run_is_the_default_and_writes_nothing(): void
    {
        $content = '<p>Fix your chain: ' . $this->link('click here') . '</p>';
        $id      = $this->post($content);

        $out = $this->fix(['post_id' => $id]);

        $this->assertTrue($out['dry_run']);
        $this->assertFalse($out['applied']);
        $this->assertSame(1, $out['count']);
        $this->assertSame('How To Service A Bicycle Chain', $out['proposed'][0]['proposed_text']);
        $this->assertSame('click here', $out['proposed'][0]['current_text']);
        $this->assertSame($this->target, $out['proposed'][0]['target_id']);
        $this->assertSame($content, get_post($id)->post_content);
    }

    public function test_apply_rewrites_only_the_text_between_the_tags(): void
    {
        $id = $this->post(
            '<p>Fix your chain: <a href="' . esc_url($this->target_url)
            . '" class="cta" rel="nofollow" target="_blank">read more</a></p>'
        );

        $this->fix(['post_id' => $id, 'apply' => true]);
        $content = get_post($id)->post_content;

        $this->assertStringContainsString('>How To Service A Bicycle Chain</a>', $content);
        $this->assertStringContainsString('class="cta"', $content);
        $this->assertStringContainsString('rel="nofollow"', $content);
        $this->assertStringContainsString('target="_blank"', $content);
        $this->assertStringNotContainsString('read more', $content);
    }

    public function test_one_rollback_reverts_the_entire_pass(): void
    {
        $original = '<p>' . $this->link('click here') . ' and ' . $this->link('learn more') . '</p>';
        $id       = $this->post($original);

        $out = $this->fix(['post_id' => $id, 'apply' => true]);
        $this->assertSame(2, $out['count']);
        $this->assertNotSame($original, get_post($id)->post_content);

        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));
        $this->assertSame($original, get_post($id)->post_content);
    }

    public function test_empty_link_text_is_fixed_too(): void
    {
        $id = $this->post('<p>' . $this->link('') . '</p>');

        $this->assertSame(1, $this->fix(['post_id' => $id])['count']);
    }

    public function test_trailing_punctuation_still_reads_as_generic(): void
    {
        $id = $this->post('<p>' . $this->link('Read more &raquo;') . '</p>');

        $this->assertSame(1, $this->fix(['post_id' => $id])['count']);
    }

    // -- What it refuses to touch --------------------------------------------

    public function test_descriptive_link_text_is_left_alone(): void
    {
        $content = '<p>' . $this->link('our bicycle chain guide') . '</p>';
        $id      = $this->post($content);

        $out = $this->fix(['post_id' => $id, 'apply' => true]);

        $this->assertSame(0, $out['count']);
        $this->assertSame('text_already_descriptive', $out['skipped'][0]['reason']);
        $this->assertSame($content, get_post($id)->post_content);
    }

    public function test_external_links_are_never_rewritten(): void
    {
        $id = $this->post('<p><a href="https://example.org/guide">click here</a></p>');

        $out = $this->fix(['post_id' => $id, 'apply' => true]);

        $this->assertSame(0, $out['count']);
        $this->assertSame('destination_not_resolvable', $out['skipped'][0]['reason']);
    }

    public function test_mailto_and_fragment_links_are_never_rewritten(): void
    {
        $id = $this->post('<p><a href="mailto:hi@example.com">here</a><a href="#top">here</a></p>');

        $out = $this->fix(['post_id' => $id]);

        $this->assertSame(0, $out['count']);
        $this->assertCount(2, $out['skipped']);
    }

    public function test_anchors_containing_markup_are_skipped_so_nothing_is_deleted(): void
    {
        $content = '<p><a href="' . esc_url($this->target_url) . '"><img src="/uploads/a.jpg"></a></p>';
        $id      = $this->post($content);

        $out = $this->fix(['post_id' => $id, 'apply' => true]);

        $this->assertSame(0, $out['count']);
        $this->assertSame('contains_markup', $out['skipped'][0]['reason']);
        $this->assertSame($content, get_post($id)->post_content);
    }

    public function test_internal_link_to_a_missing_post_is_skipped(): void
    {
        $id = $this->post('<p><a href="' . esc_url(home_url('/no-such-page/')) . '">click here</a></p>');

        $out = $this->fix(['post_id' => $id]);

        $this->assertSame(0, $out['count']);
        $this->assertSame('destination_not_resolvable', $out['skipped'][0]['reason']);
    }

    public function test_reapplying_the_pass_proposes_nothing(): void
    {
        $id = $this->post('<p>' . $this->link('click here') . '</p>');

        $this->fix(['post_id' => $id, 'apply' => true]);
        $second = $this->fix(['post_id' => $id, 'apply' => true]);

        $this->assertSame(0, $second['count']);
        $this->assertArrayNotHasKey('operation_id', $second);
    }

    public function test_missing_post_id_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->fix([]);
    }

    public function test_registrar_skips_when_free(): void
    {
        Gate::set_pro_for_tests(false);
        $registrar = new Registrar();
        $registrar->register(
            new Ability(
                'wpmcp/fix-link-text',
                'pro',
                'Replace generic anchor text.',
                ['type' => 'object', 'properties' => ['post_id' => ['type' => 'integer']], 'required' => ['post_id']],
                [new Fix_Link_Text(), 'handle'],
                'edit_posts',
                'analysis',
                'update'
            )
        );
        $this->assertCount(0, $registrar->all());
    }
}
