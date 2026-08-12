<?php

namespace WPMCP\Tests\Free\Compliance;

use WPMCP\Compliance\Finding;
use WPMCP\Compliance\Profile;
use WPMCP\Compliance\Rule_Context;
use WPMCP\Compliance\Runner;
use WPMCP\Compliance\Severity;

/**
 * End to end over the two committed fixture plugins in fixtures/: one that is
 * meant to pass everything, one that trips a rule from every pack.
 *
 * These are the tests that catch a rule which fires on ordinary, correct
 * WordPress code.
 */
class RunnerIntegrationTest extends Compliance_Test_Case
{
    private function report(string $fixture, ?Profile $profile = null)
    {
        return Runner::with_default_rules()->run(
            Rule_Context::for_path($this->fixture_path($fixture), $profile ?? Profile::wporg_free())
        );
    }

    public function test_the_clean_fixture_passes_the_whole_rule_set(): void
    {
        $report = $this->report('clean-toolkit');

        $this->assertFalse(
            $report->has_blockers(),
            "the clean fixture must not trip any rule:\n" . implode("\n", array_map(
                static fn (Finding $finding) => $finding->rule_id() . ' ' . $finding->location() . ' ' . $finding->message(),
                $report->findings()
            ))
        );
        $this->assertSame([], $report->findings());
        $this->assertSame(count($report->rules_run()), $report->rule_count());
    }

    public function test_the_violating_fixture_trips_a_rule_in_every_pack(): void
    {
        $report = $this->report('violating-plugin');
        $rules = array_keys($report->by_rule());

        $expected = [
            'WPORG-05-TRIALWARE',
            'WPORG-05-QUOTA',
            'WPORG-07-EXTERNAL-SERVICES',
            'WPORG-09-PRIVACY-CLAIM',
            'WPORG-08-UPDATER',
            'PCP-LOCALHOST',
            'WPORG-09-EXEC',
            'WPORG-11-ADMIN-NAG',
            'WPORG-12-README',
            'WPORG-12-SHORT-URL',
            'WPORG-17-TRADEMARK',
            'PCP-DIRECT-FILE-ACCESS',
            'PCP-ESCAPING',
            'PCP-INPUT-SANITIZATION',
            'PCP-NONCE-CAP',
            'WPORG-01-GPL',
        ];
        foreach ($expected as $rule_id) {
            $this->assertContains($rule_id, $rules, $rule_id . ' should have fired on the violating fixture');
        }
        $this->assertTrue($report->has_blockers());
    }

    public function test_every_finding_points_at_a_real_file_and_line(): void
    {
        $report = $this->report('violating-plugin');

        foreach ($report->findings() as $finding) {
            $path = $this->fixture_path('violating-plugin') . '/' . $finding->file();
            $this->assertGreaterThanOrEqual(0, $finding->line());
            if (0 === $finding->line()) {
                // A file-wide finding may be about a path that is missing,
                // which is the point of it.
                continue;
            }
            $this->assertFileExists($path, $finding->rule_id() . ' reported a path that does not exist');
            $this->assertLessThanOrEqual(
                count(file($path)),
                $finding->line(),
                $finding->rule_id() . ' reported a line past the end of ' . $finding->file()
            );
        }
    }

    public function test_the_distribution_profile_clears_the_wporg_only_blockers(): void
    {
        $strict = $this->report('violating-plugin');
        $lenient = $this->report('violating-plugin', Profile::distribution());

        $this->assertGreaterThan(
            $lenient->count_of(Severity::BLOCKER),
            $strict->count_of(Severity::BLOCKER),
            'the wporg-free profile must be strictly harsher than distribution'
        );

        $lenient_blockers = [];
        foreach ($lenient->findings() as $finding) {
            if (Severity::BLOCKER === $finding->severity()) {
                $lenient_blockers[] = $finding->rule_id();
            }
        }
        $this->assertNotContains('WPORG-05-TRIALWARE', $lenient_blockers);
        $this->assertNotContains('WPORG-12-README', $lenient_blockers);
        // Dishonest privacy copy and missing security checks are defects
        // wherever the zip comes from.
        $this->assertContains('WPORG-09-PRIVACY-CLAIM', $lenient_blockers);
        $this->assertContains('PCP-NONCE-CAP', $lenient_blockers);
    }

    public function test_the_whole_rule_set_runs_against_this_repository_without_crashing(): void
    {
        $repository = dirname(__DIR__, 3);
        $report = Runner::with_default_rules()->run(
            Rule_Context::for_path($repository, Profile::distribution())
        );

        $this->assertGreaterThan(300, $report->files_scanned(), 'the repository scan should see the whole plugin');
        foreach ($report->findings() as $finding) {
            $this->assertStringNotContainsString('failed to run', $finding->message(), $finding->rule_id());
        }
    }
}
