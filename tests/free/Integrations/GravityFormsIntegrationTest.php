<?php

namespace WPMCP\Tests\Free\Integrations;

use WPMCP\Integrations\Gravity_Forms_Integration;

require_once __DIR__ . '/../../support/gfapi-stub.php';

/**
 * Gravity Forms read dispatcher. Exercised against a faithful GFAPI double
 * (see tests/support/gfapi-stub.php) reproducing Gravity Forms 2.9's public
 * API contracts, since Gravity Forms is paid and cannot install from wp.org.
 */
class GravityFormsIntegrationTest extends \WP_UnitTestCase
{
    private Gravity_Forms_Integration $integration;

    protected function setUp(): void
    {
        parent::setUp();
        \GFAPI::reset();
        \GFAPI::$forms = [
            1 => [ 'id' => 1, 'title' => 'Contact', 'is_active' => true, 'date_created' => '2026-01-01 00:00:00', 'fields' => [ [ 'id' => 1, 'type' => 'text', 'label' => 'Name' ], [ 'id' => 2, 'type' => 'email', 'label' => 'Email' ] ] ],
        ];
        \GFAPI::$entries = [
            10 => [ 'id' => 10, 'form_id' => 1, 'status' => 'active', '1' => 'Ada', '2' => 'ada@example.com' ],
            11 => [ 'id' => 11, 'form_id' => 1, 'status' => 'spam', '1' => 'Spammer', '2' => 'x@spam.test' ],
        ];
        \GFAPI::$notes = [
            [ 'id' => 100, 'entry_id' => 10, 'value' => 'Followed up by phone' ],
        ];
        $this->integration = new Gravity_Forms_Integration();
    }

    protected function tearDown(): void
    {
        \GFAPI::reset();
        parent::tearDown();
    }

    public function test_reports_available_and_lists_operations(): void
    {
        $this->assertTrue($this->integration->is_available());

        $out = $this->integration->handle_read([ 'operation' => 'list-operations' ]);
        $this->assertArrayNotHasKey('error', $out);
        $names = array_column($out['result']['operations'], 'name');
        foreach ([ 'list-forms', 'get-form', 'list-entries', 'get-entry', 'get-notes' ] as $op) {
            $this->assertContains($op, $names, "catalog should advertise {$op}");
        }
    }

    public function test_list_forms_returns_summary_with_counts(): void
    {
        $out = $this->integration->handle_read([ 'operation' => 'list-forms' ]);

        $this->assertArrayNotHasKey('error', $out);
        $this->assertSame(1, $out['result']['total']);
        $form = $out['result']['forms'][0];
        $this->assertSame(1, $form['id']);
        $this->assertSame('Contact', $form['title']);
        $this->assertTrue($form['is_active']);
        $this->assertSame(2, $form['field_count']);
        $this->assertSame(2, $form['entry_count']);
    }

    public function test_get_form_returns_full_form_and_null_when_missing(): void
    {
        $out = $this->integration->handle_read([ 'operation' => 'get-form', 'args' => [ 'form_id' => 1 ] ]);
        $this->assertArrayNotHasKey('error', $out);
        $this->assertSame('Contact', $out['result']['form']['title']);
        $this->assertCount(2, $out['result']['form']['fields']);

        $missing = $this->integration->handle_read([ 'operation' => 'get-form', 'args' => [ 'form_id' => 999 ] ]);
        $this->assertNull($missing['result']['form']);
    }

    public function test_list_entries_pages_and_filters_by_status(): void
    {
        $all = $this->integration->handle_read([ 'operation' => 'list-entries', 'args' => [ 'form_id' => 1 ] ]);
        $this->assertSame(2, $all['result']['total']);
        $this->assertCount(2, $all['result']['entries']);

        $spam = $this->integration->handle_read([ 'operation' => 'list-entries', 'args' => [ 'form_id' => 1, 'status' => 'spam' ] ]);
        $this->assertSame(1, $spam['result']['total']);
        $this->assertSame('Spammer', $spam['result']['entries'][0]['1']);

        $paged = $this->integration->handle_read([ 'operation' => 'list-entries', 'args' => [ 'form_id' => 1, 'page_size' => 1, 'offset' => 1 ] ]);
        $this->assertCount(1, $paged['result']['entries']);
        $this->assertSame(2, $paged['result']['total']);
    }

    public function test_get_entry_and_notes(): void
    {
        $entry = $this->integration->handle_read([ 'operation' => 'get-entry', 'args' => [ 'entry_id' => 10 ] ]);
        $this->assertSame('ada@example.com', $entry['result']['entry']['2']);

        $missing = $this->integration->handle_read([ 'operation' => 'get-entry', 'args' => [ 'entry_id' => 404 ] ]);
        $this->assertNull($missing['result']['entry']);

        $notes = $this->integration->handle_read([ 'operation' => 'get-notes', 'args' => [ 'entry_id' => 10 ] ]);
        $this->assertCount(1, $notes['result']['notes']);
        $this->assertSame('Followed up by phone', $notes['result']['notes'][0]['value']);
    }

    public function test_rejects_unknown_operation(): void
    {
        $out = $this->integration->handle_read([ 'operation' => 'nope' ]);
        $this->assertArrayHasKey('error', $out);
    }

    public function test_missing_required_arg_is_rejected_before_dispatch(): void
    {
        // get-form requires form_id; omit it.
        $out = $this->integration->handle_read([ 'operation' => 'get-form' ]);
        $this->assertArrayHasKey('error', $out);
    }
}
