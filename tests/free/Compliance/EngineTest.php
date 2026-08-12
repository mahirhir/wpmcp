<?php

namespace WPMCP\Tests\Free\Compliance;

use WPMCP\Compliance\Finding;
use WPMCP\Compliance\Http_Index;
use WPMCP\Compliance\Plugin_Header;
use WPMCP\Compliance\Plugin_Source;
use WPMCP\Compliance\Profile;
use WPMCP\Compliance\Readme_File;
use WPMCP\Compliance\Rule;
use WPMCP\Compliance\Rule_Context;
use WPMCP\Compliance\Rule_Registry;
use WPMCP\Compliance\Runner;
use WPMCP\Compliance\Severity;
use WPMCP\Compliance\Source_File;

/**
 * The engine itself: severities, profiles, the runner contract and the
 * parsing helpers every rule depends on.
 */
class EngineTest extends Compliance_Test_Case
{
    public function test_severity_ranking_and_validation(): void
    {
        $this->assertTrue(Severity::is_valid(Severity::BLOCKER));
        $this->assertFalse(Severity::is_valid('catastrophe'));
        $this->assertTrue(Severity::at_least(Severity::BLOCKER, Severity::BEST_PRACTICE));
        $this->assertFalse(Severity::at_least(Severity::BEST_PRACTICE, Severity::BLOCKER));
        $this->assertSame(0, Severity::rank('catastrophe'));
        $this->assertCount(4, Severity::all());
    }

    public function test_finding_location_and_serialisation(): void
    {
        $rule = Rule_Registry::get('WPORG-05-TRIALWARE');
        $this->assertInstanceOf(Rule::class, $rule);

        $bound = (new Finding('src/Gate.php', 12, 'gated', 'return self::is_pro();'))
            ->bind($rule, Severity::BLOCKER);

        $this->assertSame('src/Gate.php:12', $bound->location());
        $this->assertSame('WPORG-05-TRIALWARE', $bound->rule_id());
        $this->assertSame(Severity::BLOCKER, $bound->severity());
        $this->assertSame('gated', $bound->to_array()['message']);
        $this->assertSame('readme.txt', (new Finding('readme.txt', 0, 'x'))->location());
    }

    public function test_every_registered_rule_has_complete_metadata_and_a_unique_id(): void
    {
        $ids = [];
        foreach (Rule_Registry::all() as $rule) {
            $this->assertNotSame('', $rule->id());
            $this->assertNotSame('', $rule->title());
            $this->assertNotSame('', $rule->guideline());
            $this->assertGreaterThan(80, strlen($rule->explanation()), $rule->id() . ' needs a real explanation');
            $this->assertTrue(Severity::is_valid($rule->default_severity()), $rule->id());
            $this->assertNotContains($rule->id(), $ids, 'duplicate rule id ' . $rule->id());
            $this->assertNotSame('other', Rule_Registry::pack_of($rule->id()), $rule->id() . ' is not in a pack');
            $ids[] = $rule->id();
        }
        $this->assertSame($ids, Rule_Registry::ids());
        $this->assertNull(Rule_Registry::get('NOPE-1'));
        $this->assertSame([], Rule_Registry::pack('nope'));
    }

    public function test_profiles_differ_only_where_the_guidelines_differ(): void
    {
        $rule = Rule_Registry::get('WPORG-05-TRIALWARE');
        $security = Rule_Registry::get('PCP-NONCE-CAP');

        $this->assertSame(Severity::BLOCKER, Profile::wporg_free()->severity_for($rule));
        $this->assertSame(Severity::BEST_PRACTICE, Profile::distribution()->severity_for($rule));
        $this->assertSame(Severity::BLOCKER, Profile::distribution()->severity_for($security));
        $this->assertTrue(Profile::wporg_free()->is_artifact_scan());
        $this->assertFalse(Profile::distribution()->is_artifact_scan());
        $this->assertFalse(Profile::wporg_free()->is_muted($rule));
    }

