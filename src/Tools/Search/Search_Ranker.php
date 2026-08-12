<?php

// phpcs:disable PSR1.Files.SideEffects.FoundWithSymbols -- ABSPATH guard is an intentional side effect.
// phpcs:disable Squiz.Classes.ValidClassName.NotCamelCaps -- WP-style snake_case class name is intentional.
// phpcs:disable PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- WP-style snake_case method names are intentional.

namespace WPMCP\Tools\Search;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Query tokenisation, fragment scoring, and snippet extraction (issue #83).
 *
 * Ranking lives in PHP rather than in a MySQL FULLTEXT index on purpose.
 * FULLTEXT relevance depends on server-level configuration a plugin does not
 * control (`innodb_ft_min_token_size`, the stopword table, and the MySQL
 * vs MariaDB scoring differences), which would make results silently vary
 * between hosts and make relevance untestable in CI. A deterministic,
 * field-weighted scorer over a bounded candidate set is slower in theory and
 * far more predictable in practice, and it is the part users actually judge.
 */
class Search_Ranker
{
    public const MAX_TERMS         = 10;
    public const MIN_TERM_LENGTH   = 2;
    public const SNIPPET_MAX_CHARS = 180;

    /**
     * Split a natural-language query into distinct lowercase terms.
     *
     * @return string[]
     */
    public static function tokenize(string $query): array
    {
        $query = strtolower(trim($query));
        $parts = preg_split('/[^\p{L}\p{N}_\-]+/u', $query, -1, PREG_SPLIT_NO_EMPTY);
        $terms = [];
        foreach (is_array($parts) ? $parts : [] as $part) {
            $part = trim((string) $part, '-_');
            if (mb_strlen($part) < self::MIN_TERM_LENGTH) {
                continue;
            }
            $terms[ $part ] = true;
            if (count($terms) >= self::MAX_TERMS) {
                break;
            }
        }

        return array_keys($terms);
    }

    /**
     * Score one candidate fragment against the terms and the raw phrase.
     *
     * The shape of the score, in order of contribution:
     *  - term coverage: how many of the query's distinct terms the fragment
     *    contains at all (dominant, so a fragment holding every term always
     *    beats one that repeats a single term)
     *  - occurrence count per term, with sharply diminishing returns
     *  - the field weight from the index (title 50, heading 30, body 10, url 5)
     *  - a phrase bonus when the whole query appears verbatim
     *  - a whole-word bonus, so "cart" prefers "cart" over "cartography"
     *
     * @param string[] $terms
     */
    public static function score_fragment(string $content, array $terms, string $phrase, int $weight): float
    {
        $haystack = strtolower($content);
        if ('' === $haystack || [] === $terms) {
            return 0.0;
        }

        $covered = 0;
        $hits    = 0.0;
        foreach ($terms as $term) {
            $count = substr_count($haystack, $term);
            if ($count < 1) {
                continue;
            }
            ++$covered;
            // Diminishing returns: the 1st occurrence is worth far more than the 9th.
            $hits += 1.0 + log(1.0 + (float) ($count - 1));
            if (preg_match('/\b' . preg_quote($term, '/') . '\b/u', $haystack)) {
                $hits += 0.5;
            }
        }

        if (0 === $covered) {
            return 0.0;
        }

        $coverage = $covered / count($terms);
        $phrase   = strtolower(trim($phrase));
        $bonus    = ('' !== $phrase && count($terms) > 1 && false !== strpos($haystack, $phrase)) ? 2.0 : 0.0;

        return round(($coverage * 10.0 + $hits + $bonus) * ((float) $weight / 10.0), 4);
    }

    /**
     * A short, tag-free excerpt of $content centred on its first term match.
     *
     * @param string[] $terms
     */
    public static function snippet(string $content, array $terms): string
    {
        $content = trim($content);
        if ('' === $content) {
            return '';
        }
        if (strlen($content) <= self::SNIPPET_MAX_CHARS) {
            return $content;
        }

        $haystack = strtolower($content);
        $position = 0;
        foreach ($terms as $term) {
            $found = strpos($haystack, $term);
            if (false !== $found) {
                $position = $found;
                break;
            }
        }

        $start = max(0, $position - 60);
        // Do not cut mid-word at the front when we are not at the very start.
        if ($start > 0) {
            $space = strpos($content, ' ', $start);
            $start = (false === $space || $space > $position) ? $start : $space + 1;
        }

        $snippet = substr($content, $start, self::SNIPPET_MAX_CHARS);
        $snippet = trim((string) $snippet);

        return ($start > 0 ? '...' : '') . $snippet . (($start + self::SNIPPET_MAX_CHARS) < strlen($content) ? '...' : '');
    }
}
