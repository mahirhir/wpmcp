<?php

namespace WPMCP\Compliance\Rules;

use WPMCP\Compliance\Rule_Context;
use WPMCP\Compliance\Severity;

/**
 * Guideline 17 and Plugin Check's Trademarks_Check.
 *
 * Matching semantics are Trademarks_Check::has_trademarked_slug():
 *  - a term ending in "-" may not START the slug,
 *  - a term without a trailing "-" may not appear ANYWHERE in the slug,
 *  - "woocommerce" has a for-use exception: "-for-woocommerce" and friends,
 *    provided the term appears nowhere else,
 *  - "woo" is a portmanteau: a slug may not start with it.
 */
final class Trademark_Rule extends Base_Rule
{
    /** Trademarks_Check::TRADEMARK_SLUGS, verbatim order. */
    public const TRADEMARK_SLUGS = [
        'adobe-', 'adsense-', 'advanced-custom-fields-', 'adwords-', 'akismet-',
        'all-in-one-wp-migration', 'amazon-', 'android-', 'apple-', 'applenews-', 'applepay-',
        'aws-', 'azon-', 'bbpress-', 'bing-', 'booking-com', 'bootstrap-', 'buddypress-',
        'chatgpt-', 'chat-gpt-', 'cloudflare-', 'contact-form-7-', 'cpanel-', 'disqus-', 'divi-',
        'dropbox-', 'easy-digital-downloads-', 'elementor-', 'envato-', 'fbook', 'facebook',
        'fb-', 'fb-messenger', 'fedex-', 'feedburner', 'firefox-', 'fontawesome-', 'font-awesome-',
        'ganalytics-', 'gberg', 'github-', 'givewp-', 'google-', 'googlebot-', 'googles-',
        'gravity-form-', 'gravity-forms-', 'gravityforms-', 'gtmetrix-', 'gutenberg', 'guten-',
        'hubspot-', 'ig-', 'insta-', 'instagram', 'internet-explorer-', 'ios-', 'jetpack-',
        'macintosh-', 'macos-', 'mailchimp-', 'microsoft-', 'ninja-forms-', 'oculus', 'onlyfans-',
        'only-fans-', 'opera-', 'paddle-', 'paypal-', 'pinterest-', 'plugin', 'skype-', 'stripe-',
        'tiktok-', 'tik-tok-', 'trustpilot', 'twitch-', 'twitter-', 'tweet', 'ups-', 'usps-',
        'vvhatsapp', 'vvcommerce', 'vva-', 'vvoo', 'wa-', 'webpush-vn', 'wh4tsapps', 'whatsapp',
        'whats-app', 'watson', 'windows-', 'wocommerce', 'woocom-', 'woocommerce', 'woocomerce',
        'woo-commerce', 'woo-', 'wo-', 'wordpress', 'wordpess', 'wpress', 'wp', 'wc',
        'wp-mail-smtp-', 'yandex-', 'yahoo-', 'youtube-', 'you-tube-', 'yoast',
    ];

    /** Trademarks_Check::FOR_USE_EXCEPTIONS */
    private const FOR_USE_EXCEPTIONS = ['woocommerce'];

    /** Trademarks_Check::PORTMANTEAUS */
    private const PORTMANTEAUS = ['woo'];

    /**
     * Marks that are not on the wp.org list but are live trademarks of AI
     * vendors. Reviewers apply guideline 17 to "other projects" too, and
     * guideline 12 independently bars competitor names as tags.
     */
    public const VENDOR_MARKS = [
        'claude', 'anthropic', 'chatgpt', 'openai', 'gpt-4', 'gemini', 'copilot', 'cursor',
        'perplexity', 'midjourney',
    ];

    /**
     * Terms tolerated in practice: thousands of directory slugs start with
     * wp-, and Plugin Check itself comments "it's allowed, but shows a
     * warning" against 'wp'.
     */
    private const TOLERATED = ['wp', 'wc'];

    public function id(): string
    {
        return 'WPORG-17-TRADEMARK';
    }

    public function guideline(): string
    {
        return 'Guideline 17; Plugin Check Trademarks_Check';
    }

    public function title(): string
    {
        return 'Trademarked term in slug, name, text domain or tags';
    }

    public function explanation(): string
    {
        return 'A slug may not begin with another product\'s term, and may not contain a term that is '
            . 'restricted anywhere. Names follow the "Dancing Sloths for Superbox" pattern: the mark '
            . 'goes at the end, after "for". Tags are stricter still, because guideline 12 also bars '
            . 'competitor plugin names as tags. Slugs cannot be changed after approval, so this is the '
            . 'one class of finding that is expensive to fix late.';
    }

