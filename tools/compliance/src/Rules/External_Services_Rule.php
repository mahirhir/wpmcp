<?php

namespace WPMCP\Compliance\Rules;

use WPMCP\Compliance\Rule_Context;
use WPMCP\Compliance\Severity;

/**
 * Guideline 7 plus the reviewer checklist's third-party service disclosure
 * requirement: "Clearly disclose as to what is used, when, and why. Include
 * links to the service's terms of use and/or privacy policy."
 *
 * Every host the plugin can reach is cross-checked against readme.txt. This
 * is the single most common rejection reason for a tool plugin.
 */
final class External_Services_Rule extends Base_Rule
{
    public const DISCLOSURE_SECTIONS = ['external services', 'third party services', 'third-party services'];

    public function id(): string
    {
        return 'WPORG-07-EXTERNAL-SERVICES';
    }

    public function guideline(): string
    {
        return 'Guideline 7, tracking and consent; reviewer checklist, third-party services';
    }

    public function title(): string
    {
        return 'Undisclosed external service';
    }

    public function explanation(): string
    {
        return 'Guideline 7 requires that "documentation on how any user data is collected, and used, '
            . 'should be included in the plugin\'s readme", and the reviewer checklist requires each '
            . 'third-party service to be named with what is sent, when, and links to its terms of use '
            . 'and privacy policy. That applies even when you are the third party. Add an '
            . '"== External services ==" section naming every host below, or remove the call.';
    }

    public function check(Rule_Context $context): array
    {
        $findings = [];
        $readme = $context->readme();
        $index = $context->http_index();

        $disclosure = null;
        foreach (self::DISCLOSURE_SECTIONS as $section) {
            if ($readme->has_section($section)) {
                $disclosure = (string) $readme->section($section);
                break;
            }
        }

        foreach ($index->external_hosts() as $host => $occurrences) {
            $first = $occurrences[0];
            $disclosed_anywhere = $readme->mentions($host);
            $disclosed_in_section = null !== $disclosure && false !== stripos($disclosure, $host);

            if ($disclosed_in_section) {
                continue;
            }

            // A host that only ever appears as an array-element value is a link
            // the plugin hands back, not an endpoint it requests. Saying it is
            // "contacted" would be false, so it is reported as what it is.
            if ('linked' === \WPMCP\Compliance\Http_Index::role_of($occurrences)) {
                $findings[] = $this->file_finding(
                    $first['file'],
                    sprintf(
                        '%s is linked but never requested (the literal is an array value, not a request target); if it is the service\'s terms or licence page, cite it in the "== External services ==" entry for that service rather than leaving it undocumented',
                        $host
                    ),
                    Severity::REVIEWER_DISCRETION,
                    $first['line'],
                    $context->source()->file($first['file'])->snippet($first['line'])
                );
                continue;
            }

            $findings[] = $this->file_finding(
                $first['file'],
                $disclosed_anywhere
                    ? sprintf(
                        'external service %s is mentioned in readme.txt but not in an "== External services ==" section with the data sent and links to its terms of use and privacy policy',
                        $host
                    )
                    : sprintf(
                        'external host %s is requested from a file that makes network calls and is not disclosed in readme.txt (%d reference%s)',
                        $host,
                        count($occurrences),
                        1 === count($occurrences) ? '' : 's'
                    ),
                $disclosed_anywhere ? Severity::LIKELY_REJECT : null,
                $first['line'],
                $context->source()->file($first['file'])->snippet($first['line'])
            );
        }

        foreach ($index->dynamic_call_sites() as $site) {
            $findings[] = $this->file_finding(
                $site['file'],
                sprintf(
                    '%s() sends a request to a destination that is not statically resolvable (caller-supplied or stored in an option); the readme must describe when it fires and where it can reach',
                    $site['function']
                ),
                Severity::REVIEWER_DISCRETION,
                $site['line'],
                $context->source()->file($site['file'])->snippet($site['line'])
            );
        }

        return $findings;
    }
}
