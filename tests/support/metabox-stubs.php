<?php
/**
 * Faithful global test doubles for the Meta Box integration. Meta Box's real
 * bootstrap is heavy, so this reproduces the exact public API the integration
 * calls (rwmb_meta / rwmb_set_meta / rwmb_get_registry), verified against Meta
 * Box 5.x. Real functions always win.
 */

class Rwmb_Test_Store
{
    /** @var array<int,array<string,mixed>> post_id => [key => value] */
    public static array $values = [];

    /** @var array<int,object> registered meta_box objects */
    public static array $boxes = [];

    public static function reset(): void
    {
        self::$values = [];
        self::$boxes  = [];
    }

    public static function add_box(string $id, string $title, array $post_types, array $fields): void
    {
        self::$boxes[] = (object) [
            'meta_box' => [
                'id'         => $id,
                'title'      => $title,
                'post_types' => $post_types,
                'fields'     => $fields,
            ],
        ];
    }
}

class Rwmb_Test_Registry
{
    public function all(): array
    {
        return Rwmb_Test_Store::$boxes;
    }
}

if (! function_exists('rwmb_meta')) {
    function rwmb_meta($key, $args = [], $post_id = null)
    {
        return Rwmb_Test_Store::$values[(int) $post_id][(string) $key] ?? '';
    }
}

if (! function_exists('rwmb_set_meta')) {
    function rwmb_set_meta($post_id, $key, $value, $args = [])
    {
        Rwmb_Test_Store::$values[(int) $post_id][(string) $key] = $value;
    }
}

if (! function_exists('rwmb_get_registry')) {
    function rwmb_get_registry($type)
    {
        return new Rwmb_Test_Registry();
    }
}