    public function test_unknown_profile_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Profile::named('sandbox');
    }

    public function test_profile_options_can_be_overridden_without_touching_severities(): void
    {
        $profile = Profile::wporg_free()->with_options(['artifact' => false]);

        $this->assertFalse($profile->is_artifact_scan());
        $this->assertSame(Profile::WPORG_FREE, $profile->name());
        $this->assertNotSame('', $profile->description());
        $this->assertFalse($profile->allows_exec('src/Tools/Code/Php_Snippet_Runner.php', 'eval'));
        $this->assertTrue(Profile::distribution()->allows_exec('src/Tools/Code/Php_Snippet_Runner.php', 'EVAL'));
    }

    public function test_runner_applies_profile_severity_and_counts_findings(): void
    {
        $context = $this->context([
            'example-toolkit.php' => $this->main_file(),
            'includes/gate.php' => "<?php\nclass Gate {\n    public static function run() {\n        return self::is_pro();\n    }\n}\n",
        ]);

        $report = (new Runner([Rule_Registry::get('WPORG-05-TRIALWARE')]))->run($context);

        $this->assertTrue($report->has_blockers());
        $this->assertSame(1, $report->count_of(Severity::BLOCKER));
        $this->assertSame(['WPORG-05-TRIALWARE'], $report->rules_run());
        $this->assertSame(1, $report->rule_count());
        $this->assertSame(2, $report->files_scanned());
        $this->assertSame(Profile::WPORG_FREE, $report->profile());
        $this->assertArrayHasKey('WPORG-05-TRIALWARE', $report->by_rule());
        $this->assertCount(1, $report->at_least(Severity::LIKELY_REJECT));
    }

    public function test_a_muted_rule_is_skipped_entirely(): void
    {
        $rule = Rule_Registry::get('WPORG-05-TRIALWARE');
        $muted = new class ($rule) implements Rule {
            public function __construct(private Rule $inner)
            {
            }
            public function id(): string
            {
                return $this->inner->id();
            }
            public function guideline(): string
            {
                return $this->inner->guideline();
            }
            public function title(): string
            {
                return $this->inner->title();
            }
            public function explanation(): string
            {
                return $this->inner->explanation();
            }
            public function default_severity(): string
            {
                return $this->inner->default_severity();
            }
            public function check(Rule_Context $context): array
            {
                throw new \RuntimeException('a muted rule must never run');
            }
        };

        $profile = Profile::custom('house', 'house rules', ['WPORG-05-TRIALWARE' => null]);
        $this->assertTrue($profile->is_muted($muted));

        $report = (new Runner([$muted]))->run($this->context(['example-toolkit.php' => $this->main_file()], $profile));

        $this->assertSame([], $report->rules_run());
        $this->assertSame([], $report->findings());
        $this->assertNull($profile->severity_for($muted));
    }

    public function test_a_rule_that_throws_is_reported_and_does_not_abort_the_run(): void
    {
        $broken = new class implements Rule {
            public function id(): string
            {
                return 'TEST-BROKEN';
            }
            public function guideline(): string
            {
                return 'test';
            }
            public function title(): string
            {
                return 'broken';
            }
            public function explanation(): string
            {
                return 'test';
            }
            public function default_severity(): string
            {
                return Severity::BEST_PRACTICE;
            }
            public function check(Rule_Context $context): array
            {
                throw new \RuntimeException('exploded');
            }
        };

        $report = (new Runner([$broken, Rule_Registry::get('WPORG-01-GPL')]))
            ->run($this->context(['example-toolkit.php' => $this->main_file(['License' => ''])]));

        $this->assertTrue($report->has_blockers());
        $messages = array_map(static fn (Finding $f) => $f->message(), $report->findings());
        $this->assertStringContainsString('rule TEST-BROKEN failed to run: exploded', implode("\n", $messages));
        $this->assertStringContainsString('no License header', implode("\n", $messages));
    }

    public function test_runner_can_be_restricted_to_named_rules(): void
    {
        $runner = Runner::with_default_rules()->only(['wporg-01-gpl']);

        $this->assertCount(1, $runner->rules());
        $this->assertSame('WPORG-01-GPL', $runner->rules()[0]->id());
    }

    public function test_plugin_source_finds_the_main_file_and_honours_excludes(): void
    {
        $root = $this->make_plugin([
            'example-toolkit.php' => $this->main_file(),
            'includes/value.php' => "<?php\nclass Value {}\n",
            'tests/ValueTest.php' => "<?php\nclass ValueTest {}\n",
            'readme.txt' => $this->readme(),
        ]);
        $source = new Plugin_Source($root);

        $this->assertNotNull($source->main_file());
        $this->assertSame('example-toolkit.php', $source->main_file()->relative_path());
        $this->assertNotNull($source->readme());
        $this->assertTrue($source->exists('includes/value.php'));
        $this->assertTrue($source->has('includes'));
        $this->assertTrue($source->is_excluded('tests/ValueTest.php'));

        $paths = array_map(static fn (Source_File $file) => $file->relative_path(), $source->source_files());
        $this->assertContains('includes/value.php', $paths);
        $this->assertNotContains('tests/ValueTest.php', $paths);
    }

    public function test_plugin_source_on_a_missing_directory_is_empty(): void
    {
        $source = new Plugin_Source('/nonexistent/path/for/tests');

        $this->assertSame([], $source->entries());
        $this->assertNull($source->main_file());
        $this->assertNull($source->readme());
    }

    public function test_plugin_header_parses_fields_and_derives_the_slug(): void
    {
        $context = $this->context(['example-toolkit.php' => $this->main_file()]);
        $header = $context->header();

        $this->assertSame('Example Toolkit', $header->name());
        $this->assertSame('1.2.3', $header->version());
        $this->assertSame('example-toolkit', $header->text_domain());
        $this->assertSame('example-toolkit', $context->slug());
        $this->assertTrue($header->has('license'));
        $this->assertGreaterThan(0, $header->line_of('Version'));

        $empty = new Plugin_Header(null);
        $this->assertSame('', $empty->name());
        $this->assertSame('', $empty->relative_path());
        $this->assertSame(0, $empty->line_of('Version'));
    }

    public function test_readme_parsing_covers_headers_sections_and_the_short_description(): void
    {
        $context = $this->context([
            'example-toolkit.php' => $this->main_file(),
            'readme.txt' => $this->readme(['extra_sections' => "== External services ==\n\nNothing.\n"]),
        ]);
        $readme = $context->readme();

        $this->assertTrue($readme->exists());
        $this->assertSame('Example Toolkit', $readme->title());
        $this->assertSame(['example', 'testing'], $readme->tags());
        $this->assertSame('1.2.3', $readme->header('stable tag'));
        $this->assertTrue($readme->has_section('external services'));
        $this->assertStringContainsString('Nothing.', (string) $readme->section('external services'));
        $this->assertContains('description', $readme->section_names());
        $this->assertStringContainsString('example plugin', $readme->short_description());
        $this->assertTrue($readme->mentions('GPLv2'));
        $this->assertGreaterThan(0, $readme->line_of('Stable tag'));

        $missing = new Readme_File(null);
        $this->assertFalse($missing->exists());
        $this->assertSame([], $missing->tags());
        $this->assertSame(0, $missing->size());
        $this->assertSame('readme.txt', $missing->relative_path());
        $this->assertSame(0, $missing->line_of('anything'));
    }

    public function test_source_file_token_helpers(): void
    {
        $root = $this->make_plugin([
            'includes/sample.php' => "<?php\n// wp_remote_get in a comment\nclass Sample {\n    const URL = 'https://api.example.test/v1';\n    public function run() {\n        return wp_remote_get( self::URL );\n    }\n}\n",
        ]);
        $file = (new Plugin_Source($root))->file('includes/sample.php');

        $this->assertTrue($file->is_php());
        $this->assertSame('php', $file->extension());
        $this->assertCount(1, $file->find_calls(['wp_remote_get']));
        $this->assertSame([], $file->find_calls(['nonexistent_function']));
        $this->assertNotSame([], $file->find_symbols(['sample']));
        $this->assertSame([], $file->grep('/never-present/'));
        $this->assertTrue($file->contains('api.example.test'));
        $this->assertStringContainsString('class Sample', $file->snippet(3));
        $this->assertSame('', $file->line(999));

        $literals = array_column($file->string_literals(), 'value');
        $this->assertContains('https://api.example.test/v1', $literals);
    }

    public function test_http_index_classifies_loopback_external_and_dynamic_calls(): void
    {
        $context = $this->context([
            'example-toolkit.php' => $this->main_file(),
            'includes/external.php' => "<?php\nclass External {\n    const URL = 'https://api.example-service.test/v1';\n    public function run() {\n        return wp_remote_get( self::URL );\n    }\n}\n",
            'includes/loopback.php' => "<?php\nclass Loopback {\n    public function run() {\n        return wp_remote_post( rest_url( 'example/v1' ) );\n    }\n}\n",
            'includes/dynamic.php' => "<?php\nclass Dynamic {\n    public function run( \$url ) {\n        return wp_safe_remote_get( \$url );\n    }\n}\n",
            'includes/local.php' => "<?php\nclass Local {\n    public function run( \$path ) {\n        return file_get_contents( \$path );\n    }\n}\n",
        ]);
        $index = $context->http_index();

        $this->assertArrayHasKey('api.example-service.test', $index->external_hosts());
        $this->assertTrue($index->has_network_activity());

        $kinds = [];
        foreach ($index->call_sites() as $site) {
            $kinds[$site['file']] = $site['kind'];
        }
        $this->assertSame('external', $kinds['includes/external.php']);
        $this->assertSame('loopback', $kinds['includes/loopback.php']);
        $this->assertSame('dynamic', $kinds['includes/dynamic.php']);
        $this->assertArrayNotHasKey('includes/local.php', $kinds, 'a local file read is not a network call');
        $this->assertCount(1, $index->dynamic_call_sites());
    }

    public function test_http_index_is_empty_for_a_plugin_that_never_calls_out(): void
    {
        $index = new Http_Index([]);

        $this->assertSame([], $index->call_sites());
        $this->assertSame([], $index->external_hosts());
        $this->assertFalse($index->has_network_activity());
    }
}
