<?php

namespace WPMCP\Tests\Free\Compliance;

use WPMCP\Compliance\Rules\Gpl_License_Rule;
use WPMCP\Compliance\Rules\Licensing_Sdk_Rule;
use WPMCP\Compliance\Rules\Paid_Gating_Rule;
use WPMCP\Compliance\Rules\Paid_Quota_Rule;

/**
 * Group A of the rulebook: licensing, gating, monetisation.
 */
class LicensingRulesTest extends Compliance_Test_Case
{
    public function test_paid_gating_reports_every_predicate_call_site(): void
    {
        $findings = $this->findings(new Paid_Gating_Rule(), [
            'example-toolkit.php' => $this->main_file(),
            'includes/gate.php' => "<?php\nclass Gate {\n    public static function is_pro() { return false; }\n}\n",
            'includes/feature.php' => "<?php\nif ( Gate::is_pro() ) {\n    render_premium();\n}\n",
        ]);

        $this->assert_reports($findings, 'is_pro()');
        $this->assertContains('includes/feature.php:2', $this->locations($findings));
    }

    /**
     * 'pro_active' => Gate::is_pro() reports the paid state, it does not act on
     * it, so it locks nothing. Saying it "decides behaviour" would be untrue of
     * that line. It still has to go with the gate, so it is reported, just not
     * as the locked-code case.
     */
    public function test_paid_predicate_in_a_value_position_is_not_a_blocker(): void
    {
        $context = "<?php\nclass Context {\n    public function report() {\n        return [\n";
        $context .= "            'version'    => '1.0',\n            'pro_active' => Gate::is_pro(),\n        ];\n    }\n}\n";

        $findings = $this->findings(new Paid_Gating_Rule(), [
            'example-toolkit.php' => $this->main_file(),
            'includes/context.php' => $context,
        ]);

        $this->assertCount(1, $findings);
        $this->assert_reports($findings, 'reported as a value rather than branched on');
        $this->assertSame('reviewer-discretion', $findings[0]->severity_override());
    }

    /**
     * Assignment is not a value position: $is_pro = Gate::is_pro() exists to be
     * branched on a line later, and downgrading it would hide a real gate.
     */
    public function test_paid_predicate_assigned_to_a_variable_is_still_a_blocker(): void
    {
        $screen = "<?php\nclass Screen {\n    public function render() {\n        \$is_pro = Gate::is_pro();\n";
        $screen .= "        if ( \$is_pro ) {\n            return 'premium';\n        }\n        return 'free';\n    }\n}\n";

        $findings = $this->findings(new Paid_Gating_Rule(), [
            'example-toolkit.php' => $this->main_file(),
            'includes/screen.php' => $screen,
        ]);

        $this->assertCount(1, $findings);
        $this->assert_reports($findings, 'decides behaviour in shipped code');
        $this->assertNull($findings[0]->severity_override());
    }

    public function test_paid_gating_ignores_a_docblock_mention(): void
    {
        $findings = $this->findings(new Paid_Gating_Rule(), [
            'example-toolkit.php' => $this->main_file(),
            'includes/notes.php' => "<?php\n/**\n * Uses can_use_premium_code(), not the __premium_only variant.\n */\nclass Notes {}\n",
        ]);

        $this->assert_clean($findings);
    }

    public function test_paid_gating_reports_freemius_premium_markers(): void
    {
        $findings = $this->findings(new Paid_Gating_Rule(), [
            'example-toolkit.php' => $this->main_file(),
            'includes/pro.php' => "<?php\nclass Pro {\n    public function render__premium_only() {}\n}\n",
            'includes/annotated.php' => "<?php\n/**\n * @fs_premium_only\n */\nclass Annotated {}\n",
        ]);

        $this->assert_reports($findings, 'render__premium_only');
        $this->assert_reports($findings, '@fs_premium_only');
    }

    public function test_paid_quota_reports_an_unbounded_sentinel_beside_a_paid_predicate(): void
    {
        $findings = $this->findings(new Paid_Quota_Rule(), [
            'example-toolkit.php' => $this->main_file(),
            'includes/gate.php' => "<?php\nclass Gate {\n    public static function history_limit(): int {\n        return self::is_pro() ? PHP_INT_MAX : 20;\n    }\n}\n",
        ]);

        $this->assert_reports($findings, 'PHP_INT_MAX');
        $this->assert_reports($findings, 'history_limit()');
    }

    public function test_paid_quota_ignores_an_unconditional_cap(): void
    {
        $findings = $this->findings(new Paid_Quota_Rule(), [
            'example-toolkit.php' => $this->main_file(),
            'includes/gate.php' => "<?php\nclass Gate {\n    public static function history_limit(): int {\n        return 20;\n    }\n}\n",
        ]);

        $this->assert_clean($findings);
    }

    public function test_paid_quota_ignores_a_sentinel_far_from_any_paid_predicate(): void
    {
        $body = "<?php\nclass Gate {\n    public static function is_pro(): bool { return false; }\n";
        $body .= str_repeat("    // filler line so the sentinel is out of range\n", 12);
        $body .= "    public static function ceiling(): int { return PHP_INT_MAX; }\n}\n";

        $findings = $this->findings(new Paid_Quota_Rule(), [
            'example-toolkit.php' => $this->main_file(),
            'includes/gate.php' => $body,
        ]);

        $this->assert_clean($findings);
    }

    public function test_licensing_rule_reports_the_sdk_and_a_bypassed_opt_in(): void
    {
        $findings = $this->findings(new Licensing_Sdk_Rule(), [
            'example-toolkit.php' => $this->main_file(),
            'includes/licensing.php' => "<?php\nfunction boot() {\n    return fs_dynamic_init( [\n        'id' => 1,\n        'anonymous_mode' => true,\n    ] );\n}\n",
            'composer.json' => "{\n  \"require\": {\n    \"freemius/wordpress-sdk\": \"^2.13\"\n  }\n}\n",
        ]);

        $this->assert_reports($findings, 'fs_dynamic_init()');
        $this->assert_reports($findings, 'anonymous_mode is enabled');
        $this->assert_reports($findings, 'shipped composer dependency');
    }

    public function test_licensing_rule_is_quiet_without_a_licensing_sdk(): void
    {
        $findings = $this->findings(new Licensing_Sdk_Rule(), [
            'example-toolkit.php' => $this->main_file(),
            'includes/plain.php' => "<?php\nclass Plain { public function run() {} }\n",
            'composer.json' => "{\n  \"require\": { \"php\": \">=8.1\" }\n}\n",
        ]);

        $this->assert_clean($findings);
    }

    public function test_gpl_rule_reports_a_missing_licence(): void
    {
        $findings = $this->findings(new Gpl_License_Rule(), [
            'example-toolkit.php' => $this->main_file(['License' => '']),
            'readme.txt' => $this->readme(['License' => 'Proprietary']),
        ]);

        $this->assert_reports($findings, 'no License header');
        $this->assert_reports($findings, 'not a recognised GPL-compatible identifier');
        $this->assert_reports($findings, 'no licence text ships');
    }

    public function test_gpl_rule_accepts_the_recommended_declaration(): void
    {
        $findings = $this->findings(new Gpl_License_Rule(), [
            'example-toolkit.php' => $this->main_file(),
            'readme.txt' => $this->readme(),
            'LICENSE' => "GNU GENERAL PUBLIC LICENSE Version 2\n",
        ]);

        $this->assert_clean($findings);
    }
}
