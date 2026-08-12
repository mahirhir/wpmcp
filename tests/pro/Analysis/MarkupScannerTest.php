<?php

namespace WPMCP\Tests\Pro\Analysis;

use WPMCP\Tools\Analysis\Markup_Scanner;

/**
 * The fixers are only as safe as this tokenizer: every guarantee about
 * "we rewrite one attribute and leave every other byte alone" reduces to
 * offsets being correct and comments (Gutenberg block delimiters) being
 * skipped rather than parsed.
 */
class MarkupScannerTest extends \WP_UnitTestCase
{
    public function test_records_offsets_that_round_trip_the_source(): void
    {
        $html = '<p class="lead">Hello <em>there</em></p>';
        $tags = Markup_Scanner::tags($html);

        $this->assertCount(2, $tags);
        foreach ($tags as $tag) {
            $this->assertSame(
                $tag['source'],
                substr($html, $tag['offset'], $tag['length']),
                'Recorded offset/length must slice exactly the open tag out of the source.'
            );
        }
    }

    public function test_skips_comments_so_block_delimiters_are_never_parsed(): void
    {
        $html = '<!-- wp:image {"id":7} --><figure><img src="a.jpg"></figure><!-- /wp:image -->';
        $names = array_column(Markup_Scanner::tags($html), 'name');

        $this->assertSame(['figure', 'img'], $names);
    }

    public function test_a_tag_commented_out_is_not_a_tag(): void
    {
        $tags = Markup_Scanner::tags('<!-- <img src="ghost.jpg"> --><img src="real.jpg">');

        $this->assertCount(1, $tags);
        $this->assertSame('real.jpg', $tags[0]['attributes']['src']);
    }

    public function test_tracks_parent_and_inner_content(): void
    {
        $tags = Markup_Scanner::tags('<div><span>inner text</span></div>');

        $this->assertNull($tags[0]['parent']);
        $this->assertSame(0, $tags[1]['parent']);
        $this->assertSame('inner text', $tags[1]['inner']);
        $this->assertSame('<span>inner text</span>', $tags[0]['inner']);
    }

    public function test_void_elements_do_not_open_a_scope(): void
    {
        $tags = Markup_Scanner::tags('<p><br><img src="a.jpg"><b>bold</b></p>');
        $byName = array_column($tags, 'parent', 'name');

        $this->assertSame(0, $byName['br']);
        $this->assertSame(0, $byName['img']);
        $this->assertSame(0, $byName['b'], 'A <br> must not become the parent of what follows it.');
    }

    public function test_location_counts_per_tag_name(): void
    {
        $tags = Markup_Scanner::tags('<img src="a"><p>x</p><img src="b">');

        $this->assertSame(['img[1]', 'p[1]', 'img[2]'], array_column($tags, 'location'));
    }

    public function test_attribute_value_containing_a_bracket_does_not_truncate_the_tag(): void
    {
        $tags = Markup_Scanner::tags('<img src="a.jpg" alt="5 > 4 always"><p>after</p>');

        $this->assertSame('5 > 4 always', $tags[0]['attributes']['alt']);
        $this->assertSame('p', $tags[1]['name']);
    }

    public function test_stray_close_tag_does_not_corrupt_nesting(): void
    {
        $tags = Markup_Scanner::tags('</div><div><span>x</span></div>');

        $this->assertSame(['div', 'span'], array_column($tags, 'name'));
        $this->assertSame(0, $tags[1]['parent']);
    }

    public function test_with_attribute_replaces_in_place_and_appends_when_absent(): void
    {
        $this->assertSame(
            '<img src="a.jpg" alt="New text" class="x">',
            Markup_Scanner::with_attribute('<img src="a.jpg" alt="old" class="x">', 'alt', 'New text')
        );
        $this->assertSame(
            '<img src="a.jpg" alt="New text">',
            Markup_Scanner::with_attribute('<img src="a.jpg">', 'alt', 'New text')
        );
        $this->assertSame(
            '<img src="a.jpg" alt="New text" />',
            Markup_Scanner::with_attribute('<img src="a.jpg" />', 'alt', 'New text')
        );
    }

    public function test_with_attribute_escapes_and_never_expands_backreferences(): void
    {
        $out = Markup_Scanner::with_attribute('<img src="a.jpg" alt="old">', 'alt', 'A $1 "deal" \\1');

        $this->assertStringContainsString('$1', $out);
        $this->assertStringContainsString('\\1', $out);
        $this->assertStringContainsString('&quot;deal&quot;', $out);
    }

    public function test_style_helpers_replace_one_declaration_and_append_another(): void
    {
        $this->assertSame(
            ['color' => '#111', 'font-weight' => 'bold'],
            Markup_Scanner::parse_style('color: #111; font-weight: bold;')
        );
        $this->assertSame(
            'color:#595959; font-weight: bold',
            Markup_Scanner::with_style_property('color:#777777; font-weight: bold', 'color', '#595959')
        );
        $this->assertSame(
            'font-weight:bold;color:#595959',
            Markup_Scanner::with_style_property('font-weight:bold;', 'color', '#595959')
        );
    }

    public function test_splice_applies_multiple_edits_without_shifting_offsets(): void
    {
        $html  = 'AAA BBB CCC';
        $edits = [
            ['offset' => 0, 'length' => 3, 'replacement' => 'first'],
            ['offset' => 8, 'length' => 3, 'replacement' => 'third'],
            ['offset' => 4, 'length' => 3, 'replacement' => 'second'],
        ];

        $this->assertSame('first second third', Markup_Scanner::splice($html, $edits));
    }

    public function test_empty_input_yields_no_tags(): void
    {
        $this->assertSame([], Markup_Scanner::tags(''));
        $this->assertSame([], Markup_Scanner::parse_attributes('   '));
    }
}
