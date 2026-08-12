<?php

// phpcs:disable PSR1.Files.SideEffects.FoundWithSymbols -- ABSPATH guard is an intentional side effect.
// phpcs:disable Squiz.Classes.ValidClassName.NotCamelCaps -- WP-style snake_case class name is intentional.
// phpcs:disable PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- WP-style snake_case method names are intentional.

namespace WPMCP\Tools\Search;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Incremental maintenance of the content search index (issue #83).
 *
 * Every save of an indexable post, and every menu edit, refreshes just that
 * object's fragments, so the index is correct without anyone remembering to
 * rebuild it. Deletes purge, so a search never resurrects removed copy.
 *
 * `save_post` fires at the end of wp_insert_post, AFTER meta written in the
 * same call, which is what makes reading `_elementor_data` here correct for
 * the plugin's own builder writes. Builder tools that update postmeta on an
 * existing post without touching the post row do not fire it, which is why
 * `updated_post_meta` / `added_post_meta` are wired for the three builder
 * storage keys as well.
 */
class Index_Hooks
{
    /** Postmeta keys whose change means the page's indexed copy changed. */
    public const BUILDER_META_KEYS = [
        '_elementor_data',
        '_bricks_page_content_2',
        '_elementor_edit_mode',
        '_et_pb_use_builder',
    ];

    /** Guard against re-entrancy when one save triggers another. */
    private static bool $indexing = false;

    public function register(): void
    {
        add_action('save_post', [$this, 'on_save_post'], 20, 2);
        add_action('deleted_post', [$this, 'on_deleted_post'], 10, 2);
        add_action('trashed_post', [$this, 'on_deleted_post'], 10, 1);
        add_action('untrashed_post', [$this, 'on_untrashed_post'], 10, 1);
        // Both menu hooks are needed: `wp_update_nav_menu` fires when the menu
        // OBJECT changes (rename, bulk save), while adding or editing a single
        // item only fires `wp_update_nav_menu_item`.
        add_action('wp_update_nav_menu', [$this, 'on_menu_updated'], 20, 1);
        add_action('wp_update_nav_menu_item', [$this, 'on_menu_updated'], 20, 1);
        add_action('wp_delete_nav_menu', [$this, 'on_menu_deleted'], 10, 1);
        add_action('updated_post_meta', [$this, 'on_post_meta_changed'], 20, 4);
        add_action('added_post_meta', [$this, 'on_post_meta_changed'], 20, 4);
    }

    public function on_save_post(int $post_id, $post = null): void
    {
        if (self::$indexing || $this->is_noise($post_id)) {
            return;
        }
        self::$indexing = true;
        try {
            Content_Indexer::index_post($post_id);
        } finally {
            self::$indexing = false;
        }
    }

    /** @param \WP_Post|null $post */
    public function on_deleted_post(int $post_id, $post = null): void
    {
        Content_Indexer::purge_post($post_id);

        // A removed menu ITEM is a deleted nav_menu_item post, and core has no
        // dedicated action for it. Menus are a small, fixed-size set, so the
        // honest fix is to refresh them rather than leave a dangling entry in
        // the index. Only reachable for the nav_menu_item post type.
        if ($post instanceof \WP_Post && 'nav_menu_item' === $post->post_type) {
            $this->reindex_all_menus();
        }
    }

    public function on_untrashed_post(int $post_id): void
    {
        $this->on_save_post($post_id);
    }

    public function on_menu_updated(int $menu_id): void
    {
        if (self::$indexing) {
            return;
        }
        self::$indexing = true;
        try {
            Content_Indexer::index_menu($menu_id);
        } finally {
            self::$indexing = false;
        }
    }

    private function reindex_all_menus(): void
    {
        if (self::$indexing) {
            return;
        }
        self::$indexing = true;
        try {
            foreach ((array) wp_get_nav_menus() as $menu) {
                Content_Indexer::index_menu((int) $menu->term_id);
            }
        } finally {
            self::$indexing = false;
        }
    }

    public function on_menu_deleted($term): void
    {
        $menu_id = is_object($term) ? (int) ($term->term_id ?? 0) : (int) $term;
        if ($menu_id > 0) {
            Content_Indexer::purge_menu($menu_id);
        }
    }

    /**
     * @param int|string $meta_id
     * @param int        $post_id
     * @param string     $meta_key
     * @param mixed      $meta_value
     */
    public function on_post_meta_changed($meta_id, $post_id, $meta_key, $meta_value): void
    {
        if (! in_array((string) $meta_key, self::BUILDER_META_KEYS, true)) {
            return;
        }
        $this->on_save_post((int) $post_id);
    }

    /**
     * Saves that must never touch the index: revisions, autosaves, and the
     * bulk-import / cron paths where indexing per row would be a needless
     * cost. A caller doing a bulk import runs reindex-search once at the end.
     */
    private function is_noise(int $post_id): bool
    {
        if ($post_id <= 0) {
            return false;
        }
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return true;
        }
        if (defined('WP_IMPORTING') && WP_IMPORTING) {
            return true;
        }
        return (bool) wp_is_post_revision($post_id) || (bool) wp_is_post_autosave($post_id);
    }
}
