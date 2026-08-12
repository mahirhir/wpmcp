<?php

namespace WPMCP\Compliance\Rules;

use WPMCP\Compliance\Rule_Context;
use WPMCP\Compliance\Severity;

/**
 * Guideline 4: "Code must be (mostly) human readable."
 *
 * Encoder signatures come straight from Code_Obfuscation_Check; the minified
 * check mirrors Minified_Files_Check and the promoted
 * Internal.Tokenizer.Exception, which says a minified file is only acceptable
 * when the unminified source ships beside it.
 */
final class Code_Obfuscation_Rule extends Base_Rule
{
    private const ENCODERS = [
        'Zend Guard' => '/(<\?php @Zend;)|(This file was encoded by)/',
        'Source Guardian' => '/(sourceguardian\.com)|(function_exists\(\'sg_load\'\))|(\$__x=)/',
        'ionCube' => '/ionCube/',
    ];

    private const MINIFIED_EXTENSIONS = ['js', 'css'];
    private const LONG_LINE = 1000;

    public function id(): string
    {
        return 'WPORG-04-OBFUSCATION';
    }

    public function guideline(): string
    {
        return 'Guideline 4, code must be human readable';
    }

    public function title(): string
    {
        return 'Obfuscated, encoded or minified-without-source code';
    }

    public function explanation(): string
    {
        return 'Encoders are prohibited outright. Minified assets are permitted only with the '
            . 'unminified source in the plugin, or a readme link to the public development location '
            . 'where the source and build tooling live. Minified PHP is an outright error, because the '
            . 'tokenizer cannot process it.';
    }

    public function check(Rule_Context $context): array
    {
        $findings = [];

        foreach ($context->php_files() as $file) {
            foreach (self::ENCODERS as $encoder => $pattern) {
                foreach ($file->grep($pattern) as $hit) {
                    $findings[] = $this->finding($file, $hit['line'], sprintf('%s encoded file: obfuscated code is prohibited', $encoder));
                    break;
                }
            }
            if ($this->looks_minified($file->contents())) {
                $findings[] = $this->finding($file, 1, 'PHP file appears to be minified; the human readable source must ship');
            }
        }

        foreach ($context->source()->entries() as $relative) {
            if ($context->source()->is_excluded($relative)) {
                continue;
            }
            $extension = strtolower(pathinfo($relative, PATHINFO_EXTENSION));
            if (! in_array($extension, self::MINIFIED_EXTENSIONS, true)) {
                continue;
            }
            if (! preg_match('/\.min\.' . $extension . '$/i', $relative)) {
                continue;
            }
            $source = preg_replace('/\.min\.' . $extension . '$/i', '.' . $extension, $relative);
            if (null !== $source && $context->source()->exists($source)) {
                continue;
            }
            $findings[] = $this->file_finding(
                $relative,
                'minified asset ships without its unminified source; include the source or link the development location in readme.txt',
                Severity::LIKELY_REJECT
            );
        }

        return $findings;
    }

    private function looks_minified(string $contents): bool
    {
        if ('' === trim($contents)) {
            return false;
        }
        $lines = preg_split('/\r\n|\r|\n/', $contents) ?: [];
        if (count($lines) > 8) {
            return false;
        }
        foreach ($lines as $line) {
            if (strlen($line) > self::LONG_LINE) {
                return true;
            }
        }
        return false;
    }
}
