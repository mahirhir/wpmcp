<?php

namespace WPMCP\Tools\Analysis;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Source-accurate HTML tokenizer for the auto-fixers.
 *
 * The audit side of this domain (Content_Extractor) parses post content with
 * DOMDocument, which is fine for READING structure. The fixers cannot use it:
 * DOMDocument::saveHTML() re-serializes the whole document (injects
 * html/body, rewrites entities, normalizes void tags) and would rewrite far
 * more of a post than the fix pass intended. Gutenberg block delimiters are
 * HTML comments whose payload must survive byte-for-byte, so a whole-document
 * round trip is not an option.
 *
 * This scanner instead records the byte offset and length of every element's
 * open tag, so a fixer can splice a single attribute back into the original
 * string and leave every other byte untouched. It also tracks nesting (parent
 * index + matching close-tag offset), which is what the contrast fixer needs
 * to resolve an inherited background color.
 *
 * Deliberately not a full HTML parser: it skips comments (so block delimiters
 * and anything commented out are never treated as markup), understands void
 * and self-closed elements, and degrades gracefully on mis-nested markup by
 * only popping the stack when a matching open tag is found. Everything here
 * is pure string work with no WordPress dependency beyond esc_attr().
 */
final class Markup_Scanner
{
    /** Elements that never have a closing tag, so they must not be pushed on the nesting stack. */
    private const VOID_ELEMENTS = [
        'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input',
        'link', 'meta', 'param', 'source', 'track', 'wbr',
    ];

    /**
     * One regex alternation over: HTML comments (skipped wholesale, which is
     * what keeps Gutenberg block delimiters safe), close tags, and open tags.
     * The attribute run tolerates quoted values containing '>' so a title or
     * alt attribute cannot truncate the match early.
     */
    private const TOKEN_PATTERN = '/<!--.*?-->'
        . '|<\/([a-zA-Z][a-zA-Z0-9:-]*)\s*>'
        . '|<([a-zA-Z][a-zA-Z0-9:-]*)((?:\s+[^\s=\/>]+(?:\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s"\'>]+))?)*)\s*(\/?)>/s';

