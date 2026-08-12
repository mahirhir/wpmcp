<?php

namespace WPMCP\Tests\Pro\Analysis;

use WPMCP\Tools\Analysis\Color_Contrast;
use WPMCP\Tools\Analysis\Contrast_Suggester;

class ContrastSuggesterTest extends \WP_UnitTestCase
{
    public function test_parses_the_color_forms_it_promises(): void
    {
        $this->assertSame('#112233', Contrast_Suggester::parse_color('#112233'));
        $this->assertSame('#112233', Contrast_Suggester::parse_color('  #112233  '));
        $this->assertSame('#ffffff', Contrast_Suggester::parse_color('#FFF'));
        $this->assertSame('#ffffff', Contrast_Suggester::parse_color('white'));
        $this->assertSame('#204080', Contrast_Suggester::parse_color('rgb(32, 64, 128)'));
        $this->assertSame('#204080', Contrast_Suggester::parse_color('rgba(32,64,128,0.5)'));
    }

    public function test_refuses_colors_it_cannot_measure(): void
    {
        $this->assertNull(Contrast_Suggester::parse_color('linear-gradient(#fff, #000)'));
        $this->assertNull(Contrast_Suggester::parse_color('var(--brand)'));
        $this->assertNull(Contrast_Suggester::parse_color('currentColor'));
        $this->assertNull(Contrast_Suggester::parse_color('rebeccapurple'));
        $this->assertNull(Contrast_Suggester::parse_color(''));
        $this->assertNull(
            Contrast_Suggester::parse_color('rgba(0,0,0,0)'),
            'A fully transparent color has no contrast to fix.'
        );
    }

    public function test_reaches_the_target_ratio(): void
    {
        $suggestion = Contrast_Suggester::suggest('#777777', '#ffffff', 4.5);

        $this->assertNotNull($suggestion);
        $this->assertGreaterThanOrEqual(4.5, $suggestion['ratio']);
        $this->assertSame(
            $suggestion['ratio'],
            round((float) Color_Contrast::contrast_ratio($suggestion['hex'], '#ffffff'), 2),
            'The reported ratio must be the ratio of the color actually returned.'
        );
    }

    public function test_preserves_hue_and_saturation_and_only_moves_lightness(): void
    {
        $original   = '#7a9ecb';
        $suggestion = Contrast_Suggester::suggest($original, '#ffffff', 4.5);

        $this->assertNotNull($suggestion);
        [$h_before, $s_before, $l_before] = Contrast_Suggester::hex_to_hsl($original);
        [$h_after, $s_after, $l_after]    = Contrast_Suggester::hex_to_hsl($suggestion['hex']);

        $this->assertEqualsWithDelta($h_before, $h_after, 0.01, 'Hue must survive the fix.');
        $this->assertEqualsWithDelta($s_before, $s_after, 0.05, 'Saturation must survive the fix.');
        $this->assertNotEqualsWithDelta($l_before, $l_after, 0.001);
        $this->assertTrue($suggestion['hue_preserved']);
    }

    public function test_picks_the_smaller_lightness_move_of_the_two_directions(): void
    {
        // Light-grey text on a dark-grey background at the large-text target:
        // both directions clear 3:1, but lightening is a fraction of the
        // lightness move that crossing down through the background would be.
        $suggestion = Contrast_Suggester::suggest('#999999', '#626262', 3.0);

        $this->assertNotNull($suggestion);
        $this->assertSame('lighter', $suggestion['direction']);
    }

    public function test_goes_darker_when_that_is_the_shorter_route(): void
    {
        $suggestion = Contrast_Suggester::suggest('#8f8f8f', '#ffffff', 4.5);

        $this->assertNotNull($suggestion);
        $this->assertSame('darker', $suggestion['direction']);
    }

    public function test_returns_null_when_no_lightness_reaches_the_target(): void
    {
        // Against a mid-grey backdrop, neither black nor white clears 7:1.
        $this->assertNull(Contrast_Suggester::suggest('#808080', '#767676', 7.0));
    }

    public function test_returns_null_for_unreadable_input(): void
    {
        $this->assertNull(Contrast_Suggester::suggest('var(--x)', '#ffffff', 4.5));
        $this->assertNull(Contrast_Suggester::suggest('#000000', 'var(--x)', 4.5));
    }

    public function test_achieved_level_labels_the_strongest_grade_earned(): void
    {
        $this->assertSame('AAA', Contrast_Suggester::achieved_level(21.0));
        $this->assertSame('AA', Contrast_Suggester::achieved_level(4.6));
        $this->assertSame('AA_large', Contrast_Suggester::achieved_level(3.2));
        $this->assertSame('fail', Contrast_Suggester::achieved_level(2.0));
    }

    public function test_hsl_round_trips_through_hex(): void
    {
        foreach (['#000000', '#ffffff', '#ff0000', '#00ff00', '#0000ff', '#7a9ecb', '#123456'] as $hex) {
            [$h, $s, $l] = Contrast_Suggester::hex_to_hsl($hex);
            $this->assertSame($hex, Contrast_Suggester::hsl_to_hex($h, $s, $l), "Round trip failed for {$hex}");
        }
    }

    public function test_reaches_aaa_when_asked_for_aaa(): void
    {
        $suggestion = Contrast_Suggester::suggest('#c0392b', '#ffffff', 7.0);

        $this->assertNotNull($suggestion);
        $this->assertSame('AAA', Contrast_Suggester::achieved_level($suggestion['ratio']));
    }
}
