<?php

namespace WPMCP\Tests\Free\Compliance;

use WPMCP\Compliance\Cli;
use WPMCP\Compliance\Profile;
use WPMCP\Compliance\Reporters\Json_Reporter;
use WPMCP\Compliance\Reporters\Markdown_Reporter;
use WPMCP\Compliance\Reporters\Table_Reporter;
use WPMCP\Compliance\Rule_Registry;
use WPMCP\Compliance\Runner;
use WPMCP\Compliance\Severity;

/**
 * The command line contract: CI depends on the exit codes and on the three
 * output formats staying parseable.
 */
class CliTest extends Compliance_Test_Case
{
    private function violating_tree(): string
    {
        return $this->make_plugin([
            'example-toolkit.php' => $this->main_file(),
            'readme.txt' => $this->readme(),
            'includes/gate.php' => "<?php\nclass Gate {\n    public static function run() {\n        return self::is_pro();\n    }\n}\n",
        ]);
    }

    public function test_blockers_exit_non_zero_and_clean_trees_exit_zero(): void
    {
        $cli = new Cli(sys_get_temp_dir());

        $dirty = $cli->run(['--profile=wporg-free', '--path=' . $this->violating_tree(), '--rule=WPORG-05-TRIALWARE']);
        $this->assertSame(Cli::EXIT_FINDINGS, $dirty['status']);
        $this->assertStringContainsString('FAIL:', $dirty['output']);

        $clean = $cli->run(['--profile=wporg-free', '--path=' . $this->make_plugin([
            'example-toolkit.php' => $this->main_file(),
            'readme.txt' => $this->readme(),
        ]), '--rule=WPORG-05-TRIALWARE']);
        $this->assertSame(Cli::EXIT_OK, $clean['status']);
        $this->assertStringContainsString('no findings', $clean['output']);
    }

    public function test_the_distribution_profile_does_not_fail_on_paid_gating(): void
    {
        $cli = new Cli(sys_get_temp_dir());
        $result = $cli->run(['--profile=distribution', '--path=' . $this->violating_tree(), '--rule=WPORG-05-TRIALWARE']);

        $this->assertSame(Cli::EXIT_OK, $result['status']);
        $this->assertStringContainsString('best-practice', $result['output']);
    }

    public function test_fail_on_lowers_the_bar(): void
    {
        $cli = new Cli(sys_get_temp_dir());
        $arguments = ['--profile=distribution', '--path=' . $this->violating_tree(), '--rule=WPORG-05-TRIALWARE'];

        $this->assertSame(Cli::EXIT_OK, $cli->run($arguments)['status']);
        $this->assertSame(Cli::EXIT_FINDINGS, $cli->run(array_merge($arguments, ['--fail-on=best-practice']))['status']);
    }

    public function test_json_output_is_valid_and_carries_the_finding_metadata(): void
    {
        $cli = new Cli(sys_get_temp_dir());
        $result = $cli->run([
            '--profile=wporg-free',
            '--path=' . $this->violating_tree(),
            '--rule=WPORG-05-TRIALWARE',
            '--format=json',
        ]);

        $decoded = json_decode($result['output'], true);
        $this->assertIsArray($decoded);
        $this->assertSame('wporg-free', $decoded['profile']);
        $this->assertTrue($decoded['blocking']);
        $this->assertSame(1, $decoded['counts']['blocker']);
        $this->assertSame('WPORG-05-TRIALWARE', $decoded['findings'][0]['rule']);
        $this->assertSame('includes/gate.php', $decoded['findings'][0]['file']);
        $this->assertSame(4, $decoded['findings'][0]['line']);
        $this->assertStringContainsString('Guideline 5', $decoded['findings'][0]['guideline']);
    }

    public function test_markdown_output_includes_the_rule_explanation(): void
    {
        $cli = new Cli(sys_get_temp_dir());
        $result = $cli->run([
            '--profile=wporg-free',
            '--path=' . $this->violating_tree(),
            '--rule=WPORG-05-TRIALWARE',
            '--format=markdown',
        ]);

        $this->assertStringContainsString('# WordPress.org compliance report', $result['output']);
        $this->assertStringContainsString('## WPORG-05-TRIALWARE', $result['output']);
        $this->assertStringContainsString('restricted or locked', $result['output']);
        $this->assertStringContainsString('`includes/gate.php:4`', $result['output']);
    }

    public function test_pack_selection_runs_only_that_pack(): void
    {
        $cli = new Cli(sys_get_temp_dir());
        $result = $cli->run([
            '--profile=wporg-free',
            '--path=' . $this->violating_tree(),
            '--pack=security',
            '--format=json',
        ]);

        $decoded = json_decode($result['output'], true);
        $this->assertSame(count(Rule_Registry::pack('security')), count($decoded['rules_run']));
        $this->assertContains('PCP-NONCE-CAP', $decoded['rules_run']);
        $this->assertNotContains('WPORG-05-TRIALWARE', $decoded['rules_run']);
    }

    public function test_list_rules_and_explain(): void
    {
        $cli = new Cli(sys_get_temp_dir());

        $list = $cli->run(['--list-rules']);
        $this->assertSame(Cli::EXIT_OK, $list['status']);
        $this->assertStringContainsString('WPORG-05-TRIALWARE', $list['output']);
        $this->assertStringContainsString(sprintf('%d rules', count(Rule_Registry::all())), $list['output']);

        $explain = $cli->run(['--explain=wporg-05-quota']);
        $this->assertSame(Cli::EXIT_OK, $explain['status']);
        $this->assertStringContainsString('WPORG-05-QUOTA', $explain['output']);
        $this->assertStringContainsString('Numeric cap that varies with paid state', $explain['output']);

        $unknown = $cli->run(['--explain=NOPE']);
        $this->assertSame(Cli::EXIT_USAGE, $unknown['status']);
    }

