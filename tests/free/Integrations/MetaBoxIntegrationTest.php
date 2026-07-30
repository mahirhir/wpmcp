<?php

namespace WPMCP\Tests\Free\Integrations;

use WPMCP\Integrations\Meta_Box_Integration;

require_once __DIR__ . '/../../support/metabox-stubs.php';

/**
 * The Meta Box custom-fields integration, exercised against a faithful double
 * of Meta Box's public API (tests/support/metabox-stubs.php). Live Meta Box
 * stays production-verified.
 */
class MetaBoxIntegrationTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        \Rwmb_Test_Store::reset();
        \Rwmb_Test_Store::add_box('info', 'Extra info', ['post', 'page'], [
            ['id' => 'subtitle', 'name' => 'Subtitle', 'type' => 'text'],
            ['id' => 'rating', 'name' => 'Rating', 'type' => 'number'],
        ]);
    }

    public function test_is_available_when_functions_exist(): void
    {
        $this->assertTrue((new Meta_Box_Integration())->is_available());
    }

    public function test_list_meta_boxes(): void
    {
        $out = (new Meta_Box_Integration())->handle_read(['operation' => 'list-meta-boxes']);
        $box = $out['result']['meta_boxes'][0];
        $this->assertSame('info', $box['id']);
        $this->assertSame('Extra info', $box['title']);
        $this->assertContains('post', $box['post_types']);
        $this->assertSame('subtitle', $box['fields'][0]['id']);
    }

    public function test_get_fields_reads_values(): void
    {
        \Rwmb_Test_Store::$values[42] = ['subtitle' => 'Hello', 'rating' => 5];

        $out = (new Meta_Box_Integration())->handle_read([
            'operation' => 'get-fields',
            'args'      => ['post_id' => 42, 'keys' => ['subtitle', 'rating']],
        ]);

        $this->assertSame('Hello', $out['result']['fields']['subtitle']);
        $this->assertSame(5, $out['result']['fields']['rating']);
    }

    public function test_update_fields_writes_and_reads_back(): void
    {
        add_filter('wpmcp_enable_metabox_write', '__return_true');
        $post_id = self::factory()->post->create();

        $out = (new Meta_Box_Integration())->handle_write([
            'operation' => 'update-fields',
            'args'      => ['post_id' => $post_id, 'fields' => ['subtitle' => 'New sub']],
        ]);

        remove_filter('wpmcp_enable_metabox_write', '__return_true');
        $this->assertSame('New sub', $out['result']['fields']['subtitle']);
        $this->assertSame('New sub', \Rwmb_Test_Store::$values[$post_id]['subtitle']);
    }

    public function test_update_fields_disabled_by_default(): void
    {
        $post_id = self::factory()->post->create();
        $out     = (new Meta_Box_Integration())->handle_write([
            'operation' => 'update-fields',
            'args'      => ['post_id' => $post_id, 'fields' => ['subtitle' => 'x']],
        ]);
        $this->assertArrayHasKey('error', $out);
    }
}
