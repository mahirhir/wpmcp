<?php

namespace WPMCP\Compliance\Rules;

use WPMCP\Compliance\Rule_Context;
use WPMCP\Compliance\Severity;
use WPMCP\Compliance\Source_File;

/**
 * Plugin Check Late_Escaping_Check / WordPress.Security.EscapeOutput.
 *
 * Escaping and nonces are the top security-close reason. This is a sampling
 * heuristic, not a taint analysis: it reports echo/print statements that
 * interpolate a variable without any escaping or casting on the way out.
 */
final class Output_Escaping_Rule extends Base_Rule
{
    private const ESCAPERS = [
        'esc_html', 'esc_attr', 'esc_url', 'esc_url_raw', 'esc_textarea', 'esc_js', 'esc_html__',
        'esc_html_e', 'esc_attr__', 'esc_attr_e', 'esc_xml', 'wp_kses', 'wp_kses_post',
        'wp_kses_data', 'absint', 'intval', 'number_format', 'number_format_i18n', 'sanitize_html_class',
        'wp_json_encode', 'esc_html_x', 'esc_attr_x', 'selected', 'checked', 'disabled',
        'wp_nonce_field', 'submit_button', 'paginate_links', 'wp_dropdown_pages', 'get_submit_button',
    ];

    public function default_severity(): string
    {
        return Severity::LIKELY_REJECT;
    }

    public function id(): string
    {
        return 'PCP-ESCAPING';
    }

    public function guideline(): string
    {
        return 'Plugin Check Late_Escaping_Check; WordPress.Security.EscapeOutput';
    }

    public function title(): string
    {
        return 'Unescaped output';
    }

    public function explanation(): string
    {
        return 'Every dynamic value must be escaped at the point of output with the function that '
            . 'matches its context: esc_html for text, esc_attr for attributes, esc_url for links, '
            . 'wp_kses_post for markup. Integer casts count. This check samples echo and print '
            . 'statements, so a clean run is evidence, not proof.';
    }

    public function check(Rule_Context $context): array
    {
        $findings = [];
        foreach ($context->php_files() as $file) {
            foreach ($this->echo_statements($file) as $statement) {
                if (! str_contains($statement['text'], '$')) {
                    continue;
                }
                if ($this->is_escaped($statement['text'])) {
                    continue;
                }
                // Plugin Check reports this either way (it does not follow
                // calls), so the finding stands. But claiming "no escaping
                // function" is wrong when the output comes from a renderer in
                // this same tree that escapes everything it interpolates, and
                // the fix in that case is a justified phpcs:ignore rather than
                // a second layer of escaping. Name the callee so the reviewer
                // note can be written from the finding.
                $escaping_callee = $this->escaping_callee($context, $statement['text']);
                $findings[] = $this->finding(
                    $file,
                    $statement['line'],
                    null === $escaping_callee
                        ? 'output interpolates a variable with no escaping function or integer cast'
                        : sprintf(
                            'output is not escaped at the echo; it comes from %s, which escapes every value it interpolates (%s), so the fix is a phpcs:ignore recording that, not more escaping',
                            $escaping_callee['name'] . '()',
                            $escaping_callee['location']
                        )
                );
            }
        }
        return $findings;
    }

    /**
     * One level of call resolution, within the scanned tree only. Returns the
     * first function named in the echoed expression whose definition applies an
     * escaping function, or null when nothing resolves.
     *
     * @return array{name:string,location:string}|null
     */
    private function escaping_callee(Rule_Context $context, string $statement): ?array
    {
        $pattern = '/(?:([A-Za-z_][A-Za-z0-9_\\\\]*)\s*::\s*)?([A-Za-z_][A-Za-z0-9_]*)\s*\(/';
        if (! preg_match_all($pattern, $statement, $matches, PREG_SET_ORDER)) {
            return null;
        }
        foreach ($matches as $match) {
            $qualifier = '' !== $match[1] ? ltrim($match[1], '\\') : null;
            $name = $match[2];
            if (in_array(strtolower($name), self::ESCAPERS, true)) {
                continue;
            }
            $definition = $this->definition_of($context, $qualifier, $name);
            if (null !== $definition) {
                return [
                    'name' => null === $qualifier ? $name : $qualifier . '::' . $name,
                    'location' => $definition,
                ];
            }
        }
        return null;
    }

    /**
     * Resolve one call to a definition in the scanned tree. A class-qualified
     * call is looked up only in the file that declares that class. An
     * unqualified one must resolve to exactly one definition; if the name is
     * ambiguous the rule says nothing rather than naming the wrong function.
     *
     * @return string|null "file:line" of a definition whose body escapes
     */
    private function definition_of(Rule_Context $context, ?string $qualifier, string $name): ?string
    {
        $declaration = '/function\s+' . preg_quote($name, '/') . '\s*\(/';
        $matches = [];
        foreach ($context->php_files() as $file) {
            if (null !== $qualifier && ! $this->declares_type($file, $qualifier)) {
                continue;
            }
            foreach ($file->grep($declaration) as $hit) {
                $matches[] = ['file' => $file, 'line' => $hit['line']];
                break;
            }
        }
        if (1 !== count($matches)) {
            return null;
        }
        $only = $matches[0];
        if (! $this->body_escapes($only['file'])) {
            return null;
        }
        return $only['file']->relative_path() . ':' . $only['line'];
    }

    private function declares_type(Source_File $file, string $type): bool
    {
        $short = substr(strrchr($type, '\\') ?: ('\\' . $type), 1);
        return [] !== $file->grep('/\b(?:class|interface|trait|enum)\s+' . preg_quote($short, '/') . '\b/');
    }

    private function body_escapes(Source_File $file): bool
    {
        foreach ($file->find_calls(self::ESCAPERS) as $_call) {
            return true;
        }
        return false;
    }

    /**
     * @return array<int,array{line:int,text:string}>
     */
    private function echo_statements(Source_File $file): array
    {
        $tokens = $file->tokens();
        $statements = [];
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (! is_array($token) || ! in_array($token[0], [T_ECHO, T_PRINT, T_OPEN_TAG_WITH_ECHO], true)) {
                continue;
            }
            $line = $token[2];
            $text = '';
            for ($j = $i + 1; $j < $count; $j++) {
                $next = $tokens[$j];
                if (is_string($next)) {
                    if (';' === $next) {
                        break;
                    }
                    $text .= $next;
                    continue;
                }
                if (T_CLOSE_TAG === $next[0]) {
                    break;
                }
                $text .= $next[1];
            }
            $statements[] = ['line' => $line, 'text' => $text];
        }
        return $statements;
    }

    private function is_escaped(string $statement): bool
    {
        foreach (self::ESCAPERS as $escaper) {
            if (preg_match('/\b' . preg_quote($escaper, '/') . '\s*\(/', $statement)) {
                return true;
            }
        }
        // A numeric or boolean cast makes the whole expression safe, whatever
        // is inside it: echo (int) count( $rows ).
        if (preg_match('/^\s*\(\s*(int|integer|float|double|bool|boolean)\s*\)/', $statement)) {
            return true;
        }
        // A ternary whose branches are both literals emits a literal, however
        // the condition is written: echo $on ? \'0\' : \'1\'.
        if (preg_match('/\?(.*:.*)$/s', $statement, $branches) && ! str_contains($branches[1], '$')) {
            return true;
        }
        $stripped = preg_replace('/\(\s*(int|integer|float|double|bool|boolean)\s*\)\s*\$[A-Za-z_][A-Za-z0-9_\->\[\]\'"]*/', '', $statement) ?? $statement;
        return ! str_contains($stripped, '$');
    }
}
