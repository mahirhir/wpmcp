<?php
/**
 * Faithful global test double for the SureForms integration, reproducing the
 * SRFM\Inc\Database\Tables\Entries accessor the integration uses for every
 * entry read and delete (get, get_all, get_total_entries_by_status, delete),
 * verified against SureForms 2.x. SureForms creates its own srfm_entries table
 * on activation, which the harness cannot install, so the accessor is doubled
 * here while the forms side runs against a real sureforms_form CPT. A real
 * SureForms always wins. SRFM_VER is deliberately NOT defined: availability is
 * then driven by the CPT, so a test can exercise the unavailable path.
 */

namespace SRFM\Inc\Database\Tables {
    if (! class_exists('\SRFM\Inc\Database\Tables\Entries')) {
        class Entries
        {
            /** @var array<int,array<string,mixed>> Entry rows keyed by ID. */
            public static array $rows = [];

            /** Ids passed to delete(), in call order. */
            public static array $deleted = [];

            /** Force delete() to report failure. */
            public static bool $delete_fails = false;

            public static function reset(): void
            {
                self::$rows         = [];
                self::$deleted      = [];
                self::$delete_fails = false;
            }

            /** Seed one row the way SureForms stores it (form_data is JSON). */
            public static function seed(int $id, int $form_id, string $status, array $values, string $created_at = '2026-01-01 00:00:00'): void
            {
                self::$rows[ $id ] = [
                    'ID'         => $id,
                    'form_id'    => $form_id,
                    'status'     => $status,
                    'created_at' => $created_at,
                    'form_data'  => wp_json_encode($values),
                ];
            }

            public static function get($id)
            {
                return self::$rows[ (int) $id ] ?? [];
            }

            /** Honours only the equality WHERE clauses the integration sends. */
            public static function get_all($args = [])
            {
                $rows = array_values(self::$rows);
                foreach ((array) ($args['where'] ?? []) as $clause) {
                    $key   = (string) ($clause['key'] ?? '');
                    $value = $clause['value'] ?? null;
                    $rows  = array_values(array_filter(
                        $rows,
                        static fn ($row) => isset($row[ $key ]) && (string) $row[ $key ] === (string) $value
                    ));
                }
                return $rows;
            }

            public static function get_total_entries_by_status($status, $form_id = 0)
            {
                return count(array_filter(
                    self::$rows,
                    static fn ($row) => (int) $row['form_id'] === (int) $form_id
                ));
            }

            public static function delete($id)
            {
                self::$deleted[] = (int) $id;
                if (self::$delete_fails) {
                    return false;
                }
                unset(self::$rows[ (int) $id ]);
                return true;
            }
        }
    }
}
