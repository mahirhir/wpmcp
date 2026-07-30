<?php

namespace WPMCP\Tests\Free\SEO;

use WPMCP\Tools\SEO\SEO_Adapter;

/**
 * The SEO Framework support in the SEO adapter. Forced through the WPMCP_TESTING
 * seam (Yoast is the detected plugin in the harness), this verifies the
 * _genesis_* postmeta mapping, the '1' noindex/nofollow encoding (like Yoast),
 * and that the absent focus-keyword slot is skipped end to end via
 * update_meta -> raw meta -> get_meta.
 */
class SeoFrameworkAdapterTest extends \WP_UnitTestCase
{
    protected function tearDown(): void
    {
        SEO_Adapter::set_active_plugin_for_tests(null);
        parent::tearDown();
    }

    public function test_writes_genesis_keys_and_reads_back(): void
    {
        SEO_Adapter::set_active_plugin_for_tests('seoframework');
        $post_id = self::factory()->post->create();

        SEO_Adapter::update_meta($post_id, [
            'title'         => 'TSF Title',
            'description'   => 'TSF Desc',
            'focus_keyword' => 'ignored',
            'canonical'     => 'https://example.com/y',
            'noindex'       => true,
            'nofollow'      => true,
        ]);

        $this->assertSame('TSF Title', get_post_meta($post_id, '_genesis_title', true));
        $this->assertSame('TSF Desc', get_post_meta($post_id, '_genesis_description', true));
        $this->assertSame('https://example.com/y', get_post_meta($post_id, '_genesis_canonical_uri', true));
        $this->assertSame('1', get_post_meta($post_id, '_genesis_noindex', true));
        $this->assertSame('1', get_post_meta($post_id, '_genesis_nofollow', true));

        $meta = SEO_Adapter::get_meta($post_id);
        $this->assertSame('TSF Title', $meta['title']);
        $this->assertSame('', $meta['focus_keyword']);
        $this->assertTrue($meta['noindex']);
        $this->assertTrue($meta['nofollow']);
    }

    public function test_focus_keyword_is_never_written_to_an_empty_key(): void
    {
        SEO_Adapter::set_active_plugin_for_tests('seoframework');
        $post_id = self::factory()->post->create();

        SEO_Adapter::update_meta($post_id, ['focus_keyword' => 'should not persist']);

        // Nothing was written under an empty meta key (would have polluted all meta).
        $meta = SEO_Adapter::get_meta($post_id);
        $this->assertSame('', $meta['focus_keyword']);
    }

    public function test_plugin_info_reports_seoframework(): void
    {
        SEO_Adapter::set_active_plugin_for_tests('seoframework');
        $info = SEO_Adapter::plugin_info();
        $this->assertSame('seoframework', $info['plugin']);
        $this->assertSame('The SEO Framework', $info['name']);
    }
}
