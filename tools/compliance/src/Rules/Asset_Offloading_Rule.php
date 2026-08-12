<?php

namespace WPMCP\Compliance\Rules;

use WPMCP\Compliance\Rule_Context;

/**
 * Guideline 8: "Calling third party CDNs for reasons other than font
 * inclusions; all non-service related JavaScript and CSS must be included
 * locally". Enforced by PluginCheck.CodeAnalysis.Offloading (error) and
 * EnqueuedResourceOffloading (error).
 */
final class Asset_Offloading_Rule extends Base_Rule
{
    /** Extensions the Offloading sniff scans string literals for. */
    private const ASSET_EXTENSIONS = [
        'css', 'svg', 'jpg', 'jpeg', 'gif', 'png', 'webm', 'mp4', 'mpg', 'mpeg', 'mp3', 'json',
    ];

    private const ENQUEUE_FUNCTIONS = [
        'wp_register_script',
        'wp_enqueue_script',
        'wp_register_style',
        'wp_enqueue_style',
    ];

    public function id(): string
    {
        return 'WPORG-07-OFFLOADING';
    }

    public function guideline(): string
    {
        return 'Guideline 7 and 8; PluginCheck.CodeAnalysis.Offloading';
    }

    public function title(): string
    {
        return 'Remotely hosted asset';
    }

    public function explanation(): string
    {
        return 'Guideline 7 prohibits "offloading assets (including images and scripts) that are '
            . 'unrelated to a service" and guideline 8 prohibits third-party CDNs for anything but '
            . 'fonts. Ship scripts, styles and images inside the plugin. A URL that is service '
            . 'response data rather than a loaded asset is fine, but it must not be passed to '
            . 'wp_enqueue_script/style.';
    }

    public function check(Rule_Context $context): array
    {
        $findings = [];
        $extensions = implode('|', self::ASSET_EXTENSIONS);
        foreach ($context->php_files() as $file) {
            foreach ($file->string_literals() as $literal) {
                if (! preg_match('#https?://[^\s\'"]+\.(' . $extensions . ')(\?[^\s\'"]*)?$#i', trim($literal['value']), $matches)) {
                    continue;
                }
                $findings[] = $this->finding(
                    $file,
                    $literal['line'],
                    sprintf('remote .%s asset URL: offloading assets to a remote host is a Plugin Check error', strtolower($matches[1]))
                );
            }
            foreach ($file->find_calls(self::ENQUEUE_FUNCTIONS) as $call) {
                $window = $file->line($call['line']) . "\n" . $file->line($call['line'] + 1);
                if (! preg_match('#[\'"]https?://#i', $window)) {
                    continue;
                }
                $findings[] = $this->finding(
                    $file,
                    $call['line'],
                    sprintf('%s() is called with an external resource', $call['name'])
                );
            }
        }
        return $findings;
    }
}
