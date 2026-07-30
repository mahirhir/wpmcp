<?php

namespace WPMCP\Tests\Free\SEO;

use WPMCP\Tools\SEO\SEO_Adapter;

/**
 * SureRank support in the SEO adapter. SureRank stores every SEO field inside a
 * single serialized `_surerank_meta` post-meta array (sub-keys page_title,
 * page_description, canonical_url, and post_no_index / post_no_follow encoded as
 * 'yes'/'no'), so it needs dedicated read/write branches rather than the
 * per-field key map the other plugins use. Forced through the WPMCP_TESTING
 * seam and verified end to end via update_meta -> raw array -> get_meta.
 */
class SureRankAdapterTest extends \WP_UnitTestCase
{
    protected function tearDown(): void
    {
        SEO_Adapter::set_active_plugin_for_tests(null);
        parent::tearDown();
    }

    public function test_writes_into_single_surerank_array_and_reads_back(): void
    {
        SEO_Adapter::set_active_plugin_for_tests('surerank');
        $post_id = self::factory()->post->create();

        SEO_Adapter::update_meta($post_id, [
            'title'       => 'SR Title',
            'description' => 'SR Desc',
            'canonical'   => 'https://example.com/z',
            'noindex'     => true,
            'nofollow'    => true,
        ]);

        // Everything lands in the one _surerank_meta array, robots as yes/no.
        $stored = get_post_meta($post_id, '_surerank_meta', true);
        $this->assertIsArray($stored);
        $this->assertSame('SR Title', $stored['page_title']);
        $this->assertSame('SR Desc', $stored['page_description']);
        $this->assertSame('https://example.com/z', $stored['canonical_url']);
        $this->assertSame('yes', $stored['post_no_index']);
        $this->assertSame('yes', $stored['post_no_follow']);

        $meta = SEO_Adapter::get_meta($post_id);
        $this->assertSame('SR Title', $meta['title']);
        $this->assertSame('SR Desc', $meta['description']);
        $this->assertTrue($meta['noindex']);
        $this->assertTrue($meta['nofollow']);
    }

    public function test_partial_update_preserves_other_surerank_keys(): void
    {
        SEO_Adapter::set_active_plugin_for_tests('surerank');
        $post_id = self::factory()->post->create();

        SEO_Adapter::update_meta($post_id, ['title' => 'First']);
        SEO_Adapter::update_meta($post_id, ['description' => 'Second']);

        $stored = get_post_meta($post_id, '_surerank_meta', true);
        $this->assertSame('First', $stored['page_title']);
        $this->assertSame('Second', $stored['page_description']);
    }

    public function test_clearing_robots_reads_false(): void
    {
        SEO_Adapter::set_active_plugin_for_tests('surerank');
        $post_id = self::factory()->post->create();

        SEO_Adapter::update_meta($post_id, ['noindex' => true, 'nofollow' => true]);
        SEO_Adapter::update_meta($post_id, ['noindex' => false, 'nofollow' => false]);

        $meta = SEO_Adapter::get_meta($post_id);
        $this->assertFalse($meta['noindex']);
        $this->assertFalse($meta['nofollow']);
    }

    public function test_plugin_info_reports_surerank(): void
    {
        SEO_Adapter::set_active_plugin_for_tests('surerank');
        $info = SEO_Adapter::plugin_info();
        $this->assertSame('surerank', $info['plugin']);
        $this->assertSame('SureRank', $info['name']);
    }
}
