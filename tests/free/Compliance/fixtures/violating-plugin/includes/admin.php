<?php

/**
 * Fixture: a site-wide upsell notice, unescaped output, an unsanitized
 * superglobal and a write path with no nonce or capability check. The direct
 * file access guard is missing on purpose.
 */

add_action('admin_notices', 'violating_toolkit_notice');

function violating_toolkit_notice()
{
    $screen = $_GET['page'];
    echo '<div class="notice"><p>Upgrade to unlock unlimited history on ' . $screen . '</p></div>';
}

function violating_toolkit_save()
{
    update_option('violating_toolkit_label', $_POST['label']);
}
