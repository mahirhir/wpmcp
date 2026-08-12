<?php

namespace WPMCP\Compliance;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * The scanned tree.
 *
 * Two views, deliberately different:
 *
 *  - source_files() is what the code rules analyse. Development-only paths
 *    (tests, tooling, vendor) are excluded, because they never ship.
 *  - entries() is every path found, used by the packaging rules, which have
 *    to be able to say "this must not be in the zip".
 */
final class Plugin_Source
{
    public const DEFAULT_EXCLUDES = [
        'vendor',
        'node_modules',
        'dist',
        'coverage',
        'tests',
        'tools',
        'bin',
        'docs',
        'scripts',
        'assets',
        '.git',
        '.github',
    ];

    /** @var array<int,string> relative paths of every file found */
    private array $files = [];
    /** @var array<int,string> relative paths of every directory found */
    private array $directories = [];
    /** @var array<string,Source_File> */
    private array $source_files = [];
    private bool $scanned = false;

    /**
     * @param string[] $excludes top-level relative paths kept out of source_files()
     */
    public function __construct(private string $root, private array $excludes = self::DEFAULT_EXCLUDES)
    {
        $this->root = rtrim($root, '/');
    }

    public function root(): string
    {
        return $this->root;
    }

    /**
     * @return string[]
     */
    public function excludes(): array
    {
        return $this->excludes;
    }

    /**
     * @return string[] relative paths
     */
    public function entries(): array
    {
        $this->scan();
        return $this->files;
    }

    /**
     * @return string[] relative paths
     */
    public function directories(): array
    {
        $this->scan();
        return $this->directories;
    }

    public function has(string $relative_path): bool
    {
        $this->scan();
        return in_array($relative_path, $this->files, true) || in_array($relative_path, $this->directories, true);
    }

    /**
     * Analysable PHP files.
     *
     * @return Source_File[]
     */
    public function source_files(): array
    {
        $this->scan();
        $files = [];
        foreach ($this->files as $relative) {
            if ($this->is_excluded($relative)) {
                continue;
            }
            if ('php' !== strtolower(pathinfo($relative, PATHINFO_EXTENSION))) {
                continue;
            }
            $files[] = $this->file($relative);
        }
        return $files;
    }

    public function file(string $relative_path): Source_File
    {
        if (! isset($this->source_files[$relative_path])) {
            $this->source_files[$relative_path] = new Source_File($this->root . '/' . $relative_path, $relative_path);
        }
        return $this->source_files[$relative_path];
    }

    public function exists(string $relative_path): bool
    {
        return file_exists($this->root . '/' . $relative_path);
    }

    /**
     * Files under one directory, walked on demand.
     *
     * scan() deliberately does not descend into vendor/, because no code rule
     * should analyse third-party dependencies. A rule that has a specific,
     * bounded reason to look inside one dependency asks for it here.
     *
     * @return Source_File[]
     */
    public function files_under(string $relative_directory, string $extension = 'php'): array
    {
        $absolute = $this->root . '/' . trim($relative_directory, '/');
        if (! is_dir($absolute)) {
            return [];
        }
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($absolute, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $entry) {
            if (! $entry->isFile() || strtolower($entry->getExtension()) !== $extension) {
                continue;
            }
            $relative = ltrim(str_replace($this->root, '', $entry->getPathname()), '/');
            $files[] = $this->file($relative);
        }
        usort($files, static fn (Source_File $a, Source_File $b) => strcmp($a->relative_path(), $b->relative_path()));
        return $files;
    }

    public function is_excluded(string $relative_path): bool
    {
        foreach ($this->excludes as $exclude) {
            if ($relative_path === $exclude || str_starts_with($relative_path, $exclude . '/')) {
                return true;
            }
        }
        return false;
    }

    /**
     * The main plugin file: the root-level PHP file carrying a Plugin Name
     * header. Falls back to the single root PHP file when no header is found.
     */
    public function main_file(): ?Source_File
    {
        $this->scan();
        $candidates = [];
        foreach ($this->files as $relative) {
            if (str_contains($relative, '/')) {
                continue;
            }
            if ('php' !== strtolower(pathinfo($relative, PATHINFO_EXTENSION))) {
                continue;
            }
            $candidates[] = $relative;
        }
        foreach ($candidates as $relative) {
            $file = $this->file($relative);
            if (preg_match('/^[\s*#\/]*Plugin Name\s*:/mi', $file->contents())) {
                return $file;
            }
        }
        return isset($candidates[0]) ? $this->file($candidates[0]) : null;
    }

    public function readme(): ?Source_File
    {
        foreach (['readme.txt', 'README.txt'] as $name) {
            if ($this->exists($name)) {
                return $this->file($name);
            }
        }
        return null;
    }

    private function scan(): void
    {
        if ($this->scanned) {
            return;
        }
        $this->scanned = true;
        if (! is_dir($this->root)) {
            return;
        }
        $this->walk($this->root, '');
        sort($this->files);
        sort($this->directories);
    }

    private function walk(string $absolute, string $prefix): void
    {
        $handle = @opendir($absolute);
        if (false === $handle) {
            return;
        }
        while (false !== ($entry = readdir($handle))) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }
            $relative = '' === $prefix ? $entry : $prefix . '/' . $entry;
            $path = $absolute . '/' . $entry;
            if (is_dir($path)) {
                $this->directories[] = $relative;
                // Never descend into VCS or dependency trees: the packaging
                // rules only need to know the directory is there.
                if (in_array($entry, ['.git', '.svn', '.hg', '.bzr', 'node_modules', 'vendor'], true)) {
                    continue;
                }
                $this->walk($path, $relative);
                continue;
            }
            $this->files[] = $relative;
        }
        closedir($handle);
    }
}
