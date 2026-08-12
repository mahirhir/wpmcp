<?php

namespace WPMCP\Compliance\Reporters;

use WPMCP\Compliance\Report;

interface Reporter
{
    /**
     * Render the report. Reporters return a string; nothing writes to stdout
     * except the CLI entry point, so every format is testable.
     */
    public function render(Report $report): string;
}
