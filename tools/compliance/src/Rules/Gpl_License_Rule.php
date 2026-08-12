<?php

namespace WPMCP\Compliance\Rules;

use WPMCP\Compliance\Rule_Context;
use WPMCP\Compliance\Severity;

/**
 * Guideline 1: "All code, data, and images ... must comply with the GPL or a
 * GPL-Compatible license."
 */
final class Gpl_License_Rule extends Base_Rule
{
    /**
     * SPDX identifiers accepted by Plugin Check's readme license check, in
     * the spellings developers actually write.
     */
    private const COMPATIBLE = [
        'gplv2', 'gplv2orlater', 'gplv3', 'gplv3orlater', 'gpl2', 'gpl3',
        'gpl-2.0', 'gpl-2.0-or-later', 'gpl-2.0+', 'gpl-3.0', 'gpl-3.0-or-later', 'gpl-3.0+',
        'gpl', 'mit', 'apache-2.0', 'bsd-2-clause', 'bsd-3-clause', 'lgpl-2.1-or-later',
        'lgpl-3.0-or-later', 'isc', 'mpl-2.0',
    ];

    public function id(): string
    {
        return 'WPORG-01-GPL';
    }

    public function guideline(): string
    {
        return 'Guideline 1, GPL compatibility';
    }

    public function title(): string
    {
        return 'GPL declaration';
    }

    public function explanation(): string
    {
        return 'The main file needs a License header, the readme needs License and License URI, and '
            . 'both must name the same GPL-compatible licence. "GPLv2 or later" is what WordPress '
            . 'itself uses and is the recommended value. Ship the licence text as LICENSE.';
    }

    public function check(Rule_Context $context): array
    {
        $findings = [];
        $header = $context->header();
        $readme = $context->readme();

        $declared = (string) $header->get('license');
        if ('' === trim($declared)) {
            $findings[] = $this->file_finding(
                $header->relative_path(),
                'no License header in the main plugin file',
                null,
                $header->line_of('Plugin Name')
            );
        } elseif (! $this->is_compatible($declared)) {
            $findings[] = $this->file_finding(
                $header->relative_path(),
                sprintf('license "%s" is not a recognised GPL-compatible identifier', $declared),
                null,
                $header->line_of('License')
            );
        }

        if ($readme->exists()) {
            $readme_license = (string) $readme->header('license');
            if ('' !== trim($readme_license) && ! $this->is_compatible($readme_license)) {
                $findings[] = $this->file_finding(
                    $readme->relative_path(),
                    sprintf('readme license "%s" is not a recognised GPL-compatible identifier', $readme_license),
                    null,
                    $readme->header_line('license')
                );
            }
            if (null === $readme->header('license uri')) {
                $findings[] = $this->file_finding($readme->relative_path(), 'readme has no License URI', null, $readme->title_line());
            }
        }

        $has_license_file = false;
        foreach (['LICENSE', 'LICENSE.txt', 'LICENSE.md', 'license.txt', 'COPYING'] as $candidate) {
            if ($context->source()->exists($candidate)) {
                $has_license_file = true;
                break;
            }
        }
        if (! $has_license_file) {
            $findings[] = $this->file_finding('LICENSE', 'no licence text ships with the plugin', Severity::BEST_PRACTICE);
        }

        return $findings;
    }

    private function is_compatible(string $license): bool
    {
        $normalised = strtolower(trim($license));
        $normalised = str_replace([' or later', ' ', '_'], ['-or-later', '', ''], $normalised);
        if (in_array($normalised, self::COMPATIBLE, true)) {
            return true;
        }
        $collapsed = preg_replace('/[^a-z0-9]/', '', $normalised) ?? $normalised;
        foreach (self::COMPATIBLE as $candidate) {
            if ($collapsed === preg_replace('/[^a-z0-9]/', '', $candidate)) {
                return true;
            }
        }
        return false;
    }
}
