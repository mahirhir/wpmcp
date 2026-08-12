<?php

namespace WPMCP\Tests\Pro\Elementor;

use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Elementor\Create_Global_Class;
use WPMCP\Tools\Elementor\Delete_Global_Class;
use WPMCP\Tools\Elementor\Global_Class_Schema;
use WPMCP\Tools\Elementor\Global_Class_Usage;
use WPMCP\Tools\Elementor\Global_Classes_Store;
use WPMCP\Tools\Elementor\List_Global_Classes;
use WPMCP\Tools\Elementor\Reorder_Global_Classes;
use WPMCP\Tools\Elementor\Update_Global_Class;

/**
 * Elementor v4 global class authoring (issue #132).
 *
 * The suite exercises the four write tools against Elementor's real global
 * classes repository, which is where the interesting behaviour lives: the
 * whole class set is rewritten on every call, so the optimistic lock, the
 * per-variant merge semantics, the append-never-drop reorder contract and the
 * snapshot that can resurrect a deleted class all have to hold against
 * Elementor's own storage rather than a stub.
 */
class GlobalClassesWriteTest extends Structural_Harness
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! Global_Classes_Store::is_supported()) {
            $this->markTestSkipped('Elementor v4 global classes are not available');
        }
    }

    // ---- helpers ------------------------------------------------------------

    private function state_hash(): string
    {
        $state = Global_Classes_Store::read();

        return Global_Classes_Store::state_hash($state['items'], $state['order']);
    }

    private function stored(): array
    {
        $state = Global_Classes_Store::read();

        return $state['items'];
    }

    private function stored_order(): array
    {
        $state = Global_Classes_Store::read();

        return $state['order'];
    }

    /** Create a class and return its id. */
    private function create(string $label, array $styles = ['color' => '#111111'], array $extra = []): string
    {
        $out = (new Create_Global_Class())->handle(array_merge([
            'expected_hash' => $this->state_hash(),
            'label'         => $label,
            'styles'        => $styles,
        ], $extra));

        $this->assertIsArray($out, is_wp_error($out) ? $out->get_error_message() : '');

        return $out['id'];
    }

    private function variant(array $item, string $breakpoint = 'desktop', ?string $state = null): ?array
    {
        foreach ((array) ($item['variants'] ?? []) as $candidate) {
            $meta = (array) ($candidate['meta'] ?? []);
            if (($meta['breakpoint'] ?? 'desktop') === $breakpoint && ($meta['state'] ?? null) === $state) {
                return $candidate;
            }
        }

        return null;
    }

    // ---- create -------------------------------------------------------------

    public function test_create_stores_a_validated_class_and_returns_its_id(): void
    {
        $out = (new Create_Global_Class())->handle([
            'expected_hash' => $this->state_hash(),
            'label'         => 'card-base',
            'styles'        => [
                'background_color' => '#ffffff',
                'border_radius'    => 8,
                'padding'          => 24,
                'gap'              => 16,
                'direction'        => 'column',
            ],
        ]);

        $this->assertIsArray($out);
        $this->assertMatchesRegularExpression('/^g-[0-9a-f]{7}$/', $out['id']);
        $this->assertSame('card-base', $out['label']);
        $this->assertArrayHasKey('operation_id', $out);
        $this->assertArrayHasKey('state_hash', $out);

        $item = $this->stored()[$out['id']];
        $this->assertSame('class', $item['type']);
        $this->assertSame('card-base', $item['label']);

        $props = $this->variant($item)['props'];
        $this->assertSame(
            ['$$type' => 'background', 'value' => ['color' => ['$$type' => 'color', 'value' => '#ffffff']]],
            $props['background']
        );
        $this->assertSame(['$$type' => 'size', 'value' => ['size' => 8, 'unit' => 'px']], $props['border-radius']);
        $this->assertSame('column', $props['flex-direction']['value']);
        $this->assertSame(24, $props['padding']['value']['block-start']['value']['size']);
        $this->assertContains($out['id'], $this->stored_order());
    }

    public function test_create_accepts_a_breakpoint_and_state_variant(): void
    {
        $id = $this->create('cta-link', ['color' => '#ff0000'], ['breakpoint' => 'mobile', 'state' => 'hover']);

        $variant = $this->variant($this->stored()[$id], 'mobile', 'hover');
        $this->assertNotNull($variant);
        $this->assertSame('#ff0000', $variant['props']['color']['value']);
    }

    public function test_create_refuses_an_invalid_label(): void
    {
        $out = (new Create_Global_Class())->handle([
            'expected_hash' => $this->state_hash(),
            'label'         => '9 lives',
        ]);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('invalid_label', $out->get_error_code());
        $this->assertSame([], $this->stored());
    }

    public function test_create_refuses_a_reserved_label(): void
    {
        $out = (new Create_Global_Class())->handle([
            'expected_hash' => $this->state_hash(),
            'label'         => 'container',
        ]);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('invalid_label', $out->get_error_code());
    }

    public function test_create_refuses_a_duplicate_label(): void
    {
        $this->create('hero');

        $out = (new Create_Global_Class())->handle([
            'expected_hash' => $this->state_hash(),
            'label'         => 'hero',
        ]);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('duplicate_label', $out->get_error_code());
        $this->assertCount(1, $this->stored());
    }

    public function test_create_refuses_an_unknown_breakpoint_instead_of_coercing_it(): void
    {
        $out = (new Create_Global_Class())->handle([
            'expected_hash' => $this->state_hash(),
            'label'         => 'hero',
            'breakpoint'    => 'phablet',
            'styles'        => ['color' => '#111111'],
        ]);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('invalid_breakpoint', $out->get_error_code());
        $this->assertSame([], $this->stored());
    }

    public function test_create_refuses_an_unknown_state(): void
    {
        $out = (new Create_Global_Class())->handle([
            'expected_hash' => $this->state_hash(),
            'label'         => 'hero',
            'state'         => 'hovered',
        ]);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('invalid_state', $out->get_error_code());
    }

    public function test_create_refuses_an_unknown_friendly_style_key(): void
    {
        $out = (new Create_Global_Class())->handle([
            'expected_hash' => $this->state_hash(),
            'label'         => 'hero',
            'styles'        => ['bakground_color' => '#ffffff'],
        ]);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('unknown_style_key', $out->get_error_code());
    }

    public function test_create_refuses_a_non_hex_color(): void
    {
        $out = (new Create_Global_Class())->handle([
            'expected_hash' => $this->state_hash(),
            'label'         => 'hero',
            'styles'        => ['color' => 'rebeccapurple'],
        ]);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('invalid_color', $out->get_error_code());
    }

    public function test_create_refuses_a_raw_prop_that_is_not_type_wrapped(): void
    {
        $out = (new Create_Global_Class())->handle([
            'expected_hash' => $this->state_hash(),
            'label'         => 'hero',
            'props'         => ['color' => '#fff'],
        ]);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('invalid_prop', $out->get_error_code());
    }

    public function test_create_refuses_a_style_property_elementor_would_silently_drop(): void
    {
        $out = (new Create_Global_Class())->handle([
            'expected_hash' => $this->state_hash(),
            'label'         => 'hero',
            'props'         => ['row-gap' => ['$$type' => 'size', 'value' => ['size' => 4, 'unit' => 'px']]],
        ]);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('unknown_style_prop', $out->get_error_code());
        $this->assertStringContainsString('row-gap', $out->get_error_message());
        $this->assertSame([], $this->stored());
    }

    public function test_create_refuses_a_stale_expected_hash(): void
    {
        $out = (new Create_Global_Class())->handle([
            'expected_hash' => 'deadbeef',
            'label'         => 'hero',
        ]);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('stale_expected_hash', $out->get_error_code());
        $this->assertSame([], $this->stored());
    }

    public function test_create_requires_an_expected_hash(): void
    {
        $out = (new Create_Global_Class())->handle(['label' => 'hero']);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('missing_expected_hash', $out->get_error_code());
    }

    public function test_a_concurrent_create_invalidates_a_stale_hash_instead_of_dropping_the_class(): void
    {
        $hash = $this->state_hash();
        $this->create('added-by-another-agent');

        $out = (new Create_Global_Class())->handle([
            'expected_hash' => $hash,
            'label'         => 'mine',
        ]);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('stale_expected_hash', $out->get_error_code());
        $this->assertCount(1, $this->stored(), 'The concurrently added class must survive.');
    }

    public function test_write_tools_refuse_a_user_without_the_class_editing_capability(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));

        $out = (new Create_Global_Class())->handle([
            'expected_hash' => $this->state_hash(),
            'label'         => 'hero',
        ]);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('forbidden', $out->get_error_code());
    }

    // ---- update -------------------------------------------------------------

    public function test_update_renames_a_class(): void
    {
        $id = $this->create('old-name');

        $out = (new Update_Global_Class())->handle([
            'expected_hash' => $this->state_hash(),
            'id'            => $id,
            'label'         => 'new-name',
        ]);

        $this->assertIsArray($out);
        $this->assertSame('new-name', $this->stored()[$id]['label']);
    }

    public function test_update_merges_into_the_matching_variant_and_leaves_others_alone(): void
    {
        $id = $this->create('card', ['color' => '#111111', 'font_size' => 16]);
        (new Update_Global_Class())->handle([
            'expected_hash' => $this->state_hash(),
            'id'            => $id,
            'styles'        => ['color' => '#222222'],
            'breakpoint'    => 'mobile',
        ]);

        $out = (new Update_Global_Class())->handle([
            'expected_hash' => $this->state_hash(),
            'id'            => $id,
            'styles'        => ['background_color' => '#ffffff'],
        ]);

        $this->assertIsArray($out);
        $this->assertSame(2, $out['variants']);

        $desktop = $this->variant($this->stored()[$id])['props'];
        $this->assertSame('#111111', $desktop['color']['value'], 'The merged variant keeps its other props.');
        $this->assertSame(16, $desktop['font-size']['value']['size']);
        $this->assertArrayHasKey('background', $desktop);

        $mobile = $this->variant($this->stored()[$id], 'mobile')['props'];
        $this->assertSame('#222222', $mobile['color']['value'], 'The mobile variant is untouched.');
        $this->assertArrayNotHasKey('background', $mobile);
    }

    public function test_update_can_replace_a_variant_wholesale(): void
    {
        $id = $this->create('card', ['color' => '#111111', 'font_size' => 16]);

        (new Update_Global_Class())->handle([
            'expected_hash'   => $this->state_hash(),
            'id'              => $id,
            'styles'          => ['color' => '#333333'],
            'replace_variant' => true,
        ]);

        $props = $this->variant($this->stored()[$id])['props'];
        $this->assertSame(['color'], array_keys($props));
        $this->assertSame('#333333', $props['color']['value']);
    }

    public function test_update_adds_a_variant_for_a_new_breakpoint_state_pair(): void
    {
        $id = $this->create('button');

        (new Update_Global_Class())->handle([
            'expected_hash' => $this->state_hash(),
            'id'            => $id,
            'styles'        => ['background_color' => '#000000'],
            'state'         => 'hover',
        ]);

        $this->assertNotNull($this->variant($this->stored()[$id], 'desktop', 'hover'));
        $this->assertNotNull($this->variant($this->stored()[$id], 'desktop', null));
    }

    public function test_update_rejects_an_unknown_class(): void
    {
        $out = (new Update_Global_Class())->handle([
            'expected_hash' => $this->state_hash(),
            'id'            => 'g-nope123',
            'label'         => 'whatever',
        ]);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('class_not_found', $out->get_error_code());
    }

    public function test_update_requires_something_to_change(): void
    {
        $id = $this->create('card');

        $out = (new Update_Global_Class())->handle([
            'expected_hash' => $this->state_hash(),
            'id'            => $id,
        ]);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('nothing_to_update', $out->get_error_code());
    }

    public function test_update_requires_an_id(): void
    {
        $out = (new Update_Global_Class())->handle(['expected_hash' => $this->state_hash()]);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('missing_id', $out->get_error_code());
    }

    // ---- delete -------------------------------------------------------------

    public function test_delete_without_confirm_reports_usage_and_writes_nothing(): void
    {
        $id   = $this->create('used-everywhere');
        $page = $this->make_page($this->page_using($id));

        $out = (new Delete_Global_Class())->handle([
            'expected_hash' => $this->state_hash(),
            'id'            => $id,
        ]);

        $this->assertIsArray($out);
        $this->assertFalse($out['deleted']);
        $this->assertTrue($out['confirm_required']);
        $this->assertSame(1, $out['usage']['total']);
        $this->assertSame($page, $out['usage']['posts'][0]['post_id']);
        $this->assertSame(1, $out['usage']['posts'][0]['occurrences']);
        $this->assertArrayHasKey($id, $this->stored(), 'The dry run must not delete anything.');
    }

    public function test_delete_with_confirm_removes_the_class_and_its_order_entry(): void
    {
        $id = $this->create('doomed');

        $out = (new Delete_Global_Class())->handle([
            'expected_hash' => $this->state_hash(),
            'id'            => $id,
            'confirm'       => true,
        ]);

        $this->assertIsArray($out);
        $this->assertTrue($out['deleted']);
        $this->assertArrayNotHasKey($id, $this->stored());
        $this->assertNotContains($id, $this->stored_order());
    }

    public function test_delete_rejects_an_unknown_class(): void
    {
        $out = (new Delete_Global_Class())->handle([
            'expected_hash' => $this->state_hash(),
            'id'            => 'g-missing',
            'confirm'       => true,
        ]);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('class_not_found', $out->get_error_code());
    }

    public function test_delete_requires_an_id(): void
    {
        $out = (new Delete_Global_Class())->handle(['expected_hash' => $this->state_hash()]);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('missing_id', $out->get_error_code());
    }

    public function test_a_deleted_class_is_resurrected_by_rollback_operation(): void
    {
        $id = $this->create('precious', ['color' => '#abcdef']);
        $this->assertArrayHasKey($id, $this->stored());

        $out = (new Delete_Global_Class())->handle([
            'expected_hash' => $this->state_hash(),
            'id'            => $id,
            'confirm'       => true,
        ]);
        $this->assertArrayNotHasKey($id, $this->stored());

        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));

        $restored = $this->stored();
        $this->assertArrayHasKey($id, $restored, 'rollback-operation must bring the deleted class back.');
        $this->assertSame('precious', $restored[$id]['label']);
        $this->assertSame('#abcdef', $this->variant($restored[$id])['props']['color']['value']);
        $this->assertContains($id, $this->stored_order());
    }

    public function test_rollback_undoes_a_style_update(): void
    {
        $id = $this->create('tile', ['color' => '#111111']);

        $out = (new Update_Global_Class())->handle([
            'expected_hash' => $this->state_hash(),
            'id'            => $id,
            'styles'        => ['color' => '#999999'],
        ]);
        $this->assertSame('#999999', $this->variant($this->stored()[$id])['props']['color']['value']);

        Rollback_Service::restore_operation($out['operation_id']);

        $this->assertSame('#111111', $this->variant($this->stored()[$id])['props']['color']['value']);
    }

    public function test_the_write_is_recorded_as_a_global_classes_operation(): void
    {
        $out = (new Create_Global_Class())->handle([
            'expected_hash' => $this->state_hash(),
            'label'         => 'audited',
            'session_id'    => 'sess-132',
        ]);

        $row = Snapshot_Store::get_by_operation($out['operation_id']);

        $this->assertNotNull($row);
        $this->assertSame('elementor_global_classes', $row['object_type']);
        $this->assertSame('create-global-class', $row['tool_name']);
        $this->assertSame('sess-132', $row['session_id']);
        $this->assertSame($out['kit_id'], (int) $row['object_id']);
    }

    public function test_update_refuses_a_raw_prop_outside_the_style_schema(): void
    {
        $id = $this->create('tile');

        $out = (new Update_Global_Class())->handle([
            'expected_hash' => $this->state_hash(),
            'id'            => $id,
            'props'         => ['not-a-css-prop' => ['$$type' => 'string', 'value' => 'x']],
        ]);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('unknown_style_prop', $out->get_error_code());
    }

    public function test_rollback_removes_a_created_class(): void
    {
        $out = (new Create_Global_Class())->handle([
            'expected_hash' => $this->state_hash(),
            'label'         => 'temporary',
        ]);

        Rollback_Service::restore_operation($out['operation_id']);

        $this->assertArrayNotHasKey($out['id'], $this->stored());
    }

    // ---- reorder ------------------------------------------------------------

    public function test_reorder_puts_requested_ids_first_and_appends_the_rest(): void
    {
        $a = $this->create('alpha');
        $b = $this->create('beta');
        $c = $this->create('gamma');
        $this->assertSame([$a, $b, $c], $this->stored_order());

        $out = (new Reorder_Global_Classes())->handle([
            'expected_hash' => $this->state_hash(),
            'order'         => [$c],
        ]);

        $this->assertIsArray($out);
        $this->assertSame([$c, $a, $b], $out['order']);
        $this->assertSame([$c, $a, $b], $this->stored_order());
    }

    public function test_reorder_sets_a_full_order(): void
    {
        $a = $this->create('alpha');
        $b = $this->create('beta');

        (new Reorder_Global_Classes())->handle([
            'expected_hash' => $this->state_hash(),
            'order'         => [$b, $a],
        ]);

        $this->assertSame([$b, $a], $this->stored_order());
    }

    public function test_reorder_refuses_an_unknown_id(): void
    {
        $a = $this->create('alpha');

        $out = (new Reorder_Global_Classes())->handle([
            'expected_hash' => $this->state_hash(),
            'order'         => ['g-ghost1', $a],
        ]);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('unknown_class', $out->get_error_code());
        $this->assertSame([$a], $this->stored_order());
    }

    public function test_reorder_requires_an_order(): void
    {
        $out = (new Reorder_Global_Classes())->handle(['expected_hash' => $this->state_hash()]);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('missing_order', $out->get_error_code());
    }

    public function test_rollback_restores_the_previous_order(): void
    {
        $a = $this->create('alpha');
        $b = $this->create('beta');

        $out = (new Reorder_Global_Classes())->handle([
            'expected_hash' => $this->state_hash(),
            'order'         => [$b, $a],
        ]);
        $this->assertSame([$b, $a], $this->stored_order());

        Rollback_Service::restore_operation($out['operation_id']);

        $this->assertSame([$a, $b], $this->stored_order());
    }

    // ---- list ---------------------------------------------------------------

    public function test_list_reports_the_classes_in_order_with_the_lock_hash(): void
    {
        $a = $this->create('alpha');
        $b = $this->create('beta');

        $out = (new List_Global_Classes())->handle([]);

        $this->assertSame([$a, $b], array_column($out['classes'], 'id'));
        $this->assertSame([$a, $b], $out['order']);
        $this->assertSame($this->state_hash(), $out['state_hash']);

        // The hash listed here is exactly what a write accepts.
        $this->assertIsArray((new Update_Global_Class())->handle([
            'expected_hash' => $out['state_hash'],
            'id'            => $a,
            'label'         => 'alpha-renamed',
        ]));
    }

    // ---- usage scanner ------------------------------------------------------

    public function test_usage_scan_finds_every_post_applying_the_class_and_counts_hits(): void
    {
        $id    = 'g-1a2b3c4';
        $one   = $this->make_page($this->page_using($id, 2));
        $two   = $this->make_page($this->page_using($id));
        $this->make_page($this->page_using('g-other12'));

        $usage = Global_Class_Usage::scan($id);

        $this->assertSame(2, $usage['total']);
        $this->assertFalse($usage['truncated']);
        $this->assertSame([$one, $two], array_column($usage['posts'], 'post_id'));
        $this->assertSame(2, $usage['posts'][0]['occurrences']);
        $this->assertSame('page', $usage['posts'][0]['post_type']);
    }

    public function test_usage_scan_does_not_match_an_id_that_is_only_a_prefix(): void
    {
        $this->make_page($this->page_using('g-1a2b3c4'));

        $this->assertSame(0, Global_Class_Usage::scan('g-1a2')['total']);
    }

    public function test_usage_scan_reports_truncation_when_over_the_limit(): void
    {
        $id = 'g-crowded';
        $this->make_page($this->page_using($id));
        $this->make_page($this->page_using($id));

        $usage = Global_Class_Usage::scan($id, 1);

        $this->assertSame(2, $usage['total']);
        $this->assertSame(1, $usage['listed']);
        $this->assertTrue($usage['truncated']);
    }

    public function test_usage_scan_of_an_unused_class_is_empty(): void
    {
        $this->assertSame(
            ['total' => 0, 'listed' => 0, 'truncated' => false, 'posts' => []],
            Global_Class_Usage::scan('g-unused1')
        );
    }

    public function test_usage_scan_of_an_empty_id_is_empty(): void
    {
        $this->assertSame(0, Global_Class_Usage::scan('')['total']);
    }

    // ---- schema helpers -----------------------------------------------------

    public function test_style_keys_are_advertised_for_the_tool_schema(): void
    {
        $keys = Global_Class_Schema::style_keys();

        $this->assertContains('background_color', $keys);
        $this->assertContains('padding_top', $keys);
        $this->assertContains('justify', $keys);
        $this->assertSame($keys, array_values(array_unique($keys)));
    }

    public function test_valid_states_come_from_elementor(): void
    {
        $this->assertContains('hover', Global_Class_Schema::valid_states());
        $this->assertNotContains(null, Global_Class_Schema::valid_states());
    }

    public function test_per_side_padding_only_sets_the_sides_given(): void
    {
        $props = Global_Class_Schema::props([
            'styles' => ['padding_top' => 10, 'padding_bottom' => 20, 'padding_bottom_unit' => 'em'],
        ]);

        $this->assertSame(['block-start', 'block-end'], array_keys($props['padding']['value']));
        $this->assertSame('em', $props['padding']['value']['block-end']['value']['unit']);
    }

    public function test_the_raw_props_escape_hatch_overrides_a_built_style(): void
    {
        $props = Global_Class_Schema::props([
            'styles' => ['width' => 10],
            'props'  => ['width' => ['$$type' => 'size', 'value' => ['size' => 99, 'unit' => '%']]],
        ]);

        $this->assertSame(99, $props['width']['value']['size']);
    }

    // ---- store --------------------------------------------------------------

    public function test_the_store_falls_back_to_legacy_kit_meta_when_the_repository_is_empty(): void
    {
        $kit_id = (int) \Elementor\Plugin::instance()->kits_manager->get_active_id();
        update_post_meta($kit_id, Global_Classes_Store::META_KEY, [
            'items' => [
                'g-legacy1' => ['id' => 'g-legacy1', 'type' => 'class', 'label' => 'legacy', 'variants' => []],
            ],
            'order' => ['g-legacy1'],
        ]);
        clean_post_cache($kit_id);

        $state = Global_Classes_Store::read();

        $this->assertSame(['g-legacy1'], array_keys($state['items']));
        $this->assertSame(['g-legacy1'], $state['order']);
    }

    public function test_the_store_never_drops_a_class_missing_from_the_stored_order(): void
    {
        $kit_id = (int) \Elementor\Plugin::instance()->kits_manager->get_active_id();
        update_post_meta($kit_id, Global_Classes_Store::META_KEY, [
            'items' => [
                'g-one' => ['id' => 'g-one', 'type' => 'class', 'label' => 'one', 'variants' => []],
                'g-two' => ['id' => 'g-two', 'type' => 'class', 'label' => 'two', 'variants' => []],
            ],
            'order' => ['g-two', 'g-two', 'g-gone'],
        ]);
        clean_post_cache($kit_id);

        $this->assertSame(['g-two', 'g-one'], Global_Classes_Store::read()['order']);
    }

    public function test_the_state_hash_changes_with_content_and_with_order(): void
    {
        $a = ['g-a' => ['id' => 'g-a', 'label' => 'a'], 'g-b' => ['id' => 'g-b', 'label' => 'b']];

        $this->assertSame(
            Global_Classes_Store::state_hash($a, ['g-a', 'g-b']),
            Global_Classes_Store::state_hash(array_reverse($a, true), ['g-a', 'g-b']),
            'Key order in the items map is not meaningful.'
        );
        $this->assertNotSame(
            Global_Classes_Store::state_hash($a, ['g-a', 'g-b']),
            Global_Classes_Store::state_hash($a, ['g-b', 'g-a'])
        );
        $this->assertNotSame(
            Global_Classes_Store::state_hash($a, ['g-a', 'g-b']),
            Global_Classes_Store::state_hash(['g-a' => ['id' => 'g-a', 'label' => 'z']] + $a, ['g-a', 'g-b'])
        );
    }

    // ---- fixtures -----------------------------------------------------------

    /** An atomic element tree applying $class_id to $count elements. */
    private function page_using(string $class_id, int $count = 1): array
    {
        $children = [];
        for ($i = 0; $i < $count; $i++) {
            $children[] = [
                'id'         => sprintf('atomic%d', $i),
                'elType'     => 'widget',
                'widgetType' => 'heading',
                'settings'   => ['classes' => ['$$type' => 'classes', 'value' => [$class_id]]],
                'elements'   => [],
            ];
        }

        return [[
            'id'       => 'cont001',
            'elType'   => 'container',
            'settings' => [],
            'elements' => $children,
            'isInner'  => false,
        ]];
    }
}
