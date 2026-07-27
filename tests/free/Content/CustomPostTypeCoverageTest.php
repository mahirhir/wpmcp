<?php

namespace WPMCP\Tests\Free\Content;

use WPMCP\Tools\Content\Create_Post;
use WPMCP\Tools\Content\Get_Post;
use WPMCP\Tools\Content\List_Posts;
use WPMCP\Tools\Content\Update_Post;

/**
 * The content tools are post-type-agnostic: they list, read, create, and
 * update entries of ANY registered public custom post type (not just posts and
 * pages), with every write snapshotted. This is what covers CPT-based plugins,
 * for example directory listings, without a plugin-specific integration. Proven
 * here with a stand-in third-party CPT.
 */
class CustomPostTypeCoverageTest extends \WP_UnitTestCase
{
    private const CPT = 'demo_listing';

    protected function setUp(): void
    {
        parent::setUp();
        register_post_type(self::CPT, [ 'public' => true, 'label' => 'Listings' ]);
    }

    protected function tearDown(): void
    {
        unregister_post_type(self::CPT);
        parent::tearDown();
    }

    public function test_create_read_list_update_a_custom_post_type(): void
    {
        // Create
        $created = (new Create_Post())->handle([
            'post_type' => self::CPT,
            'title'     => 'Acme Bakery',
            'status'    => 'publish',
            'meta'      => [ 'phone' => '555-0100' ],
        ]);
        $id = (int) $created['post_id'];
        $this->assertGreaterThan(0, $id);

        // Read (including non-protected meta)
        $read = (new Get_Post())->handle([ 'post_id' => $id ]);
        $this->assertSame('Acme Bakery', $read['title']);
        $this->assertSame(self::CPT, $read['post_type']);
        $this->assertSame('555-0100', $read['meta']['phone']);

        // List by type
        $list  = (new List_Posts())->handle([ 'post_type' => self::CPT ]);
        $ids   = array_map(static fn ($p) => (int) $p['post_id'], $list['posts']);
        $this->assertContains($id, $ids);

        // Update
        (new Update_Post())->handle([ 'post_id' => $id, 'title' => 'Acme Bakery and Cafe' ]);
        $this->assertSame('Acme Bakery and Cafe', get_post($id)->post_title);
    }
}
