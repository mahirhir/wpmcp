<?php

namespace WPMCP\Compliance\Rules;

use WPMCP\Compliance\Rule_Context;

/**
 * Guideline 9: nothing "illegal, dishonest, or morally offensive".
 *
 * A readme that promises the plugin never phones home, in a build that does,
 * is not a documentation bug. It is a false statement about privacy in the
 * listing a user reads before installing, and it is the kind of thing that
 * gets a plugin closed after approval rather than merely rejected.
 */
final class Privacy_Claim_Rule extends Base_Rule
{
    private const ABSOLUTE_CLAIMS = [
        '/\bmakes no calls home\b/i',
        '/\bno calls home\b/i',
        '/\bnever (?:calls home|contacts|phones home)\b/i',
        '/\bno telemetry\.\s*$/im',
        '/\bdoes not (?:contact|call) (?:any )?(?:external|third[- ]party|remote) (?:servers?|services?)\b/i',
        '/\bno (?:external|outbound) (?:requests?|connections?|calls?)\b/i',
    ];

    public function id(): string
    {
        return 'WPORG-09-PRIVACY-CLAIM';
    }

    public function guideline(): string
    {
        return 'Guideline 9, nothing dishonest; guideline 7, documentation of data use';
    }

    public function title(): string
    {
        return 'readme privacy claim contradicted by the code';
    }

    public function explanation(): string
    {
        return 'The readme states that the plugin makes no outbound calls, but the build contains '
            . 'network call sites reaching hosts the plugin does not control. Either qualify the claim '
            . 'so it is true of this build (naming the user-initiated calls and the hosts), or remove '
            . 'the calls. Reviewers check this claim specifically, because it is the one users rely on.';
    }

    public function check(Rule_Context $context): array
    {
        $readme = $context->readme();
        if (! $readme->exists()) {
            return [];
        }
        // Only hosts the plugin actually requests contradict a "no calls home"
        // claim. A licence-page URL sitting in a result array does not.
        $hosts = array_keys($context->http_index()->requested_hosts());
        if ([] === $hosts) {
            return [];
        }

        $findings = [];
        $reported_lines = [];
        foreach (self::ABSOLUTE_CLAIMS as $pattern) {
            if (! preg_match($pattern, $readme->contents(), $matches)) {
                continue;
            }
            // Several patterns can match the same sentence; report it once.
            $line = $readme->line_of(trim($matches[0]));
            if (isset($reported_lines[$line])) {
                continue;
            }
            $reported_lines[$line] = true;
            $findings[] = $this->file_finding(
                $readme->relative_path(),
                sprintf(
                    'readme claims "%s" but the build reaches %s',
                    trim($matches[0]),
                    implode(', ', array_slice($hosts, 0, 6)) . (count($hosts) > 6 ? ', ...' : '')
                ),
                null,
                $line,
                trim($matches[0])
            );
        }
        return $findings;
    }
}
