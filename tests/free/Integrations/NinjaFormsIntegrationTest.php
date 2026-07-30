<?php

namespace WPMCP\Tests\Free\Integrations;

use WPMCP\Integrations\Ninja_Forms_Integration;

require_once __DIR__ . '/../../support/ninjaforms-stubs.php';

/**
 * The Ninja Forms read integration, exercised against a faithful double of the
 * Ninja_Forms()->form() model accessor (tests/support/ninjaforms-stubs.php).
 * Live Ninja Forms stays production-verified.
 */
class NinjaFormsIntegrationTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        \NF_Test_FormHandler::$forms = [
            8 => new \NF_Test_Form(8, ['title' => 'Contact'], [
                new \NF_Test_Field(80, ['type' => 'email', 'label' => 'Your email']),
                new \NF_Test_Field(81, ['type' => 'textarea', 'label' => 'Message']),
            ]),
        ];
    }

    public function test_is_available(): void
    {
        $this->assertTrue((new Ninja_Forms_Integration())->is_available());
    }

    public function test_list_forms(): void
    {
        $out = (new Ninja_Forms_Integration())->handle_read(['operation' => 'list-forms']);
        $this->assertSame(1, $out['result']['total']);
        $this->assertSame('Contact', $out['result']['forms'][0]['title']);
        $this->assertSame(8, $out['result']['forms'][0]['id']);
    }

    public function test_get_form_with_fields(): void
    {
        $out = (new Ninja_Forms_Integration())->handle_read([
            'operation' => 'get-form',
            'args'      => ['form_id' => 8],
        ]);
        $form = $out['result']['form'];
        $this->assertSame('Contact', $form['title']);
        $this->assertSame('email', $form['fields'][0]['type']);
        $this->assertSame('Your email', $form['fields'][0]['label']);
    }

    public function test_get_missing_form_returns_null(): void
    {
        $out = (new Ninja_Forms_Integration())->handle_read([
            'operation' => 'get-form',
            'args'      => ['form_id' => 999],
        ]);
        $this->assertNull($out['result']['form']);
    }
}
