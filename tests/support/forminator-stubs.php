<?php
/**
 * Faithful global test double for the Forminator integration, reproducing the
 * public Forminator_API surface the integration calls (get_forms, get_form,
 * get_form_entries, get_entry, delete_entry) plus the shapes of the form,
 * field, and entry models it reads, verified against Forminator 1.5x.
 * Forminator cannot be booted in the harness (its own tables and installer),
 * so the API is doubled here; live Forminator stays production-verified and a
 * real Forminator_API always wins.
 */

if (! class_exists('Forminator_API')) {
    /** Field model double: Forminator exposes ->raw through a magic __get. */
    class Forminator_Test_Field
    {
        public function __construct(private array $raw_data)
        {
        }

        public function __get(string $name)
        {
            return 'raw' === $name ? $this->raw_data : null;
        }
    }

    /** Form model double: id, slug name, settings, status, get_fields(). */
    class Forminator_Test_Form
    {
        /** @param Forminator_Test_Field[] $fields */
        public function __construct(
            public int $id,
            public string $name,
            public array $settings = [],
            public string $status = 'publish',
            private array $fields = []
        ) {
        }

        /** @return Forminator_Test_Field[] */
        public function get_fields(): array
        {
            return $this->fields;
        }
    }

    /** Entry model double: entry_id, date_created_sql, meta_data. */
    class Forminator_Test_Entry
    {
        public function __construct(
            public int $entry_id,
            public int $form_id,
            public string $date_created_sql = '2026-01-01 00:00:00',
            public array $meta_data = []
        ) {
        }
    }

    class Forminator_API
    {
        /** @var array<int,Forminator_Test_Form> */
        public static array $forms = [];

        /** @var array<int,Forminator_Test_Entry> */
        public static array $entries = [];

        /** Ids passed to delete_entry(), in call order. */
        public static array $deleted = [];

        /** Force delete_entry() to report failure. */
        public static bool $delete_fails = false;

        public static function reset(): void
        {
            self::$forms        = [];
            self::$entries      = [];
            self::$deleted      = [];
            self::$delete_fails = false;
        }

        /** @return Forminator_Test_Form[] */
        public static function get_forms($ids = null, $page = 1, $per_page = 10)
        {
            $all    = array_values(self::$forms);
            $offset = max(0, ((int) $page - 1) * (int) $per_page);
            return array_slice($all, $offset, (int) $per_page);
        }

        public static function get_form($id)
        {
            return self::$forms[(int) $id] ?? null;
        }

        /** @return Forminator_Test_Entry[] */
        public static function get_form_entries($form_id)
        {
            return array_values(array_filter(
                self::$entries,
                static fn ($e) => (int) $e->form_id === (int) $form_id
            ));
        }

        public static function get_entry($form_id, $entry_id)
        {
            $entry = self::$entries[(int) $entry_id] ?? null;
            if (! $entry || (int) $entry->form_id !== (int) $form_id) {
                return null;
            }
            return $entry;
        }

        public static function delete_entry($form_id, $entry_id)
        {
            self::$deleted[] = (int) $entry_id;
            if (self::$delete_fails) {
                return false;
            }
            unset(self::$entries[(int) $entry_id]);
            return true;
        }
    }
}
