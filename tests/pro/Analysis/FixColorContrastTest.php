<?php

namespace WPMCP\Tests\Pro\Analysis;

use WPMCP\MCP\{Ability, Registrar};
use WPMCP\Pro\Gate;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Tools\Analysis\Color_Contrast;
use WPMCP\Tools\Analysis\Fix_Color_Contrast;

class FixColorContrastTest extends \WP_UnitTestCase
{
    private array $created = [];

    protected function setUp(): void
    {
        parent::setUp();
        Gate::set_pro_for_tests(true);
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
        return (new Fix_Color_Contrast())->handle($args);
    }

    // -- The dry-run contract -------------------------------------------------

    public function test_dry_run_is_the_default_and_writes_nothing(): void
    {
        $content = '<p style="color:#999999">Low contrast copy</p>';
        $id      = $this->post($content);

        $out = $this->fix(['post_id' => $id]);

        $this->assertTrue($out['dry_run']);
        $this->assertFalse($out['applied']);
        $this->assertArrayNotHasKey('operation_id', $out);
        $this->assertSame(
            $content,
            get_post($id)->post_content,
            'A dry run must leave the stored content byte-for-byte unchanged.'
        );
    }

    public function test_dry_run_returns_the_full_proposed_change_set(): void
    {
        $id = $this->post('<p style="color:#999999">Low contrast copy</p>');

        $out      = $this->fix(['post_id' => $id]);
        $proposal = $out['proposed'][0];

        $this->assertSame(1, $out['count']);
        $this->assertSame('p[1]', $proposal['location']);
        $this->assertSame('#999999', $proposal['from']);
        $this->assertSame('#ffffff', $proposal['background']);
        $this->assertSame('default', $proposal['background_source']);
        $this->assertLessThan(4.5, $proposal['before_ratio']);
        $this->assertGreaterThanOrEqual(4.5, $proposal['after_ratio']);
        $this->assertSame('AA', $proposal['achieved_level']);
        $this->assertTrue($proposal['hue_preserved']);
        $this->assertSame('Low contrast copy', $proposal['text_sample']);
    }

    public function test_apply_true_writes_the_proposed_color(): void
    {
        $id = $this->post('<p style="color:#999999">Low contrast copy</p>');

        $preview = $this->fix(['post_id' => $id]);
        $applied = $this->fix(['post_id' => $id, 'apply' => true]);

        $this->assertTrue($applied['applied']);
        $this->assertFalse($applied['dry_run']);
        $this->assertNotEmpty($applied['operation_id']);

        $content = get_post($id)->post_content;
        $this->assertStringContainsString($preview['proposed'][0]['to'], $content);
        $this->assertStringNotContainsString('#999999', $content);
    }

    public function test_the_written_pair_actually_meets_the_target(): void
    {
        $id = $this->post('<div style="background-color:#003366"><p style="color:#336699">Text</p></div>');

        $out = $this->fix(['post_id' => $id, 'apply' => true]);

        $ratio = (float) Color_Contrast::contrast_ratio($out['proposed'][0]['to'], '#003366');
        $this->assertGreaterThanOrEqual(4.5, $ratio);
        $this->assertSame('ancestor', $out['proposed'][0]['background_source']);
    }

    // -- Reversibility --------------------------------------------------------

