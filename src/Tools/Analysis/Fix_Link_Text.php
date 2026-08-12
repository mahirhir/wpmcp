<?php

namespace WPMCP\Tools\Analysis;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Auto-fixer for the accessibility and SEO problem that is the same problem:
 * "click here" / "read more" anchor text.
 *
 * WCAG 2.4.4 wants link text that describes its destination out of context;
 * search engines read the same anchor text as the strongest on-page signal
 * about the linked page. Both are fixed by one rewrite, and the only source
 * of truth this fixer will use is the destination itself: the title of the
 * internal post the link actually points at.
 *
 * Dry run by default (see Fix_Pass). Apply mode rewrites only the text
 * BETWEEN the anchor's tags and writes the whole pass in one Safe_Mutation
 * snapshot, so the href, classes, rel and every other attribute survive
 * byte-for-byte.
 *
 * Deliberate refusals:
 * - External links are skipped. This site cannot know what another site's
 *   page is called, and inventing anchor text for it is how a fixer turns
 *   into a liability.
 * - Anchors containing markup (an image, a nested span, a button wrapper) are
 *   skipped: replacing their contents would delete the markup.
 * - Links whose text is already descriptive are left alone.
 */
class Fix_Link_Text
{
    public function handle(array $args): array
    {
        $post    = Fix_Pass::post($args);
        $content = (string) $post->post_content;
        $tags    = Markup_Scanner::tags($content);

        $proposed = [];
        $skipped  = [];
        $edits    = [];

        foreach ($tags as $tag) {
            if ('a' !== $tag['name']) {
                continue;
            }

            $href = trim((string) ($tag['attributes']['href'] ?? ''));
            if ('' === $href) {
                continue;
            }

            $inner = (string) $tag['inner'];
            // Weakness is judged on the readable text, so an anchor wrapping
            // an image reads as empty (which it is, to a screen reader) rather
            // than as descriptive because it contains bytes.
            $text = trim(wp_strip_all_tags($inner));

            if (! $this->is_weak($text)) {
                $skipped[] = $this->skip($tag, 'text_already_descriptive');
                continue;
            }

            if (str_contains($inner, '<')) {
                $skipped[] = $this->skip($tag, 'contains_markup');
                continue;
            }

            $target_id = $this->resolve_target($href);
            if (0 === $target_id) {
                $skipped[] = $this->skip($tag, 'destination_not_resolvable');
                continue;
            }

            $title = trim(wp_strip_all_tags((string) get_the_title($target_id)));
            if ('' === $title || strtolower($title) === strtolower($text)) {
                $skipped[] = $this->skip($tag, 'no_better_text_available');
                continue;
            }

            $proposed[] = [
                'location'      => $tag['location'],
                'href'          => $href,
                'current_text'  => $text,
                'proposed_text' => $title,
                'source'        => 'destination_title',
                'target_id'     => $target_id,
            ];

            // Only the text node between the tags is replaced; the open and
            // close tags are never part of the edit range.
            $edits[] = [
                'offset'      => (int) $tag['offset'] + (int) $tag['length'],
                'length'      => strlen($inner),
                'replacement' => esc_html($title),
            ];
        }

        return Fix_Pass::finish('fix-link-text', $post, $args, $proposed, $skipped, $edits);
    }

    /**
     * Empty or generic anchor text, using the same phrase list the
     * accessibility audit already flags so the fixer and the audit can never
     * disagree about what counts as a problem.
     */
    private function is_weak(string $text): bool
    {
        // Decode entities and shed decorative punctuation first, so
        // "Read more &raquo;" and "Click here..." are recognized as the same
        // two phrases the audit already flags.
        $normalized = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $normalized = (string) preg_replace('/\s+/u', ' ', $normalized);
        $normalized = (string) preg_replace('/^[^\p{L}\p{N}]+|[^\p{L}\p{N}]+$/u', '', $normalized);

        return '' === $normalized
            || in_array(strtolower($normalized), A11y_Analyzer::NON_DESCRIPTIVE_LINKS, true);
    }

    /** Post id this href points at on this site, or 0 for external / unresolvable links. */
    private function resolve_target(string $href): int
    {
        if (preg_match('#^(mailto:|tel:|javascript:|\#)#i', $href)) {
            return 0;
        }

        $host = (string) wp_parse_url($href, PHP_URL_HOST);
        if ('' !== $host && strtolower($host) !== strtolower((string) wp_parse_url(home_url(), PHP_URL_HOST))) {
            return 0;
        }

        return (int) url_to_postid($href);
    }

    /** @param array<string,mixed> $tag */
    private function skip(array $tag, string $reason): array
    {
        return [
            'location' => (string) $tag['location'],
            'reason'   => $reason,
        ];
    }
}
