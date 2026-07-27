<?php

namespace WPMCP\Integrations;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Gravity Forms integration exposed as a wpmcp/gravityforms-read /
 * wpmcp/gravityforms-write dispatcher pair (issue #66 forms surface).
 *
 * Read operations delegate to Gravity Forms' own public GFAPI (verified
 * against Gravity Forms 2.9): get_forms(), get_form(), get_entries(),
 * get_entry(), get_notes(). They let an agent inspect a site's forms, their
 * field definitions and notifications, and the submitted entries and notes,
 * without ever booting or bypassing Gravity Forms itself.
 *
 * Ships read-only on purpose: Gravity Forms entries live in Gravity Forms'
 * own tables (gf_entry / gf_entry_meta), which are NOT one of the object
 * types Safe_Mutation snapshots (post, option, user, comment, order), so an
 * entry write could not be made one-click reversible. Rather than ship a
 * write that silently breaks the snapshot-before-every-write guarantee, entry
 * management is deferred until it can be done recoverably. The pair still
 * registers a write channel for forward-compatibility, currently with no
 * write operations, so the surface and docs stay stable when writes land.
 */
class Gravity_Forms_Integration extends Integration_Dispatcher
{
    public function integration(): string
    {
        return 'gravityforms';
    }

    public function is_available(): bool
    {
        return class_exists('GFAPI');
    }

    protected function summary(): string
    {
        return 'Gravity Forms (forms, fields, notifications, and entries)';
    }

    protected function operations(): array
    {
        return [
            'list-forms' => [
                'mode'         => 'read',
                'description'  => 'List Gravity Forms forms with id, title, active state, entry count, and field count',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'active' => [ 'type' => 'boolean' ],
                    ],
                ],
                'handler'      => function (array $args): array {
                    $active = ! isset($args['active']) || (bool) $args['active'];
                    $forms  = \GFAPI::get_forms($active);
                    $out    = [];
                    foreach ((array) $forms as $form) {
                        $id    = (int) ($form['id'] ?? 0);
                        $count = \GFAPI::count_entries($id);
                        $out[] = [
                            'id'          => $id,
                            'title'       => (string) ($form['title'] ?? ''),
                            'is_active'   => ! empty($form['is_active']),
                            'date_created' => $form['date_created'] ?? null,
                            'field_count' => is_array($form['fields'] ?? null) ? count($form['fields']) : 0,
                            'entry_count' => is_wp_error($count) ? null : (int) $count,
                        ];
                    }
                    return [ 'forms' => $out, 'total' => count($out) ];
                },
            ],
            'get-form' => [
                'mode'         => 'read',
                'description'  => 'Read one form in full: fields (with type, label, id), settings, notifications, and confirmations',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'form_id' => [ 'type' => 'integer', 'minimum' => 1 ],
                    ],
                    'required'   => [ 'form_id' ],
                ],
                'handler'      => function (array $args): array {
                    $form = \GFAPI::get_form((int) $args['form_id']);
                    return [ 'form' => empty($form) ? null : $form ];
                },
            ],
            'list-entries' => [
                'mode'         => 'read',
                'description'  => 'List a form\'s entries, newest first, with paging (page_size default 20, max 100) and an optional status filter (active/spam/trash)',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'form_id'   => [ 'type' => 'integer', 'minimum' => 1 ],
                        'page_size' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 100 ],
                        'offset'    => [ 'type' => 'integer', 'minimum' => 0 ],
                        'status'    => [ 'type' => 'string', 'enum' => [ 'active', 'spam', 'trash' ] ],
                    ],
                    'required'   => [ 'form_id' ],
                ],
                'handler'      => function (array $args): array {
                    $form_id   = (int) $args['form_id'];
                    $page_size = (int) ($args['page_size'] ?? 20);
                    $offset    = (int) ($args['offset'] ?? 0);
                    $search    = isset($args['status']) ? [ 'status' => (string) $args['status'] ] : [];
                    $paging    = [ 'offset' => $offset, 'page_size' => $page_size ];
                    $total     = 0;
                    $entries   = \GFAPI::get_entries($form_id, $search, null, $paging, $total);
                    return [
                        'form_id' => $form_id,
                        'entries' => is_wp_error($entries) ? [] : (array) $entries,
                        'total'   => (int) $total,
                        'paging'  => $paging,
                    ];
                },
            ],
            'get-entry' => [
                'mode'         => 'read',
                'description'  => 'Read a single entry (all field values plus meta) by entry id',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'entry_id' => [ 'type' => 'integer', 'minimum' => 1 ],
                    ],
                    'required'   => [ 'entry_id' ],
                ],
                'handler'      => function (array $args): array {
                    $entry = \GFAPI::get_entry((int) $args['entry_id']);
                    return [ 'entry' => is_wp_error($entry) ? null : $entry ];
                },
            ],
            'get-notes' => [
                'mode'         => 'read',
                'description'  => 'List the notes attached to an entry',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'entry_id' => [ 'type' => 'integer', 'minimum' => 1 ],
                    ],
                    'required'   => [ 'entry_id' ],
                ],
                'handler'      => function (array $args): array {
                    $notes = \GFAPI::get_notes([ 'entry_id' => (int) $args['entry_id'] ]);
                    return [ 'entry_id' => (int) $args['entry_id'], 'notes' => is_array($notes) ? $notes : [] ];
                },
            ],
        ];
    }
}
