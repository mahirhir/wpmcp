<?php

namespace WPMCP\Tests\Free\Compliance;

use WPMCP\Compliance\Rules\Asset_Offloading_Rule;
use WPMCP\Compliance\Rules\External_Services_Rule;
use WPMCP\Compliance\Rules\Localhost_Rule;
use WPMCP\Compliance\Rules\Plugin_Install_Rule;
use WPMCP\Compliance\Rules\Privacy_Claim_Rule;
use WPMCP\Compliance\Rules\Updater_Rule;

/**
 * Group B of the rulebook: phoning home, privacy, external services.
 */
class NetworkRulesTest extends Compliance_Test_Case
{
    private function networked_plugin(string $readme): array
    {
        return [
            'example-toolkit.php' => $this->main_file(),
            'readme.txt' => $readme,
            'includes/audit.php' => "<?php\nclass Audit {\n    const ENDPOINT = 'https://api.example-service.test/v1/report';\n\n    public function run() {\n        return wp_remote_get( self::ENDPOINT );\n    }\n}\n",
        ];
    }

    public function test_external_service_without_disclosure_is_reported(): void
    {
        $findings = $this->findings(new External_Services_Rule(), $this->networked_plugin($this->readme()));

        $this->assert_reports($findings, 'api.example-service.test');
        $this->assert_reports($findings, 'not disclosed in readme.txt');
    }

    public function test_external_service_disclosed_in_the_dedicated_section_is_accepted(): void
    {
        $section = "== External services ==\n\nThis plugin contacts api.example-service.test when you run an audit, sending the site\n"
            . "URL and the WordPress version. Terms: https://example-service.test/terms\n"
            . 'Privacy policy: https://example-service.test/privacy';

        $findings = $this->findings(
            new External_Services_Rule(),
            $this->networked_plugin($this->readme(['extra_sections' => $section]))
        );

        $this->assert_clean($findings);
    }

    public function test_a_host_named_outside_the_disclosure_section_is_downgraded_not_ignored(): void
    {
        $readme = $this->readme(['short' => 'Talks to api.example-service.test when you ask it to.']);
        $findings = $this->findings(new External_Services_Rule(), $this->networked_plugin($readme));

        $this->assert_reports($findings, 'mentioned in readme.txt but not in an "== External services ==" section');
        $this->assertSame('likely-reject', $findings[0]->severity_override());
    }

    public function test_loopback_requests_are_not_treated_as_external(): void
    {
        $findings = $this->findings(new External_Services_Rule(), [
            'example-toolkit.php' => $this->main_file(),
            'readme.txt' => $this->readme(),
            'includes/self-test.php' => "<?php\nclass Self_Test {\n    public function run() {\n        return wp_remote_post( home_url( '/wp-json/example/v1/ping' ) );\n    }\n}\n",
        ]);

        $this->assert_clean($findings);
    }

    public function test_a_dynamic_destination_is_flagged_for_manual_review(): void
    {
        $findings = $this->findings(new External_Services_Rule(), [
            'example-toolkit.php' => $this->main_file(),
            'readme.txt' => $this->readme(),
            'includes/fetch.php' => "<?php\nclass Fetch {\n    public function run( string \$url ) {\n        return wp_remote_get( \$url );\n    }\n}\n",
        ]);

        $this->assert_reports($findings, 'not statically resolvable');
        $this->assertSame('reviewer-discretion', $findings[0]->severity_override());
    }

    /**
     * A host that only ever appears as an array-element value is a link the
     * plugin hands back, never a request target. Reporting it as "reachable"
     * at blocker severity is a claim the code does not support, so it is
     * reported as what it is and left for the reviewer's judgement.
     */
    private function attribution_plugin(string $readme): array
    {
        $search = "<?php\nclass Search {\n    const ENDPOINT = 'https://api.example-service.test/v1/search';\n\n";
        $search .= "    public function run() {\n        \$body = wp_remote_get( self::ENDPOINT );\n";
        $search .= "        return [\n            'license'     => 'Example License',\n";
        $search .= "            'license_url' => 'https://www.example-links.test/license/',\n        ];\n    }\n}\n";

        return [
            'example-toolkit.php' => $this->main_file(),
            'readme.txt' => $readme,
            'includes/search.php' => $search,
        ];
    }

