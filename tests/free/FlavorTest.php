<?php

namespace WPMCP\Tests\Free;

use WPMCP\MCP\Registrar;
use WPMCP\Plugin;

/**
 * Build flavors (wp.org vertical builds, e.g. wpmcp-for-woocommerce) gate
 * which ability groups register. The default flavor is 'full' and registers
 * everything; the 'woocommerce' flavor keeps the safety core, content,
 * blocks, and WooCommerce domains but drops builders, integrations, REST
 * passthrough, and the guarded execution tools whose files are pruned from
 * that build's zip entirely.
 */
class FlavorTest extends \WP_UnitTestCase
{
    protected function tearDown(): void
    {
        Plugin::set_flavor_for_tests(null);
        parent::tearDown();
    }

    public function test_default_flavor_is_full(): void
    {
        $this->assertSame('full', Plugin::flavor());
    }

    public function test_flavor_override_requires_testing_mode_only(): void
    {
        Plugin::set_flavor_for_tests('woocommerce');
        $this->assertSame('woocommerce', Plugin::flavor());
    }

    public function test_woocommerce_flavor_keeps_safety_content_and_woo(): void
    {
        $names = $this->registered_names('woocommerce');

        // Safety core and content survive in every flavor.
        $this->assertContains('wpmcp/get-page', $names);
        $this->assertContains('wpmcp/rollback-operation', $names);
        $this->assertContains('wpmcp/rollback-session', $names);

        // The WooCommerce domain is the point of this flavor.
        $this->assertContains('wpmcp/list-products', $names);
        $this->assertContains('wpmcp/create-product', $names);
        $this->assertContains('wpmcp/list-orders', $names);
    }

    public function test_woocommerce_flavor_drops_pruned_domains(): void
    {
        $names = $this->registered_names('woocommerce');

        // Builders: their files are pruned from the wrapper zip.
        $this->assertNotContains('wpmcp/add-widget', $names);
        $this->assertNotContains('wpmcp/get-elementor-data', $names);
        $this->assertNotContains('wpmcp/create-custom-widget', $names);
        $this->assertNotContains('wpmcp/create-custom-block', $names);

        // Guarded execution: no eval()/proc_open call sites may ship at all.
        $this->assertNotContains('wpmcp/run-php-snippet', $names);
        $this->assertNotContains('wpmcp/run-wp-cli', $names);

        // Breadth kept out of the small wp.org build.
        $this->assertNotContains('wpmcp/call-rest', $names);
        $this->assertNotContains('wpmcp/cloud-connect', $names);
    }

    public function test_woocommerce_flavor_is_a_strict_subset_of_full(): void
    {
        $woo  = $this->registered_names('woocommerce');
        $full = $this->registered_names(null);

        $this->assertNotEmpty($woo);
        $this->assertLessThan(count($full), count($woo));
        $this->assertSame([], array_diff($woo, $full));
    }

    /** @return string[] declared ability names under the given flavor. */
    private function registered_names(?string $flavor): array
    {
        Plugin::set_flavor_for_tests($flavor);
        $registrar = new Registrar();
        Plugin::instance()->register_abilities_into($registrar);

        return array_map(fn ($a) => $a->name, array_values($registrar->declared()));
    }
}
