<?php

namespace WPMCP\Integrations;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Fluent Forms read integration (wpmcp/fluentforms-read pair), delegating to
 * Fluent Forms' own query builder wpFluent() against its fluentform_forms table
 * (verified against Fluent Forms 5.x). Surfaces forms and their decoded field
 * definitions.
 *
 * Read-only, and forms-only: submissions live in Fluent Forms' own tables that
 * WP MCP does not snapshot, so entry writes could not be made reversible and
 * are deferred (mirrors the other form integrations).
 */
class Fluent_Forms_Integration extends Integration_Dispatcher
{
    public function integration(): string
    {
        return 'fluentforms';
    }

    public function is_available(): bool
    {
        return function_exists('wpFluent');
    }

    protected function summary(): string
    {
        return 'Fluent Forms (forms and their field definitions)';
    }

    private static function decode_fields($row): array
    {
        $raw = is_object($row) ? ($row->form_fields ?? '') : ($row['form_fields'] ?? '');
        $data = json_decode((string) $raw, true);
        $fields = [];
        if (is_array($data['fields'] ?? null)) {
            foreach ($data['fields'] as $field) {
                $fields[] = [
                    'name'    => (string) ($field['attributes']['name'] ?? ($field['uniqElKey'] ?? '')),
                    'element' => (string) ($field['element'] ?? ''),
                    'label'   => (string) ($field['settings']['label'] ?? ''),
                ];
            }
        }
        return $fields;
    }

    protected function operations(): array
    {
        return [
            'list-forms' => [
                'mode'         => 'read',
                'description'  => 'List Fluent Forms forms with id and title',
                'input_schema' => [ 'type' => 'object', 'properties' => [] ],
                'handler'      => function (): array {
                    $rows = wpFluent()->table('fluentform_forms')->get();
                    $out  = [];
                    foreach ((array) $rows as $row) {
                        $out[] = [
                            'id'    => (int) (is_object($row) ? $row->id : $row['id']),
                            'title' => (string) (is_object($row) ? $row->title : $row['title']),
                        ];
                    }
                    return [ 'forms' => $out, 'total' => count($out) ];
                },
            ],
            'get-form' => [
                'mode'         => 'read',
                'description'  => 'Read one Fluent Forms form with its decoded field definitions',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [ 'form_id' => [ 'type' => 'integer', 'minimum' => 1 ] ],
                    'required'   => [ 'form_id' ],
                ],
                'handler'      => function (array $args): array {
                    $row = wpFluent()->table('fluentform_forms')->where('id', (int) $args['form_id'])->first();
                    if (! $row) {
                        return [ 'form' => null ];
                    }
                    return [ 'form' => [
                        'id'     => (int) (is_object($row) ? $row->id : $row['id']),
                        'title'  => (string) (is_object($row) ? $row->title : $row['title']),
                        'fields' => self::decode_fields($row),
                    ] ];
                },
            ],
        ];
    }
}
