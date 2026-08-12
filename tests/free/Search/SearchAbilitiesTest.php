<?php

namespace WPMCP\Tests\Free\Search;

use WPMCP\MCP\Ability;
use WPMCP\Plugin;
use WPMCP\Tests\Free\Platform\RegisteredAbilities;

/**
 * Registration contract for the content search tools (issue #83): tier,
 * capability split, MCP annotations, and flavor gating.
 */
class SearchAbilitiesTest extends \WP_UnitTestCase
{
    protected function tearDown(): void
    {
        Plugin::set_flavor_for_tests(null);
        parent::tearDown();
    }

    /** @return array<string,Ability> */
    private function abilities(): array
    {
        $indexed = [];
        foreach (RegisteredAbilities::all() as $ability) {
            $indexed[ $ability->name ] = $ability;
        }
        return $indexed;
    }

    public function test_both_search_tools_register_free(): void
    {
        $abilities = $this->abilities();

        $this->assertArrayHasKey('wpmcp/search-content', $abilities);
        $this->assertArrayHasKey('wpmcp/reindex-search', $abilities);
        $this->assertSame('free', $abilities['wpmcp/search-content']->tier);
        $this->assertSame('free', $abilities['wpmcp/reindex-search']->tier);
    }

    public function test_search_is_read_only_and_reindex_is_a_non_destructive_update(): void
    {
        $abilities = $this->abilities();

        $search = $abilities['wpmcp/search-content'];
        $this->assertTrue($search->read_only_hint);
        $this->assertFalse($search->destructive_hint);
        $this->assertTrue($search->idempotent_hint);

        $reindex = $abilities['wpmcp/reindex-search'];
        $this->assertFalse($reindex->read_only_hint);
        $this->assertFalse($reindex->destructive_hint);
        $this->assertTrue($reindex->idempotent_hint);
    }

    public function test_capability_split_matches_the_cost_of_each_tool(): void
    {
        $abilities = $this->abilities();

        // Reading is the standard content bar; a site-wide rebuild is not.
        $this->assertSame('edit_posts', $abilities['wpmcp/search-content']->capability);
        $this->assertSame('manage_options', $abilities['wpmcp/reindex-search']->capability);
    }

    public function test_query_is_the_only_required_search_input(): void
    {
        $schema = $this->abilities()['wpmcp/search-content']->input_schema;

        $this->assertSame(['query'], $schema['required']);
        foreach (['post_types', 'sources', 'object_types', 'limit', 'offset', 'hits_per_result'] as $key) {
            $this->assertArrayHasKey($key, $schema['properties']);
        }
        $this->assertArrayNotHasKey('required', $this->abilities()['wpmcp/reindex-search']->input_schema);
    }

    public function test_the_small_woocommerce_build_drops_the_search_group(): void
    {
        Plugin::set_flavor_for_tests('woocommerce');
        $plugin = Plugin::instance();
        $prop   = new \ReflectionProperty(Plugin::class, 'registrar');
        $backup = $prop->getValue($plugin);

        $prop->setValue($plugin, new \WPMCP\MCP\Registrar());
        try {
            $plugin->register_abilities();
            $names = array_map(static fn (Ability $a): string => $a->name, $plugin->registrar()->all());
        } finally {
            $prop->setValue($plugin, $backup);
        }

        $this->assertNotContains('wpmcp/search-content', $names);
        $this->assertNotContains('wpmcp/reindex-search', $names);
    }
}
