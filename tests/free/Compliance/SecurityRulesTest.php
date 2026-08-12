<?php

namespace WPMCP\Tests\Free\Compliance;

use WPMCP\Compliance\Rules\Direct_File_Access_Rule;
use WPMCP\Compliance\Rules\Input_Sanitization_Rule;
use WPMCP\Compliance\Rules\Nonce_Capability_Rule;
use WPMCP\Compliance\Rules\Output_Escaping_Rule;

/**
 * Group C of the rulebook, security half: guards, escaping, sanitization,
 * nonces and capabilities.
 */
class SecurityRulesTest extends Compliance_Test_Case
{
    public function test_a_file_with_side_effects_and_no_guard_is_reported(): void
    {
        $findings = $this->findings(new Direct_File_Access_Rule(), [
            'example-toolkit.php' => $this->main_file(),
            'includes/bootstrap.php' => "<?php\nadd_action( 'init', 'example_boot' );\nfunction example_boot() {}\n",
        ]);

        $this->assert_reports($findings, 'no ABSPATH guard');
        $this->assertSame(['includes/bootstrap.php:2'], $this->locations($findings));
    }

    public function test_a_guarded_file_and_a_pure_class_declaration_are_both_accepted(): void
    {
        $findings = $this->findings(new Direct_File_Access_Rule(), [
            'example-toolkit.php' => $this->main_file(),
            'includes/guarded.php' => "<?php\nif ( ! defined( 'ABSPATH' ) ) { exit; }\nadd_action( 'init', 'example_boot' );\nfunction example_boot() {}\n",
            'includes/value.php' => "<?php\n\nnamespace Example\\Values;\n\nfinal class Value\n{\n    public function get(): int\n    {\n        return 1;\n    }\n}\n",
        ]);

        $this->assert_clean($findings);
    }

    /**
     * Direct_File_Access_Check accepts five exact shapes. A guard carrying an
     * extra conjunct matches none of them, so Plugin Check reports the file as
     * unprotected even though the constant is named. Confirmed against Plugin
     * Check 2.0.0, which flagged exactly this line in wpmcp's own src/Plugin.php.
     */
    public function test_a_guard_with_an_extra_conjunct_is_reported(): void
    {
        $body = "<?php\n\nnamespace Example;\n\n";
        $body .= "if (! defined('ABSPATH') && ! defined('EXAMPLE_TESTING')) {\n    exit;\n}\n\n";
        $body .= "add_action('init', 'example_boot');\nfunction example_boot() {}\n";

        $findings = $this->findings(new Direct_File_Access_Rule(), [
            'example-toolkit.php' => $this->main_file(),
            'includes/bootstrap.php' => $body,
        ]);

        $this->assert_reports($findings, 'not in a form Direct_File_Access_Check accepts');
        $this->assertSame(['includes/bootstrap.php:5'], $this->locations($findings));
    }

    /**
     * @dataProvider accepted_guard_provider
     */
    public function test_every_accepted_guard_shape_is_clean(string $guard): void
    {
        $findings = $this->findings(new Direct_File_Access_Rule(), [
            'example-toolkit.php' => $this->main_file(),
            'includes/guarded.php' => "<?php\n" . $guard . "\nadd_action( 'init', 'example_boot' );\nfunction example_boot() {}\n",
        ]);

        $this->assert_clean($findings);
    }

    /**
     * @return array<string,array{0:string}>
     */
    public static function accepted_guard_provider(): array
    {
        return [
            'abspath or exit' => ["defined( 'ABSPATH' ) || exit;"],
            'abspath or die' => ["defined( 'ABSPATH' ) or die;"],
            'wpinc or exit' => ["defined( 'WPINC' ) || exit;"],
            'if not abspath exit' => ["if ( ! defined( 'ABSPATH' ) ) exit;"],
            'if not abspath braced' => ["if ( ! defined( 'ABSPATH' ) ) { exit; }"],
            'if not wpinc braced die' => ["if ( ! defined( 'WPINC' ) ) { die(); }"],
        ];
    }

    /**
     * A guard quoted in a docblock is not a guard. The checker strips comments
     * before matching, so this rule does too.
     */
    public function test_a_guard_mentioned_only_in_a_comment_is_not_accepted(): void
    {
        $body = "<?php\n/**\n * Callers must add defined( 'ABSPATH' ) || exit; at the top.\n */\n";
        $body .= "add_action( 'init', 'example_boot' );\nfunction example_boot() {}\n";

        $findings = $this->findings(new Direct_File_Access_Rule(), [
            'example-toolkit.php' => $this->main_file(),
            'includes/bootstrap.php' => $body,
        ]);

        $this->assert_reports($findings, 'not in a form Direct_File_Access_Check accepts');
    }

    public function test_unescaped_output_is_reported(): void
    {
        $findings = $this->findings(new Output_Escaping_Rule(), [
            'example-toolkit.php' => $this->main_file(),
            'includes/screen.php' => "<?php\nif ( ! defined( 'ABSPATH' ) ) { exit; }\nfunction example_screen( \$label ) {\n    echo '<p>' . \$label . '</p>';\n}\n",
        ]);

        $this->assert_reports($findings, 'no escaping function or integer cast');
        $this->assertSame('likely-reject', (new Output_Escaping_Rule())->default_severity());
    }

