<?php

namespace WPMCP\Compliance\Rules;

use WPMCP\Compliance\Rule_Context;
use WPMCP\Compliance\Severity;

/**
 * Guideline 8: "Serving updates or otherwise installing plugins, themes, or
 * add-ons from servers other than WordPress.org's" and "Installing premium
 * versions of the same plugin". Reviewer checklist: "A plugin can be required
 * but not included or auto-installed", and it is not allowed for plugins to
 * change the activation status of other plugins.
 *
 * Directly relevant to an MCP tool surface, which can expose exactly these
 * capabilities to an agent.
 */
final class Plugin_Install_Rule extends Base_Rule
{
    private const INSTALL_CALLS = [
        'activate_plugin',
        'activate_plugins',
        'deactivate_plugins',
        'delete_plugins',
        'install_plugin',
        'wp_ajax_install_plugin',
        'switch_theme',
        'delete_theme',
    ];

    private const UPGRADER_CLASSES = [
        'Plugin_Upgrader',
        'Theme_Upgrader',
        'Plugin_Installer_Skin',
        'Automatic_Upgrader_Skin',
    ];

    public function default_severity(): string
    {
        return Severity::REVIEWER_DISCRETION;
    }

    public function id(): string
    {
        return 'WPORG-08-PLUGIN-INSTALL';
    }

    public function guideline(): string
    {
        return 'Guideline 8; reviewer checklist, bundling and activation of other plugins';
    }

    public function title(): string
    {
        return 'Installs, activates or removes other plugins and themes';
    }

    public function explanation(): string
    {
        return 'A plugin may require another plugin, but may not install, activate, deactivate or '
            . 'delete one, and may not fetch add-ons from anywhere but WordPress.org. Where the '
            . 'capability is exposed to an automated caller, expect the reviewer to test it: it needs '
            . 'a capability check, an explicit user action behind it, and honest documentation, or it '
            . 'needs to be out of the directory build.';
    }

    public function check(Rule_Context $context): array
    {
        $findings = [];
        foreach ($context->php_files() as $file) {
            foreach ($file->find_calls(self::INSTALL_CALLS, false) as $call) {
                $findings[] = $this->finding(
                    $file,
                    $call['line'],
                    sprintf('%s() changes what is installed or active on the site', $call['name'])
                );
            }
            foreach ($file->find_symbols(array_map('strtolower', self::UPGRADER_CLASSES)) as $symbol) {
                $findings[] = $this->finding(
                    $file,
                    $symbol['line'],
                    sprintf('%s drives package installation from PHP', $symbol['name'])
                );
            }
        }
        return $findings;
    }
}
