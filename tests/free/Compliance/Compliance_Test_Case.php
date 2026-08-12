<?php

namespace WPMCP\Tests\Free\Compliance;

use WPMCP\Compliance\Finding;
use WPMCP\Compliance\Profile;
use WPMCP\Compliance\Rule;
use WPMCP\Compliance\Rule_Context;

/**
 * Fixture plumbing for the compliance engine tests.
 *
 * Every test builds a throwaway plugin tree on disk from an array of
 * path => contents, so a rule is always exercised against real files, the way
 * it runs in CI, and each test reads as the smallest plugin that provokes it.
 */
abstract class Compliance_Test_Case extends \WP_UnitTestCase
{
    /** @var string[] */
    private array $fixture_roots = [];

    protected function tearDown(): void
    {
        foreach ($this->fixture_roots as $root) {
            $this->remove_tree($root);
        }
        $this->fixture_roots = [];
        parent::tearDown();
    }

    /**
     * The main file of a plugin that passes every rule, so a test only has to
     * describe the one thing it is about.
     *
     * @param array<string,string> $overrides header field => value
     */
    protected function main_file(array $overrides = []): string
    {
        $headers = array_merge(
            [
                'Plugin Name' => 'Example Toolkit',
                'Description' => 'An example plugin used by the compliance engine tests.',
                'Version' => '1.2.3',
                'Requires at least' => '6.9',
                'Requires PHP' => '8.1',
                'License' => 'GPL-2.0-or-later',
                'Text Domain' => 'example-toolkit',
            ],
            $overrides
        );
        $lines = ['<?php', '/**'];
        foreach ($headers as $field => $value) {
            if ('' === $value) {
                continue;
            }
            $lines[] = sprintf(' * %s: %s', $field, $value);
        }
        $lines[] = ' */';
        $lines[] = "if ( ! defined( 'ABSPATH' ) ) { exit; }";
        $lines[] = '';
        return implode("\n", $lines);
    }

    /**
     * A readme that passes every rule.
     *
     * @param array<string,string> $overrides header field => value, plus the
     *                                        pseudo-fields "title", "short"
     *                                        and "extra_sections"
     */
    protected function readme(array $overrides = []): string
    {
        $title = $overrides['title'] ?? 'Example Toolkit';
        $short = $overrides['short'] ?? 'A small example plugin used by the compliance engine tests.';
        $extra = $overrides['extra_sections'] ?? '';
        unset($overrides['title'], $overrides['short'], $overrides['extra_sections']);

        $headers = array_merge(
            [
                'Contributors' => 'examplecontributor',
                'Tags' => 'example, testing',
                'Requires at least' => '6.9',
                'Tested up to' => '6.9',
                'Requires PHP' => '8.1',
                'Stable tag' => '1.2.3',
                'License' => 'GPLv2 or later',
                'License URI' => 'https://www.gnu.org/licenses/gpl-2.0.html',
            ],
            $overrides
        );

        $lines = [sprintf('=== %s ===', $title)];
        foreach ($headers as $field => $value) {
            if ('' === $value) {
                continue;
            }
            $lines[] = sprintf('%s: %s', $field, $value);
        }
        $lines[] = '';
        $lines[] = $short;
        $lines[] = '';
        $lines[] = '== Description ==';
        $lines[] = '';
        $lines[] = 'What the plugin does, in plain language.';
        $lines[] = '';
        $lines[] = '== Installation ==';
        $lines[] = '';
        $lines[] = '1. Install and activate the plugin.';
        $lines[] = '';
        $lines[] = '== Changelog ==';
        $lines[] = '';
        $lines[] = '= 1.2.3 =';
        $lines[] = '* First release.';
        if ('' !== $extra) {
            $lines[] = '';
            $lines[] = $extra;
        }
        $lines[] = '';
        return implode("\n", $lines);
    }

    /**
     * Write a plugin tree and return its root.
     *
     * @param array<string,string> $files relative path => contents
     */
    protected function make_plugin(array $files): string
    {
        $root = rtrim(sys_get_temp_dir(), '/') . '/wpmcp-compliance-' . uniqid('', true);
        mkdir($root, 0777, true);
        $this->fixture_roots[] = $root;

        foreach ($files as $relative => $contents) {
            $path = $root . '/' . ltrim($relative, '/');
            $directory = dirname($path);
            if (! is_dir($directory)) {
                mkdir($directory, 0777, true);
            }
            file_put_contents($path, $contents);
        }
        return $root;
    }

    /**
     * @param array<string,string> $files
     */
    protected function context(array $files, ?Profile $profile = null, ?array $excludes = null): Rule_Context
    {
        return Rule_Context::for_path(
            $this->make_plugin($files),
            $profile ?? Profile::wporg_free(),
            $excludes
        );
    }

    /**
     * Run one rule over one fixture tree.
     *
     * @param  array<string,string> $files
     * @return Finding[]
     */
    protected function findings(Rule $rule, array $files, ?Profile $profile = null): array
    {
        return $rule->check($this->context($files, $profile));
    }

    /**
     * @param  Finding[] $findings
     * @return string[]
     */
    protected function messages(array $findings): array
    {
        return array_map(static fn (Finding $finding) => $finding->message(), $findings);
    }

    /**
     * @param  Finding[] $findings
     * @return string[]
     */
    protected function locations(array $findings): array
    {
        return array_map(static fn (Finding $finding) => $finding->location(), $findings);
    }

    /**
     * @param Finding[] $findings
     */
    protected function assert_reports(array $findings, string $needle, string $message = ''): void
    {
        $haystack = implode("\n", $this->messages($findings));
        $this->assertStringContainsString(
            $needle,
            $haystack,
            '' !== $message ? $message : sprintf("expected a finding mentioning \"%s\", got:\n%s", $needle, $haystack)
        );
    }

    /**
     * @param Finding[] $findings
     */
    protected function assert_clean(array $findings): void
    {
        $this->assertSame([], $this->locations($findings), implode("\n", $this->messages($findings)));
    }

    protected function fixture_path(string $name): string
    {
        return __DIR__ . '/fixtures/' . $name;
    }

    private function remove_tree(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }
            $child = $path . '/' . $entry;
            is_dir($child) ? $this->remove_tree($child) : unlink($child);
        }
        rmdir($path);
    }
}