    public function test_escaped_cast_and_literal_ternary_output_are_accepted(): void
    {
        $body = "<?php\nif ( ! defined( 'ABSPATH' ) ) { exit; }\nfunction example_screen( \$label, \$rows, \$on ) {\n";
        $body .= "    echo esc_html( \$label );\n";
        $body .= "    echo (int) count( \$rows );\n";
        $body .= "    echo \$on ? '0' : '1';\n";
        $body .= "    echo 'static markup';\n}\n";

        $findings = $this->findings(new Output_Escaping_Rule(), [
            'example-toolkit.php' => $this->main_file(),
            'includes/screen.php' => $body,
        ]);

        $this->assert_clean($findings);
    }

    /**
     * Plugin Check reports this either way, because it does not follow calls,
     * so the finding stands. What must not stand is the claim that no escaping
     * happens: the renderer escapes every value it interpolates, and the fix is
     * a justified phpcs:ignore rather than double escaping. The message has to
     * name the callee so that note can be written from the finding.
     */
    public function test_output_from_an_escaping_renderer_names_the_callee(): void
    {
        $renderer = "<?php\n\nnamespace Example;\n\nif ( ! defined( 'ABSPATH' ) ) { exit; }\n\n";
        $renderer .= "class Widget_Renderer\n{\n    public static function render( array \$spec, array \$settings ): string\n    {\n";
        $renderer .= "        return esc_html( (string) ( \$settings['title'] ?? '' ) );\n    }\n}\n";

        $widget = "<?php\nif ( ! defined( 'ABSPATH' ) ) { exit; }\n";
        $widget .= "function example_render( \$spec, \$settings ) {\n    echo Widget_Renderer::render( \$spec, \$settings );\n}\n";

        $findings = $this->findings(new Output_Escaping_Rule(), [
            'example-toolkit.php' => $this->main_file(),
            'includes/renderer.php' => $renderer,
            'includes/widget.php' => $widget,
        ]);

        $this->assert_reports($findings, 'Widget_Renderer::render()');
        $this->assert_reports($findings, 'includes/renderer.php:9');
        $this->assert_reports($findings, 'phpcs:ignore');
    }

    /**
     * Resolution is class-qualified and refuses to guess. A render() that is
     * not the one being called must never be named in the finding.
     */
    public function test_callee_resolution_does_not_name_an_unrelated_same_named_method(): void
    {
        $other = "<?php\n\nnamespace Example;\n\nif ( ! defined( 'ABSPATH' ) ) { exit; }\n\n";
        $other .= "class Admin_Screen\n{\n    public function render(): string\n    {\n        return esc_html( 'x' );\n    }\n}\n";

        $widget = "<?php\nif ( ! defined( 'ABSPATH' ) ) { exit; }\n";
        $widget .= "function example_render( \$spec ) {\n    echo Widget_Renderer::render( \$spec );\n}\n";

        $findings = $this->findings(new Output_Escaping_Rule(), [
            'example-toolkit.php' => $this->main_file(),
            'includes/admin.php' => $other,
            'includes/widget.php' => $widget,
        ]);

        $this->assert_reports($findings, 'no escaping function or integer cast');
        $this->assertStringNotContainsString('Admin_Screen', implode("\n", $this->messages($findings)));
    }

    public function test_unsanitized_superglobal_read_is_reported(): void
    {
        $findings = $this->findings(new Input_Sanitization_Rule(), [
            'example-toolkit.php' => $this->main_file(),
            'includes/handler.php' => "<?php\nif ( ! defined( 'ABSPATH' ) ) { exit; }\nfunction example_page() {\n    \$page = \$_GET['page'];\n    return \$page;\n}\n",
        ]);

        $this->assert_reports($findings, '$_GET read without wp_unslash()');
    }

    public function test_sanitized_superglobal_read_is_accepted(): void
    {
        $findings = $this->findings(new Input_Sanitization_Rule(), [
            'example-toolkit.php' => $this->main_file(),
            'includes/handler.php' => "<?php\nif ( ! defined( 'ABSPATH' ) ) { exit; }\nfunction example_page() {\n    return isset( \$_GET['page'] ) ? sanitize_key( wp_unslash( \$_GET['page'] ) ) : '';\n}\n",
        ]);

        $this->assert_clean($findings);
    }

    public function test_a_write_handler_without_a_nonce_or_capability_check_is_reported(): void
    {
        $findings = $this->findings(new Nonce_Capability_Rule(), [
            'example-toolkit.php' => $this->main_file(),
            'includes/save.php' => "<?php\nif ( ! defined( 'ABSPATH' ) ) { exit; }\nfunction example_save() {\n    update_option( 'example', sanitize_text_field( wp_unslash( \$_POST['value'] ) ) );\n}\n",
        ]);

        $this->assert_reports($findings, 'never verifies a nonce');
        $this->assert_reports($findings, 'never checks a capability');
    }

    public function test_a_write_handler_with_both_checks_is_accepted(): void
    {
        $body = "<?php\nif ( ! defined( 'ABSPATH' ) ) { exit; }\nfunction example_save() {\n";
        $body .= "    check_admin_referer( 'example_save' );\n";
        $body .= "    if ( ! current_user_can( 'manage_options' ) ) {\n        return;\n    }\n";
        $body .= "    update_option( 'example', sanitize_text_field( wp_unslash( \$_POST['value'] ) ) );\n}\n";

        $findings = $this->findings(new Nonce_Capability_Rule(), [
            'example-toolkit.php' => $this->main_file(),
            'includes/save.php' => $body,
        ]);

        $this->assert_clean($findings);
    }
}
