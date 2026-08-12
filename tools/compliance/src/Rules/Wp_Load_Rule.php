<?php

namespace WPMCP\Compliance\Rules;

use WPMCP\Compliance\Rule_Context;

/**
 * Reviewer checklist, not permitted: "Calling wp-load directly to gain access
 * to core functions."
 *
 * The usual cause is an HTTP entry point that bootstraps WordPress itself
 * instead of registering a REST route or an admin-ajax action, which skips
 * authentication and every filter a site owner relies on.
 */
final class Wp_Load_Rule extends Base_Rule
{
    public function id(): string
    {
        return 'CHK-WP-LOAD';
    }

    public function guideline(): string
    {
        return 'Reviewer checklist, "Calling wp-load directly"';
    }

    public function title(): string
    {
        return 'Bootstraps WordPress by including wp-load';
    }

    public function explanation(): string
    {
        return 'Endpoints must go through the REST API or admin-ajax so that authentication, '
            . 'capabilities and the usual filters apply. Including wp-load.php, wp-config.php or '
            . 'wp-blog-header.php from a plugin file is on the reviewer checklist\'s not-permitted list.';
    }

    public function check(Rule_Context $context): array
    {
        $findings = [];
        foreach ($context->php_files() as $file) {
            foreach ($file->string_literals() as $literal) {
                if (! preg_match('#(wp-load\.php|wp-blog-header\.php|wp-config\.php)#i', $literal['value'], $matches)) {
                    continue;
                }
                if (! preg_match('/\b(require|require_once|include|include_once)\b/', $file->line($literal['line']))) {
                    continue;
                }
                $findings[] = $this->finding(
                    $file,
                    $literal['line'],
                    sprintf('%s is included directly instead of registering a REST route or ajax action', $matches[1])
                );
            }
        }
        return $findings;
    }
}
