<?php

namespace WPMCP\Skills;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The on/off switch for the whole agent-skills surface (issue #74).
 *
 * "Removes the surface entirely" is meant literally: when this returns
 * false, Plugin::register_skills_abilities() returns before it registers
 * anything, so list-skills/get-skill never reach the Abilities API, never
 * appear in tools/list, and cost a connecting client exactly zero tokens.
 * This is a REGISTRATION gate, unlike Tool_Exposure's compact mode, which
 * only hides already-registered tools from the advertised list.
 *
 * Two layers, both narrowing-only in practice:
 *  1. the wpmcp_skills_enabled site option (default ON, so a fresh install
 *     is self-documenting to the agents that connect to it),
 *  2. the wpmcp_skills_enabled filter, for code-level control on sites that
 *     manage configuration in a mu-plugin rather than the options table.
 *
 * The option is read with the same loose truthiness the WordPress settings
 * API produces for a checkbox ('1'/'' or true/false), and anything
 * unexpected degrades to enabled, matching the documented default.
 */
class Skills_Module
{
    public const OPTION = 'wpmcp_skills_enabled';

    public static function is_enabled(): bool
    {
        $stored  = get_option(self::OPTION, '1');
        $enabled = ! in_array($stored, ['', '0', 0, false, 'false'], true);

        /**
         * Filters whether the agent-skills MCP surface is registered at all.
         *
         * @param bool $enabled Resolved from the wpmcp_skills_enabled option.
         */
        return (bool) apply_filters('wpmcp_skills_enabled', $enabled);
    }

    /** Normalize a settings-form submission to the stored '1'/'' shape. */
    public static function sanitize($value): string
    {
        if (is_string($value)) {
            return in_array($value, ['', '0', 'false'], true) ? '' : '1';
        }

        return $value ? '1' : '';
    }
}
