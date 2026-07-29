<?php

namespace WPMCP\Tools\Elementor;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * List the Elementor dynamic tags registered on this site (name, title, group),
 * optionally filtered by group. Most tags come from Elementor Pro, so the list
 * is short or empty without it. Read-only.
 */
class List_Dynamic_Tags
{
    public function handle(array $args)
    {
        $filter = sanitize_text_field((string) ($args['group'] ?? ''));

        $manager = self::manager();
        if (null === $manager) {
            return ['tags' => [], 'count' => 0];
        }

        $tags = [];
        foreach ($this->tag_names($manager) as $name) {
            $info = $this->tag_info($manager, $name);
            if (null === $info) {
                continue;
            }
            if ('' !== $filter && $info['group'] !== $filter) {
                continue;
            }
            $tags[] = $info;
        }

        return ['tags' => $tags, 'count' => count($tags)];
    }

    /** @return object|null Elementor's dynamic tags manager when available. */
    private static function manager()
    {
        if (! class_exists('\\Elementor\\Plugin')) {
            return null;
        }
        $instance = \Elementor\Plugin::instance();
        return isset($instance->dynamic_tags) ? $instance->dynamic_tags : null;
    }

    /** @return array<int,string> */
    private function tag_names($manager): array
    {
        if (! method_exists($manager, 'get_tags')) {
            return [];
        }
        $tags = $manager->get_tags();
        return is_array($tags) ? array_keys($tags) : [];
    }

    /** @return array{name:string,title:string,group:string}|null */
    private function tag_info($manager, string $name): ?array
    {
        try {
            $tag = method_exists($manager, 'create_tag') ? $manager->create_tag(null, $name, []) : null;
        } catch (\Throwable $e) {
            $tag = null;
        }

        if (! is_object($tag)) {
            return ['name' => $name, 'title' => $name, 'group' => ''];
        }

        $group = method_exists($tag, 'get_group') ? $tag->get_group() : '';
        if (is_array($group)) {
            $group = (string) ($group[0] ?? '');
        }

        return [
            'name'  => $name,
            'title' => method_exists($tag, 'get_title') ? (string) $tag->get_title() : $name,
            'group' => (string) $group,
        ];
    }
}
