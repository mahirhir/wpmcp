<?php

namespace WPMCP\Tests\Free\Redirects;

use WPMCP\Tools\Redirects\Broken_Link_Scanner;
use WPMCP\Tools\Redirects\Redirect_Store;

/**
 * Classification of internal links (issue #128).
 *
 * Half of these tests are about NOT reporting things. A broken-link scanner
 * that flags working category, tag, author, date and archive URLs will get an
 * agent to "fix" links that were never broken, which is a worse outcome than
 * shipping no scanner at all.
 */
class BrokenLinkScannerTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        global $wpdb;
        $wpdb->query('DELETE FROM ' . Redirect_Store::table_name());
        $this->set_permalink_structure('/%postname%/');
    }

    private function post_linking_to(string ...$hrefs): \WP_Post
    {
        $content = '';
        foreach ($hrefs as $href) {
            $content .= sprintf('<p><a href="%s">link</a></p>', $href);
        }

        return get_post(self::factory()->post->create([
            'post_status'  => 'publish',
            'post_title'   => 'Linking post',
            'post_content' => $content,
        ]));
    }

    public function test_a_link_to_a_published_post_is_not_reported(): void
    {
        $target = self::factory()->post->create(['post_name' => 'target', 'post_status' => 'publish']);
        $post   = $this->post_linking_to(get_permalink($target));

        $this->assertSame([], Broken_Link_Scanner::scan_post($post));
    }

    public function test_a_link_to_nothing_is_reported_as_dead(): void
    {
        $post = $this->post_linking_to('/no-such-page');

        $findings = Broken_Link_Scanner::scan_post($post);

        $this->assertCount(1, $findings);
        $this->assertSame(Broken_Link_Scanner::ISSUE_DEAD, $findings[0]['issue']);
        $this->assertSame('/no-such-page', $findings[0]['path']);
        $this->assertSame('create-redirect', $findings[0]['suggested_action']);
        $this->assertSame($post->ID, $findings[0]['post_id']);
    }

    public function test_a_link_to_a_draft_is_reported_as_not_public_yet(): void
    {
        $draft = self::factory()->post->create(['post_name' => 'coming-soon', 'post_status' => 'draft']);
        $post  = $this->post_linking_to('/coming-soon');

        $findings = Broken_Link_Scanner::scan_post($post);

        $this->assertCount(1, $findings);
        $this->assertSame(Broken_Link_Scanner::ISSUE_UNPUBLISHED, $findings[0]['issue']);
        $this->assertSame($draft, $findings[0]['target_post_id']);
        $this->assertSame('draft', $findings[0]['target_status']);
    }

    public function test_a_link_through_a_redirect_is_reported_so_it_can_be_pointed_straight_at_the_target(): void
    {
        $target = self::factory()->post->create(['post_name' => 'destination', 'post_status' => 'publish']);
        Redirect_Store::insert(['source_path' => '/moved', 'target_post_id' => $target]);
        $post = $this->post_linking_to('/moved');

        $findings = Broken_Link_Scanner::scan_post($post);

        $this->assertCount(1, $findings);
        $this->assertSame(Broken_Link_Scanner::ISSUE_REDIRECTED, $findings[0]['issue']);
        $this->assertSame(get_permalink($target), $findings[0]['target']);
        $this->assertSame('update-link', $findings[0]['suggested_action']);
    }

    public function test_a_disabled_redirect_does_not_excuse_a_dead_link(): void
    {
        Redirect_Store::insert(['source_path' => '/moved', 'target_url' => '/elsewhere', 'enabled' => 0]);
        $post = $this->post_linking_to('/moved');

        $findings = Broken_Link_Scanner::scan_post($post);

        $this->assertSame(Broken_Link_Scanner::ISSUE_DEAD, $findings[0]['issue']);
    }

    /** @return array<string, array{0:string}> */
    public function ignored_hrefs(): array
    {
        return [
            'external site' => ['https://elsewhere.test/page'],
            'mailto'        => ['mailto:hi@example.org'],
            'tel'           => ['tel:+15551234567'],
            'fragment'      => ['#section'],
            'home'          => ['/'],
        ];
    }

    /** @dataProvider ignored_hrefs */
    public function test_links_that_are_not_internal_content_are_never_reported(string $href): void
    {
        $this->assertSame([], Broken_Link_Scanner::scan_post($this->post_linking_to($href)));
    }

    /**
     * A javascript: href set in memory, because WordPress's own content
     * sanitization strips the scheme on save: the scheme guard has to hold
     * for content that reached the database some other way (an import, a
     * direct wpdb write, an unfiltered_html author).
     */
    public function test_a_script_scheme_href_is_never_classified(): void
    {
        $post               = $this->post_linking_to('/placeholder');
        $post->post_content = '<a href="javascript:alert(1)">x</a>';

        $this->assertSame([], Broken_Link_Scanner::scan_post($post));
    }

    /** @return array<string, array{0:string}> */
    public function archive_paths(): array
    {
        return [
            'category archive' => ['/category/news'],
            'tag archive'      => ['/tag/updates'],
            'author archive'   => ['/author/admin'],
            'date archive'     => ['/2026/07'],
            'paged archive'    => ['/page/2'],
            'feed'             => ['/feed'],
        ];
    }

    /** @dataProvider archive_paths */
    public function test_archive_urls_are_not_mistaken_for_dead_links(string $path): void
    {
        $this->assertSame([], Broken_Link_Scanner::scan_post($this->post_linking_to($path)));
    }

    public function test_the_same_path_linked_twice_is_only_reported_once(): void
    {
        $post = $this->post_linking_to('/no-such-page', '/no-such-page/', 'http://example.org/no-such-page');

        $this->assertCount(1, Broken_Link_Scanner::scan_post($post));
    }

    public function test_scan_posts_walks_a_batch_and_skips_ids_that_are_gone(): void
    {
        $one = $this->post_linking_to('/missing-one');
        $two = $this->post_linking_to('/missing-two');

        $findings = Broken_Link_Scanner::scan_posts([$one->ID, $two->ID, 999999]);

        $this->assertCount(2, $findings);
        $this->assertSame(['/missing-one', '/missing-two'], array_column($findings, 'path'));
    }

    public function test_scannable_ids_pages_deterministically_and_counts_the_whole_set(): void
    {
        $ids = [];
        for ($i = 0; $i < 3; $i++) {
            $ids[] = self::factory()->post->create(['post_status' => 'publish']);
        }
        sort($ids);

        $this->assertSame($ids, Broken_Link_Scanner::scannable_ids(['post'], 10));
        $this->assertSame([$ids[1]], Broken_Link_Scanner::scannable_ids(['post'], 1, 1));
        $this->assertSame(3, Broken_Link_Scanner::scannable_total(['post']));
    }

    public function test_a_draft_post_is_never_scanned(): void
    {
        self::factory()->post->create(['post_status' => 'draft']);

        $this->assertSame(0, Broken_Link_Scanner::scannable_total(['post']));
    }
}
