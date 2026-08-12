<?php

namespace WPMCP\Compliance\Rules;

use WPMCP\Compliance\Rule_Context;

/**
 * Syntax-level items Plugin Check raises at severity 7: heredoc, backticks,
 * goto, short and alternative open tags, and byte order marks.
 *
 * Heredoc but not nowdoc. PluginCheck's HeredocSniff::register() returns
 * array( T_START_HEREDOC ), and PHP_CodeSniffer's own tokenizer re-emits a
 * nowdoc opener as its custom T_START_NOWDOC (Tokens.php:64, PHP.php:1109),
 * which that sniff never sees. PHP's native token_get_all() does not make the
 * distinction and reports both as T_START_HEREDOC, so this rule reads the
 * opener text instead: <<<'ID' is a nowdoc and Plugin Check does not flag it.
 */
final class Php_Hygiene_Rule extends Base_Rule
{
    public function id(): string
    {
        return 'PCP-PHP-HYGIENE';
    }

    public function guideline(): string
    {
        return 'Plugin Check PluginCheck.CodeAnalysis.Heredoc, Generic.PHP.* severity 7';
    }

    public function title(): string
    {
        return 'PHP syntax constructs Plugin Check rejects';
    }

    public function explanation(): string
    {
        return 'PluginCheck.CodeAnalysis.Heredoc prohibits HEREDOC. NOWDOC (<<<\'ID\') is not '
            . 'flagged: PHP_CodeSniffer tokenises it as T_START_NOWDOC and the sniff only '
            . 'registers T_START_HEREDOC. '
            . 'Generic.PHP.BacktickOperator, Generic.PHP.DisallowShortOpenTag, '
            . 'Generic.PHP.DisallowAlternativePHPTags, Generic.PHP.DiscourageGoto and '
            . 'Generic.Files.ByteOrderMark all run at severity 7. None of them have a legitimate use '
            . 'in a directory plugin.';
    }

    public function check(Rule_Context $context): array
    {
        $findings = [];
        foreach ($context->php_files() as $file) {
            foreach ($file->tokens() as $token) {
                if (! is_array($token) || T_START_HEREDOC !== $token[0]) {
                    continue;
                }
                if ($this->is_nowdoc_opener($token[1])) {
                    continue;
                }
                $findings[] = $this->finding($file, $token[2], 'HEREDOC is prohibited by PluginCheck.CodeAnalysis.Heredoc');
            }
            foreach ($file->lines_with_tokens([T_GOTO]) as $line) {
                $findings[] = $this->finding($file, $line, 'the goto language construct should not be used');
            }
            $line_of_backtick = 0;
            foreach ($file->tokens() as $index => $token) {
                if (is_string($token)) {
                    // Backticks are tokenised individually, so a markdown
                    // backtick inside a docblock cannot reach this branch.
                    if ('`' === $token && 0 === $line_of_backtick) {
                        $line_of_backtick = $this->line_near($file->tokens(), $index);
                    }
                    continue;
                }
                if (T_OPEN_TAG_WITH_ECHO === $token[0]) {
                    $findings[] = $this->finding($file, $token[2], 'short echo tag <?= is flagged by Generic.PHP.DisallowShortOpenTag.EchoFound');
                }
            }
            if ($line_of_backtick > 0) {
                $findings[] = $this->finding($file, $line_of_backtick, 'backtick operator (shell execution) is prohibited');
            }
            // Short open tags cannot be found in the token stream: with
            // short_open_tag off, "<?" tokenises as inline HTML, and with it
            // on the sniff still has to report it. Match the text instead.
            foreach ($file->grep('/<\?(?!php\b|=|xml)/') as $hit) {
                $findings[] = $this->finding($file, $hit['line'], 'short open tag: only <?php is permitted');
            }
            if (str_starts_with($file->contents(), "\xEF\xBB\xBF")) {
                $findings[] = $this->finding($file, 1, 'file starts with a UTF-8 byte order mark');
            }
        }
        return $findings;
    }

    /**
     * A T_START_HEREDOC token's text is the whole opener, "<<<ID", "<<<\"ID\""
     * or "<<<'ID'". Only the single-quoted form is a nowdoc.
     */
    private function is_nowdoc_opener(string $opener): bool
    {
        return 1 === preg_match("/^<<<\s*'/", $opener);
    }

    /**
     * Single-character tokens carry no line number; take it from the nearest
     * preceding token that does.
     *
     * @param array<int,array|string> $tokens
     */
    private function line_near(array $tokens, int $index): int
    {
        for ($i = $index; $i >= 0; $i--) {
            if (is_array($tokens[$i])) {
                return $tokens[$i][2];
            }
        }
        return 1;
    }
}
