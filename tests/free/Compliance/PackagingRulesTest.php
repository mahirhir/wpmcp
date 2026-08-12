<?php

namespace WPMCP\Tests\Free\Compliance;

use WPMCP\Compliance\Profile;
use WPMCP\Compliance\Rules\File_Hygiene_Rule;

/**
 * Plugin Check File_Type_Check, which only has meaning against a packaged
 * artifact.
 */
class PackagingRulesTest extends Compliance_Test_Case
{
    private function messy_tree(): array
    {
        return [
            'example-toolkit.php' => $this->main_file(),
            'readme.txt' => $this->readme(),
            'build.sh' => "#!/usr/bin/env bash\necho build\n",
            'DESIGN.md' => "# Design notes\n",
            '.env' => "SECRET=1\n",
            'backup.zip' => "not really a zip\n",
            'tests/ExampleTest.php' => "<?php\nclass ExampleTest {}\n",
            '.github/workflows/ci.yml' => "name: CI\n",
        ];
    }

    public function test_artifact_scan_reports_everything_that_must_not_ship(): void
    {
        $findings = $this->findings(new File_Hygiene_Rule(), $this->messy_tree(), Profile::wporg_free());
        $messages = implode("\n", $this->messages($findings));

        $this->assertStringContainsString('application file ".sh"', $messages);
        $this->assertStringContainsString('unexpected markdown file "DESIGN.md"', $messages);
        $this->assertStringContainsString('hidden file ".env"', $messages);
        $this->assertStringContainsString('compressed files are not permitted', $messages);
        $this->assertStringContainsString('"tests" is a development directory', $messages);
        $this->assertStringContainsString('".github" is a development directory', $messages);
    }

    public function test_development_checkout_scan_stays_quiet_about_development_paths(): void
    {
        $findings = $this->findings(new File_Hygiene_Rule(), $this->messy_tree(), Profile::distribution());
        $messages = implode("\n", $this->messages($findings));

        $this->assertStringNotContainsString('development directory', $messages);
        $this->assertStringNotContainsString('ExampleTest', $messages);
        // Root-level rubbish is still reported: it is not development-only.
        $this->assertStringContainsString('unexpected markdown file "DESIGN.md"', $messages);
    }

    public function test_a_clean_package_produces_no_findings(): void
    {
        $findings = $this->findings(new File_Hygiene_Rule(), [
            'example-toolkit.php' => $this->main_file(),
            'readme.txt' => $this->readme(),
            'README.md' => "# Example Toolkit\n",
            'LICENSE' => "GPL\n",
            'includes/value.php' => "<?php\nclass Value {}\n",
        ], Profile::wporg_free());

        $this->assert_clean($findings);
    }

    public function test_a_vendor_directory_without_composer_json_is_reported(): void
    {
        $findings = $this->findings(new File_Hygiene_Rule(), [
            'example-toolkit.php' => $this->main_file(),
            'vendor/autoload.php' => "<?php\n",
        ], Profile::wporg_free());

        $this->assert_reports($findings, 'vendor/ directory ships without composer.json');
    }

    public function test_dist_config_files_are_only_a_finding_inside_an_artifact(): void
    {
        $tree = [
            'example-toolkit.php' => $this->main_file(),
            'phpunit.xml.dist' => "<phpunit/>\n",
        ];

        $this->assert_clean($this->findings(new File_Hygiene_Rule(), $tree, Profile::distribution()));
        $this->assert_reports(
            $this->findings(new File_Hygiene_Rule(), $tree, Profile::wporg_free()),
            'application file ".dist"'
        );
    }

    /**
     * A checkout is supposed to contain dotfiles. .phpunit.result.cache is
     * gitignored and never copied by the build, so calling it a blocker in a
     * source-tree scan is noise; inside a zip it is a File_Type_Check error.
     * Same reasoning as the .dist carve-out above.
     */
    public function test_hidden_files_are_only_a_finding_inside_an_artifact(): void
    {
        $tree = [
            'example-toolkit.php' => $this->main_file(),
            '.phpunit.result.cache' => "{}\n",
            '.env.example' => "KEY=\n",
        ];

        $this->assert_clean($this->findings(new File_Hygiene_Rule(), $tree, Profile::distribution()));

        $messages = implode("\n", $this->messages(
            $this->findings(new File_Hygiene_Rule(), $tree, Profile::wporg_free())
        ));
        $this->assertStringContainsString('hidden file ".phpunit.result.cache"', $messages);
        $this->assertStringContainsString('hidden file ".env.example"', $messages);
    }

    /**
     * The allowlisted dotfiles stay clean even inside a zip.
     */
    /**
     * COMPLIANCE.md is a governance document a checkout is expected to carry,
     * and scripts/build-release.sh copies an explicit file list so it can never
     * reach the zip. It is still an error inside an artifact, where wp.org's
     * strict list applies.
     */
    public function test_a_governance_markdown_file_is_clean_in_a_checkout_only(): void
    {
        $tree = [
            'example-toolkit.php' => $this->main_file(),
            'COMPLIANCE.md' => "# Compliance\n",
        ];

        $this->assert_clean($this->findings(new File_Hygiene_Rule(), $tree, Profile::distribution()));
        $this->assert_reports(
            $this->findings(new File_Hygiene_Rule(), $tree, Profile::wporg_free()),
            'unexpected markdown file "COMPLIANCE.md"'
        );
    }

    public function test_allowlisted_hidden_files_are_accepted_in_an_artifact(): void
    {
        $findings = $this->findings(new File_Hygiene_Rule(), [
            'example-toolkit.php' => $this->main_file(),
            '.gitignore' => "vendor/\n",
            '.distignore' => "tests/\n",
        ], Profile::wporg_free());

        $this->assert_clean($findings);
    }
}
