<?php

namespace WPMCP\Tests\Free\Search;

use WPMCP\Tools\Search\Content_Indexer;
use WPMCP\Tools\Search\Search_Ranker;

/**
 * Unit-level guards on the parts of search that must be deterministic:
 * tokenisation, relevance scoring, snippet extraction, and the text
 * normalisation the indexer applies before anything is stored (issue #83).
 *
 * These are the pieces we deliberately kept OUT of MySQL FULLTEXT so that
 * relevance is reproducible on every host; that only pays off if it is pinned
 * by tests, which is what this file is.
 */
class SearchRankerTest extends \WP_UnitTestCase
{
    public function test_tokenize_lowercases_dedupes_and_drops_noise(): void
    {
        $this->assertSame(
            ['pricing', 'table'],
            Search_Ranker::tokenize('  Pricing, TABLE! pricing? ')
        );
        $this->assertSame([], Search_Ranker::tokenize('a - ?'));
    }

    public function test_tokenize_caps_the_term_count(): void
    {
        $query = implode(' ', array_map(static fn (int $i): string => "term{$i}", range(1, 30)));

        $this->assertCount(Search_Ranker::MAX_TERMS, Search_Ranker::tokenize($query));
    }

    public function test_term_coverage_beats_repetition_of_one_term(): void
    {
        $terms = ['blue', 'button'];

        $covers_both = Search_Ranker::score_fragment('a blue button', $terms, 'blue button', 10);
        $repeats_one = Search_Ranker::score_fragment('blue blue blue blue blue', $terms, 'blue button', 10);

        $this->assertGreaterThan($repeats_one, $covers_both);
    }

    public function test_field_weight_scales_the_score(): void
    {
        $light = Search_Ranker::score_fragment('checkout', ['checkout'], 'checkout', 10);
        $heavy = Search_Ranker::score_fragment('checkout', ['checkout'], 'checkout', 50);

        $this->assertEqualsWithDelta($light * 5, $heavy, 0.0001);
    }

    public function test_exact_phrase_earns_a_bonus_over_scattered_terms(): void
    {
        $terms  = ['team', 'testimonials'];
        $phrase = 'team testimonials';

        $exact     = Search_Ranker::score_fragment('our team testimonials block', $terms, $phrase, 10);
        $scattered = Search_Ranker::score_fragment('our team writes the testimonials', $terms, $phrase, 10);

        $this->assertGreaterThan($scattered, $exact);
    }

    public function test_whole_word_match_beats_a_substring_match(): void
    {
        $whole     = Search_Ranker::score_fragment('add to cart', ['cart'], 'cart', 10);
        $substring = Search_Ranker::score_fragment('cartography', ['cart'], 'cart', 10);

        $this->assertGreaterThan($substring, $whole);
    }

    public function test_a_fragment_without_any_term_scores_zero(): void
    {
        $this->assertSame(0.0, Search_Ranker::score_fragment('nothing relevant', ['unicorn'], 'unicorn', 50));
        $this->assertSame(0.0, Search_Ranker::score_fragment('', ['unicorn'], 'unicorn', 50));
        $this->assertSame(0.0, Search_Ranker::score_fragment('unicorn', [], '', 50));
    }

    public function test_short_content_is_returned_as_its_own_snippet(): void
    {
        $this->assertSame('Refund Policy', Search_Ranker::snippet('Refund Policy', ['refund']));
        $this->assertSame('', Search_Ranker::snippet('   ', ['refund']));
    }

    public function test_snippet_is_windowed_around_the_first_match(): void
    {
        $content = str_repeat('filler ', 100) . 'NEEDLE ' . str_repeat('filler ', 100);

        $snippet = Search_Ranker::snippet($content, ['needle']);

        $this->assertStringContainsString('NEEDLE', $snippet);
        $this->assertStringStartsWith('...', $snippet);
        $this->assertStringEndsWith('...', $snippet);
        $this->assertLessThanOrEqual(Search_Ranker::SNIPPET_MAX_CHARS + 6, strlen($snippet));
    }

    public function test_snippet_without_a_match_falls_back_to_the_head_of_the_content(): void
    {
        $content = str_repeat('alpha ', 100);

        $snippet = Search_Ranker::snippet($content, ['omega']);

        $this->assertStringStartsWith('alpha', $snippet);
    }

    public function test_normalize_strips_markup_shortcodes_and_collapses_whitespace(): void
    {
        $this->assertSame(
            'Hello world',
            Content_Indexer::normalize("<p>Hello</p>\n\n  <span>world</span>")
        );
        $this->assertSame(
            'Inner copy',
            Content_Indexer::normalize('[et_pb_text admin_label="Text"]Inner copy[/et_pb_text]')
        );
        $this->assertSame('Ben & Jerry', Content_Indexer::normalize('Ben &amp; Jerry'));
    }
}