    public function test_help_and_usage_errors(): void
    {
        $cli = new Cli(sys_get_temp_dir());

        $this->assertStringContainsString('Usage:', $cli->run(['--help'])['output']);
        $this->assertSame(Cli::EXIT_OK, $cli->run(['-h'])['status']);

        foreach ([['--profile=nope'], ['--format=xml'], ['--fail-on=meh'], ['--pack=nope'], ['--wat=1'], ['nonsense']] as $arguments) {
            $result = $cli->run($arguments);
            $this->assertSame(Cli::EXIT_USAGE, $result['status'], implode(' ', $arguments));
            $this->assertStringContainsString('error:', $result['output']);
        }

        $missing = $cli->run(['--path=/nonexistent/compliance/target']);
        $this->assertSame(Cli::EXIT_USAGE, $missing['status']);
        $this->assertStringContainsString('is not a directory', $missing['output']);
    }

    public function test_relative_paths_resolve_against_the_working_directory(): void
    {
        $root = $this->violating_tree();
        $cli = new Cli(dirname($root));

        $result = $cli->run(['--path=' . basename($root), '--rule=WPORG-05-TRIALWARE', '--format=json']);
        $decoded = json_decode($result['output'], true);

        $this->assertSame(realpath($root), $decoded['root']);
    }

    public function test_artifact_flags_override_the_profile_default(): void
    {
        $root = $this->make_plugin([
            'example-toolkit.php' => $this->main_file(),
            'phpunit.xml.dist' => "<phpunit/>\n",
        ]);
        $cli = new Cli(sys_get_temp_dir());

        $strict = $cli->run(['--profile=wporg-free', '--path=' . $root, '--rule=PCP-FILE-HYGIENE', '--format=json']);
        $relaxed = $cli->run(['--profile=wporg-free', '--no-artifact', '--path=' . $root, '--rule=PCP-FILE-HYGIENE', '--format=json']);

        $this->assertSame(1, json_decode($strict['output'], true)['counts']['blocker']);
        $this->assertSame(0, json_decode($relaxed['output'], true)['counts']['blocker']);
    }

    public function test_exclude_option_narrows_the_scan(): void
    {
        $root = $this->make_plugin([
            'example-toolkit.php' => $this->main_file(),
            'thirdparty/gate.php' => "<?php\nclass Gate { public static function run() { return self::is_pro(); } }\n",
        ]);
        $cli = new Cli(sys_get_temp_dir());

        $included = $cli->run(['--path=' . $root, '--profile=wporg-free', '--rule=WPORG-05-TRIALWARE', '--format=json']);
        $excluded = $cli->run(['--path=' . $root, '--profile=wporg-free', '--rule=WPORG-05-TRIALWARE', '--exclude=thirdparty', '--format=json']);

        $this->assertSame(1, json_decode($included['output'], true)['counts']['blocker']);
        $this->assertSame(0, json_decode($excluded['output'], true)['counts']['blocker']);
    }

    public function test_reporters_render_an_empty_report(): void
    {
        $report = (new Runner([]))->run($this->context(['example-toolkit.php' => $this->main_file()]));

        $this->assertStringContainsString('no findings', (new Table_Reporter())->render($report));
        $this->assertStringContainsString('No findings.', (new Markdown_Reporter())->render($report));
        $this->assertSame([], json_decode((new Json_Reporter())->render($report), true)['findings']);
    }

    public function test_table_reporter_colour_mode_wraps_the_badge(): void
    {
        $context = $this->context([
            'example-toolkit.php' => $this->main_file(),
            'includes/gate.php' => "<?php\nclass Gate { public static function run() { return self::is_pro(); } }\n",
        ]);
        $report = (new Runner([Rule_Registry::get('WPORG-05-TRIALWARE')]))->run($context);

        $plain = (new Table_Reporter())->render($report);
        $coloured = (new Table_Reporter(true))->render($report);

        $this->assertStringContainsString('[BLOCKER]', $plain);
        $this->assertStringContainsString("\033[1;31m[BLOCKER]", $coloured);
    }

    public function test_severity_sorting_puts_blockers_first(): void
    {
        $context = $this->context([
            'example-toolkit.php' => $this->main_file(['License' => '']),
            'includes/screen.php' => "<?php\nif ( ! defined( 'ABSPATH' ) ) { exit; }\nfunction example_screen( \$label ) {\n    echo \$label;\n}\n",
        ], Profile::wporg_free());
        $report = (new Runner([
            Rule_Registry::get('PCP-ESCAPING'),
            Rule_Registry::get('WPORG-01-GPL'),
        ]))->run($context);

        $ranks = [];
        foreach ($report->findings() as $finding) {
            $ranks[] = Severity::rank($finding->severity());
        }
        $sorted = $ranks;
        rsort($sorted);

        $this->assertSame(Severity::BLOCKER, $report->findings()[0]->severity());
        $this->assertSame($sorted, $ranks, 'findings must be ordered most severe first');
        $this->assertContains(Severity::rank(Severity::LIKELY_REJECT), $ranks);
    }
}
