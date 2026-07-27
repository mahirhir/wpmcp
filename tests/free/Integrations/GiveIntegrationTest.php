<?php

namespace WPMCP\Tests\Free\Integrations;

use WPMCP\Integrations\Give_Integration;

/**
 * GiveWP read integration, exercised against a real give_forms CPT with
 * _give_* postmeta (Give's actual underscore-prefixed storage, which the
 * generic get-post tool hides, so a curated read is needed).
 */
class GiveIntegrationTest extends \WP_UnitTestCase
{
    private Give_Integration $integration;

    protected function setUp(): void
    {
        parent::setUp();
        register_post_type('give_forms', [ 'public' => true, 'label' => 'Donation Forms' ]);
        $this->integration = new Give_Integration();
    }

    protected function tearDown(): void
    {
        unregister_post_type('give_forms');
        parent::tearDown();
    }

    private function make_form(string $title, string $price, string $earnings): int
    {
        $id = self::factory()->post->create([ 'post_type' => 'give_forms', 'post_title' => $title ]);
        update_post_meta($id, '_give_set_price', $price);
        update_post_meta($id, '_give_price_option', 'set');
        update_post_meta($id, '_give_set_goal', '10000');
        update_post_meta($id, '_give_form_earnings', $earnings);
        update_post_meta($id, '_give_form_sales', '42');
        return $id;
    }

    public function test_available_when_give_cpt_registered(): void
    {
        $this->assertTrue($this->integration->is_available());
    }

    public function test_list_forms_with_totals(): void
    {
        $this->make_form('Annual Fund', '50', '1200');
        $this->make_form('Emergency Relief', '25', '800');

        $out = $this->integration->handle_read([ 'operation' => 'list-forms' ]);
        $this->assertSame(2, $out['result']['total']);
        $titles = array_column($out['result']['forms'], 'title');
        $this->assertContains('Annual Fund', $titles);
        $this->assertNotSame('', (string) $out['result']['forms'][0]['earnings']);
    }

    public function test_get_form_returns_curated_config(): void
    {
        $id  = $this->make_form('Annual Fund', '50', '1200');
        $out = $this->integration->handle_read([ 'operation' => 'get-form', 'args' => [ 'form_id' => $id ] ]);

        $this->assertSame('Annual Fund', $out['result']['form']['title']);
        $this->assertSame('50', (string) $out['result']['form']['config']['price']);
        $this->assertSame('set', $out['result']['form']['config']['price_option']);
        $this->assertSame('10000', (string) $out['result']['form']['config']['goal']);
        $this->assertSame('42', (string) $out['result']['form']['config']['sales']);
    }

    public function test_get_form_null_for_non_give_post(): void
    {
        $page = self::factory()->post->create([ 'post_type' => 'page' ]);
        $out  = $this->integration->handle_read([ 'operation' => 'get-form', 'args' => [ 'form_id' => $page ] ]);
        $this->assertNull($out['result']['form']);
    }
}
