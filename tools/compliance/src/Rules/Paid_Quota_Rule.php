<?php

namespace WPMCP\Compliance\Rules;

use WPMCP\Compliance\Rule_Context;
use WPMCP\Compliance\Source_File;

/**
 * Guideline 5: "Functionality may not be disabled after a trial period or
 * quota is met."
 *
 * The quota form of trialware: a numeric cap whose value depends on whether
 * the site paid. Detected as an unbounded sentinel (PHP_INT_MAX, INF) sitting
 * next to a paid predicate, or a limit-shaped accessor whose body consults
 * one.
 */
final class Paid_Quota_Rule extends Base_Rule
{
    private const SENTINELS = ['PHP_INT_MAX', 'INF', 'PHP_FLOAT_MAX'];
    private const PROXIMITY = 8;

    public function id(): string
    {
        return 'WPORG-05-QUOTA';
    }

    public function guideline(): string
    {
        return 'Guideline 5, trialware is not permitted (quota form)';
    }

    public function title(): string
    {
        return 'Numeric cap that varies with paid state';
    }

    public function explanation(): string
    {
        return 'Guideline 5 says functionality "may not be disabled after a trial period or quota is '
            . 'met". A limit that reads one value for free sites and an unbounded value for paying '
            . 'ones is that prohibition exactly, and it is the shape reviewers fork-and-flip to prove '
            . 'the point. Either make the cap unconditional in the directory build, with no paid '
            . 'branch and no unbounded path in the source, or move the storage behind a documented '
            . 'service and let the service enforce the limit (guideline 6).';
    }

    public function check(Rule_Context $context): array
    {
        $findings = [];
        foreach ($context->php_files() as $file) {
            $predicate_lines = array_column($file->find_calls(Paid_Gating_Rule::PREDICATES), 'line');
            if ([] === $predicate_lines) {
                continue;
            }
            foreach ($file->find_symbols(array_map('strtolower', self::SENTINELS)) as $sentinel) {
                foreach ($predicate_lines as $predicate_line) {
                    if (abs($sentinel['line'] - $predicate_line) > self::PROXIMITY) {
                        continue;
                    }
                    $findings[] = $this->finding(
                        $file,
                        $sentinel['line'],
                        sprintf(
                            'unbounded sentinel %s is reachable only on a paid branch (paid predicate at line %d): a quota lifted by payment',
                            strtoupper($sentinel['name']),
                            $predicate_line
                        )
                    );
                    break;
                }
            }
            foreach ($this->limit_accessors($file, $predicate_lines) as $accessor) {
                $findings[] = $this->finding(
                    $file,
                    $accessor['line'],
                    sprintf(
                        '%s() returns a quota that depends on paid state; the free cap is enforced locally rather than by a service',
                        $accessor['name']
                    )
                );
            }
        }
        return $findings;
    }

    /**
     * Functions named like a cap whose body calls a paid predicate.
     *
     * @param  int[] $predicate_lines
     * @return array<int,array{name:string,line:int}>
     */
    private function limit_accessors(Source_File $file, array $predicate_lines): array
    {
        $functions = [];
        foreach ($file->grep('/function\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(/') as $hit) {
            preg_match('/function\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(/', $hit['text'], $matches);
            $functions[] = ['name' => $matches[1], 'line' => $hit['line']];
        }
        $accessors = [];
        foreach ($functions as $index => $function) {
            if (! preg_match('/(_limit|_cap|_quota|_max|^max_|^limit_)/i', $function['name'])) {
                continue;
            }
            $ends_at = $functions[$index + 1]['line'] ?? PHP_INT_MAX;
            foreach ($predicate_lines as $predicate_line) {
                if ($predicate_line >= $function['line'] && $predicate_line < $ends_at) {
                    $accessors[] = $function;
                    break;
                }
            }
        }
        return $accessors;
    }
}
