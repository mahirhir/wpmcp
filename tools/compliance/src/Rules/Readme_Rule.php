<?php

namespace WPMCP\Compliance\Rules;

use WPMCP\Compliance\Rule_Context;
use WPMCP\Compliance\Severity;

/**
 * readme.txt conformance, per "How your readme.txt works" and Plugin Check's
 * Plugin_Readme_Check.
 */
final class Readme_Rule extends Base_Rule
{
    private const REQUIRED_HEADERS = [
        'contributors',
        'tags',
        'requires at least',
        'tested up to',
        'stable tag',
        'license',
        'license uri',
    ];

    private const REQUIRED_SECTIONS = ['description', 'installation', 'changelog'];

    private const MAX_TAGS = 5;
    private const MAX_SHORT_DESCRIPTION = 150;
    private const MAX_SIZE = 10240;

    public function id(): string
    {
        return 'WPORG-12-README';
    }

    public function guideline(): string
    {
        return 'Guideline 12 and 15; Plugin Check Plugin_Readme_Check';
    }

    public function title(): string
    {
        return 'readme.txt validity';
    }

    public function explanation(): string
    {
        return 'The readme is the listing. Plugin Check errors on a missing or mismatched Stable tag, '
            . 'a license that differs from the plugin header, more than five tags, a short description '
            . 'over 150 characters, and default placeholder text. Guideline 15 requires the Stable tag '
            . 'to equal the version in the main file. Keep the file under 10 KB and move old changelog '
            . 'entries to changelog.txt.';
    }

    public function check(Rule_Context $context): array
    {
        $readme = $context->readme();
        $header = $context->header();

        if (! $readme->exists()) {
            return [$this->file_finding('readme.txt', 'readme.txt is missing; the directory listing is generated from it')];
        }

        $path = $readme->relative_path();
        $findings = [];

        foreach (self::REQUIRED_HEADERS as $required) {
            if (null === $readme->header($required)) {
                $findings[] = $this->file_finding($path, sprintf('missing required header "%s"', $required), null, $readme->title_line());
            }
        }

        foreach (self::REQUIRED_SECTIONS as $section) {
            if (! $readme->has_section($section)) {
                $findings[] = $this->file_finding($path, sprintf('missing required section "== %s =="', ucfirst($section)), Severity::LIKELY_REJECT);
            }
        }

        $tags = $readme->tags();
        if (count($tags) > self::MAX_TAGS) {
            $findings[] = $this->file_finding(
                $path,
                sprintf('%d tags: guideline 12 names "use of over 5 tags total" as spam and Plugin Check caps it at %d', count($tags), self::MAX_TAGS),
                null,
                $readme->header_line('tags')
            );
        }
        if (in_array('tag1', array_map('strtolower', $tags), true)) {
            $findings[] = $this->file_finding($path, 'placeholder tag "tag1" left in the readme', null, $readme->header_line('tags'));
        }

        $stable = (string) $readme->header('stable tag');
        $version = $header->version();
        if ('trunk' === strtolower($stable)) {
            $findings[] = $this->file_finding($path, 'Stable tag: trunk is neither supported nor recommended, and is prohibited for new plugins', null, $readme->header_line('stable tag'));
        } elseif ('' !== $stable && '' !== $version && $stable !== $version) {
            $findings[] = $this->file_finding(
                $path,
                sprintf('Stable tag "%s" does not match the Version "%s" in %s', $stable, $version, $header->relative_path()),
                null,
                $readme->header_line('stable tag')
            );
        }

        foreach (['requires at least', 'tested up to', 'requires php'] as $numeric) {
            $value = $readme->header($numeric);
            if (null === $value || '' === $value) {
                continue;
            }
            if (! preg_match('/^\d+(\.\d+)*$/', trim($value))) {
                $findings[] = $this->file_finding(
                    $path,
                    sprintf('"%s: %s" must be numbers only, for example 6.9 and not "WP 6.9"', $numeric, $value),
                    Severity::LIKELY_REJECT,
                    $readme->header_line($numeric)
                );
            }
        }

        $readme_license = $this->normalise_license((string) $readme->header('license'));
        $header_license = $this->normalise_license((string) $header->get('license'));
        if ('' !== $readme_license && '' !== $header_license && $readme_license !== $header_license) {
            $findings[] = $this->file_finding(
                $path,
                sprintf('license differs between readme ("%s") and plugin header ("%s")', $readme->header('license'), $header->get('license')),
                null,
                $readme->header_line('license')
            );
        }

        $short = $readme->short_description();
        if ('' === $short) {
            $findings[] = $this->file_finding($path, 'missing short description under the header block', Severity::LIKELY_REJECT, $readme->title_line());
        } else {
            if (strlen($short) > self::MAX_SHORT_DESCRIPTION) {
                $findings[] = $this->file_finding(
                    $path,
                    sprintf('short description is %d characters; the limit is %d', strlen($short), self::MAX_SHORT_DESCRIPTION),
                    null,
                    $readme->short_description_line()
                );
            }
            if (false !== stripos($short, 'Here is a short description of the plugin')) {
                $findings[] = $this->file_finding($path, 'placeholder short description left in the readme', null, $readme->short_description_line());
            }
            if (preg_match('/<[a-z][^>]*>|\*\*|\[[^\]]+\]\(/i', $short)) {
                $findings[] = $this->file_finding($path, 'short description contains markup; it must be plain text', Severity::LIKELY_REJECT, $readme->short_description_line());
            }
        }

        if ('' !== trim($header->name()) && '' !== trim($readme->title()) && $readme->title() !== $header->name()) {
            $findings[] = $this->file_finding(
                $path,
                sprintf('readme title "%s" differs from the plugin header name "%s"', $readme->title(), $header->name()),
                Severity::LIKELY_REJECT,
                $readme->title_line()
            );
        }

        $contributors = (string) $readme->header('contributors');
        foreach (array_filter(array_map('trim', explode(',', $contributors))) as $contributor) {
            if (preg_match('/^[A-Za-z0-9_.\-]+$/', $contributor)) {
                continue;
            }
            $findings[] = $this->file_finding(
                $path,
                sprintf('contributor "%s" is not a WordPress.org username', $contributor),
                null,
                $readme->header_line('contributors')
            );
        }

        if ([] !== $context->http_index()->external_hosts()) {
            $has_section = false;
            foreach (External_Services_Rule::DISCLOSURE_SECTIONS as $section) {
                if ($readme->has_section($section)) {
                    $has_section = true;
                    break;
                }
            }
            if (! $has_section) {
                $findings[] = $this->file_finding(
                    $path,
                    'the plugin contacts external hosts but the readme has no "== External services ==" section',
                    null,
                    $readme->title_line()
                );
            }
        }

        if ($readme->size() > self::MAX_SIZE) {
            $findings[] = $this->file_finding(
                $path,
                sprintf('readme is %d bytes; over %d "may result in errors", so split the changelog out', $readme->size(), self::MAX_SIZE),
                Severity::BEST_PRACTICE
            );
        }

        return $findings;
    }

    /**
     * "GPLv2 or later", "GPL-2.0-or-later" and "GPL v2+" all mean the same
     * licence; only a real difference should be reported.
     */
    private function normalise_license(string $license): string
    {
        $license = strtolower(trim($license));
        $license = str_replace([' or later', '-or-later', 'gplv', 'gpl-', 'gpl '], ['+', '+', 'gpl', 'gpl', 'gpl'], $license);
        $license = preg_replace('/[^a-z0-9+.]/', '', $license) ?? $license;
        return preg_replace('/(\d)\.0\b/', '$1', $license) ?? $license;
    }
}
