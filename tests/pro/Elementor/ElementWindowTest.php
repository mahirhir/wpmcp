<?php

namespace WPMCP\Tests\Pro\Elementor;

use WPMCP\Pro\Gate;
use WPMCP\Tools\Elementor\Element_Window;
use WPMCP\Tools\Elementor\Get_Elementor_Data;

/**
 * Large-page ergonomics for get-elementor-data (issue #137): summary mode,
 * max_depth truncation, and subtree windowing.
 *
 * The contract these tests pin down is that a projection never lies about how
 * much of the page it is showing (total_elements / returned_elements /
 * truncated), never changes the hashes a guarded write depends on, and never
 * touches the stored data.
 */
class ElementWindowTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Gate::set_pro_for_tests(true);
    }

    protected function tearDown(): void
    {
        Gate::set_pro_for_tests(null);
        parent::tearDown();
    }

    /** Two sections; the first is three levels deep and carries fat settings. */
    private function tree(): array
    {
        return [
            [
                'id'       => 'sect001',
                'elType'   => 'container',
                'settings' => ['flex_direction' => 'column', 'padding' => ['top' => '80', 'bottom' => '80']],
                'elements' => [
                    [
                        'id'       => 'col0001',
                        'elType'   => 'container',
                        'settings' => [],
                        'elements' => [
                            [
                                'id'         => 'head001',
                                'elType'     => 'widget',
                                'widgetType' => 'heading',
                                'settings'   => ['title' => '  Pricing   that   scales <strong>fast</strong> ', 'size' => 'xl'],
                                'elements'   => [],
                            ],
                            [
                                'id'         => 'atom001',
                                'elType'     => 'widget',
                                'widgetType' => 'e-paragraph',
                                'settings'   => [
                                    'paragraph' => [
                                        '$$type' => 'html-v3',
                                        'value'  => ['content' => ['$$type' => 'string', 'value' => 'Typed prop text'], 'children' => []],
                                    ],
                                ],
                                'elements'   => [],
                            ],
                        ],
                    ],
                ],
            ],
            [
                'id'       => 'sect002',
                'elType'   => 'container',
                'settings' => [],
                'elements' => [
                    [
                        'id'         => 'butt001',
                        'elType'     => 'widget',
                        'widgetType' => 'button',
                        'settings'   => ['text' => 'Buy now'],
                        'elements'   => [],
                    ],
                ],
            ],
        ];
    }

    private function page(?array $elements = null): int
    {
        $post_id = self::factory()->post->create(['post_type' => 'page']);
        update_post_meta($post_id, '_elementor_data', wp_json_encode($elements ?? $this->tree()));

        return $post_id;
    }

    public function test_full_read_is_unchanged_by_default(): void
    {
        $out = (new Get_Elementor_Data())->handle(['post_id' => $this->page()]);

        $this->assertSame('sect001', $out['elements'][0]['id']);
        $this->assertSame('Buy now', $out['elements'][1]['elements'][0]['settings']['text']);
        $this->assertSame(6, $out['total_elements']);
        $this->assertSame(6, $out['returned_elements']);
        $this->assertFalse($out['truncated']);
        $this->assertFalse($out['summary']);
        $this->assertArrayNotHasKey('hint', $out);
    }

    public function test_summary_drops_settings_and_reports_counts(): void
    {
        $out = (new Get_Elementor_Data())->handle(['post_id' => $this->page(), 'summary' => true]);

        $section = $out['elements'][0];
        $this->assertTrue($out['summary']);
        $this->assertArrayNotHasKey('settings', $section);
        $this->assertSame('sect001', $section['id']);
        $this->assertSame('container', $section['elType']);
        $this->assertSame(1, $section['child_count']);
        $this->assertSame(3, $section['descendant_count']);

        $heading = $section['elements'][0]['elements'][0];
        $this->assertSame('heading', $heading['widgetType']);
        $this->assertSame(0, $heading['child_count']);
        // Markup stripped, whitespace collapsed, so the label is one clean line.
        $this->assertSame('Pricing that scales fast', $heading['label']);
    }

    public function test_summary_labels_read_atomic_typed_props(): void
    {
        $out = (new Get_Elementor_Data())->handle(['post_id' => $this->page(), 'summary' => true]);

        $atomic = $out['elements'][0]['elements'][0]['elements'][1];
        $this->assertSame('e-paragraph', $atomic['widgetType']);
        $this->assertSame('Typed prop text', $atomic['label']);
    }

    public function test_summary_label_is_clipped(): void
    {
        $long = str_repeat('long heading ', 40);
        $out  = Element_Window::project(
            [['id' => 'a', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => ['title' => $long], 'elements' => []]],
            ['summary' => true]
        );

        $label = $out['elements'][0]['label'];
        $this->assertStringEndsWith("\u{2026}", $label);
        $this->assertLessThanOrEqual(Element_Window::LABEL_LENGTH + 2, strlen($label));
    }

    public function test_max_depth_truncates_and_reports_withheld_children(): void
    {
        $out = (new Get_Elementor_Data())->handle(['post_id' => $this->page(), 'summary' => true, 'max_depth' => 2]);

        $this->assertTrue($out['truncated']);
        $this->assertSame(2, $out['max_depth']);
        $this->assertSame(6, $out['total_elements']);
        $this->assertSame(4, $out['returned_elements']);

        $inner = $out['elements'][0]['elements'][0];
        $this->assertSame('col0001', $inner['id']);
        $this->assertArrayNotHasKey('elements', $inner);
        $this->assertSame(2, $inner['truncated_children']);
        $this->assertStringContainsString('element_id', $out['hint']);
    }

    public function test_max_depth_one_returns_only_top_level_sections(): void
    {
        $out = (new Get_Elementor_Data())->handle(['post_id' => $this->page(), 'max_depth' => 1]);

        $this->assertCount(2, $out['elements']);
        $this->assertSame(2, $out['returned_elements']);
        $this->assertSame(1, $out['elements'][0]['truncated_children']);
        // Full mode keeps settings on the nodes it does return.
        $this->assertSame('column', $out['elements'][0]['settings']['flex_direction']);
    }

    public function test_element_id_windows_onto_one_subtree(): void
    {
        $out = (new Get_Elementor_Data())->handle(['post_id' => $this->page(), 'element_id' => 'col0001']);

        $this->assertSame('col0001', $out['element_id']);
        $this->assertCount(1, $out['elements']);
        $this->assertSame('col0001', $out['elements'][0]['id']);
        $this->assertSame(3, $out['total_elements'], 'Totals are scoped to the window.');
        $this->assertSame(3, $out['returned_elements']);
        $this->assertSame('heading', $out['elements'][0]['elements'][0]['widgetType']);
    }

    public function test_unknown_element_id_is_an_error(): void
    {
        $out = (new Get_Elementor_Data())->handle(['post_id' => $this->page(), 'element_id' => 'nope999']);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('element_not_found', $out->get_error_code());
    }

    public function test_hashes_still_cover_the_whole_page_when_windowed(): void
    {
        $post_id = $this->page();

        $full   = (new Get_Elementor_Data())->handle(['post_id' => $post_id]);
        $window = (new Get_Elementor_Data())->handle(['post_id' => $post_id, 'summary' => true, 'max_depth' => 1]);

        $this->assertSame($full['data_hash'], $window['data_hash']);
        $this->assertSame($full['settings_hash'], $window['settings_hash']);
    }

    public function test_large_full_read_is_nudged_toward_summary_mode(): void
    {
        $elements = [];
        for ($i = 0; $i < Element_Window::LARGE_TREE_ELEMENTS + 1; $i++) {
            $elements[] = [
                'id'         => 'w' . $i,
                'elType'     => 'widget',
                'widgetType' => 'heading',
                'settings'   => ['title' => 'Row ' . $i],
                'elements'   => [],
            ];
        }

        $out = (new Get_Elementor_Data())->handle(['post_id' => $this->page($elements)]);

        $this->assertSame(151, $out['total_elements']);
        $this->assertStringContainsString('summary=true', $out['hint']);
        $this->assertCount(151, $out['elements'], 'The full tree is still returned; the hint is advice, not a cap.');
    }

    public function test_projection_never_touches_stored_data(): void
    {
        $post_id = $this->page();
        $before  = get_post_meta($post_id, '_elementor_data', true);

        (new Get_Elementor_Data())->handle(['post_id' => $post_id, 'summary' => true, 'max_depth' => 1]);

        $this->assertSame($before, get_post_meta($post_id, '_elementor_data', true));
    }

    public function test_summary_prefers_the_editor_label_and_tolerates_odd_nodes(): void
    {
        $out = Element_Window::project(
            [
                [
                    'id'       => 'sect001',
                    'elType'   => 'container',
                    'settings' => ['_title' => 'Hero section', 'title' => 'ignored'],
                    'elements' => [
                        'not-an-element',
                        ['id' => 'num0001', 'elType' => 'widget', 'widgetType' => 'counter', 'settings' => ['title' => 42]],
                        ['id' => 'bare001', 'elType' => 'widget', 'settings' => ['fields' => [['label' => 'deep']]]],
                    ],
                ],
                'garbage',
            ],
            ['summary' => true]
        );

        $section = $out['elements'][0];
        $this->assertCount(1, $out['elements'], 'Non-array top-level entries are skipped.');
        $this->assertSame('Hero section', $section['label'], 'The editor label wins over content text.');
        $this->assertSame(2, $section['child_count'], 'Non-array children are skipped.');
        $this->assertSame('42', $section['elements'][0]['label']);
        $this->assertArrayNotHasKey('label', $section['elements'][1]);
        $this->assertArrayNotHasKey('widgetType', $section['elements'][1]);
    }

    public function test_windowing_ignores_malformed_branches_when_searching(): void
    {
        $out = Element_Window::project(
            [
                'garbage',
                ['id' => 'sect001', 'elType' => 'container', 'elements' => ['junk', ['id' => 'deep001', 'elType' => 'widget']]],
            ],
            ['root_id' => 'deep001']
        );

        $this->assertSame('deep001', $out['elements'][0]['id']);
        $this->assertSame(1, $out['total_elements']);
    }

    public function test_empty_page_projects_cleanly(): void
    {
        $out = (new Get_Elementor_Data())->handle([
            'post_id' => self::factory()->post->create(['post_type' => 'page']),
            'summary' => true,
        ]);

        $this->assertSame([], $out['elements']);
        $this->assertSame(0, $out['total_elements']);
        $this->assertFalse($out['truncated']);
    }
}
