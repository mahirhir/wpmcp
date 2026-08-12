<?php

/**
 * Recorded Elementor atomic prop metadata (issue #137).
 *
 * Transcribed from Elementor 4.2.2's own `define_props_schema()` declarations
 * (modules/atomic-widgets/elements/*), in the shape Atomic_Prop_Schema emits:
 * every prop's `$$type` key, the aliases Elementor itself declares for it, and
 * the enum it constrains string values to.
 *
 * Two jobs:
 *  1. Pin the mapper's behaviour to known Elementor shapes, so its tests do
 *     not silently change meaning when the local Elementor build changes.
 *  2. Let the mapper be exercised for element types (and future Elementor
 *     versions) that the installed build does not register.
 *
 * tests/pro/Elementor/AtomicPropRepairTest.php also asserts these recorded
 * shapes still match what the live install reports, so the fixture cannot
 * quietly drift away from reality.
 */

return [
    'e-heading'   => [
        'classes'    => ['kind' => 'classes'],
        'tag'        => ['kind' => 'string', 'enum' => ['h1', 'h2', 'h3', 'h4', 'h5', 'h6']],
        'title'      => ['kind' => 'html-v3', 'aliases' => ['text', 'content', 'heading']],
        'link'       => ['kind' => 'link'],
        'attributes' => ['kind' => 'attributes'],
        '_cssid'     => ['kind' => 'string'],
    ],
    'e-paragraph' => [
        'classes'    => ['kind' => 'classes'],
        'paragraph'  => ['kind' => 'html-v3', 'aliases' => ['text', 'content']],
        'tag'        => ['kind' => 'string', 'enum' => ['p', 'span']],
        'link'       => ['kind' => 'link'],
        'attributes' => ['kind' => 'attributes'],
        '_cssid'     => ['kind' => 'string'],
    ],
    'e-button'    => [
        'classes'    => ['kind' => 'classes'],
        'text'       => ['kind' => 'html-v3', 'aliases' => ['content', 'label']],
        'link'       => ['kind' => 'link'],
        'tag'        => ['kind' => 'string'],
        'attributes' => ['kind' => 'attributes'],
        '_cssid'     => ['kind' => 'string'],
    ],
    'e-image'     => [
        'classes'    => ['kind' => 'classes'],
        'image'      => ['kind' => 'image'],
        'link'       => ['kind' => 'link'],
        'attributes' => ['kind' => 'attributes'],
        '_cssid'     => ['kind' => 'string'],
    ],
    /**
     * Not an Elementor element: a synthetic entry covering the remaining
     * prop kinds the mapper can build, so each coercion has a test that does
     * not depend on some future Elementor widget happening to use that kind.
     */
    'e-kinds'     => [
        'label'   => ['kind' => 'string'],
        'count'   => ['kind' => 'number'],
        'toggle'  => ['kind' => 'boolean'],
        'markup'  => ['kind' => 'html'],
        'href'    => ['kind' => 'url'],
        'shade'   => ['kind' => 'color'],
        'tags'    => ['kind' => 'string-array'],
        'gap'     => ['kind' => 'size'],
        'unknown' => ['kind' => 'dimensions'],
    ],
    'e-flexbox'   => [
        'classes'    => ['kind' => 'classes'],
        'tag'        => ['kind' => 'string', 'enum' => ['div', 'header', 'section', 'article', 'aside', 'footer', 'a', 'button']],
        'link'       => ['kind' => 'link'],
        'attributes' => ['kind' => 'attributes'],
        '_cssid'     => ['kind' => 'string'],
    ],
];
