<?php

namespace WPMCP\Tools\Elementor;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Reads Elementor's OWN atomic prop metadata for an element type (issue #137).
 *
 * Every Elementor 4.x atomic element declares a props schema
 * (`static::get_props_schema()`), and each entry is a Prop_Type object that
 * already knows three things we would otherwise have to hand-maintain:
 *
 *   - its `$$type` key            Prop_Type::get_key()      -> 'string', 'html-v3', 'link', ...
 *   - the aliases agents guess    meta['aliases']           -> e-heading 'title' accepts text/content/heading
 *   - the allowed enum values     get_settings()['enum']    -> heading tag is h1..h6
 *
 * Reading that metadata instead of keeping a private alias table is the whole
 * point: when Elementor renames a prop, adds an alias, or widens an enum, we
 * follow automatically and cannot drift. A hand-kept mapping table silently
 * rots the day the builder ships a new widget.
 *
 * The lookup is cached per element type for the request, and `set_for_tests()`
 * injects recorded fixtures so the mapper is testable against pinned Elementor
 * shapes even where the live atomic module is absent.
 */
class Atomic_Prop_Schema
{
    /** @var array<string, array<string, array{kind: string, aliases: array<int,string>, enum: ?array<int,string>}>> */
    private static array $cache = [];

    /** @var array<string, array>|null Recorded schemas that stand in for the live registry. */
    private static ?array $recorded = null;

    /**
     * Test seam: use recorded prop metadata instead of the live Elementor
     * registry. Pass null to go back to reading the real thing.
     *
     * @param array<string, array<string, array>>|null $schemas
     */
    public static function set_for_tests(?array $schemas): void
    {
        self::$recorded = $schemas;
        self::$cache    = [];
    }

    public static function flush(): void
    {
        self::$cache = [];
    }

    /** Whether we have prop metadata for this element type at all. */
    public static function known(string $element_type): bool
    {
        return [] !== self::for_type($element_type);
    }

    /**
     * @return array<string, array{kind: string, aliases: array<int,string>, enum: ?array<int,string>}>
     *         Prop name => metadata. Empty when the type is unknown here
     *         (not an atomic element, or Elementor is not loaded).
     */
    public static function for_type(string $element_type): array
    {
        if (isset(self::$cache[ $element_type ])) {
            return self::$cache[ $element_type ];
        }

        $schema = null !== self::$recorded
            ? self::normalize_recorded(self::$recorded[ $element_type ] ?? [])
            : self::read_live($element_type);

        return self::$cache[ $element_type ] = $schema;
    }

    /**
     * Resolve a caller-supplied prop name to the real prop name, using
     * Elementor's declared aliases. Returns null when the schema is known and
     * the name is not (so the caller can warn rather than write a prop the
     * element will ignore).
     */
    public static function canonical(string $element_type, string $name): ?string
    {
        $schema = self::for_type($element_type);

        if ([] === $schema || isset($schema[ $name ])) {
            return '' === $name ? null : $name;
        }

        foreach ($schema as $prop => $meta) {
            if (in_array($name, $meta['aliases'], true)) {
                return $prop;
            }
        }

        return null;
    }

    /** The `$$type` key Elementor expects for a prop, or null when unknown. */
    public static function kind(string $element_type, string $prop): ?string
    {
        $schema = self::for_type($element_type);

        return isset($schema[ $prop ]) ? $schema[ $prop ]['kind'] : null;
    }

    /** @return array<int,string>|null Allowed string values for a prop, when Elementor declares an enum. */
    public static function enum(string $element_type, string $prop): ?array
    {
        $schema = self::for_type($element_type);

        return isset($schema[ $prop ]) ? $schema[ $prop ]['enum'] : null;
    }

    /**
     * Whether a `$$type` is one Elementor will accept for this prop. A prop
     * wrapped in a union accepts several (its own type plus the dynamic-tag
     * and component-override alternates), and none of those need repairing.
     */
    public static function accepts(string $element_type, string $prop, string $kind): bool
    {
        $schema = self::for_type($element_type);

        if (! isset($schema[ $prop ])) {
            return false;
        }

        return in_array($kind, $schema[ $prop ]['accepts'], true);
    }

