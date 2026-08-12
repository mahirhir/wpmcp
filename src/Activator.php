<?php

// phpcs:disable PSR1.Files.SideEffects.FoundWithSymbols -- ABSPATH guard is an intentional side effect.

namespace WPMCP;

use WPMCP\Auth\OAuth_Config;
use WPMCP\Auth\Oauth_Gc;
use WPMCP\Safety\Snapshot_Store;

if (! defined('ABSPATH')) {
    exit;
}

class Activator
{
    public static function activate(): void
    {
        Snapshot_Store::install();

        // Daily OAuth store sweep (issue #133). Only scheduled when the
        // OAuth subsystem is actually on; boot() re-ensures it if OAuth is
        // enabled later, and unschedules it if it is turned back off.
        if (OAuth_Config::is_enabled()) {
            Oauth_Gc::ensure_scheduled();
        }
    }
}