    /**
     * Tokenize every element open tag in document order.
     *
     * @return array<int,array{
     *   name:string, offset:int, length:int, source:string,
     *   attributes:array<string,string>, parent:int|null,
     *   location:string, inner:string
     * }>
     */
    public static function tags(string $html): array
    {
        if ('' === $html) {
            return [];
        }

        $matches = [];
        preg_match_all(self::TOKEN_PATTERN, $html, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        $tags    = [];
        $stack   = [];
        $counter = [];

        foreach ($matches as $match) {
            // Comment: the whole token is consumed and nothing is recorded.
            if (! isset($match[1]) || (-1 === $match[1][1] && -1 === ($match[2][1] ?? -1))) {
                continue;
            }

            // Close tag: unwind to the nearest matching open tag, if any.
            if (-1 !== $match[1][1]) {
                $name  = strtolower($match[1][0]);
                $depth = self::find_open($stack, $tags, $name);
                if (null !== $depth) {
                    while (count($stack) > $depth) {
                        $popped = array_pop($stack);
                        if (count($stack) === $depth) {
                            $start                 = $tags[$popped]['offset'] + $tags[$popped]['length'];
                            $tags[$popped]['inner'] = substr($html, $start, $match[0][1] - $start);
                        }
                    }
                }
                continue;
            }

            $name              = strtolower($match[2][0]);
            $counter[$name]    = ($counter[$name] ?? 0) + 1;
            $index             = count($tags);
            $tags[$index]      = [
                'name'       => $name,
                'offset'     => $match[0][1],
                'length'     => strlen($match[0][0]),
                'source'     => $match[0][0],
                'attributes' => self::parse_attributes((string) ($match[3][0] ?? '')),
                'parent'     => [] === $stack ? null : $stack[count($stack) - 1],
                'location'   => $name . '[' . $counter[$name] . ']',
                'inner'      => '',
            ];

            $self_closed = '/' === ($match[4][0] ?? '');
            if (! $self_closed && ! in_array($name, self::VOID_ELEMENTS, true)) {
                $stack[] = $index;
            }
        }

        return $tags;
    }

    /**
     * Stack depth of the nearest open tag with this name, or null when the
     * close tag has no matching open tag (stray markup, which is ignored
     * rather than allowed to corrupt the nesting model).
     *
     * @param int[]                       $stack
     * @param array<int,array{name:string}> $tags
     */
    private static function find_open(array $stack, array $tags, string $name): ?int
    {
        for ($depth = count($stack) - 1; $depth >= 0; $depth--) {
            if ($tags[$stack[$depth]]['name'] === $name) {
                return $depth;
            }
        }
        return null;
    }

    /**
     * Parse an open tag's raw attribute run into a lowercased name => value
     * map. Valueless attributes (e.g. `hidden`) map to an empty string.
     *
     * @return array<string,string>
     */
    public static function parse_attributes(string $source): array
    {
        if ('' === trim($source)) {
            return [];
        }

        $out     = [];
        $pattern = '/([^\s=\/>]+)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>]+)))?/';
        preg_match_all($pattern, $source, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $name = strtolower(trim($match[1]));
            if ('' === $name) {
                continue;
            }
            $value = '';
            if (isset($match[4]) && '' !== $match[4]) {
                $value = $match[4];
            } elseif (isset($match[3]) && '' !== $match[3]) {
                $value = $match[3];
            } elseif (isset($match[2])) {
                $value = $match[2];
            }
            $out[$name] = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        return $out;
    }

    /**
     * Return the open tag rewritten with $name set to $value: the existing
     * attribute's value is replaced in place (preserving everything else in
     * the tag byte-for-byte), or the attribute is appended just before the
     * tag's closing bracket when it was absent.
     */
    public static function with_attribute(string $tag_source, string $name, string $value): string
    {
        $name     = strtolower($name);
        $escaped  = esc_attr($value);
        $existing = '/(\s' . preg_quote($name, '/') . '\s*=\s*)("[^"]*"|\'[^\']*\'|[^\s"\'>]+)/i';

        // preg_replace_callback, not preg_replace: an escaped attribute value
        // is arbitrary user text and could contain backreference syntax
        // (\1, $2) that a replacement string would expand.
        if (preg_match($existing, $tag_source)) {
            return (string) preg_replace_callback(
                $existing,
                static fn (array $m): string => $m[1] . '"' . $escaped . '"',
                $tag_source,
                1
            );
        }

        $insertion = ' ' . $name . '="' . $escaped . '"';
        if (preg_match('/\s*\/>$/', $tag_source)) {
            return (string) preg_replace('/\s*\/>$/', $insertion . ' />', $tag_source, 1);
        }
        return substr($tag_source, 0, -1) . $insertion . '>';
    }

    /**
     * Apply offset splices to the original markup. Edits are applied from the
     * highest offset down so every earlier offset stays valid, which is what
     * makes a whole fix pass a single, exact rewrite of the source string.
     *
     * @param array<int,array{offset:int,length:int,replacement:string}> $edits
     */
    public static function splice(string $html, array $edits): string
    {
        usort($edits, static fn (array $a, array $b): int => $b['offset'] <=> $a['offset']);
        foreach ($edits as $edit) {
            $html = substr_replace($html, $edit['replacement'], $edit['offset'], $edit['length']);
        }
        return $html;
    }

    /**
     * Parse a `style` attribute into a lowercased property => value map.
     *
     * @return array<string,string>
     */
    public static function parse_style(string $style): array
    {
        $out = [];
        foreach (explode(';', $style) as $declaration) {
            if (! str_contains($declaration, ':')) {
                continue;
            }
            [$property, $value] = explode(':', $declaration, 2);
            $property           = strtolower(trim($property));
            if ('' === $property) {
                continue;
            }
            $out[$property] = trim($value);
        }
        return $out;
    }

    /**
     * Return the style attribute with one property's value replaced, keeping
     * the declaration in its original position and leaving every other
     * declaration's original spelling untouched.
     */
    public static function with_style_property(string $style, string $property, string $value): string
    {
        $pattern = '/(^|;)(\s*' . preg_quote($property, '/') . '\s*:\s*)([^;]*)/i';
        if (preg_match($pattern, $style)) {
            return (string) preg_replace_callback(
                $pattern,
                static fn (array $m): string => $m[1] . $m[2] . $value,
                $style,
                1
            );
        }
        $trimmed = rtrim(trim($style), ';');
        return '' === $trimmed
            ? $property . ':' . $value
            : $trimmed . ';' . $property . ':' . $value;
    }
}
