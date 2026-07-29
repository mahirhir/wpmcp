<?php

namespace WPMCP\Tools\Elementor;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Persist a template's display conditions the way Elementor's own UI does.
 *
 * When Elementor Pro's theme-builder conditions manager is available it is used
 * (it writes `_elementor_conditions` and rebuilds the location cache); without
 * Pro, the same `_elementor_conditions` meta is written directly in Elementor's
 * slash-string format, so the tool is usable and testable on free Elementor.
 */
class Template_Conditions
{
    /**
     * @param array<int,string> $conditions already-normalized slash strings.
     */
    public static function save(int $post_id, array $conditions): void
    {
        if (class_exists('\\ElementorPro\\Plugin')) {
            $manager = self::pro_conditions_manager();
            if ($manager) {
                $parts = array_map(static fn (string $c) => explode('/', $c), $conditions);
                if ($manager->save_conditions($post_id, $parts)) {
                    return;
                }
            }
        }

        update_post_meta($post_id, '_elementor_conditions', $conditions);
    }

    /** @return object|null Elementor Pro's conditions manager when present. */
    private static function pro_conditions_manager()
    {
        $pro           = \ElementorPro\Plugin::instance();
        $theme_builder = isset($pro->modules_manager)
            ? $pro->modules_manager->get_modules('theme-builder')
            : null;

        if ($theme_builder && method_exists($theme_builder, 'get_conditions_manager')) {
            $manager = $theme_builder->get_conditions_manager();
            if ($manager && method_exists($manager, 'save_conditions')) {
                return $manager;
            }
        }

        return null;
    }
}