    public function test_a_link_only_host_is_reported_as_linked_not_contacted(): void
    {
        $findings = $this->findings(new External_Services_Rule(), $this->attribution_plugin($this->readme()));

        $this->assert_reports($findings, 'www.example-links.test is linked but never requested');
        $this->assert_reports($findings, 'api.example-service.test is requested');

        $linked = array_values(array_filter(
            $findings,
            static fn ($finding) => str_contains($finding->message(), 'linked but never requested')
        ));
        $this->assertCount(1, $linked);
        $this->assertSame('reviewer-discretion', $linked[0]->severity_override());
    }

    /**
     * The privacy claim must be judged against hosts the plugin requests. A
     * licence-page URL sitting in a result array does not make "no calls home"
     * false, and naming it there would overstate the finding.
     */
    public function test_privacy_claim_names_only_requested_hosts(): void
    {
        $readme = $this->readme(['extra_sections' => "== Privacy ==\n\nNo telemetry. The plugin makes no calls home."]);
        $findings = $this->findings(new Privacy_Claim_Rule(), $this->attribution_plugin($readme));

        $this->assertCount(1, $findings);
        $this->assertStringContainsString('api.example-service.test', $findings[0]->message());
        $this->assertStringNotContainsString('www.example-links.test', $findings[0]->message());
    }

    public function test_privacy_claim_contradicted_by_a_network_call(): void
    {
        $readme = $this->readme(['extra_sections' => "== Privacy ==\n\nNo telemetry. The plugin makes no calls home."]);
        $findings = $this->findings(new Privacy_Claim_Rule(), $this->networked_plugin($readme));

        $this->assert_reports($findings, 'makes no calls home');
        $this->assert_reports($findings, 'api.example-service.test');
        $this->assertCount(1, $findings, 'overlapping claim patterns must report the sentence once');
    }

    public function test_privacy_claim_is_accepted_when_the_plugin_makes_no_external_calls(): void
    {
        $findings = $this->findings(new Privacy_Claim_Rule(), [
            'example-toolkit.php' => $this->main_file(),
            'readme.txt' => $this->readme(['extra_sections' => "== Privacy ==\n\nNo telemetry. The plugin makes no calls home."]),
            'includes/local.php' => "<?php\nclass Local { public function run() { return get_option( 'example' ); } }\n",
        ]);

        $this->assert_clean($findings);
    }

    public function test_offloaded_assets_are_reported(): void
    {
        $findings = $this->findings(new Asset_Offloading_Rule(), [
            'example-toolkit.php' => $this->main_file(),
            'includes/assets.php' => "<?php\nfunction example_assets() {\n    wp_enqueue_script( 'example', 'https://cdn.example.test/example.js', [], '1.0', true );\n    \$logo = 'https://cdn.example.test/logo.png';\n    return \$logo;\n}\n",
        ]);

        $this->assert_reports($findings, 'remote .png asset URL');
        $this->assert_reports($findings, 'wp_enqueue_script() is called with an external resource');
    }

    public function test_locally_enqueued_assets_are_accepted(): void
    {
        $findings = $this->findings(new Asset_Offloading_Rule(), [
            'example-toolkit.php' => $this->main_file(),
            'includes/assets.php' => "<?php\nfunction example_assets() {\n    wp_enqueue_script( 'example', plugins_url( 'js/example.js', __FILE__ ), [], '1.0', true );\n}\n",
        ]);

        $this->assert_clean($findings);
    }

