<?php

namespace WPMCP\Compliance\Reporters;

use WPMCP\Compliance\Report;
use WPMCP\Compliance\Rule_Registry;

/**
 * Markdown output, for pasting into an issue or a PR comment. Includes each
 * rule's explanation, so the reader does not have to open the guidelines.
 */
final class Markdown_Reporter implements Reporter
{
    public function render(Report $report): string
    {
        $out = "# WordPress.org compliance report\n\n";
        $out .= sprintf("- Profile: `%s`\n", $report->profile());
        $out .= sprintf("- Tree: `%s`\n", $report->root());
        $out .= sprintf("- Rules run: %d\n", $report->rule_count());
        $out .= sprintf("- PHP files scanned: %d\n\n", $report->files_scanned());

        $out .= "| Severity | Count |\n| --- | --- |\n";
        foreach ($report->counts() as $severity => $count) {
            $out .= sprintf("| %s | %d |\n", $severity, $count);
        }
        $out .= "\n";

        if ([] === $report->findings()) {
            return $out . "No findings.\n";
        }

        foreach ($report->by_rule() as $rule_id => $findings) {
            $rule = Rule_Registry::get($rule_id);
            $out .= sprintf(
                "## %s: %s\n\n",
                $rule_id,
                null === $rule ? 'engine error' : $rule->title()
            );
            $out .= sprintf("**Severity:** %s  \n", $findings[0]->severity());
            $out .= sprintf("**Source:** %s\n\n", $findings[0]->guideline());
            if (null !== $rule) {
                $out .= $rule->explanation() . "\n\n";
            }
            $out .= "| Location | Detail |\n| --- | --- |\n";
            foreach ($findings as $finding) {
                $out .= sprintf(
                    "| `%s` | %s |\n",
                    $finding->location(),
                    str_replace('|', '\\|', $finding->message())
                );
            }
            $out .= "\n";
        }

        $out .= $report->has_blockers()
            ? sprintf("**%d blocker(s) must be resolved before submission.**\n", $report->count_of('blocker'))
            : "**No blockers.**\n";

        return $out;
    }
}
