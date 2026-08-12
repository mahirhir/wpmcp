<?php

namespace WPMCP\Compliance\Rules;

use WPMCP\Compliance\Rule_Context;

/**
 * PluginCheck.CodeAnalysis.ShortURL (severity 6) plus guideline 12: affiliate
 * links "must directly link to the affiliate service, not a redirect or
 * cloaked URL".
 */
final class Short_Url_Rule extends Base_Rule
{
    /** ShortURLSniff::$short_url_domains */
    public const SHORTENERS = [
        'adf.ly', 'bit.do', 'bit.ly', 'clck.ru', 'cutt.ly', 'df.ly', 'goo.gl', 'is.gd', 'lc.chat',
        'ow.ly', 'polr.me', 'rb.gy', 's2r.co', 'short.link', 'shorturl.at', 'soo.gd', 'tiny.cc',
        'tinyurl.com', 'v.gd',
    ];

    public function id(): string
    {
        return 'WPORG-12-SHORT-URL';
    }

    public function guideline(): string
    {
        return 'Guideline 12; PluginCheck.CodeAnalysis.ShortURL';
    }

    public function title(): string
    {
        return 'URL shortener or cloaked link';
    }

    public function explanation(): string
    {
        return 'Plugin Check flags the known shortener domains, and guideline 12 requires affiliate '
            . 'links to be disclosed and to point directly at the affiliate service rather than at a '
            . 'redirect. Link to the real destination.';
    }

    public function check(Rule_Context $context): array
    {
        $pattern = '#\b(' . implode('|', array_map(static fn ($domain) => preg_quote($domain, '#'), self::SHORTENERS)) . ')\b#i';
        $findings = [];

        foreach ($context->php_files() as $file) {
            foreach ($file->grep($pattern) as $hit) {
                $findings[] = $this->finding($file, $hit['line'], sprintf('URL shortener "%s" is not permitted', $hit['match']));
            }
        }

        $readme = $context->readme();
        if ($readme->exists() && null !== $readme->file()) {
            foreach ($readme->file()->grep($pattern) as $hit) {
                $findings[] = $this->finding($readme->file(), $hit['line'], sprintf('URL shortener "%s" in readme.txt', $hit['match']));
            }
        }

        return $findings;
    }
}
