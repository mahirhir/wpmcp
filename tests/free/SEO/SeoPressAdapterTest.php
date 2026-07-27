<?php

namespace WPMCP\Tests\Free\SEO;

use WPMCP\Tools\SEO\SEO_Adapter;

/**
 * SEOPress support in the SEO adapter. SEOPress cannot be the detected plugin
 * while Yoast is active in the harness, so these tests force the active plugin
 * through the WPMCP_TESTING seam and verify the SEOPress postmeta mapping and
 * its 'yes' noindex/nofollow encoding (verified against SEOPress source) end
 * to end via update_meta -> raw meta -> get_meta.
 */
class SeoPressAdapterTest extends \WP_UnitTestCase
{
    protected function tearDown(): void
    {
        SEO_Adapter::set_active_plugin_for_tests(null);
        parent::tearDown();
    }

    public function test_writes_seopress_keys_and_yes_robots_then_reads_back(): void
    {
        SEO_Adapter::set_active_plugin_for_tests('seopress');
        $post_id = self::factory()->post->create();

        SEO_Adapter::update_meta($post_id, [
            'title'         => 'SP Title',
            'description'   => 'SP Desc',
            'focus_keyword' => 'seopress kw',
            'canonical'     => 'https://example.com/x',
            'noindex'       => true,
            'nofollow'      => true,
        ]);

        // Stored under SEOPress's own keys, robots encoded as 'yes'.
        $this->assertSame('SP Title', get_post_meta($post_id, '_seopress_titles_title', true));
        $this->assertSame('SP Desc', get_post_meta($post_id, '_seopress_titles_desc', true));
        $this->assertSame('seopress kw', get_post_meta($post_id, '_seopress_analysis_target_kw', true));
        $this->assertSame('https://example.com/x', get_post_meta($post_id, '_seopress_robots_canonical', true));
        $this->assertSame('yes', get_post_meta($post_id, '_seopress_robots_index', true));
        $this->assertSame('yes', get_post_meta($post_id, '_seopress_robots_follow', true));

        // Normalized back to booleans on read.
        $meta = SEO_Adapter::get_meta($post_id);
        $this->assertSame('SP Title', $meta['title']);
        $this->assertTrue($meta['noindex']);
        $this->assertTrue($meta['nofollow']);
    }

    public function test_clearing_robots_writes_empty_and_reads_false(): void
    {
        SEO_Adapter::set_active_plugin_for_tests('seopress');
        $post_id = self::factory()->post->create();

        SEO_Adapter::update_meta($post_id, [ 'noindex' => true, 'nofollow' => true ]);
        SEO_Adapter::update_meta($post_id, [ 'noindex' => false, 'nofollow' => false ]);

        $this->assertSame('', get_post_meta($post_id, '_seopress_robots_index', true));
        $this->assertSame('', get_post_meta($post_id, '_seopress_robots_follow', true));
        $meta = SEO_Adapter::get_meta($post_id);
        $this->assertFalse($meta['noindex']);
        $this->assertFalse($meta['nofollow']);
    }

    public function test_plugin_info_reports_seopress(): void
    {
        SEO_Adapter::set_active_plugin_for_tests('seopress');
        $info = SEO_Adapter::plugin_info();
        $this->assertSame('seopress', $info['plugin']);
        $this->assertSame('SEOPress', $info['name']);
    }
}
