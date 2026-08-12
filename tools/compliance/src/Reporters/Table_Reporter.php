<?php

namespace WPMCP\Compliance\Reporters;

use WPMCP\Compliance\Finding;
use WPMCP\Compliance\Report;
use WPMCP\Compliance\Rule_Registry;
use WPMCP\Compliance\Severity;

/**
 * Human-readable output: one block per rule, most severe first, with the
 * file:line evidence under each.
 */
final class Table_Reporter implements Reporter
{
    private const COLOURS = [
        Severity::BLOCKER => "\033[1;31m",
        Severity::LIKELY_REJECT => "\033[0;31m",
        Severity::REVIEWER_DISCRETION => "\033[0;33m",
        Severity::BEST_PRACTICE => "\033[0;36m",
    ];
    private const RESET = "\033[0m";

    public function __construct(private bool $colour = false)
    {
    }

    public function render(Report $report): string
    {
        $out = sprintf(
            "wp.org compliance: profile %s, %d rules, %d PHP files under %s\n",
            $report->profile(),
            $report->rule_count(),
            $report->files_scanned(),
            $report->root()
        );
        $out .= str_repeat('=', 78) . "\n";

        if ([] === $report->findings()) {
            return $out . "\nno findings\n";
        }

        foreach ($report->by_rule() as $rule_id => $findings) {
            $rule = Rule_Registry::get($rule_id);
            $out .= sprintf(
                "\n%s  %s\n",
                $this->badge($findings[0]->severity()),
                $rule_id
            );
            $out .= sprintf("    %s\n", null === $rule ? $findings[0]->message() : $rule->title());
            $out .= sprintf("    %s\n", $findings[0]->guideline());
            foreach ($findings as $finding) {
                $out .= sprintf("      %s\n", $this->line($finding));
            }
        }

        $out .= "\n" . str_repeat('-', 78) . "\n";
        foreach ($report->counts() as $severity => $count) {
            $out .= sprintf("  %-22s %d\n", $severity, $count);
        }
        $out .= sprintf(
            "\n%s\n",
            $report->has_blockers()
                ? sprintf('FAIL: %d blocker(s) must be resolved before submission', $report->count_of(Severity::BLOCKER))
                : 'PASS: no blockers'
        );

        return $out;
    }

    private function line(Finding $finding): string
    {
        $text = sprintf('%s  %s', $finding->location(), $finding->message());
        if (Severity::BLOCKER !== $finding->severity()) {
            $text .= sprintf(' [%s]', $finding->severity());
        }
        return $text;
    }

    private function badge(string $severity): string
    {
        $label = sprintf('[%s]', strtoupper($severity));
        if (! $this->colour) {
            return $label;
        }
        return (self::COLOURS[$severity] ?? '') . $label . self::RESET;
    }
}
