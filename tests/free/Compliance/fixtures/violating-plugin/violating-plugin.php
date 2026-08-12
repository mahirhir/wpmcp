<?php
/**
 * Plugin Name: WordPress Toolkit Pro
 * Description: Fixture for the compliance engine: a plugin that trips one rule per pack.
 * Version: 3.1.0
 * Requires at least: 6.9
 * Requires PHP: 8.1
 * Update URI: https://updates.example.test/violating-plugin
 * Text Domain: violating-toolkit
 */

if (! defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/includes/gate.php';
require_once __DIR__ . '/includes/remote.php';
require_once __DIR__ . '/includes/admin.php';
