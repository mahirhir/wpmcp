<?php

namespace WPMCP\Compliance\Rules;

use WPMCP\Compliance\Rule_Context;
use WPMCP\Compliance\Severity;

/**
 * Guideline 6: "A service that exists for the sole purpose of validating
 * licenses or keys while all functional aspects of the plugin are included
 * locally is not permitted." Plus guideline 7 on the SDK's opt-in.
 *
 * A licensing SDK is not banned from the directory: Plugin Check's own review
 * ruleset carves out the freemius path by name in plugin-review.xml line 20.
 * What is banned is an SDK that unlocks locally-present code, and an opt-in
 * that is not really an opt-in.
 */
final class Licensing_Sdk_Rule extends Base_Rule
{
    private const BOOT_CALLS = ['fs_dynamic_init', 'freemius', 'wpmcp_fs'];
    private const OPT_IN_BYPASS = ['anonymous_mode', 'skip_activation', 'set_anonymous_mode', 'is_anonymous'];

    public function id(): string
    {
        return 'WPORG-06-LICENSING';
    }

    public function guideline(): string
    {
        return 'Guideline 6, software as a service; guideline 7, opt-in';
    }

    public function title(): string
    {
        return 'Licensing SDK and license-check surface';
    }

    public function explanation(): string
    {
        return 'A directory build may carry a licensing SDK, but the license check must not be what '
            . 'unlocks code that already ships (guideline 6), the opt-in must be the stock, default-off '
            . 'screen (guideline 7: "plugins may not contact external servers without explicit and '
            . 'authorized consent"), and the SDK must not serve updates. Under the distribution profile '
            . 'this is informational; under wporg-free every licensing touchpoint is listed so the '
            . 'directory cut can be checked against it.';
    }

    public function check(Rule_Context $context): array
    {
        $findings = [];

        foreach ($context->php_files() as $file) {
            foreach ($file->find_calls(self::BOOT_CALLS) as $call) {
                $findings[] = $this->finding(
                    $file,
                    $call['line'],
                    sprintf('licensing SDK entry point %s()', $call['name'])
                );
            }
            // One inventory line per file: the point is the touchpoint, not
            // every mention inside it.
            $vendor_hits = $file->grep('/\b(WPMCP_FS_ID|WPMCP_FS_PUBLIC_KEY|FS_PUBLIC_KEY|freemius)\b/i');
            if ([] !== $vendor_hits) {
                $findings[] = $this->finding(
                    $file,
                    $vendor_hits[0]['line'],
                    'licensing vendor reference: confirm the license check unlocks nothing that ships in this build',
                    Severity::REVIEWER_DISCRETION
                );
            }
            foreach ($file->grep('/\b(' . implode('|', self::OPT_IN_BYPASS) . ')\b/') as $hit) {
                if (! preg_match('/(=>|=|\()\s*true\b/', $hit['text'])) {
                    continue;
                }
                $findings[] = $this->finding(
                    $file,
                    $hit['line'],
                    sprintf(
                        '%s is enabled: the SDK opt-in screen is bypassed, so the plugin can contact the vendor without the explicit consent guideline 7 requires',
                        $hit['match']
                    )
                );
            }
        }

        $composer = $context->source()->exists('composer.json')
            ? $context->source()->file('composer.json')
            : null;
        if (null !== $composer) {
            foreach ($composer->grep('/freemius|edd-license|license-?manager/i') as $hit) {
                $findings[] = $this->finding(
                    $composer,
                    $hit['line'],
                    'licensing SDK is a shipped composer dependency; the directory build must exclude it or justify it'
                );
            }
        }
        if ($context->source()->exists('vendor/freemius')) {
            $findings[] = $this->file_finding(
                'vendor/freemius',
                'the Freemius SDK is vendored into this tree; in a directory build its updater must be neutralised (see WPORG-08-UPDATER) and its opt-in must be the stock screen'
            );
        }

        return $findings;
    }
}
