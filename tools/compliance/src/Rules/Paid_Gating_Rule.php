<?php

namespace WPMCP\Compliance\Rules;

use WPMCP\Compliance\Rule_Context;

/**
 * Guideline 5: "Plugins may not contain functionality that is restricted or
 * locked, only to be made available by payment or upgrade."
 */
final class Paid_Gating_Rule extends Base_Rule
{
    /**
     * Predicates whose return value is a paid state. Matched at token level,
     * so a docblock that merely names Gate::is_pro() is not a finding.
     */
    public const PREDICATES = [
        'is_pro',
        'is_premium',
        'is_paying',
        'is_paying_or_trial',
        'can_use_premium_code',
        'can_use_premium_code__premium_only',
        'has_license',
        'has_active_license',
        'is_plan',
        'is_plan_or_trial',
        'is_free_plan',
        'is_trial',
        'can_use',
    ];

    public function id(): string
    {
        return 'WPORG-05-TRIALWARE';
    }

    public function guideline(): string
    {
        return 'Guideline 5, trialware is not permitted';
    }

    public function title(): string
    {
        return 'Paid-state gating of shipped code';
    }

    public function explanation(): string
    {
        return 'Guideline 5 prohibits functionality that is "restricted or locked, only to be made '
            . 'available by payment or upgrade", and guideline 9 separately prohibits "implying users '
            . 'must pay to unlock included features". A branch on a paid predicate means the paid '
            . 'capability is in the zip and merely switched off, which is the case the guideline names. '
            . 'The compliant shape is to strip the paid code from the directory build (guideline 5 '
            . 'recommends "add-on plugins, hosted outside of WordPress.org, in order to exclude the '
            . 'premium code"), not to gate it at runtime.';
    }

    public function check(Rule_Context $context): array
    {
        $findings = [];
        foreach ($context->php_files() as $file) {
            foreach ($file->find_calls(self::PREDICATES) as $call) {
                // 'pro_active' => Gate::is_pro() reports the paid state, it
                // does not act on it. Saying it "decides behaviour" would be
                // untrue of that line. It still has to go when the gate is
                // removed, so it is reported, just not as the locked-code case.
                if ($call['array_value']) {
                    $findings[] = $this->finding(
                        $file,
                        $call['line'],
                        sprintf(
                            'paid-state predicate %s() is reported as a value rather than branched on; it locks nothing by itself, but it disappears with the gate when the paid code is stripped from the directory build',
                            $call['name']
                        ),
                        \WPMCP\Compliance\Severity::REVIEWER_DISCRETION
                    );
                    continue;
                }
                $findings[] = $this->finding(
                    $file,
                    $call['line'],
                    sprintf(
                        'paid-state predicate %s() decides behaviour in shipped code; the gated capability is present in the build',
                        $call['name']
                    )
                );
            }
            // Symbol level for the naming convention, so a docblock that
            // merely explains why __premium_only is not used stays quiet;
            // raw text for @fs_premium_only, which lives in docblocks by
            // design because the SDK's free-build processor reads it there.
            foreach ($file->tokens() as $token) {
                if (! is_array($token) || T_STRING !== $token[0]) {
                    continue;
                }
                if (! str_ends_with(strtolower($token[1]), '__premium_only')) {
                    continue;
                }
                $findings[] = $this->finding($file, $token[2], sprintf('%s is a Freemius premium-only symbol: the paid code path lives in the same source tree', $token[1]));
            }
            foreach ($file->grep('/@fs_premium_only/') as $hit) {
                $findings[] = $this->finding(
                    $file,
                    $hit['line'],
                    '@fs_premium_only marks code the SDK strips from the free build, which means the paid code path lives in the same source tree'
                );
            }
            if (str_ends_with($file->relative_path(), '__premium_only.php')) {
                $findings[] = $this->finding($file, 1, 'premium-only file: the paid code ships in the same tree and is excluded by the SDK rather than by the build');
            }
        }
        return $findings;
    }
}
