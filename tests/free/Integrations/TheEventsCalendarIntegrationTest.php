<?php

namespace WPMCP\Tests\Free\Integrations;

use WPMCP\Integrations\The_Events_Calendar_Integration;

/**
 * The Events Calendar read integration, exercised against a real tribe_events
 * CPT with _Event* postmeta (TEC's actual, underscore-prefixed storage, which
 * the generic get-post tool hides, so a curated read is needed).
 */
class TheEventsCalendarIntegrationTest extends \WP_UnitTestCase
{
    private The_Events_Calendar_Integration $integration;

    protected function setUp(): void
    {
        parent::setUp();
        register_post_type('tribe_events', [ 'public' => true, 'label' => 'Events' ]);
        $this->integration = new The_Events_Calendar_Integration();
    }

    protected function tearDown(): void
    {
        unregister_post_type('tribe_events');
        parent::tearDown();
    }

    private function make_event(string $title, string $start, string $end): int
    {
        $id = self::factory()->post->create([ 'post_type' => 'tribe_events', 'post_title' => $title ]);
        update_post_meta($id, '_EventStartDate', $start);
        update_post_meta($id, '_EventEndDate', $end);
        update_post_meta($id, '_EventVenueID', 12);
        update_post_meta($id, '_EventCost', '25');
        return $id;
    }

    public function test_available_when_tec_cpt_registered(): void
    {
        $this->assertTrue($this->integration->is_available());
    }

    public function test_list_events(): void
    {
        $this->make_event('Gala', '2026-09-01 18:00:00', '2026-09-01 22:00:00');
        $this->make_event('Fair', '2026-09-10 09:00:00', '2026-09-10 17:00:00');

        $out = $this->integration->handle_read([ 'operation' => 'list-events' ]);
        $this->assertSame(2, $out['result']['total']);
        $titles = array_column($out['result']['events'], 'title');
        $this->assertContains('Gala', $titles);
        $this->assertNotEmpty($out['result']['events'][0]['start_date']);
    }

    public function test_get_event_surfaces_underscore_meta_schedule(): void
    {
        $id  = $this->make_event('Gala', '2026-09-01 18:00:00', '2026-09-01 22:00:00');
        $out = $this->integration->handle_read([ 'operation' => 'get-event', 'args' => [ 'event_id' => $id ] ]);

        $this->assertSame('Gala', $out['result']['event']['title']);
        $this->assertSame('2026-09-01 18:00:00', $out['result']['event']['schedule']['start_date']);
        $this->assertSame('2026-09-01 22:00:00', $out['result']['event']['schedule']['end_date']);
        $this->assertSame('12', (string) $out['result']['event']['schedule']['venue_id']);
        $this->assertSame('25', (string) $out['result']['event']['schedule']['cost']);
    }

    public function test_get_event_null_for_non_event(): void
    {
        $page = self::factory()->post->create([ 'post_type' => 'page' ]);
        $out  = $this->integration->handle_read([ 'operation' => 'get-event', 'args' => [ 'event_id' => $page ] ]);
        $this->assertNull($out['result']['event']);
    }
}
