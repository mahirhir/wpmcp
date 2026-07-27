<?php

namespace WPMCP\Tests\Free\Integrations;

use WPMCP\Integrations\Gravity_Tables_Integration;

/**
 * Gravity Tables read integration. Exercised against a real {prefix}gravity_tables
 * custom table created in the test DB (the plugin's actual storage shape), so
 * this genuinely verifies the SQL and decoding, not a double.
 */
class GravityTablesIntegrationTest extends \WP_UnitTestCase
{
    private Gravity_Tables_Integration $integration;

    private static function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'gravity_tables';
    }

    protected function setUp(): void
    {
        parent::setUp();
        global $wpdb;
        $table = self::table();
        $wpdb->query(
            "CREATE TABLE IF NOT EXISTS `{$table}` (
                id INT(11) NOT NULL AUTO_INCREMENT,
                title VARCHAR(255) NOT NULL,
                form_id INT(11) NOT NULL,
                settings LONGTEXT,
                shortcode VARCHAR(255),
                status VARCHAR(20) DEFAULT 'active',
                created_at DATETIME,
                updated_at DATETIME,
                PRIMARY KEY (id)
            )"
        );
        $wpdb->query("TRUNCATE TABLE `{$table}`");
        $settings = wp_json_encode([ 'selected_fields' => [ '1', '2' ], 'field_labels' => [ '1' => 'Name' ] ]);
        $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO `{$table}` (title, form_id, settings, shortcode, status, updated_at) VALUES (%s,%d,%s,%s,%s,%s)",
                'Sellers',
                5,
                $settings,
                '[gravity_table id=1]',
                'active',
                '2026-07-01 12:00:00'
            )
        );
        $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO `{$table}` (title, form_id, settings, shortcode, status, updated_at) VALUES (%s,%d,%s,%s,%s,%s)",
                'Archived',
                6,
                '{}',
                '[gravity_table id=2]',
                'inactive',
                '2026-06-01 12:00:00'
            )
        );
        $this->integration = new Gravity_Tables_Integration();
    }

    protected function tearDown(): void
    {
        global $wpdb;
        $table = self::table();
        $wpdb->query("DROP TABLE IF EXISTS `{$table}`");
        parent::tearDown();
    }

    public function test_reports_available_when_table_exists(): void
    {
        $this->assertTrue($this->integration->is_available());
    }

    public function test_list_tables_returns_active_only(): void
    {
        $out = $this->integration->handle_read([ 'operation' => 'list-tables' ]);

        $this->assertArrayNotHasKey('error', $out);
        $this->assertSame(1, $out['result']['total'], 'inactive tables are excluded');
        $t = $out['result']['tables'][0];
        $this->assertSame('Sellers', $t['title']);
        $this->assertSame(5, $t['form_id']);
        $this->assertSame('[gravity_table id=1]', $t['shortcode']);
    }

    public function test_get_table_decodes_settings_and_nulls_when_missing(): void
    {
        $list = $this->integration->handle_read([ 'operation' => 'list-tables' ]);
        $id   = $list['result']['tables'][0]['id'];

        $out = $this->integration->handle_read([ 'operation' => 'get-table', 'args' => [ 'table_id' => $id ] ]);
        $this->assertSame('Sellers', $out['result']['table']['title']);
        $this->assertSame([ '1', '2' ], $out['result']['table']['settings']['selected_fields']);

        $missing = $this->integration->handle_read([ 'operation' => 'get-table', 'args' => [ 'table_id' => 99999 ] ]);
        $this->assertNull($missing['result']['table']);
    }
}