    public function test_localhost_url_in_a_string_literal_is_reported(): void
    {
        $findings = $this->findings(new Localhost_Rule(), [
            'example-toolkit.php' => $this->main_file(),
            'includes/config.php' => "<?php\nclass Config {\n    const BASE = 'http://localhost/api';\n}\n",
        ]);

        $this->assert_reports($findings, 'http://localhost/');
    }

    /**
     * The sniff's regex is anchored on "//host/", so a bare host name is not a
     * finding. That matters here: an OAuth loopback allowlist is a protocol
     * requirement for native clients (RFC 8252), not a development leftover,
     * and Plugin Check 2.0.0 confirmed clean on exactly this shape.
     */
    public function test_localhost_rule_ignores_a_bare_loopback_host_allowlist(): void
    {
        $findings = $this->findings(new Localhost_Rule(), [
            'example-toolkit.php' => $this->main_file(),
            'includes/redirect.php' => "<?php\nclass Redirect_Rules {\n    const LOOPBACK_HOSTS = ['127.0.0.1', '::1', 'localhost'];\n}\n",
        ]);

        $this->assert_clean($findings);
    }

    /**
     * A port between the host and the slash defeats the sniff's regex. Verified
     * against the real sniff, so the engine reproduces it rather than being
     * stricter than the tool the reviewer runs.
     */
    public function test_localhost_rule_matches_the_sniff_on_a_ported_url(): void
    {
        $findings = $this->findings(new Localhost_Rule(), [
            'example-toolkit.php' => $this->main_file(),
            'includes/config.php' => "<?php\nclass Config {\n    const BASE = 'http://localhost:8080/api';\n}\n",
        ]);

        $this->assert_clean($findings);
    }

    public function test_localhost_rule_reports_a_dot_local_url(): void
    {
        $findings = $this->findings(new Localhost_Rule(), [
            'example-toolkit.php' => $this->main_file(),
            'includes/config.php' => "<?php\nclass Config {\n    const BASE = 'https://mysite.local/wp-json';\n}\n",
        ]);

        $this->assert_reports($findings, 'https://mysite.local/');
    }

    public function test_localhost_rule_ignores_ordinary_hosts(): void
    {
        $findings = $this->findings(new Localhost_Rule(), [
            'example-toolkit.php' => $this->main_file(),
            'includes/config.php' => "<?php\nclass Config {\n    const BASE = 'https://api.example.test/v1';\n}\n",
        ]);

        $this->assert_clean($findings);
    }

    public function test_updater_rule_reports_a_self_hosted_updater_and_update_uri(): void
    {
        $findings = $this->findings(new Updater_Rule(), [
            'example-toolkit.php' => $this->main_file(['Update URI' => 'https://updates.example.test/example-toolkit']),
            'includes/updates.php' => "<?php\nadd_filter( 'site_transient_update_plugins', 'example_inject_update' );\n",
        ]);

        $this->assert_reports($findings, 'Update URI');
        $this->assert_reports($findings, 'site_transient_update_plugins');
    }

    public function test_updater_rule_accepts_a_plugin_with_no_updater(): void
    {
        $findings = $this->findings(new Updater_Rule(), [
            'example-toolkit.php' => $this->main_file(),
            'includes/plain.php' => "<?php\nclass Plain {}\n",
        ]);

        $this->assert_clean($findings);
    }

    public function test_plugin_installation_capability_is_reported(): void
    {
        $findings = $this->findings(new Plugin_Install_Rule(), [
            'example-toolkit.php' => $this->main_file(),
            'includes/installer.php' => "<?php\nclass Installer {\n    public function run( \$slug ) {\n        \$upgrader = new Plugin_Upgrader();\n        activate_plugin( \$slug );\n    }\n}\n",
        ]);

        $this->assert_reports($findings, 'activate_plugin()');
        $this->assert_reports($findings, 'plugin_upgrader');
        $this->assertSame('reviewer-discretion', (new Plugin_Install_Rule())->default_severity());
    }
}
