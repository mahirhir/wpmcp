<?php

namespace WPMCP\Tests\Pro\Builders;

use WPMCP\Tools\Builders\Bricks_Content;

/**
 * Unit coverage for Bricks_Content storage semantics. Bricks stores its
 * element tree as a NATIVE serialized array in the _bricks_page_content_2
 * postmeta key (includes/ajax.php), so the reader must return arrays and the
 * writer must store arrays, while tolerating a legacy JSON string on read.
 */
class BricksContentTest extends \WP_UnitTestCase
{
    private function page(): int
    {
        return self::factory()->post->create(['post_type' => 'page']);
    }

    public function test_get_returns_native_array(): void
    {
        $id       = $this->page();
        $elements = [['id' => 'a', 'name' => 'section', 'parent' => 0, 'children' => [], 'settings' => []]];
        update_post_meta($id, Bricks_Content::META_KEY, $elements);

        $this->assertSame($elements, Bricks_Content::get($id));
    }

    public function test_get_tolerates_legacy_json_string(): void
    {
        $id       = $this->page();
        $elements = [['id' => 'a', 'name' => 'heading']];
        update_post_meta($id, Bricks_Content::META_KEY, wp_json_encode($elements));

        $this->assertSame($elements, Bricks_Content::get($id));
    }

    public function test_get_returns_null_when_meta_absent(): void
    {
        $this->assertNull(Bricks_Content::get($this->page()));
    }

    public function test_get_returns_null_for_non_array_json_scalar(): void
    {
        $id = $this->page();
        update_post_meta($id, Bricks_Content::META_KEY, wp_json_encode('just a string'));

        $this->assertNull(Bricks_Content::get($id));
    }

    public function test_save_stores_native_array_that_bricks_can_read(): void
    {
        $id       = $this->page();
        $elements = [
            ['id' => 's1', 'name' => 'section', 'parent' => 0, 'children' => ['c1'], 'settings' => []],
            ['id' => 'c1', 'name' => 'container', 'parent' => 's1', 'children' => [], 'settings' => ['_padding' => ['top' => '10']]],
        ];

        Bricks_Content::save($id, $elements);

        $raw = get_post_meta($id, Bricks_Content::META_KEY, true);
        $this->assertIsArray($raw, 'Bricks reads _bricks_page_content_2 as an array; a JSON string would be unreadable to it');
        $this->assertSame($elements, $raw);
        $this->assertSame($elements, Bricks_Content::get($id));
    }

    public function test_save_preserves_backslashes_and_special_chars(): void
    {
        $id       = $this->page();
        $elements = [['id' => 'h', 'name' => 'heading', 'settings' => ['text' => 'A \\ B "quote" & <tag>']]];

        Bricks_Content::save($id, $elements);

        $this->assertSame($elements, Bricks_Content::get($id));
    }
}
