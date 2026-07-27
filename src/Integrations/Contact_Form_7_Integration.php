<?php

namespace WPMCP\Integrations;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Contact Form 7 read integration (wpmcp/contactform7-read pair), delegating
 * to CF7's own WPCF7_ContactForm model (verified against Contact Form 7 5.x).
 *
 * Read-only, and there is nothing to write back: Contact Form 7 does not store
 * submissions at all (that is what add-ons like Flamingo are for), so this
 * integration surfaces the forms themselves, their markup, and their mail
 * templates, which is the whole of CF7's own data model.
 */
class Contact_Form_7_Integration extends Integration_Dispatcher
{
    public function integration(): string
    {
        return 'contactform7';
    }

    public function is_available(): bool
    {
        return class_exists('WPCF7_ContactForm');
    }

    protected function summary(): string
    {
        return 'Contact Form 7 (forms, markup, and mail templates)';
    }

    private static function shape(\WPCF7_ContactForm $form): array
    {
        return [
            'id'    => (int) $form->id(),
            'title' => (string) $form->title(),
            'name'  => (string) $form->name(),
        ];
    }

    protected function operations(): array
    {
        return [
            'list-forms' => [
                'mode'         => 'read',
                'description'  => 'List Contact Form 7 forms with id, title, and slug',
                'input_schema' => [ 'type' => 'object', 'properties' => [] ],
                'handler'      => function (): array {
                    $forms = \WPCF7_ContactForm::find();
                    $out   = [];
                    foreach ((array) $forms as $form) {
                        if ($form instanceof \WPCF7_ContactForm) {
                            $out[] = self::shape($form);
                        }
                    }
                    return [ 'forms' => $out, 'total' => count($out) ];
                },
            ],
            'get-form' => [
                'mode'         => 'read',
                'description'  => 'Read one form: title, slug, the form markup, and the mail template',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [ 'form_id' => [ 'type' => 'integer', 'minimum' => 1 ] ],
                    'required'   => [ 'form_id' ],
                ],
                'handler'      => function (array $args): array {
                    $form = \WPCF7_ContactForm::get_instance((int) $args['form_id']);
                    if (! $form instanceof \WPCF7_ContactForm) {
                        return [ 'form' => null ];
                    }
                    return [
                        'form' => self::shape($form) + [
                            'form_markup' => (string) $form->prop('form'),
                            'mail'        => $form->prop('mail'),
                        ],
                    ];
                },
            ],
        ];
    }
}
