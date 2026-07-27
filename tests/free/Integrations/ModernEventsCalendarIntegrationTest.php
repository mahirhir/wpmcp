<?php

namespace WPMCP\Tests\Free\Integrations;

use WPMCP\Integrations\Modern_Events_Calendar_Integration;

/**
 * Modern Events Calendar read integration. Exercised against a real
 * `mec-events` CPT registered in the test with `mec_*` postmeta (MEC's actual
 * storage), so the query and meta reads are genuinely verified.
 */
class ModernEventsCalendarIntegrationTest extends \WP_UnitTestCase
{
    private Modern_Events_Calendar_Integration $integration;

    protected function setUp(): void
    {
        parent::setUp();
        register_post_type('mec-events', [ 'public' => true, 'label' => 'Events' ]);
        $this->integration = new Modern_Events_Calendar_Integration();
    }

    protected function tearDown(): void
    {
        unregister_post_type('mec-events');
        parent::tearDown();
    }

    private function make_event(string $title, string $start, string $end): int
    {
        $id = self::factory()->post->create([ 'post_type' => 'mec-events', 'post_title' => $title ]);
        update_post_meta($id, 'mec_start_date', $start);
        update_post_meta($id, 'mec_end_date', $end);
        update_post_meta($id, 'mec_start_time', '18:00');
        update_post_meta($id, 'mec_location_id', 7);
        return $id;
    }

    public function test_available_when_mec_cpt_registered(): void
    {
        $this->assertTrue($this->integration->is_available());
    }

    public function test_list_events_returns_titles_and_dates(): void
    {
        $this->make_event('Launch Party', '2026-08-01', '2026-08-01');
        $this->make_event('Workshop', '2026-08-10', '2026-08-11');

        $out = $this->integration->handle_read([ 'operation' => 'list-events' ]);
        $this->assertArrayNotHasKey('error', $out);
        $this->assertSame(2, $out['result']['total']);
        $titles = array_column($out['result']['events'], 'title');
        $this->assertContains('Launch Party', $titles);
        $this->assertContains('Workshop', $titles);
        $this->assertNotEmpty($out['result']['events'][0]['start_date']);
    }

    public function test_get_event_returns_curated_schedule(): void
    {
        $id  = $this->make_event('Launch Party', '2026-08-01', '2026-08-02');
        $out = $this->integration->handle_read([ 'operation' => 'get-event', 'args' => [ 'event_id' => $id ] ]);

        $this->assertSame('Launch Party', $out['result']['event']['title']);
        $this->assertSame('2026-08-01', $out['result']['event']['schedule']['start_date']);
        $this->assertSame('2026-08-02', $out['result']['event']['schedule']['end_date']);
        $this->assertSame('18:00', $out['result']['event']['schedule']['start_time']);
        $this->assertSame('7', (string) $out['result']['event']['schedule']['location_id']);
    }

    public function test_get_event_null_for_non_event_post(): void
    {
        $page = self::factory()->post->create([ 'post_type' => 'page' ]);
        $out  = $this->integration->handle_read([ 'operation' => 'get-event', 'args' => [ 'event_id' => $page ] ]);
        $this->assertNull($out['result']['event']);
    }
}
