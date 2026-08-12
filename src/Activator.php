<?php

// phpcs:disable PSR1.Files.SideEffects.FoundWithSymbols -- ABSPATH guard is an intentional side effect.

namespace WPMCP;

use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Redirects\Redirect_Store;

if (! defined('ABSPATH')) {
    exit;
}

class Activator
{
    public static function activate(): void
    {
        Snapshot_Store::install();
        Redirect_Store::install();
    }
}
