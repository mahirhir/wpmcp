<?php

namespace WPMCP\Compliance\Rules;

use WPMCP\Compliance\Rule_Context;

/**
 * Plugin Check Direct_File_Access_Check: every PHP file with side effects
 * needs an ABSPATH (or WPINC) guard. Files that only declare a class,
 * interface, trait or enum are exempt.
 *
 * Merely mentioning defined('ABSPATH') is not enough. Direct_File_Access_Check
 * accepts five shapes and nothing else (Direct_File_Access_Check.php:323-346),
 * reproduced verbatim below, so a guard whose condition carries an extra
 * conjunct -- if ( ! defined('ABSPATH') && ! defined('MY_TESTING') ) -- matches
 * none of them and Plugin Check reports the file as unprotected. Recognising
 * the loose form would hide a real error from the submitter.
 */
final class Direct_File_Access_Rule extends Base_Rule
{
    /** Direct_File_Access_Check::has_direct_access_protection_regex(), verbatim. */
    private const GUARD_PATTERNS = [
        // defined( 'ABSPATH' ) || exit;
        "/defined\s*\(\s*(?:constant_name\s*:\s*)?['\"]ABSPATH['\"]\s*\)\s*(?:\|\||or)\s*(?:exit|die)\s*(?:\([^)]*\))?\s*;/i",
        // defined( 'WPINC' ) || exit;
        "/defined\s*\(\s*(?:constant_name\s*:\s*)?['\"]WPINC['\"]\s*\)\s*(?:\|\||or)\s*(?:exit|die)\s*(?:\([^)]*\))?\s*;/i",
        // if ( ! defined( 'ABSPATH' ) ) exit;
        "/if\s*\(\s*!\s*defined\s*\(\s*(?:constant_name\s*:\s*)?['\"]ABSPATH['\"]\s*\)\s*\)\s*(?:\{|exit|die)/i",
        // if ( ! defined( 'WPINC' ) ) exit;
        "/if\s*\(\s*!\s*defined\s*\(\s*(?:constant_name\s*:\s*)?['\"]WPINC['\"]\s*\)\s*\)\s*(?:\{|exit|die)/i",
        // if ( ! defined( 'ABSPATH' ) ) { die(); }
        "/if\s*\(\s*!\s*defined\s*\(\s*(?:constant_name\s*:\s*)?['\"](?:ABSPATH|WPINC)['\"]\s*\)\s*\)\s*\{[^}]*die\s*\(/i",
    ];

    /** Any mention at all, used only to tell "no guard" from "guard the checker will not accept". */
    private const LOOSE_PATTERN = '/\bdefined\s*\(\s*[\'"](?:ABSPATH|WPINC)[\'"]\s*\)/';

    public function id(): string
    {
        return 'PCP-DIRECT-FILE-ACCESS';
    }

    public function guideline(): string
    {
        return 'Plugin Check Direct_File_Access_Check';
    }

    public function title(): string
    {
        return 'Missing direct file access guard';
    }

    public function explanation(): string
    {
        return 'A PHP file that runs code when included must refuse to run when loaded directly over '
            . 'HTTP. Any of the accepted forms works: defined(\'ABSPATH\') || exit;, '
            . 'if ( ! defined( \'ABSPATH\' ) ) { exit; }, or the WPINC variant. The condition must be '
            . 'exactly that test: adding another conjunct makes Plugin Check treat the file as '
            . 'unguarded. Pure class, interface, trait and enum declarations are exempt.';
    }

    public function check(Rule_Context $context): array
    {
        $findings = [];
        foreach ($context->php_files() as $file) {
            $contents = $file->contents();
            if ('' === trim($contents)) {
                continue;
            }
            if ($this->has_accepted_guard($contents)) {
                continue;
            }
            if ($file->is_declaration_only()) {
                continue;
            }
            $line = $this->first_code_line($file->tokens());
            if (preg_match(self::LOOSE_PATTERN, $contents)) {
                $findings[] = $this->finding(
                    $file,
                    $this->guard_line($file, $line),
                    'the ABSPATH guard is not in a form Direct_File_Access_Check accepts, so Plugin '
                    . 'Check reports this file as unprotected; the condition must be the defined() '
                    . 'test on its own'
                );
                continue;
            }
            $findings[] = $this->finding(
                $file,
                $line,
                'file has side effects but no ABSPATH guard, so it executes when requested directly'
            );
        }
        return $findings;
    }

    private function has_accepted_guard(string $contents): bool
    {
        // Comments are stripped first, exactly as the checker does, so a guard
        // quoted in a docblock cannot satisfy the check.
        $code = $this->without_comments($contents);
        foreach (self::GUARD_PATTERNS as $pattern) {
            if (preg_match($pattern, $code)) {
                return true;
            }
        }
        return false;
    }

    private function without_comments(string $contents): string
    {
        $out = '';
        foreach (token_get_all($contents) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $out .= is_array($token) ? $token[1] : $token;
        }
        return $out;
    }

    /** Point at the guard the author wrote rather than the top of the file. */
    private function guard_line(\WPMCP\Compliance\Source_File $file, int $fallback): int
    {
        foreach ($file->grep(self::LOOSE_PATTERN) as $hit) {
            return $hit['line'];
        }
        return $fallback;
    }

    /**
     * @param array<int,array|string> $tokens
     */
    private function first_code_line(array $tokens): int
    {
        foreach ($tokens as $token) {
            if (! is_array($token)) {
                continue;
            }
            if (in_array($token[0], [T_OPEN_TAG, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_INLINE_HTML], true)) {
                continue;
            }
            return $token[2];
        }
        return 1;
    }
}