    public function test_one_rollback_reverts_the_entire_pass(): void
    {
        $original = '<p style="color:#999999">One</p><p style="color:#a0a0a0">Two</p>'
            . '<span style="color:#b0b0b0">Three</span>';
        $id       = $this->post($original);

        $out = $this->fix(['post_id' => $id, 'apply' => true]);
        $this->assertSame(3, $out['count']);
        $this->assertNotSame($original, get_post($id)->post_content);

        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));
        $this->assertSame(
            $original,
            get_post($id)->post_content,
            'One rollback of the single pass operation_id must restore every element it touched.'
        );
    }

    public function test_the_whole_pass_shares_one_operation_id(): void
    {
        $id = $this->post('<p style="color:#999999">One</p><p style="color:#a0a0a0">Two</p>');

        $out = $this->fix(['post_id' => $id, 'apply' => true]);

        $this->assertSame(2, $out['count']);
        $this->assertIsString($out['operation_id']);
        $this->assertStringContainsString('one snapshot', $out['note']);
    }

    public function test_nothing_to_fix_takes_no_snapshot(): void
    {
        $id = $this->post('<p style="color:#000000">Perfectly readable</p>');

        $out = $this->fix(['post_id' => $id, 'apply' => true]);

        $this->assertFalse($out['applied']);
        $this->assertArrayNotHasKey('operation_id', $out);
        $this->assertSame(0, $out['count']);
    }

    // -- What it refuses to touch --------------------------------------------

    public function test_surrounding_markup_survives_byte_for_byte(): void
    {
        $content = '<!-- wp:paragraph {"style":{"color":{"text":"#999999"}}} -->'
            . '<p class="has-text-color" style="color:#999999;font-size:18px">Copy</p>'
            . '<!-- /wp:paragraph -->';
        $id      = $this->post($content);

        $out     = $this->fix(['post_id' => $id, 'apply' => true]);
        $updated = get_post($id)->post_content;

        $this->assertStringContainsString('<!-- wp:paragraph {"style":{"color":{"text":"#999999"}}} -->', $updated);
        $this->assertStringContainsString('<!-- /wp:paragraph -->', $updated);
        $this->assertStringContainsString('class="has-text-color"', $updated);
        $this->assertStringContainsString('font-size:18px', $updated);
        $this->assertStringContainsString('color:' . $out['proposed'][0]['to'], $updated);
    }

    public function test_unreadable_colors_are_reported_never_guessed(): void
    {
        $id = $this->post(
            '<p style="color:var(--brand)">A</p>'
            . '<div style="background:linear-gradient(#fff,#000)"><p style="color:#999999">B</p></div>'
        );

        $out     = $this->fix(['post_id' => $id, 'apply' => true]);
        $reasons = array_column($out['skipped'], 'reason', 'location');

        $this->assertSame(0, $out['count']);
        $this->assertSame('unreadable_color', $reasons['p[1]']);
        $this->assertSame('unreadable_background', $reasons['p[2]']);
        $this->assertFalse($out['applied']);
    }

    public function test_passing_pairs_and_textless_elements_are_skipped(): void
    {
        $id = $this->post('<p style="color:#000000">Fine</p><div style="color:#999999"></div>');

        $reasons = array_column($this->fix(['post_id' => $id])['skipped'], 'reason', 'location');

        $this->assertSame('already_meets_target', $reasons['p[1]']);
        $this->assertSame('no_text', $reasons['div[1]']);
    }

    public function test_pairs_with_no_reachable_color_are_reported_not_forced(): void
    {
        $id = $this->post('<p style="color:#808080;background-color:#767676">Mid grey on mid grey</p>');

        $out = $this->fix(['post_id' => $id, 'target_ratio' => 7.0]);

        $this->assertSame(0, $out['count']);
        $this->assertSame('no_reachable_color', $out['skipped'][0]['reason']);
    }

    public function test_element_background_wins_over_the_page_default(): void
    {
        $id = $this->post('<p style="color:#ffffff;background-color:#1a1a1a">White on near black</p>');

        $out = $this->fix(['post_id' => $id]);

        $this->assertSame(0, $out['count'], 'White on near-black already passes; only the default white bg would fail it.');
        $this->assertSame('already_meets_target', $out['skipped'][0]['reason']);
    }

    public function test_default_background_argument_changes_the_verdict(): void
    {
        $id = $this->post('<p style="color:#ffffff">White text on a dark themed page</p>');

        $on_white = $this->fix(['post_id' => $id]);
        $on_dark  = $this->fix(['post_id' => $id, 'default_background' => '#111111']);

        $this->assertSame(1, $on_white['count']);
        $this->assertSame(0, $on_dark['count']);
    }

    public function test_target_ratio_is_honored(): void
    {
        $id = $this->post('<p style="color:#767676">Exactly AA on white</p>');

        $this->assertSame(0, $this->fix(['post_id' => $id])['count']);
        $this->assertSame(1, $this->fix(['post_id' => $id, 'target_ratio' => 7.0])['count']);
    }

    // -- Argument validation --------------------------------------------------

    public function test_missing_post_id_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->fix([]);
    }

    public function test_unknown_post_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->fix(['post_id' => 99999999]);
    }

    public function test_out_of_range_target_ratio_throws(): void
    {
        $id = $this->post('<p>x</p>');
        $this->expectException(\InvalidArgumentException::class);
        $this->fix(['post_id' => $id, 'target_ratio' => 99]);
    }

    public function test_unreadable_default_background_throws(): void
    {
        $id = $this->post('<p>x</p>');
        $this->expectException(\InvalidArgumentException::class);
        $this->fix(['post_id' => $id, 'default_background' => 'var(--page-bg)']);
    }

    // -- Registration ---------------------------------------------------------

    private function make_ability(): Ability
    {
        return new Ability(
            'wpmcp/fix-color-contrast',
            'pro',
            'Fix failing inline contrast pairs.',
            [
                'type'       => 'object',
                'properties' => ['post_id' => ['type' => 'integer']],
                'required'   => ['post_id'],
            ],
            [new Fix_Color_Contrast(), 'handle'],
            'edit_posts',
            'analysis',
            'update'
        );
    }

    public function test_registrar_skips_when_free(): void
    {
        Gate::set_pro_for_tests(false);
        $registrar = new Registrar();
        $registrar->register($this->make_ability());
        $this->assertCount(0, $registrar->all());
    }

    public function test_annotations_describe_a_write_even_though_it_defaults_to_dry_run(): void
    {
        $ability = $this->make_ability();

        $this->assertFalse($ability->read_only_hint);
        $this->assertFalse($ability->destructive_hint);
        $this->assertTrue($ability->idempotent_hint);
    }
}
