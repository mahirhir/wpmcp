<?php

namespace WPMCP\Tests\Pro\Analysis;

use WPMCP\MCP\{Ability, Registrar};
use WPMCP\Pro\Gate;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Tools\Analysis\Add_Alt_Text_From_Context;
use WPMCP\Tools\Analysis\Alt_Text_Suggester;

class AddAltTextFromContextTest extends \WP_UnitTestCase
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

    private function post(string $content, string $title = 'A Post About Bicycles'): int
    {
        $id = $this->factory()->post->create(
            ['post_content' => $content, 'post_title' => $title]
        );
        $this->created[] = $id;
        return $id;
    }

    private function fix(array $args): array
    {
        return (new Add_Alt_Text_From_Context())->handle($args);
    }

    // -- The dry-run contract -------------------------------------------------

    public function test_dry_run_is_the_default_and_writes_nothing(): void
    {
        $content = '<img src="/wp-content/uploads/red-touring-bicycle.jpg">';
        $id      = $this->post($content);

        $out = $this->fix(['post_id' => $id]);

        $this->assertTrue($out['dry_run']);
        $this->assertFalse($out['applied']);
        $this->assertSame(1, $out['count']);
        $this->assertSame($content, get_post($id)->post_content);
    }

    public function test_dry_run_explains_where_each_proposal_came_from(): void
    {
        $id = $this->post(
            '<img src="/uploads/x.png">'
            . '<img src="/uploads/red-touring-bicycle-1024x768.jpg">'
            . '<h2>Choosing a saddle</h2><img src="/uploads/IMG_4821.jpg">'
        );

        $proposals = array_column($this->fix(['post_id' => $id])['proposed'], null, 'location');

        // No filename words and no heading yet: the post title is all there is.
        $this->assertSame('A Post About Bicycles', $proposals['img[1]']['proposed_alt']);
        $this->assertSame('post_title', $proposals['img[1]']['source']);

        $this->assertSame('Red touring bicycle', $proposals['img[2]']['proposed_alt']);
        $this->assertSame('filename', $proposals['img[2]']['source']);

        // Camera-code filename, so the nearest heading above it wins.
        $this->assertSame('Choosing a saddle', $proposals['img[3]']['proposed_alt']);
        $this->assertSame('heading', $proposals['img[3]']['source']);
    }

    public function test_the_nearest_heading_above_the_image_wins(): void
    {
        $id = $this->post(
            '<h2>First section</h2><img src="/uploads/IMG_1.jpg">'
            . '<h2>Second section</h2><img src="/uploads/IMG_2.jpg">'
        );

        $proposals = array_column($this->fix(['post_id' => $id])['proposed'], 'proposed_alt', 'location');

        $this->assertSame('First section', $proposals['img[1]']);
        $this->assertSame('Second section', $proposals['img[2]']);
    }

    public function test_apply_true_writes_every_alt_attribute(): void
    {
        $id = $this->post('<img src="/uploads/red-touring-bicycle.jpg"><img src="/uploads/blue-city-bike.jpg">');

        $out     = $this->fix(['post_id' => $id, 'apply' => true]);
        $content = get_post($id)->post_content;

        $this->assertTrue($out['applied']);
        $this->assertSame(2, $out['count']);
        $this->assertStringContainsString('alt="Red touring bicycle"', $content);
        $this->assertStringContainsString('alt="Blue city bike"', $content);
    }

    // -- Reversibility --------------------------------------------------------

    public function test_one_rollback_reverts_the_entire_pass(): void
    {
        $original = '<img src="/uploads/red-touring-bicycle.jpg"><img src="/uploads/blue-city-bike.jpg">';
        $id       = $this->post($original);

        $out = $this->fix(['post_id' => $id, 'apply' => true]);
        $this->assertNotSame($original, get_post($id)->post_content);

        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));
        $this->assertSame($original, get_post($id)->post_content);
    }

    public function test_media_library_alt_is_declared_untouched(): void
    {
        $id = $this->post('<img src="/uploads/red-touring-bicycle.jpg">');

        $out = $this->fix(['post_id' => $id, 'apply' => true]);

        $this->assertStringContainsString('untouched', $out['media_library_alt']);
    }

    // -- What it refuses to touch --------------------------------------------

    public function test_existing_alt_text_is_never_overwritten_by_default(): void
    {
        $content = '<img src="/uploads/red-touring-bicycle.jpg" alt="A hand-written description">';
        $id      = $this->post($content);

        $out = $this->fix(['post_id' => $id, 'apply' => true]);

        $this->assertSame(0, $out['count']);
        $this->assertSame('already_has_alt', $out['skipped'][0]['reason']);
        $this->assertSame($content, get_post($id)->post_content);
    }

    public function test_existing_alt_text_is_replaced_only_when_explicitly_asked(): void
    {
        $id = $this->post('<img src="/uploads/red-touring-bicycle.jpg" alt="old">');

        $out = $this->fix(['post_id' => $id, 'apply' => true, 'overwrite_existing' => true]);

        $this->assertSame(1, $out['count']);
        $this->assertTrue($out['proposed'][0]['overwrites']);
        $this->assertSame('old', $out['proposed'][0]['current_alt']);
        $this->assertStringContainsString('alt="Red touring bicycle"', get_post($id)->post_content);
    }

    public function test_images_marked_decorative_are_left_alone(): void
    {
        $id = $this->post(
            '<img src="/uploads/divider-line.png" role="presentation">'
            . '<img src="/uploads/spacer-block.png" aria-hidden="true">'
            . '<img src="/uploads/corner-flourish.png" role="none">'
        );

        $out = $this->fix(['post_id' => $id, 'apply' => true, 'overwrite_existing' => true]);

        $this->assertSame(0, $out['count']);
        $this->assertSame(
            ['marked_decorative', 'marked_decorative', 'marked_decorative'],
            array_column($out['skipped'], 'reason')
        );
    }

    public function test_an_empty_alt_attribute_is_treated_as_fixable(): void
    {
        $id = $this->post('<img src="/uploads/red-touring-bicycle.jpg" alt="">');

        $out = $this->fix(['post_id' => $id]);

        $this->assertSame(1, $out['count']);
        $this->assertFalse($out['proposed'][0]['overwrites']);
    }

    public function test_an_image_with_no_usable_context_is_skipped(): void
    {
        $id = $this->post('<img src="/uploads/IMG_0001.jpg">', '');

        $out = $this->fix(['post_id' => $id]);

        $this->assertSame(0, $out['count']);
        $this->assertSame('no_context_available', $out['skipped'][0]['reason']);
    }

    public function test_reapplying_the_same_pass_proposes_nothing(): void
    {
        $id = $this->post('<img src="/uploads/red-touring-bicycle.jpg">');

        $this->fix(['post_id' => $id, 'apply' => true]);
        $second = $this->fix(['post_id' => $id, 'apply' => true, 'overwrite_existing' => true]);

        $this->assertSame(0, $second['count']);
        $this->assertSame('proposal_matches_current', $second['skipped'][0]['reason']);
    }

    public function test_gutenberg_block_delimiters_survive_the_write(): void
    {
        $id = $this->post(
            '<!-- wp:image {"id":42,"sizeSlug":"large"} --><figure class="wp-block-image">'
            . '<img src="/uploads/red-touring-bicycle.jpg" class="wp-image-42"/></figure><!-- /wp:image -->'
        );

        $this->fix(['post_id' => $id, 'apply' => true]);
        $content = get_post($id)->post_content;

        $this->assertStringContainsString('<!-- wp:image {"id":42,"sizeSlug":"large"} -->', $content);
        $this->assertStringContainsString('<!-- /wp:image -->', $content);
        $this->assertStringContainsString('class="wp-image-42"', $content);
        $this->assertStringContainsString('alt="Red touring bicycle"', $content);
    }

    // -- The pure suggester ---------------------------------------------------

    public function test_filenames_that_are_camera_codes_yield_nothing(): void
    {
        $this->assertSame('', Alt_Text_Suggester::from_filename('/uploads/IMG_1234.jpg'));
        $this->assertSame('', Alt_Text_Suggester::from_filename('/uploads/DSC00042.JPG'));
        $this->assertSame('', Alt_Text_Suggester::from_filename('/uploads/1024x768.png'));
        $this->assertSame('', Alt_Text_Suggester::from_filename('/uploads/hero.jpg'));
    }

    public function test_filenames_lose_wordpress_size_and_scale_suffixes(): void
    {
        $this->assertSame(
            'Red touring bicycle',
            Alt_Text_Suggester::from_filename('https://example.com/wp-content/uploads/2026/08/red-touring-bicycle-1024x768-scaled-e17123456789.jpg')
        );
    }

    public function test_long_context_is_truncated_on_a_word_boundary(): void
    {
        $proposal = Alt_Text_Suggester::propose('/uploads/x.png', str_repeat('bicycle ', 40), '');

        $this->assertNotNull($proposal);
        $this->assertLessThanOrEqual(125, strlen($proposal['alt']));
        $this->assertStringEndsWith('bicycle', $proposal['alt']);
    }

    // -- Argument validation and registration ---------------------------------

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
                'wpmcp/add-alt-text-from-context',
                'pro',
                'Write alt text for images that have none.',
                ['type' => 'object', 'properties' => ['post_id' => ['type' => 'integer']], 'required' => ['post_id']],
                [new Add_Alt_Text_From_Context(), 'handle'],
                'edit_posts',
                'analysis',
                'update'
            )
        );
        $this->assertCount(0, $registrar->all());
    }
}
