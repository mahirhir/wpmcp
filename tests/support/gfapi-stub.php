<?php
/**
 * Faithful global GFAPI test double for the Gravity Forms integration tests.
 *
 * Gravity Forms is a paid plugin and cannot be installed from wordpress.org in
 * the test harness, so this stub reproduces the exact public method contracts
 * used by Gravity_Forms_Integration (verified against Gravity Forms 2.9's
 * includes/api.php). Live Gravity Forms remains production-verified, matching
 * the plugin's stance for third-party services CI cannot boot.
 *
 * Only defined when the real GFAPI is absent, so a real install always wins.
 */
if (! class_exists('GFAPI')) {
    class GFAPI
    {
        /** @var array<int,array<string,mixed>> */
        public static array $forms = [];
        /** @var array<int,array<string,mixed>> */
        public static array $entries = [];
        /** @var array<int,array<string,mixed>> */
        public static array $notes = [];

        public static function reset(): void
        {
            self::$forms = self::$entries = self::$notes = [];
        }

        public static function get_forms($active = true, $trash = false, $sort_column = 'id', $sort_dir = 'ASC')
        {
            return array_values(self::$forms);
        }

        public static function get_form($form_id)
        {
            return self::$forms[(int) $form_id] ?? false;
        }

        public static function count_entries($form_ids, $search_criteria = [])
        {
            return count(self::$entries);
        }

        public static function get_entries($form_ids, $search_criteria = [], $sorting = null, $paging = null, &$total_count = null)
        {
            $all = array_values(self::$entries);
            if (! empty($search_criteria['status'])) {
                $all = array_values(array_filter($all, static fn ($e) => ($e['status'] ?? 'active') === $search_criteria['status']));
            }
            $total_count = count($all);
            $offset      = (int) ($paging['offset'] ?? 0);
            $size        = (int) ($paging['page_size'] ?? 20);
            return array_slice($all, $offset, $size);
        }

        public static function get_entry($entry_id)
        {
            return self::$entries[(int) $entry_id] ?? new \WP_Error('gform_id_not_found', 'Entry not found.');
        }

        public static function get_notes($search_criteria = [], $sorting = null)
        {
            $entry_id = (int) ($search_criteria['entry_id'] ?? 0);
            return array_values(array_filter(self::$notes, static fn ($n) => (int) ($n['entry_id'] ?? 0) === $entry_id));
        }
    }
}
