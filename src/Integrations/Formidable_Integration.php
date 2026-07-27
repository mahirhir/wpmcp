<?php

namespace WPMCP\Integrations;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Formidable Forms read integration (wpmcp/formidable-read pair), delegating
 * to Formidable's own FrmForm / FrmEntry models (verified against Formidable
 * 6.x). Read-only for the same reason as Gravity Forms: Formidable entries
 * live in Formidable's own tables, which Safe_Mutation does not snapshot, so
 * writes are deferred until they can be made one-click reversible.
 */
class Formidable_Integration extends Integration_Dispatcher
{
    public function integration(): string
    {
        return 'formidable';
    }

    public function is_available(): bool
    {
        return class_exists('FrmForm') && class_exists('FrmEntry');
    }

    protected function summary(): string
    {
        return 'Formidable Forms (forms, fields, and entries)';
    }

    protected function operations(): array
    {
        return [
            'list-forms' => [
                'mode'         => 'read',
                'description'  => 'List Formidable forms with id, name, and form key',
                'input_schema' => [ 'type' => 'object', 'properties' => [] ],
                'handler'      => function (): array {
                    $forms = \FrmForm::getAll();
                    $out   = [];
                    foreach ((array) $forms as $f) {
                        $out[] = [
                            'id'   => (int) ($f->id ?? 0),
                            'name' => (string) ($f->name ?? ''),
                            'key'  => (string) ($f->form_key ?? ''),
                        ];
                    }
                    return [ 'forms' => $out, 'total' => count($out) ];
                },
            ],
            'get-form' => [
                'mode'         => 'read',
                'description'  => 'Read one Formidable form with its stored settings and fields',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [ 'form_id' => [ 'type' => 'integer', 'minimum' => 1 ] ],
                    'required'   => [ 'form_id' ],
                ],
                'handler'      => function (array $args): array {
                    $form = \FrmForm::getOne((int) $args['form_id']);
                    return [ 'form' => empty($form) ? null : $form ];
                },
            ],
            'list-entries' => [
                'mode'         => 'read',
                'description'  => 'List a form\'s entries (submissions)',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [ 'form_id' => [ 'type' => 'integer', 'minimum' => 1 ] ],
                    'required'   => [ 'form_id' ],
                ],
                'handler'      => function (array $args): array {
                    $form_id = (int) $args['form_id'];
                    $entries = \FrmEntry::getAll([ 'it.form_id' => $form_id ], '', '', true);
                    return [ 'form_id' => $form_id, 'entries' => is_array($entries) ? array_values($entries) : [], 'total' => is_array($entries) ? count($entries) : 0 ];
                },
            ],
            'get-entry' => [
                'mode'         => 'read',
                'description'  => 'Read a single entry with its field values by entry id',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [ 'entry_id' => [ 'type' => 'integer', 'minimum' => 1 ] ],
                    'required'   => [ 'entry_id' ],
                ],
                'handler'      => function (array $args): array {
                    $entry = \FrmEntry::getOne((int) $args['entry_id'], true);
                    return [ 'entry' => empty($entry) ? null : $entry ];
                },
            ],
        ];
    }
}
