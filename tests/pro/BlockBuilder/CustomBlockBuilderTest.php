<?php

namespace WPMCP\Tests\Pro\BlockBuilder;

use WPMCP\Pro\Gate;
use WPMCP\Tools\BlockBuilder\Block_Spec;
use WPMCP\Tools\BlockBuilder\Block_Renderer;
use WPMCP\Tools\BlockBuilder\Block_Registry;
use WPMCP\Tools\BlockBuilder\Create_Custom_Block;
use WPMCP\Tools\BlockBuilder\Update_Custom_Block;
use WPMCP\Tools\BlockBuilder\Get_Custom_Block;
use WPMCP\Tools\BlockBuilder\List_Custom_Blocks;
use WPMCP\Tools\BlockBuilder\Delete_Custom_Block;
use WPMCP\Tools\BlockBuilder\Set_Block_Status;
use WPMCP\Tools\BlockBuilder\Validate_Block_Spec;
use WPMCP\Tools\BlockBuilder\List_Block_Control_Types;

/**
 * Cluster 7b (EMCP parity): the custom Gutenberg block builder, data-driven
 * (no code generation, no eval). A spec is stored as a wpmcp_block post and
 * registered via register_block_type with a render_callback that interpolates
 * attribute values into the template. Covers validation, the pure renderer's
 * per-attribute escaping, store CRUD, and real runtime registration.
 */
class CustomBlockBuilderTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Gate::set_pro_for_tests(true);
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    protected function tearDown(): void
    {
        Gate::set_pro_for_tests(null);
        parent::tearDown();
    }

    private function valid_spec(): array
    {
        return [
            'name'       => 'callout',
            'title'      => 'Callout',
            'category'   => 'widgets',
            'attributes' => [
                ['name' => 'heading', 'type' => 'string', 'label' => 'Heading', 'default' => 'Note'],
                ['name' => 'body', 'type' => 'richtext', 'label' => 'Body', 'default' => ''],
                ['name' => 'link', 'type' => 'url', 'label' => 'Link', 'default' => ''],
            ],
            'template'   => '<div class="callout"><h4>{{heading}}</h4><div>{{body}}</div><a href="{{link}}">More</a></div>',
        ];
    }

    // ---- validation / control types -----------------------------------------

    public function test_valid_spec_passes(): void
    {
        $this->assertTrue(Block_Spec::validate($this->valid_spec()));
        $this->assertTrue((new Validate_Block_Spec())->handle(['spec' => $this->valid_spec()])['valid']);
    }

    public function test_spec_rejects_unknown_attribute_type(): void
    {
        $spec = $this->valid_spec();
        $spec['attributes'][0]['type'] = 'bogus';
        $err = Block_Spec::validate($spec);
        $this->assertInstanceOf(\WP_Error::class, $err);
        $this->assertSame('invalid_attribute', $err->get_error_code());
    }

    public function test_spec_rejects_duplicate_attribute_names(): void
    {
        $spec = $this->valid_spec();
        $spec['attributes'][1]['name'] = 'heading';
        $this->assertInstanceOf(\WP_Error::class, Block_Spec::validate($spec));
    }

    public function test_list_block_control_types(): void
    {
        $types = array_column((new List_Block_Control_Types())->handle([])['attribute_types'], 'type');
        $this->assertContains('string', $types);
        $this->assertContains('richtext', $types);
        $this->assertContains('boolean', $types);
    }

    // ---- renderer -----------------------------------------------------------

    public function test_renderer_escapes_per_attribute(): void
    {
        $html = Block_Renderer::render($this->valid_spec(), [
            'heading' => 'Hi <script>x</script>',
            'body'    => '<em>ok</em><script>bad()</script>',
            'link'    => 'https://e.com/a"onmouseover="x',
        ]);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('<em>ok</em>', $html);
        $this->assertStringNotContainsString('"onmouseover="', $html);
    }

    public function test_renderer_defaults_and_unknown_placeholder(): void
    {
        $spec             = $this->valid_spec();
        $spec['template'] = '{{heading}}|{{ghost}}';
        $this->assertSame('Note|', Block_Renderer::render($spec, []));
    }

    // ---- store CRUD + runtime registration ----------------------------------

    public function test_create_stores_and_normalizes_name(): void
    {
        $out = (new Create_Custom_Block())->handle(['spec' => $this->valid_spec()]);
        $this->assertSame('wpmcp_block', get_post_type($out['block_id']));
        $this->assertSame('wpmcp/callout', $out['name']);
    }

    public function test_get_list_update_delete_and_status(): void
    {
        $bid = (new Create_Custom_Block())->handle(['spec' => $this->valid_spec()])['block_id'];

        $this->assertSame('wpmcp/callout', (new Get_Custom_Block())->handle(['block_id' => $bid])['spec']['name']);
        $this->assertContains($bid, array_column((new List_Custom_Blocks())->handle([])['blocks'], 'block_id'));

        $spec          = $this->valid_spec();
        $spec['title'] = 'Renamed';
        (new Update_Custom_Block())->handle(['block_id' => $bid, 'spec' => $spec]);
        $this->assertSame('Renamed', get_post_meta($bid, '_wpmcp_block_spec', true)['title']);

        (new Set_Block_Status())->handle(['block_id' => $bid, 'status' => 'draft']);
        $this->assertSame('draft', get_post_status($bid));

        $this->assertSame('trashed', (new Delete_Custom_Block())->handle(['block_id' => $bid])['deleted']);
    }

    public function test_registry_registers_active_block_as_real_block_type(): void
    {
        (new Create_Custom_Block())->handle(['spec' => $this->valid_spec()]);

        Block_Registry::register();

        $registry = \WP_Block_Type_Registry::get_instance();
        $this->assertTrue($registry->is_registered('wpmcp/callout'));

        // The registered block renders through the spec template.
        $type = $registry->get_registered('wpmcp/callout');
        $html = call_user_func($type->render_callback, ['heading' => 'Live']);
        $this->assertStringContainsString('Live', $html);

        $registry->unregister('wpmcp/callout');
    }
}
