<?php

// phpcs:disable PSR1.Files.SideEffects.FoundWithSymbols -- ABSPATH guard is an intentional side effect.

namespace WPMCP;

use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Search\Search_Index_Store;

if (! defined('ABSPATH')) {
    exit;
}

class Activator
{
    public static function activate(): void
    {
        Snapshot_Store::install();
        // The content search index table (issue #83). Search_Index_Store also
        // self-heals on first use, so an update that never re-runs activation
        // still works; creating it here keeps the common path free of DDL.
        // class_exists-guarded because vertical builds (wpmcp-for-woocommerce)
        // prune src/Tools/Search from the zip along with its ability group.
        if (class_exists(Search_Index_Store::class)) {
            Search_Index_Store::install();
        }
    }
}
