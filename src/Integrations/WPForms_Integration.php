<?php

namespace WPMCP\Integrations;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * WPForms read integration (wpmcp/wpforms-read pair), delegating to WPForms'
 * own accessor wpforms()->form (verified against WPForms 1.8.x). Surfaces the
 * forms and their decoded field definitions.
 *
 * Read-only, and forms-only for now: entry storage is a WPForms Pro feature
 * with its own accessor, and entries could not be snapshotted for reversible
 * writes anyway, so entry access is deferred rather than shipped half-safe.
 */
class WPForms_Integration extends Integration_Dispatcher
{
    public function integration(): string
    {
        return 'wpforms';
    }

    public function is_available(): bool
    {
        return function_exists('wpforms') && is_object(wpforms()->form ?? null);
    }

    protected function summary(): string
    {
        return 'WPForms (forms and their field definitions)';
    }

    private static function decode(\WP_Post $post): array
    {
        $data = function_exists('wpforms_decode') ? wpforms_decode($post->post_content) : json_decode((string) $post->post_content, true);
        return [
            'id'     => (int) $post->ID,
            'title'  => (string) $post->post_title,
            'fields' => is_array($data['fields'] ?? null) ? array_values($data['fields']) : [],
        ];
    }

    protected function operations(): array
    {
        return [
            'list-forms' => [
                'mode'         => 'read',
                'description'  => 'List WPForms forms with id and title',
                'input_schema' => [ 'type' => 'object', 'properties' => [] ],
                'handler'      => function (): array {
                    $forms = wpforms()->form->get('', [ 'post_status' => 'publish' ]);
                    $out   = [];
                    foreach ((array) $forms as $post) {
                        if ($post instanceof \WP_Post) {
                            $out[] = [ 'id' => (int) $post->ID, 'title' => (string) $post->post_title ];
                        }
                    }
                    return [ 'forms' => $out, 'total' => count($out) ];
                },
            ],
            'get-form' => [
                'mode'         => 'read',
                'description'  => 'Read one WPForms form with its decoded field definitions',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [ 'form_id' => [ 'type' => 'integer', 'minimum' => 1 ] ],
                    'required'   => [ 'form_id' ],
                ],
                'handler'      => function (array $args): array {
                    $post = wpforms()->form->get((int) $args['form_id']);
                    return [ 'form' => $post instanceof \WP_Post ? self::decode($post) : null ];
                },
            ],
        ];
    }
}
