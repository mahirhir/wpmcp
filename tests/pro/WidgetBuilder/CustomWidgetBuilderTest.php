<?php

namespace WPMCP\Tests\Pro\WidgetBuilder;

use WPMCP\Pro\Gate;
use WPMCP\Tools\WidgetBuilder\Widget_Spec;
use WPMCP\Tools\WidgetBuilder\Widget_Renderer;
use WPMCP\Tools\WidgetBuilder\Create_Custom_Widget;
use WPMCP\Tools\WidgetBuilder\Update_Custom_Widget;
use WPMCP\Tools\WidgetBuilder\Get_Custom_Widget;
use WPMCP\Tools\WidgetBuilder\List_Custom_Widgets;
use WPMCP\Tools\WidgetBuilder\Delete_Custom_Widget;
use WPMCP\Tools\WidgetBuilder\Set_Widget_Status;
use WPMCP\Tools\WidgetBuilder\Validate_Widget_Spec;
use WPMCP\Tools\WidgetBuilder\List_Control_Types;

/**
 * Cluster 7 (EMCP parity): the custom Elementor widget builder, implemented
 * data-driven (no code generation, no eval, keeping wpmcp's single-eval-site
 * safety invariant). A spec is stored as a wpmcp_widget post; a single dynamic
 * Widget_Base renders it at runtime by interpolating control values into the
 * template. These tests cover validation, the pure renderer's per-control
 * escaping, and the spec store CRUD.
 */
class CustomWidgetBuilderTest extends \WP_UnitTestCase
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
            'name'     => 'promo-box',
            'title'    => 'Promo Box',
            'icon'     => 'eicon-info-box',
            'keywords' => ['promo', 'cta'],
            'controls' => [
                ['name' => 'heading', 'type' => 'text', 'label' => 'Heading', 'default' => 'Hi'],
                ['name' => 'body', 'type' => 'wysiwyg', 'label' => 'Body', 'default' => ''],
                ['name' => 'link', 'type' => 'url', 'label' => 'Link', 'default' => ''],
            ],
            'template' => '<div class="promo"><h3>{{heading}}</h3><div>{{body}}</div><a href="{{link}}">Go</a></div>',
        ];
    }

    // ---- validate-widget-spec / list-control-types --------------------------

    public function test_valid_spec_passes(): void
    {
        $this->assertTrue(Widget_Spec::validate($this->valid_spec()));

        $out = (new Validate_Widget_Spec())->handle(['spec' => $this->valid_spec()]);
        $this->assertTrue($out['valid']);
    }

    public function test_spec_requires_title(): void
    {
        $spec = $this->valid_spec();
        unset($spec['title']);
        $this->assertInstanceOf(\WP_Error::class, Widget_Spec::validate($spec));
    }

    public function test_spec_rejects_unknown_control_type(): void
    {
        $spec = $this->valid_spec();
        $spec['controls'][0]['type'] = 'bogus';
        $err = Widget_Spec::validate($spec);
        $this->assertInstanceOf(\WP_Error::class, $err);
        $this->assertSame('invalid_control', $err->get_error_code());
    }

    public function test_spec_rejects_duplicate_control_names(): void
    {
        $spec = $this->valid_spec();
        $spec['controls'][1]['name'] = 'heading';
        $this->assertInstanceOf(\WP_Error::class, Widget_Spec::validate($spec));
    }

    public function test_spec_requires_template(): void
    {
        $spec = $this->valid_spec();
        unset($spec['template']);
        $this->assertInstanceOf(\WP_Error::class, Widget_Spec::validate($spec));
    }

    public function test_list_control_types(): void
    {
        $out = (new List_Control_Types())->handle([]);
        $types = array_column($out['control_types'], 'type');
        $this->assertContains('text', $types);
        $this->assertContains('wysiwyg', $types);
        $this->assertContains('url', $types);
        $this->assertContains('image', $types);
    }

    // ---- Widget_Renderer (pure) ---------------------------------------------

    public function test_renderer_interpolates_and_escapes_per_control(): void
    {
        $spec = $this->valid_spec();
        $html = Widget_Renderer::render($spec, [
            'heading' => 'Hello <script>x</script>',
            'body'    => '<strong>Bold</strong><script>evil()</script>',
            'link'    => 'https://example.com/a"onmouseover="x',
        ]);

        // text control: fully escaped.
        $this->assertStringNotContainsString('<script>x</script>', $html);
        $this->assertStringContainsString('Hello', $html);
        // wysiwyg: safe tags survive, the executable script tag is stripped
        // (wp_kses_post removes <script>, leaving only inert text).
        $this->assertStringContainsString('<strong>Bold</strong>', $html);
        $this->assertStringNotContainsString('<script>', $html);
        // url: esc_url strips the quote, so the attribute cannot be broken out
        // of (no `"onmouseover="` handler is injected).
        $this->assertStringNotContainsString('"onmouseover="', $html);
    }

    public function test_renderer_blanks_unknown_placeholder(): void
    {
        $spec             = $this->valid_spec();
        $spec['template'] = 'A{{heading}}B{{ghost}}C';
        $html             = Widget_Renderer::render($spec, ['heading' => 'X']);
        $this->assertSame('AXBC', $html);
    }

    public function test_renderer_uses_defaults_when_setting_absent(): void
    {
        $spec = $this->valid_spec();
        $html = Widget_Renderer::render($spec, []);
        $this->assertStringContainsString('Hi', $html); // heading default
    }

    // ---- store CRUD ---------------------------------------------------------

    public function test_create_stores_widget_spec(): void
    {
        $out = (new Create_Custom_Widget())->handle(['spec' => $this->valid_spec()]);
        $this->assertIsArray($out);
        $wid = $out['widget_id'];
        $this->assertSame('wpmcp_widget', get_post_type($wid));
        $this->assertSame('promo-box', $out['name']);
        $stored = get_post_meta($wid, '_wpmcp_widget_spec', true);
        $this->assertSame('Promo Box', $stored['title']);
    }

    public function test_create_rejects_invalid_spec(): void
    {
        $spec = $this->valid_spec();
        unset($spec['template']);
        $out = (new Create_Custom_Widget())->handle(['spec' => $spec]);
        $this->assertInstanceOf(\WP_Error::class, $out);
    }

    public function test_get_and_list_and_update_and_delete(): void
    {
        $wid = (new Create_Custom_Widget())->handle(['spec' => $this->valid_spec()])['widget_id'];

        $got = (new Get_Custom_Widget())->handle(['widget_id' => $wid]);
        $this->assertSame('promo-box', $got['spec']['name']);

        $list = (new List_Custom_Widgets())->handle([]);
        $this->assertContains($wid, array_column($list['widgets'], 'widget_id'));

        $spec          = $this->valid_spec();
        $spec['title'] = 'Renamed';
        (new Update_Custom_Widget())->handle(['widget_id' => $wid, 'spec' => $spec]);
        $this->assertSame('Renamed', get_post_meta($wid, '_wpmcp_widget_spec', true)['title']);

        $del = (new Delete_Custom_Widget())->handle(['widget_id' => $wid]);
        $this->assertSame('trashed', $del['deleted']);
    }

    public function test_set_widget_status(): void
    {
        $wid = (new Create_Custom_Widget())->handle(['spec' => $this->valid_spec()])['widget_id'];

        (new Set_Widget_Status())->handle(['widget_id' => $wid, 'status' => 'draft']);
        $this->assertSame('draft', get_post_status($wid));

        (new Set_Widget_Status())->handle(['widget_id' => $wid, 'status' => 'publish']);
        $this->assertSame('publish', get_post_status($wid));
    }
}
