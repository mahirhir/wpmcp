<?php

namespace WPMCP\Compliance\Reporters;

use WPMCP\Compliance\Report;

/**
 * Machine-readable output for CI annotations and for diffing one run against
 * the next.
 */
final class Json_Reporter implements Reporter
{
    public function render(Report $report): string
    {
        $json = json_encode($report->to_array(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        return (false === $json ? '{}' : $json) . "\n";
    }
}
