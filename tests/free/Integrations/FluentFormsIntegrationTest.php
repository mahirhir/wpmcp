<?php

namespace WPMCP\Tests\Free\Integrations;

use WPMCP\Integrations\Fluent_Forms_Integration;

require_once __DIR__ . '/../../support/fluentforms-stubs.php';

/**
 * The Fluent Forms read integration, exercised against a faithful double of the
 * minimal wpFluent() query-builder surface it uses
 * (tests/support/fluentforms-stubs.php). Live Fluent Forms stays
 * production-verified.
 */
class FluentFormsIntegrationTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        \FF_Test_Query::$rows = [
            4 => (object) [
                'id'          => 4,
                'title'       => 'Signup',
                'form_fields' => wp_json_encode([
                    'fields' => [
                        ['element' => 'input_email', 'attributes' => ['name' => 'email'], 'settings' => ['label' => 'Email']],
                    ],
                ]),
            ],
        ];
    }

    public function test_is_available(): void
    {
        $this->assertTrue((new Fluent_Forms_Integration())->is_available());
    }

    public function test_list_forms(): void
    {
        $out = (new Fluent_Forms_Integration())->handle_read(['operation' => 'list-forms']);
        $this->assertSame(1, $out['result']['total']);
        $this->assertSame('Signup', $out['result']['forms'][0]['title']);
        $this->assertSame(4, $out['result']['forms'][0]['id']);
    }

    public function test_get_form_decodes_fields(): void
    {
        $out = (new Fluent_Forms_Integration())->handle_read([
            'operation' => 'get-form',
            'args'      => ['form_id' => 4],
        ]);
        $form = $out['result']['form'];
        $this->assertSame('Signup', $form['title']);
        $this->assertSame('input_email', $form['fields'][0]['element']);
        $this->assertSame('email', $form['fields'][0]['name']);
        $this->assertSame('Email', $form['fields'][0]['label']);
    }

    public function test_get_missing_form_returns_null(): void
    {
        $out = (new Fluent_Forms_Integration())->handle_read([
            'operation' => 'get-form',
            'args'      => ['form_id' => 999],
        ]);
        $this->assertNull($out['result']['form']);
    }
}