    public function check(Rule_Context $context): array
    {
        $findings = [];
        $header = $context->header();
        $readme = $context->readme();

        $slug = $context->slug();
        foreach ($this->matches($slug) as $term) {
            $findings[] = $this->file_finding(
                $header->relative_path(),
                sprintf('plugin slug "%s" contains the restricted term "%s"', $slug, $term),
                $this->severity_of($term),
                $header->line_of('Plugin Name')
            );
        }

        $domain = $context->text_domain();
        if ($domain !== $slug) {
            $findings[] = $this->file_finding(
                $header->relative_path(),
                sprintf('text domain "%s" does not match the slug "%s"; wp.org translations key off the slug', $domain, $slug),
                Severity::LIKELY_REJECT,
                $header->line_of('Text Domain')
            );
        }

        foreach ($this->name_findings($header->name(), $header->relative_path(), $header->line_of('Plugin Name')) as $finding) {
            $findings[] = $finding;
        }

        if ($readme->exists()) {
            foreach ($this->name_findings($readme->title(), $readme->relative_path(), $readme->title_line()) as $finding) {
                $findings[] = $finding;
            }
            foreach ($readme->tags() as $tag) {
                foreach ($this->matches($this->slugify($tag)) as $term) {
                    $findings[] = $this->file_finding(
                        $readme->relative_path(),
                        sprintf('tag "%s" contains the restricted term "%s"', $tag, $term),
                        $this->severity_of($term),
                        $readme->header_line('tags')
                    );
                }
                foreach (self::VENDOR_MARKS as $mark) {
                    if (strtolower($tag) !== $mark && ! str_contains($this->slugify($tag), $mark)) {
                        continue;
                    }
                    $findings[] = $this->file_finding(
                        $readme->relative_path(),
                        sprintf('tag "%s" is a third-party trademark; tags may not name other vendors or competing products', $tag),
                        null,
                        $readme->header_line('tags')
                    );
                }
            }
        }

        return $findings;
    }

    /**
     * A display name may carry a mark only in the trailing "X for Mark" form.
     *
     * @return \WPMCP\Compliance\Finding[]
     */
    private function name_findings(string $name, string $file, int $line): array
    {
        if ('' === trim($name)) {
            return [];
        }
        $findings = [];
        foreach ($this->matches($this->slugify($name)) as $term) {
            $bare = rtrim($term, '-');
            if (preg_match('/\bfor\s+' . preg_quote($bare, '/') . '\s*$/i', trim($name))) {
                continue;
            }
            $findings[] = $this->file_finding(
                $file,
                sprintf(
                    'display name "%s" carries the restricted term "%s" other than as a trailing "for %s"',
                    $name,
                    $bare,
                    $bare
                ),
                $this->severity_of($term),
                $line
            );
        }
        return $findings;
    }

    /**
     * @return string[] matched terms
     */
    public function matches(string $slug): array
    {
        $slug = strtolower(trim($slug));
        if ('' === $slug) {
            return [];
        }
        $matched = [];

        foreach (self::PORTMANTEAUS as $portmanteau) {
            if (str_starts_with($slug, $portmanteau)) {
                $matched[] = $portmanteau;
            }
        }

        $checked = $slug;
        foreach (self::FOR_USE_EXCEPTIONS as $exception) {
            foreach (['-for-', '-with-', '-using-', '-and-'] as $joiner) {
                $pattern = $joiner . $exception;
                if (! str_ends_with($checked, $pattern)) {
                    continue;
                }
                $remainder = substr($checked, 0, -strlen($pattern));
                if (! str_contains($remainder, $exception)) {
                    $checked = $remainder;
                }
                break 2;
            }
        }

        foreach (self::TRADEMARK_SLUGS as $term) {
            if (str_ends_with($term, '-')) {
                if (str_starts_with($checked, $term)) {
                    $matched[] = $term;
                }
                continue;
            }
            if (str_contains($checked, $term)) {
                $matched[] = $term;
            }
        }

        return array_values(array_unique($matched));
    }

    private function severity_of(string $term): ?string
    {
        return in_array(rtrim($term, '-'), self::TOLERATED, true) ? Severity::BEST_PRACTICE : null;
    }

    private function slugify(string $value): string
    {
        $slug = strtolower(trim($value));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? $slug;
        return trim($slug, '-');
    }
}
