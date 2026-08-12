<?php

namespace WPMCP\Compliance\Rules;

use WPMCP\Compliance\Rule_Context;
use WPMCP\Compliance\Severity;

/**
 * Plugin Check File_Type_Check, plus the "included unneeded folders" item
 * from the common-issues list.
 *
 * Only meaningful against a packaged artifact, so under a profile that is not
 * an artifact scan the development-only paths are skipped: a git checkout is
 * supposed to contain tests and build scripts, a zip is not.
 */
final class File_Hygiene_Rule extends Base_Rule
{
    private const ARCHIVE_EXTENSIONS = ['zip', 'gz', 'tgz', 'rar', 'tar', '7z'];

    private const APPLICATION_EXTENSIONS = [
        'a', 'bin', 'bpk', 'deploy', 'dist', 'distz', 'dmg', 'dms', 'dump', 'elc', 'exe', 'iso',
        'lha', 'lrf', 'lzh', 'o', 'obj', 'phar', 'pkg', 'sh', 'so',
    ];

    private const VCS_DIRECTORIES = ['.git', '.svn', '.hg', '.bzr'];

    private const AI_DIRECTORIES = ['.cursor', '.claude', '.aider', '.continue', '.windsurf', '.ai'];

    private const HIDDEN_ALLOWLIST = ['.distignore', '.gitignore'];

    /** File_Type_Check's list, applied to a packaged zip. */
    private const ROOT_MARKDOWN_ALLOWLIST = [
        'README.md', 'readme.txt', 'LICENSE', 'LICENSE.md', 'CHANGELOG.md', 'CONTRIBUTING.md', 'SECURITY.md',
    ];

    /**
     * Governance documents a checkout is expected to carry that are not on
     * wp.org's list. They are still an error inside a zip, which is where the
     * strict list applies; flagging them in a source tree only trains people
     * to ignore the rule.
     */
    private const DEV_ROOT_MARKDOWN_ALLOWLIST = ['COMPLIANCE.md', 'CODE_OF_CONDUCT.md', 'WPORG-SUBMISSION.md'];

    private const UNNEEDED_DIRECTORIES = ['tests', 'test', 'node_modules', '.github', 'bower_components'];

    public function id(): string
    {
        return 'PCP-FILE-HYGIENE';
    }

    public function guideline(): string
    {
        return 'Plugin Check File_Type_Check; common issues, unneeded folders';
    }

    public function title(): string
    {
        return 'Files that must not be in the zip';
    }

    public function explanation(): string
    {
        return 'File_Type_Check errors on archives, phar files, version control checkouts, hidden '
            . 'files outside the .distignore/.gitignore allowlist, application files (.sh is on that '
            . 'list, so the build scripts must stay out), unexpected markdown in the plugin root, '
            . 'names with spaces or special characters, paths that differ only in case, and a vendor '
            . 'directory with no composer.json beside it.';
    }

    public function check(Rule_Context $context): array
    {
        $source = $context->source();
        $artifact = $context->profile()->is_artifact_scan();
        $findings = [];

        foreach ($source->directories() as $directory) {
            $name = basename($directory);
            if (in_array($name, self::VCS_DIRECTORIES, true)) {
                $findings[] = $this->file_finding($directory, sprintf('version control checkout "%s" must not ship', $name));
                continue;
            }
            if (in_array($name, self::AI_DIRECTORIES, true)) {
                $findings[] = $this->file_finding($directory, sprintf('AI instruction directory "%s" must not ship', $name));
                continue;
            }
            if (! $artifact) {
                continue;
            }
            if (in_array($name, self::UNNEEDED_DIRECTORIES, true)) {
                $findings[] = $this->file_finding(
                    $directory,
                    sprintf('"%s" is a development directory and should not be packaged', $name),
                    Severity::LIKELY_REJECT
                );
            }
        }

        $seen_lowercase = [];
        foreach ($source->entries() as $relative) {
            if (! $artifact && $source->is_excluded($relative)) {
                continue;
            }
            $name = basename($relative);
            $extension = strtolower(pathinfo($relative, PATHINFO_EXTENSION));

            // phpcs.xml.dist and phpunit.xml.dist are the standard PHP
            // convention for a checked-in tool config. They are only a
            // finding once they are inside a zip.
            if (! $artifact && 'dist' === $extension) {
                continue;
            }

            if (in_array($extension, self::ARCHIVE_EXTENSIONS, true)) {
                $findings[] = $this->file_finding($relative, 'compressed files are not permitted');
            } elseif (in_array($extension, self::APPLICATION_EXTENSIONS, true)) {
                $findings[] = $this->file_finding($relative, sprintf('application file ".%s" is not permitted', $extension));
            }

            // A checkout is supposed to contain dotfiles: .gitignore, .env
            // templates, .phpunit.result.cache and the like. Only their presence
            // inside a zip is a File_Type_Check error, so this is artifact-only
            // for the same reason the .dist carve-out above is.
            if ($artifact && str_starts_with($name, '.') && ! in_array($name, self::HIDDEN_ALLOWLIST, true)) {
                $findings[] = $this->file_finding($relative, sprintf('hidden file "%s" is not permitted', $name));
            }

            if (preg_match('/[\s!@#$%^&*()+=\[\]{};:"\'<>,?\\\\|`~]/', $name)) {
                $findings[] = $this->file_finding($relative, 'file and folder names must not contain spaces or special characters');
            }

            $markdown_allowlist = $artifact
                ? self::ROOT_MARKDOWN_ALLOWLIST
                : array_merge(self::ROOT_MARKDOWN_ALLOWLIST, self::DEV_ROOT_MARKDOWN_ALLOWLIST);
            if (! str_contains($relative, '/') && 'md' === $extension && ! in_array($name, $markdown_allowlist, true)) {
                $findings[] = $this->file_finding($relative, sprintf('unexpected markdown file "%s" in the plugin root', $name));
            }

            $lower = strtolower($relative);
            if (isset($seen_lowercase[$lower]) && $seen_lowercase[$lower] !== $relative) {
                $findings[] = $this->file_finding($relative, sprintf('collides with "%s": two paths differing only in case', $seen_lowercase[$lower]));
            }
            $seen_lowercase[$lower] = $relative;
        }

        if ($source->has('vendor') && ! $source->exists('composer.json')) {
            $findings[] = $this->file_finding('vendor', 'a vendor/ directory ships without composer.json beside it');
        }

        return $findings;
    }
}
