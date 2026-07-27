<?php

namespace WPMCP\Tests\Free\Blocks;

use WPMCP\Tools\Blocks\List_Block_Types;
use WPMCP\Tools\Blocks\Get_Block_Type;
use WPMCP\Tools\Blocks\Add_Block;

/**
 * The Gutenberg block tools are block-source-agnostic: they read the whole
 * WP_Block_Type_Registry and operate on raw block markup, so blocks from
 * third-party block suites (Kadence Blocks, GenerateBlocks, Spectra/UAGB) are
 * discovered, inspected, and edited exactly like core blocks, with no
 * suite-specific integration. This test proves that with a stand-in
 * third-party block registered under a non-core namespace.
 */
class ThirdPartyBlockSuiteTest extends \WP_UnitTestCase
{
    private const BLOCK = 'acme/fancy-card';

    protected function setUp(): void
    {
        parent::setUp();
        register_block_type(self::BLOCK, [
            'title'      => 'Fancy Card',
            'category'   => 'widgets',
            'attributes' => [ 'heading' => [ 'type' => 'string' ], 'accent' => [ 'type' => 'string' ] ],
        ]);
    }

    protected function tearDown(): void
    {
        unregister_block_type(self::BLOCK);
        parent::tearDown();
    }

    public function test_list_block_types_surfaces_a_third_party_suite_block(): void
    {
        $out   = (new List_Block_Types())->handle([]);
        $names = array_column($out['block_types'], 'name');
        $this->assertContains(self::BLOCK, $names, 'a registered non-core block must be listed');
    }

    public function test_get_block_type_returns_the_suite_block_schema(): void
    {
        $out = (new Get_Block_Type())->handle([ 'name' => self::BLOCK ]);
        $this->assertSame(self::BLOCK, $out['name']);
        $this->assertArrayHasKey('heading', $out['attributes'], 'its attributes are exposed');
    }

    public function test_add_block_inserts_a_suite_block_by_markup(): void
    {
        $post_id = self::factory()->post->create([ 'post_type' => 'page', 'post_content' => '' ]);

        $expected_hash = hash('sha256', (string) get_post($post_id)->post_content);
        (new Add_Block())->handle([
            'id'            => $post_id,
            'path'          => [ 0 ],
            'expected_hash' => $expected_hash,
            'markup'        => '<!-- wp:acme/fancy-card {"heading":"Hi","accent":"#2563eb"} --><div class="wp-block-acme-fancy-card">Hi</div><!-- /wp:acme/fancy-card -->',
        ]);

        $content = get_post($post_id)->post_content;
        $this->assertStringContainsString('wp:acme/fancy-card', $content, 'the suite block landed in the document');
        $this->assertStringContainsString('"heading":"Hi"', $content);
    }
}
