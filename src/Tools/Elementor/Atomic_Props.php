<?php

namespace WPMCP\Tools\Elementor;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Constructors for Elementor 4.0+ atomic "typed prop" values.
 *
 * Atomic elements store each setting as { "$$type": <kind>, "value": <value> }
 * rather than a bare scalar. These helpers build that public Elementor data
 * shape so the atomic tools produce settings Elementor's v4 editor reads.
 */
class Atomic_Props
{
    public static function string(string $value): array
    {
        return ['$$type' => 'string', 'value' => $value];
    }

    /** @param int|float|string $value */
    public static function number($value): array
    {
        return ['$$type' => 'number', 'value' => is_numeric($value) ? $value + 0 : 0];
    }

    public static function boolean(bool $value): array
    {
        return ['$$type' => 'boolean', 'value' => $value];
    }

    /** Rich-text prop (headings, paragraphs, button labels). */
    public static function html(string $text): array
    {
        return [
            '$$type' => 'html-v3',
            'value'  => [
                'content'  => self::string($text),
                'children' => [],
            ],
        ];
    }

    /** @param array<int,string> $class_ids */
    public static function classes(array $class_ids = []): array
    {
        return ['$$type' => 'classes', 'value' => array_values($class_ids)];
    }

    public static function link(string $url, bool $target_blank = false): array
    {
        $value = ['destination' => self::string($url)];
        if ($target_blank) {
            $value['isTargetBlank'] = self::boolean(true);
        }
        return ['$$type' => 'link', 'value' => $value];
    }

    public static function image(int $image_id, string $image_url = '', string $alt = ''): array
    {
        $src = $image_id > 0
            ? ['id' => self::number($image_id), 'url' => null]
            : ['id' => null, 'url' => self::string($image_url)];

        return [
            '$$type' => 'image',
            'value'  => [
                'src'  => ['$$type' => 'image-src', 'value' => $src],
                'size' => self::string('full'),
                'alt'  => '' !== $alt ? self::string($alt) : null,
            ],
        ];
    }
}
