<?php

namespace WPMCP\Tests\Pro\Builders;

use WPMCP\Pro\Gate;
use WPMCP\Tools\Builders\Get_Builder_Content;

/**
 * Bricks and Divi content lives in plain postmeta/post_content, so (like
 * DetectBuilderTest) these tests are never skipped for missing plugins.
 */
class GetBuilderContentTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Gate::set_pro_for_tests(true);
    }

    protected function tearDown(): void
    {
        Gate::set_pro_for_tests(null);
        parent::tearDown();
    }

    public function test_returns_native_bricks_array_from_postmeta(): void
    {
        // Bricks stores its element tree as a NATIVE (serialized) array in
        // _bricks_page_content_2 (see includes/ajax.php: update_post_meta with
        // the $content array). This is how a real Bricks page looks on disk.
        $post_id  = self::factory()->post->create(['post_type' => 'page']);
        $elements = [
            ['id' => 'sec01', 'name' => 'section', 'parent' => 0, 'children' => ['con01'], 'settings' => []],
            ['id' => 'con01', 'name' => 'container', 'parent' => 'sec01', 'children' => ['hea01'], 'settings' => []],
            ['id' => 'hea01', 'name' => 'heading', 'parent' => 'con01', 'children' => [], 'settings' => ['text' => 'Hello']],
        ];
        update_post_meta($post_id, '_bricks_page_content_2', $elements);

        $out = (new Get_Builder_Content())->handle(['post_id' => $post_id]);

        $this->assertIsArray($out);
        $this->assertSame('bricks', $out['builder']);
        $this->assertSame($elements, $out['content']);
    }

    public function test_tolerates_legacy_json_string_bricks_content(): void
    {
        // Older data (or an external writer) may have left a JSON string; the
        // reader still decodes it rather than reporting the page as empty.
        $post_id = self::factory()->post->create(['post_type' => 'page']);
        $elements = [['id' => 'abc123', 'name' => 'section', 'children' => []]];
        update_post_meta($post_id, '_bricks_page_content_2', wp_json_encode($elements));

        $out = (new Get_Builder_Content())->handle(['post_id' => $post_id]);

        $this->assertIsArray($out);
        $this->assertSame('bricks', $out['builder']);
        $this->assertSame($elements, $out['content']);
    }

    public function test_returns_divi_shortcode_string_and_use_builder_flag(): void
    {
        $shortcode = '[et_pb_section][et_pb_row][et_pb_column type="4_4"][et_pb_text]Hi[/et_pb_text][/et_pb_column][/et_pb_row][/et_pb_section]';
        $post_id   = self::factory()->post->create(['post_type' => 'page', 'post_content' => $shortcode]);
        update_post_meta($post_id, '_et_pb_use_builder', 'on');

        $out = (new Get_Builder_Content())->handle(['post_id' => $post_id]);

        $this->assertSame('divi', $out['builder']);
        $this->assertSame($shortcode, $out['content']);
        $this->assertTrue($out['use_builder']);
    }

    public function test_returns_wp_error_when_post_id_missing(): void
    {
        $out = (new Get_Builder_Content())->handle([]);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('missing_post_id', $out->get_error_code());
    }

    public function test_returns_wp_error_when_post_not_found(): void
    {
        $out = (new Get_Builder_Content())->handle(['post_id' => 999999]);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('post_not_found', $out->get_error_code());
    }

    public function test_returns_wp_error_for_unsupported_builder(): void
    {
        $post_id = self::factory()->post->create([
            'post_type'    => 'page',
            'post_content' => '<p>Plain classic content.</p>',
        ]);

        $out = (new Get_Builder_Content())->handle(['post_id' => $post_id]);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('unsupported_builder', $out->get_error_code());
    }
}
