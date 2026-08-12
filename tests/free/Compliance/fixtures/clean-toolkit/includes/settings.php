<?php

/**
 * Fixture: a settings screen that does everything the reviewer checklist asks
 * for. Nonce, capability, sanitized input, escaped output, one text domain.
 */

if (! defined('ABSPATH')) {
    exit;
}

function clean_toolkit_menu()
{
    add_options_page(
        __('Clean Toolkit', 'clean-toolkit'),
        __('Clean Toolkit', 'clean-toolkit'),
        'manage_options',
        'clean-toolkit',
        'clean_toolkit_render'
    );
}

function clean_toolkit_render()
{
    if (! current_user_can('manage_options')) {
        return;
    }
    $label = get_option('clean_toolkit_label', '');
    echo '<div class="wrap"><h1>' . esc_html__('Clean Toolkit', 'clean-toolkit') . '</h1>';
    echo '<p>' . esc_html($label) . '</p></div>';
}

function clean_toolkit_save()
{
    check_admin_referer('clean_toolkit_save');
    if (! current_user_can('manage_options')) {
        return;
    }
    $label = isset($_POST['label']) ? sanitize_text_field(wp_unslash($_POST['label'])) : '';
    update_option('clean_toolkit_label', $label);
}
