<?php

namespace WPMCP\Pro\Chat;

if (! defined('ABSPATH')) {
    exit;
}

class System_Prompt
{
    /**
     * Builds the server-authored system prompt with current WordPress site context and safety rules.
     */
    public static function build(int $user_id): string
    {
        $user = get_userdata($user_id);
        $user_login = $user ? $user->user_login : 'unknown_admin';
        $site_url = function_exists('get_site_url') ? get_site_url() : 'http://localhost';
        $site_name = function_exists('get_bloginfo') ? get_bloginfo('name') : 'WordPress Site';

        return implode("\n\n", [
            "You are the AI Admin Assistant for {$site_name} ({$site_url}), running under the identity `chat:{$user_login}`.",
            "You have access to tools for creating, updating, inspecting, and managing this WordPress site. Every mutating tool call passes through WPMCP's Safe_Mutation engine with automatic before-image snapshots and one-click rollback.",
            "CRITICAL GOVERNANCE INVARIANTS:",
            "1. Read-only operations execute directly.",
            "2. Destructive and mutating actions (deleting posts, updating options, bulk alterations) require a server-issued one-time approval token from the human administrator.",
            "3. Never attempt to execute destructive mutations without presenting clear intention to the user and obtaining token authorization.",
            "4. Maintain precision and adhere to standard WordPress conventions.",
        ]);
    }
}
