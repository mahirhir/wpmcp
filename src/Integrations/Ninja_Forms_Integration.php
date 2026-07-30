<?php

namespace WPMCP\Integrations;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Ninja Forms read integration (wpmcp/ninjaforms-read pair), delegating to
 * Ninja Forms' own model accessor Ninja_Forms()->form() (verified against Ninja
 * Forms 3.x). Surfaces forms and their field definitions.
 *
 * Read-only, and forms-only: submissions are stored as their own objects and
 * could not be snapshotted for reversible writes, so entry access is deferred
 * rather than shipped half-safe (mirrors the WPForms integration's posture).
 */
class Ninja_Forms_Integration extends Integration_Dispatcher
{
    public function integration(): string
    {
        return 'ninjaforms';
    }

    public function is_available(): bool
    {
        return function_exists('Ninja_Forms');
    }

    protected function summary(): string
    {
        return 'Ninja Forms (forms and their field definitions)';
    }

    protected function operations(): array
    {
        return [
            'list-forms' => [
                'mode'         => 'read',
                'description'  => 'List Ninja Forms forms with id and title',
                'input_schema' => [ 'type' => 'object', 'properties' => [] ],
                'handler'      => function (): array {
                    $out = [];
                    foreach ((array) Ninja_Forms()->form()->get_forms() as $form) {
                        $out[] = [
                            'id'    => (int) $form->get_id(),
                            'title' => (string) $form->get_setting('title'),
                        ];
                    }
                    return [ 'forms' => $out, 'total' => count($out) ];
                },
            ],
            'get-form' => [
                'mode'         => 'read',
                'description'  => 'Read one Ninja Forms form with its field definitions (id, type, label)',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [ 'form_id' => [ 'type' => 'integer', 'minimum' => 1 ] ],
                    'required'   => [ 'form_id' ],
                ],
                'handler'      => function (array $args): array {
                    $form = Ninja_Forms()->form((int) $args['form_id']);
                    $model = $form->get_form();
                    if (! $model) {
                        return [ 'form' => null ];
                    }
                    $fields = [];
                    foreach ((array) $form->get_fields() as $field) {
                        $fields[] = [
                            'id'    => (int) $field->get_id(),
                            'type'  => (string) $field->get_setting('type'),
                            'label' => (string) $field->get_setting('label'),
                        ];
                    }
                    return [ 'form' => [
                        'id'     => (int) $model->get_id(),
                        'title'  => (string) $model->get_setting('title'),
                        'fields' => $fields,
                    ] ];
                },
            ],
        ];
    }
}
