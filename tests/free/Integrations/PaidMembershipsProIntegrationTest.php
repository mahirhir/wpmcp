<?php

namespace WPMCP\Tests\Free\Integrations;

use WPMCP\Integrations\Paid_Memberships_Pro_Integration;

/**
 * Paid Memberships Pro read integration, exercised against real PMPro custom
 * tables (levels + memberships_users) created in the test DB, so the SQL and
 * the active-member count are genuinely verified.
 */
class PaidMembershipsProIntegrationTest extends \WP_UnitTestCase
{
    private Paid_Memberships_Pro_Integration $integration;

    private static function levels(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'pmpro_membership_levels';
    }

    private static function members(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'pmpro_memberships_users';
    }

    protected function setUp(): void
    {
        parent::setUp();
        global $wpdb;
        $lv = self::levels();
        $mu = self::members();
        $wpdb->query("CREATE TABLE IF NOT EXISTS `{$lv}` (
            id INT NOT NULL AUTO_INCREMENT, name VARCHAR(255), description LONGTEXT,
            initial_payment DECIMAL(18,8) DEFAULT 0, billing_amount DECIMAL(18,8) DEFAULT 0,
            cycle_number INT DEFAULT 0, cycle_period VARCHAR(20) DEFAULT '',
            allow_signups TINYINT DEFAULT 1, PRIMARY KEY (id))");
        $wpdb->query("CREATE TABLE IF NOT EXISTS `{$mu}` (
            id INT NOT NULL AUTO_INCREMENT, user_id INT, membership_id INT,
            status VARCHAR(20) DEFAULT 'active', PRIMARY KEY (id))");
        $wpdb->query("TRUNCATE TABLE `{$lv}`");
        $wpdb->query("TRUNCATE TABLE `{$mu}`");
        $wpdb->query($wpdb->prepare("INSERT INTO `{$lv}` (name, initial_payment, billing_amount, cycle_number, cycle_period, allow_signups) VALUES (%s,%s,%s,%d,%s,%d)", 'Gold', '99.00', '99.00', 1, 'Year', 1));
        $wpdb->query($wpdb->prepare("INSERT INTO `{$lv}` (name, initial_payment, billing_amount, cycle_number, cycle_period, allow_signups) VALUES (%s,%s,%s,%d,%s,%d)", 'Free', '0.00', '0.00', 0, '', 1));
        // two active + one cancelled member on level 1
        foreach ([['active'], ['active'], ['cancelled']] as $i => $s) {
            $wpdb->query($wpdb->prepare("INSERT INTO `{$mu}` (user_id, membership_id, status) VALUES (%d,%d,%s)", $i + 1, 1, $s[0]));
        }
        $this->integration = new Paid_Memberships_Pro_Integration();
    }

    protected function tearDown(): void
    {
        global $wpdb;
        $wpdb->query('DROP TABLE IF EXISTS `' . self::members() . '`');
        $wpdb->query('DROP TABLE IF EXISTS `' . self::levels() . '`');
        parent::tearDown();
    }

    public function test_available_when_levels_table_exists(): void
    {
        $this->assertTrue($this->integration->is_available());
    }

    public function test_list_levels_with_active_member_counts(): void
    {
        $out = $this->integration->handle_read([ 'operation' => 'list-levels' ]);

        $this->assertArrayNotHasKey('error', $out);
        $this->assertSame(2, $out['result']['total']);
        $gold = $out['result']['levels'][0];
        $this->assertSame('Gold', $gold['name']);
        $this->assertSame('1 Year', $gold['cycle']);
        $this->assertSame(2, $gold['active_members'], 'cancelled member is excluded');
    }

    public function test_get_level_full_config(): void
    {
        $out = $this->integration->handle_read([ 'operation' => 'get-level', 'args' => [ 'level_id' => 1 ] ]);
        $this->assertSame('Gold', $out['result']['level']['name']);
        $this->assertSame(2, $out['result']['level']['active_members']);

        $missing = $this->integration->handle_read([ 'operation' => 'get-level', 'args' => [ 'level_id' => 999 ] ]);
        $this->assertNull($missing['result']['level']);
    }
}
