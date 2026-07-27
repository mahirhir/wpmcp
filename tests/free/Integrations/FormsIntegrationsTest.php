<?php

namespace WPMCP\Tests\Free\Integrations;

use WPMCP\Integrations\Formidable_Integration;
use WPMCP\Integrations\Contact_Form_7_Integration;
use WPMCP\Integrations\WPForms_Integration;

require_once __DIR__ . '/../../support/forms-stubs.php';

/**
 * The Formidable, Contact Form 7, and WPForms read integrations, exercised
 * against faithful doubles of each plugin's public API (see
 * tests/support/forms-stubs.php). Live plugins stay production-verified.
 */
class FormsIntegrationsTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        \FrmForm::$forms = [
            5 => (object) [ 'id' => 5, 'name' => 'Booking', 'form_key' => 'booking' ],
        ];
        \FrmEntry::$entries = [
            50 => (object) [ 'id' => 50, 'form_id' => 5, 'name' => 'e50' ],
            51 => (object) [ 'id' => 51, 'form_id' => 9, 'name' => 'other' ],
        ];
        \WPCF7_ContactForm::$registry = [];
        \WPCF7_ContactForm::seed(7, 'Contact', 'contact-us', [ 'form' => '[text your-name]', 'mail' => [ 'subject' => 'New message' ] ]);
        wpforms()->form->forms = [
            3 => self::wp_post(3, 'Newsletter', wp_json_encode([ 'fields' => [ [ 'id' => 0, 'type' => 'email' ] ] ])),
        ];
    }

    private static function wp_post(int $id, string $title, string $content): \WP_Post
    {
        return new \WP_Post((object) [ 'ID' => $id, 'post_title' => $title, 'post_content' => $content, 'post_type' => 'wpforms' ]);
    }

    // ---- Formidable --------------------------------------------------------
    public function test_formidable_lists_and_reads_forms_and_entries(): void
    {
        $i = new Formidable_Integration();
        $this->assertTrue($i->is_available());

        $forms = $i->handle_read([ 'operation' => 'list-forms' ]);
        $this->assertSame(1, $forms['result']['total']);
        $this->assertSame('Booking', $forms['result']['forms'][0]['name']);

        $form = $i->handle_read([ 'operation' => 'get-form', 'args' => [ 'form_id' => 5 ] ]);
        $this->assertSame('Booking', $form['result']['form']->name);
        $missing = $i->handle_read([ 'operation' => 'get-form', 'args' => [ 'form_id' => 999 ] ]);
        $this->assertNull($missing['result']['form']);

        $entries = $i->handle_read([ 'operation' => 'list-entries', 'args' => [ 'form_id' => 5 ] ]);
        $this->assertSame(1, $entries['result']['total']);
        $entry = $i->handle_read([ 'operation' => 'get-entry', 'args' => [ 'entry_id' => 50 ] ]);
        $this->assertSame(50, $entry['result']['entry']->id);
    }

    // ---- Contact Form 7 ----------------------------------------------------
    public function test_cf7_lists_and_reads_forms_with_markup_and_mail(): void
    {
        $i = new Contact_Form_7_Integration();
        $this->assertTrue($i->is_available());

        $forms = $i->handle_read([ 'operation' => 'list-forms' ]);
        $this->assertSame(1, $forms['result']['total']);
        $this->assertSame('Contact', $forms['result']['forms'][0]['title']);

        $form = $i->handle_read([ 'operation' => 'get-form', 'args' => [ 'form_id' => 7 ] ]);
        $this->assertSame('contact-us', $form['result']['form']['name']);
        $this->assertSame('[text your-name]', $form['result']['form']['form_markup']);
        $this->assertSame('New message', $form['result']['form']['mail']['subject']);

        $missing = $i->handle_read([ 'operation' => 'get-form', 'args' => [ 'form_id' => 404 ] ]);
        $this->assertNull($missing['result']['form']);
    }

    // ---- WPForms -----------------------------------------------------------
    public function test_wpforms_lists_and_reads_forms(): void
    {
        $i = new WPForms_Integration();
        $this->assertTrue($i->is_available());

        $forms = $i->handle_read([ 'operation' => 'list-forms' ]);
        $this->assertSame(1, $forms['result']['total']);
        $this->assertSame('Newsletter', $forms['result']['forms'][0]['title']);

        $form = $i->handle_read([ 'operation' => 'get-form', 'args' => [ 'form_id' => 3 ] ]);
        $this->assertSame('Newsletter', $form['result']['form']['title']);
        $this->assertSame('email', $form['result']['form']['fields'][0]['type']);
    }
}
