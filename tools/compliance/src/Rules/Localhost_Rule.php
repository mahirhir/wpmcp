<?php

namespace WPMCP\Compliance\Rules;

use WPMCP\Compliance\Rule_Context;

/**
 * PluginCheck.CodeAnalysis.Localhost, error: "Do not use
 * Localhost/127.0.0.1/*.local in your code."
 *
 * The sniff is narrower than its message suggests. LocalhostSniff::process_token()
 * returns early unless the string contains '//', and then matches
 * '#(https?:)?\/\/(localhost|127.0.0.1|(.*\.local(host)?))\/#i'. So it only fires on
 * a localhost *URL*, never on a bare host name. That distinction matters: an OAuth
 * loopback allowlist such as ['127.0.0.1', '::1', 'localhost'] is a protocol
 * requirement (RFC 8252 native-app redirects), not a development leftover, and
 * Plugin Check correctly leaves it alone. This rule mirrors the sniff exactly so it
 * does not manufacture a blocker the reviewer's own tooling will not raise.
 */
final class Localhost_Rule extends Base_Rule
{
    /** LocalhostSniff.php, verbatim. */
    private const SNIFF_PATTERN = '#(https?:)?//(localhost|127\.0\.0\.1|(.*\.local(host)?))/#i';

    public function id(): string
    {
        return 'PCP-LOCALHOST';
    }

    public function guideline(): string
    {
        return 'Plugin Check PluginCheck.CodeAnalysis.Localhost (error)';
    }

    public function title(): string
    {
        return 'Localhost or development host in shipped code';
    }

    public function explanation(): string
    {
        return 'Plugin Check errors on a localhost, 127.0.0.1 or *.local URL in shipped PHP. '
            . 'The usual sources are a development fallback base URL and a default option value; '
            . 'both need to go before submission. A bare host name in an allowlist is not a URL '
            . 'and the sniff does not flag it.';
    }

    public function check(Rule_Context $context): array
    {
        $findings = [];
        foreach ($context->php_files() as $file) {
            foreach ($file->string_literals() as $literal) {
                $value = $literal['value'];
                // The sniff's own early return: no '//', no finding.
                if (! str_contains($value, '//')) {
                    continue;
                }
                if (! preg_match(self::SNIFF_PATTERN, $value, $matches)) {
                    continue;
                }
                $findings[] = $this->finding(
                    $file,
                    $literal['line'],
                    sprintf('development host URL "%s" in a string literal', $matches[0])
                );
            }
        }
        return $findings;
    }
}
