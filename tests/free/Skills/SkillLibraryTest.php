<?php

namespace WPMCP\Tests\Free\Skills;

use WPMCP\Skills\Skill_Library;

/**
 * Discovery, frontmatter parsing and validation for the agent-skill catalog
 * (issue #74).
 *
 * The contract these tests pin: a document is served only when it parses AND
 * validates, an invalid document is reported rather than silently dropped,
 * and nothing outside a declared source root can ever be read.
 */
class SkillLibraryTest extends \WP_UnitTestCase
{
    use Skill_Fixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Skill_Library::reset();
    }

    protected function tearDown(): void
    {
        $this->clean_up_skill_fixtures();
        parent::tearDown();
    }

    public function test_every_bundled_skill_parses_and_validates(): void
    {
        $skills = Skill_Library::all();

        $this->assertGreaterThanOrEqual(5, count($skills), 'The bundled starter library must ship at least 5 skills.');
        $this->assertSame([], Skill_Library::invalid(), 'No bundled SKILL.md may fail validation.');

        foreach ($skills as $skill) {
            $this->assertSame('bundled', $skill['source']);
            $this->assertNotSame('', $skill['name']);
            $this->assertNotSame('', $skill['description']);
            $this->assertMatchesRegularExpression('/^\d+\.\d+(\.\d+)?$/', $skill['version']);
            $this->assertContains($skill['tier'], ['free', 'pro']);
        }
    }

    public function test_bundled_skills_cover_the_documented_starter_set(): void
    {
        $slugs = array_keys(Skill_Library::index());

        foreach (
            [
                'wpmcp-safe-writes',
                'wpmcp-governance',
                'wpmcp-elementor-editing',
                'wpmcp-woocommerce-catalog',
                'wpmcp-safe-database-work',
            ] as $expected
        ) {
            $this->assertContains($expected, $slugs);
        }
    }

    public function test_bundled_bodies_are_served_verbatim(): void
    {
        $skill = Skill_Library::get('wpmcp-safe-writes');
        $raw   = (string) file_get_contents(Skill_Library::bundled_dir() . '/wpmcp-safe-writes/SKILL.md');

        $this->assertNotNull($skill);
        $this->assertStringContainsString($skill['body'], $raw);
        $this->assertStringStartsWith('# Safe writes with wpmcp', $skill['body']);
        $this->assertStringNotContainsString('---', substr($skill['body'], 0, 3));
    }

    public function test_parse_returns_null_without_a_frontmatter_block(): void
    {
        $this->assertNull(Skill_Library::parse("# Just markdown\n"));
        $this->assertNull(Skill_Library::parse("---\nname: unterminated\n"));
    }

    public function test_parse_reads_scalars_inline_lists_and_block_lists(): void
    {
        $parsed = Skill_Library::parse(
            "---\n"
            . "name: \"Quoted name\"\n"
            . "description: 'Single quoted'\n"
            . "version: 2.1\n"
            . "tags: [alpha, beta]\n"
            . "requires:\n"
            . "  - wpmcp/get-page\n"
            . "  - wpmcp/update-post\n"
            . "---\n"
            . "Body.\n"
        );

        $this->assertNotNull($parsed);
        $this->assertSame('Quoted name', $parsed['frontmatter']['name']);
        $this->assertSame('Single quoted', $parsed['frontmatter']['description']);
        $this->assertSame(['alpha', 'beta'], $parsed['frontmatter']['tags']);
        $this->assertSame(['wpmcp/get-page', 'wpmcp/update-post'], $parsed['frontmatter']['requires']);
        $this->assertSame('Body.', $parsed['body']);
    }

    public function test_parse_ignores_comments_and_blank_lines_and_empty_inline_lists(): void
    {
        $parsed = Skill_Library::parse(
            "---\n# a comment\n\nname: X\ntags: []\n---\nBody.\n"
        );

        $this->assertNotNull($parsed);
        $this->assertSame('X', $parsed['frontmatter']['name']);
        $this->assertSame([], $parsed['frontmatter']['tags']);
    }

    public function test_parse_rejects_a_frontmatter_line_it_cannot_understand(): void
    {
        $this->assertNull(Skill_Library::parse("---\nname: ok\nthis is not a key\n---\nBody.\n"));
    }

    public function test_parse_closes_on_the_first_delimiter_line_only(): void
    {
        $parsed = Skill_Library::parse("---\nname: X\n---\nIntro\n\n---\n\nAfter a horizontal rule.\n");

        $this->assertNotNull($parsed);
        $this->assertSame('X', $parsed['frontmatter']['name']);
        $this->assertStringContainsString('After a horizontal rule.', $parsed['body']);
    }

    public function test_parse_accepts_windows_line_endings(): void
    {
        $parsed = Skill_Library::parse("---\r\nname: X\r\n---\r\nBody.\r\n");

        $this->assertNotNull($parsed);
        $this->assertSame('Body.', $parsed['body']);
    }

    public function test_validate_names_every_missing_required_field(): void
    {
        $errors = Skill_Library::validate([], '');

        $this->assertContains('missing_name', $errors);
        $this->assertContains('missing_description', $errors);
        $this->assertContains('missing_version', $errors);
        $this->assertContains('empty_body', $errors);
    }

    public function test_validate_rejects_a_non_semver_version(): void
    {
        $errors = Skill_Library::validate(
            ['name' => 'X', 'description' => 'Y', 'version' => 'v1-beta'],
            'Body.'
        );

        $this->assertSame(['invalid_version'], $errors);
    }

    public function test_validate_rejects_an_unknown_tier(): void
    {
        $errors = Skill_Library::validate(
            ['name' => 'X', 'description' => 'Y', 'version' => '1.0', 'tier' => 'enterprise'],
            'Body.'
        );

        $this->assertSame(['invalid_tier'], $errors);
    }

    public function test_validate_enforces_the_name_and_description_bounds(): void
    {
        $errors = Skill_Library::validate(
            [
                'name'        => str_repeat('n', Skill_Library::MAX_NAME_LENGTH + 1),
                'description' => str_repeat('d', Skill_Library::MAX_DESCRIPTION_LENGTH + 1),
                'version'     => '1.0',
            ],
            'Body.'
        );

        $this->assertContains('name_too_long', $errors);
        $this->assertContains('description_too_long', $errors);
    }

    public function test_validate_rejects_list_fields_that_are_not_lists_of_strings(): void
    {
        $errors = Skill_Library::validate(
            [
                'name'        => 'X',
                'description' => 'Y',
                'version'     => '1.0',
                'tags'        => 'not-a-list',
                'requires'    => [['nested']],
            ],
            'Body.'
        );

        $this->assertContains('invalid_tags', $errors);
        $this->assertContains('invalid_requires', $errors);
    }

    public function test_a_custom_source_is_discovered_through_the_filter(): void
    {
        $dir = $this->make_source_dir();
        $this->write_skill($dir, 'my-house-style', $this->skill_doc(['name' => 'House style']));
        $this->use_source($dir);

        $skill = Skill_Library::get('my-house-style');

        $this->assertNotNull($skill);
        $this->assertSame('House style', $skill['name']);
        $this->assertSame('fixture', $skill['source']);
        // The bundled library is still there: a custom source appends.
        $this->assertNotNull(Skill_Library::get('wpmcp-safe-writes'));
    }

    public function test_a_later_source_overrides_a_bundled_slug(): void
    {
        $dir = $this->make_source_dir();
        $this->write_skill($dir, 'wpmcp-safe-writes', $this->skill_doc(['name' => 'Our own version']));
        $this->use_source($dir);

        $skill = Skill_Library::get('wpmcp-safe-writes');

        $this->assertSame('Our own version', $skill['name']);
        $this->assertSame('fixture', $skill['source']);
    }

    public function test_nested_skills_are_discovered_one_level_deep(): void
    {
        $dir = $this->make_source_dir();
        $this->write_skill($dir, 'builders/elementor', $this->skill_doc(['name' => 'Nested']));
        $this->use_only_source($dir);

        $this->assertSame(['builders/elementor'], array_keys(Skill_Library::index()));
    }

    public function test_an_invalid_document_is_excluded_and_reported(): void
    {
        $dir = $this->make_source_dir();
        $this->write_skill($dir, 'broken', "---\nname: Broken\n---\n\nNo description, no version.\n");
        $this->write_skill($dir, 'fine', $this->skill_doc());
        $this->use_only_source($dir);

        $this->assertSame(['fine'], array_keys(Skill_Library::index()));

        $invalid = Skill_Library::invalid();
        $this->assertCount(1, $invalid);
        $this->assertStringEndsWith('broken/SKILL.md', $invalid[0]['path']);
        $this->assertContains('missing_description', $invalid[0]['errors']);
        $this->assertContains('missing_version', $invalid[0]['errors']);
    }

    public function test_a_document_without_frontmatter_is_reported_not_served(): void
    {
        $dir = $this->make_source_dir();
        $this->write_skill($dir, 'headerless', "# No frontmatter here\n");
        $this->use_only_source($dir);

        $this->assertSame([], Skill_Library::all());
        $this->assertSame(['missing_frontmatter'], Skill_Library::invalid()[0]['errors']);
    }

    public function test_an_oversized_document_is_refused_so_bodies_stay_servable(): void
    {
        $dir = $this->make_source_dir();
        $this->write_skill(
            $dir,
            'huge',
            $this->skill_doc([], str_repeat('x', Skill_Library::MAX_FILE_BYTES + 1))
        );
        $this->use_only_source($dir);

        $this->assertSame([], Skill_Library::all());
        $this->assertSame(['file_too_large'], Skill_Library::invalid()[0]['errors']);
    }

    public function test_a_directory_whose_name_is_not_a_slug_is_skipped(): void
    {
        $dir = $this->make_source_dir();
        $this->write_skill($dir, 'Not_A_Slug', $this->skill_doc());
        $this->write_skill($dir, 'good-slug', $this->skill_doc());
        $this->use_only_source($dir);

        $this->assertSame(['good-slug'], array_keys(Skill_Library::index()));
    }

    public function test_a_symlink_escaping_the_source_root_is_not_read(): void
    {
        $dir     = $this->make_source_dir();
        $outside = $this->make_source_dir('outside');
        $this->write_skill($outside, 'secret', $this->skill_doc(['name' => 'Should not be served']));

        mkdir($dir . '/escape');
        symlink($outside . '/secret/SKILL.md', $dir . '/escape/SKILL.md');
        $this->use_only_source($dir);

        $this->assertSame([], Skill_Library::all(), 'A SKILL.md resolving outside its source root must be skipped.');
    }

    public function test_malformed_source_descriptors_are_discarded(): void
    {
        $filter = static fn ($sources) => [
            'not-an-array',
            ['id' => '', 'path' => sys_get_temp_dir()],
            ['id' => 'nopath'],
            ['id' => 'missing-dir', 'path' => sys_get_temp_dir() . '/wpmcp-not-here-' . wp_generate_password(6, false)],
        ];

        add_filter('wpmcp_skill_sources', $filter);
        try {
            Skill_Library::reset();
            $this->assertSame([], Skill_Library::sources());
            $this->assertSame([], Skill_Library::all());
        } finally {
            remove_filter('wpmcp_skill_sources', $filter);
            Skill_Library::reset();
        }
    }

    public function test_a_non_array_filter_return_leaves_the_default_sources(): void
    {
        $filter = static fn ($sources) => 'nonsense';

        add_filter('wpmcp_skill_sources', $filter);
        try {
            Skill_Library::reset();
            $ids = array_column(Skill_Library::sources(), 'id');
            $this->assertContains('bundled', $ids);
        } finally {
            remove_filter('wpmcp_skill_sources', $filter);
            Skill_Library::reset();
        }
    }

    public function test_a_source_entry_without_a_label_falls_back_to_its_id(): void
    {
        $dir    = $this->make_source_dir();
        $filter = static fn ($sources) => [['id' => 'bare', 'path' => $dir]];

        add_filter('wpmcp_skill_sources', $filter);
        try {
            Skill_Library::reset();
            $this->assertSame([['id' => 'bare', 'label' => 'bare', 'path' => $dir]], Skill_Library::sources());
        } finally {
            remove_filter('wpmcp_skill_sources', $filter);
            Skill_Library::reset();
        }
    }

    public function test_the_catalog_is_capped(): void
    {
        $dir = $this->make_source_dir();
        for ($i = 0; $i < Skill_Library::MAX_SKILLS + 5; $i++) {
            $this->write_skill($dir, 'skill-' . $i, $this->skill_doc());
        }
        $this->use_only_source($dir);

        $this->assertCount(Skill_Library::MAX_SKILLS, Skill_Library::index());
    }

    public function test_a_skill_requiring_an_unregistered_ability_is_marked_unavailable(): void
    {
        $dir = $this->make_source_dir();
        $this->write_skill(
            $dir,
            'needs-a-ghost',
            $this->skill_doc(['requires' => '[wpmcp/no-such-ability, wpmcp/get-page]'])
        );
        $this->use_only_source($dir);

        $skill = Skill_Library::get('needs-a-ghost');

        $this->assertFalse($skill['available']);
        $this->assertSame(['wpmcp/no-such-ability'], $skill['missing_abilities']);
    }

    public function test_get_returns_null_for_an_unknown_slug(): void
    {
        $this->assertNull(Skill_Library::get('nope'));
        $this->assertNull(Skill_Library::get('../../wp-config'));
    }

    public function test_reset_drops_the_memoized_index(): void
    {
        $dir = $this->make_source_dir();
        $this->use_only_source($dir);
        $this->assertSame([], Skill_Library::all());

        $this->write_skill($dir, 'added-later', $this->skill_doc());
        $this->assertSame([], Skill_Library::all(), 'The index is memoized per request.');

        Skill_Library::reset();
        $this->assertSame(['added-later'], array_keys(Skill_Library::index()));
    }
}
