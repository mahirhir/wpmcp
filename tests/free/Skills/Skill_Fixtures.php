<?php

namespace WPMCP\Tests\Free\Skills;

use WPMCP\Skills\Skill_Library;

/**
 * Builds throwaway on-disk skill sources for the issue #74 suite and wires
 * them in through the public wpmcp_skill_sources filter, i.e. exactly the
 * extension point a third-party plugin would use. Nothing here reaches into
 * Skill_Library's internals.
 */
trait Skill_Fixtures
{
    /** @var string[] absolute temp directories created by this test. */
    private array $skill_dirs = [];

    /** @var callable|null */
    private $skill_source_filter = null;

    private function make_source_dir(string $label = 'src'): string
    {
        $dir = sys_get_temp_dir() . '/wpmcp-skills-' . $label . '-' . wp_generate_password(8, false);
        mkdir($dir, 0777, true);
        $this->skill_dirs[] = $dir;

        return $dir;
    }

    /** Write a SKILL.md at <dir>/<slug>/SKILL.md and return its path. */
    private function write_skill(string $dir, string $slug, string $contents): string
    {
        $path = $dir . '/' . $slug;
        mkdir($path, 0777, true);
        file_put_contents($path . '/SKILL.md', $contents);

        return $path . '/SKILL.md';
    }

    /**
     * A valid document with overridable frontmatter.
     *
     * @param array<string, string> $front
     */
    private function skill_doc(array $front = [], string $body = 'Body text.'): string
    {
        $front = array_merge(
            [
                'name'        => 'Fixture skill',
                'description' => 'A fixture used by the skills test suite.',
                'version'     => '1.0.0',
            ],
            $front
        );

        $lines = [];
        foreach ($front as $key => $value) {
            $lines[] = $key . ': ' . $value;
        }

        return "---\n" . implode("\n", $lines) . "\n---\n\n" . $body . "\n";
    }

    /** Register $dir as an extra skill source for the rest of the test. */
    private function use_source(string $dir, string $id = 'fixture'): void
    {
        $this->skill_source_filter = static function ($sources) use ($dir, $id) {
            $sources[] = ['id' => $id, 'label' => 'Fixture', 'path' => $dir];
            return $sources;
        };
        add_filter('wpmcp_skill_sources', $this->skill_source_filter);
        Skill_Library::reset();
    }

    /** Register $dir as the ONLY skill source, so the bundled library is out of the way. */
    private function use_only_source(string $dir, string $id = 'fixture'): void
    {
        $this->skill_source_filter = static fn ($sources) => [
            ['id' => $id, 'label' => 'Fixture', 'path' => $dir],
        ];
        add_filter('wpmcp_skill_sources', $this->skill_source_filter);
        Skill_Library::reset();
    }

    private function clean_up_skill_fixtures(): void
    {
        if (null !== $this->skill_source_filter) {
            remove_filter('wpmcp_skill_sources', $this->skill_source_filter);
            $this->skill_source_filter = null;
        }
        foreach ($this->skill_dirs as $dir) {
            $this->rmdir_recursive($dir);
        }
        $this->skill_dirs = [];
        Skill_Library::reset();
    }

    private function rmdir_recursive(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach ((array) scandir($dir) as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_link($path) || is_file($path)) {
                unlink($path);
                continue;
            }
            $this->rmdir_recursive($path);
        }
        rmdir($dir);
    }
}
