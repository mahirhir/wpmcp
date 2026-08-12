<?php

namespace WPMCP\Tools\Analysis;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Auto-fixer: write alt text for images in a post that have none, derived
 * from the image filename, the nearest heading above it, or the post title
 * (see Alt_Text_Suggester for the ordering and why there is no model call).
 *
 * Dry run by default (see Fix_Pass), so the caller always sees the proposed
 * text, its source, and the image it belongs to before anything is written.
 * Apply mode sets the alt attribute on every proposed image and writes the
 * whole pass in one Safe_Mutation snapshot.
 *
 * Two rules this fixer will not break:
 *
 * - EXISTING ALT TEXT IS NEVER OVERWRITTEN unless the caller passes
 *   overwrite_existing=true. A human-written description outranks anything
 *   derived from a filename.
 * - AN IMAGE MARKED DECORATIVE IS LEFT ALONE. role="presentation",
 *   role="none", and aria-hidden="true" are deliberate authoring decisions
 *   that say "this image carries no information"; adding alt text to those
 *   makes the page worse for a screen-reader user, not better. They are
 *   reported as skipped with the reason, never silently fixed.
 *
 * Note that an image with an EMPTY alt attribute is treated as fixable rather
 * than as an intentional decorative marker, matching what
 * Analyze_Accessibility already flags. Authors who mean "decorative" should
 * say so with role="presentation", which this fixer honors.
 */
class Add_Alt_Text_From_Context
{
    public function handle(array $args): array
    {
        $post      = Fix_Pass::post($args);
        $overwrite = true === filter_var($args['overwrite_existing'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $tags      = Markup_Scanner::tags((string) $post->post_content);
        $title     = (string) get_the_title((int) $post->ID);

        $proposed = [];
        $skipped  = [];
        $edits    = [];

        foreach ($tags as $tag) {
            if ('img' !== $tag['name']) {
                continue;
            }

            $attributes  = (array) $tag['attributes'];
            $current_alt = trim((string) ($attributes['alt'] ?? ''));

            if ($this->is_decorative($attributes)) {
                $skipped[] = $this->skip($tag, 'marked_decorative');
                continue;
            }

            if ('' !== $current_alt && ! $overwrite) {
                $skipped[] = $this->skip($tag, 'already_has_alt');
                continue;
            }

            $src        = (string) ($attributes['src'] ?? '');
            $suggestion = Alt_Text_Suggester::propose(
                $src,
                Fix_Pass::heading_before($tags, (int) $tag['offset']),
                $title
            );

            if (null === $suggestion) {
                $skipped[] = $this->skip($tag, 'no_context_available');
                continue;
            }

            if ($suggestion['alt'] === $current_alt) {
                $skipped[] = $this->skip($tag, 'proposal_matches_current');
                continue;
            }

            $proposed[] = [
                'location'      => $tag['location'],
                'src'           => $src,
                'current_alt'   => $current_alt,
                'proposed_alt'  => $suggestion['alt'],
                'source'        => $suggestion['source'],
                'overwrites'    => '' !== $current_alt,
                'attachment_id' => $this->attachment_id($src),
            ];

            $edits[] = [
                'offset'      => (int) $tag['offset'],
                'length'      => (int) $tag['length'],
                'replacement' => Markup_Scanner::with_attribute((string) $tag['source'], 'alt', $suggestion['alt']),
            ];
        }

        $out = Fix_Pass::finish('add-alt-text-from-context', $post, $args, $proposed, $skipped, $edits);

        // Stated on every response, not buried in docs: the library value is
        // a different object than the one this pass snapshotted, so it is
        // deliberately untouched (see Fix_Pass).
        $out['media_library_alt'] = 'untouched: this pass only rewrites the post content it snapshotted. Use wpmcp/update-media to set _wp_attachment_image_alt reversibly.';

        return $out;
    }

    /**
     * An image the author already declared as carrying no information. ARIA
     * says the accessible name of such an element is intentionally empty, so
     * the correct fix is no fix.
     *
     * @param array<string,string> $attributes
     */
    private function is_decorative(array $attributes): bool
    {
        $role = strtolower(trim((string) ($attributes['role'] ?? '')));
        if (in_array($role, ['presentation', 'none'], true)) {
            return true;
        }
        return 'true' === strtolower(trim((string) ($attributes['aria-hidden'] ?? '')));
    }

    /**
     * Attachment id for a content image, reported so a caller can follow up
     * with wpmcp/update-media. 0 when the src is not a local attachment.
     */
    private function attachment_id(string $src): int
    {
        if ('' === $src || ! function_exists('attachment_url_to_postid')) {
            return 0;
        }
        // WordPress resolves only full-size URLs, so strip a size suffix
        // (-1024x768) before asking, which is how a scaled content image
        // still finds its attachment.
        $full = (string) preg_replace('/-\d{2,4}x\d{2,4}(\.[a-z0-9]{2,5})$/i', '$1', $src);
        return (int) attachment_url_to_postid($full);
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