    /**
     * Pull the schema off the live element class. Everything is guarded:
     * Elementor may be absent, pre-4.0, or the type may simply not be atomic,
     * and all of those mean "no metadata", never a fatal.
     *
     * @return array<string, array{kind: string, aliases: array<int,string>, enum: ?array<int,string>}>
     */
    private static function read_live(string $element_type): array
    {
        $element = self::resolve_element($element_type);
        if (null === $element || ! method_exists($element, 'get_props_schema')) {
            return [];
        }

        try {
            $props = $element::get_props_schema();
        } catch (\Throwable $e) {
            return [];
        }

        if (! is_array($props)) {
            return [];
        }

        $schema = [];
        foreach ($props as $name => $prop_type) {
            if (! is_object($prop_type) || ! method_exists($prop_type, 'get_key')) {
                continue;
            }

            $aliases = self::aliases_of($prop_type);
            $primary = self::primary_of($prop_type);

            $schema[ (string) $name ] = [
                'kind'    => (string) $primary::get_key(),
                'aliases' => $aliases,
                'enum'    => self::enum_of($primary),
                'accepts' => self::accepted_kinds($prop_type),
            ];
        }

        return $schema;
    }

    /**
     * Elementor wraps most props in a Union_Prop_Type at runtime (the
     * components and dynamic-tags modules both extend the schema through the
     * `elementor/atomic-widgets/props-schema` filter), with the element's own
     * declared type first and the alternates after it. The union's declared
     * type is the one to build for; the alternates are still valid to store,
     * which is what `accepts` records.
     */
    private static function primary_of(object $prop_type): object
    {
        if ('union' !== (string) $prop_type::get_key() || ! method_exists($prop_type, 'get_prop_types')) {
            return $prop_type;
        }

        $members = $prop_type->get_prop_types();
        $first   = is_array($members) ? reset($members) : false;

        return is_object($first) && method_exists($first, 'get_key') ? $first : $prop_type;
    }

    /** @return array<int,string> every `$$type` this prop legitimately accepts. */
    private static function accepted_kinds(object $prop_type): array
    {
        if ('union' !== (string) $prop_type::get_key() || ! method_exists($prop_type, 'get_prop_types')) {
            return [(string) $prop_type::get_key()];
        }

        $members = $prop_type->get_prop_types();

        return is_array($members) && [] !== $members
            ? array_values(array_map('strval', array_keys($members)))
            : ['union'];
    }

    /** @return object|null The registered widget or element instance for a type. */
    private static function resolve_element(string $element_type): ?object
    {
        if ('' === $element_type || ! class_exists('\\Elementor\\Plugin')) {
            return null;
        }

        try {
            $plugin = \Elementor\Plugin::instance();

            if (isset($plugin->widgets_manager)) {
                $widget = $plugin->widgets_manager->get_widget_types($element_type);
                if (is_object($widget)) {
                    return $widget;
                }
            }

            if (isset($plugin->elements_manager)) {
                $element = $plugin->elements_manager->get_element_types($element_type);
                if (is_object($element)) {
                    return $element;
                }
            }
        } catch (\Throwable $e) {
            return null;
        }

        return null;
    }

    /** @return array<int,string> */
    private static function aliases_of(object $prop_type): array
    {
        if (! method_exists($prop_type, 'get_meta')) {
            return [];
        }

        $meta    = (array) $prop_type->get_meta();
        $aliases = $meta['aliases'] ?? [];

        if (! is_array($aliases)) {
            return [];
        }

        return array_values(array_filter(array_map('strval', $aliases), static fn ($alias) => '' !== $alias));
    }

    /** @return array<int,string>|null */
    private static function enum_of(object $prop_type): ?array
    {
        $enum = null;

        if (method_exists($prop_type, 'get_enum')) {
            $enum = $prop_type->get_enum();
        } elseif (method_exists($prop_type, 'get_setting')) {
            $enum = $prop_type->get_setting('enum');
        }

        if (! is_array($enum) || [] === $enum) {
            return null;
        }

        return array_values(array_map('strval', $enum));
    }

    /**
     * Recorded fixtures are written in the same shape the live reader emits,
     * with aliases/enum optional.
     *
     * @return array<string, array{kind: string, aliases: array<int,string>, enum: ?array<int,string>}>
     */
    private static function normalize_recorded(array $recorded): array
    {
        $schema = [];

        foreach ($recorded as $name => $meta) {
            if (! is_array($meta) || ! isset($meta['kind'])) {
                continue;
            }

            $kind = (string) $meta['kind'];

            $schema[ (string) $name ] = [
                'kind'    => $kind,
                'aliases' => array_values(array_map('strval', (array) ($meta['aliases'] ?? []))),
                'enum'    => isset($meta['enum']) && is_array($meta['enum']) && [] !== $meta['enum']
                    ? array_values(array_map('strval', $meta['enum']))
                    : null,
                'accepts' => isset($meta['accepts']) && is_array($meta['accepts']) && [] !== $meta['accepts']
                    ? array_values(array_map('strval', $meta['accepts']))
                    : [$kind],
            ];
        }

        return $schema;
    }
}
