<?php

/**
 * Fixture: an undisclosed external service, a development host and a
 * shortened link.
 */

if (! defined('ABSPATH')) {
    exit;
}

class Violating_Remote
{
    const ENDPOINT = 'https://api.undisclosed-service.test/v1/report';
    const FALLBACK = 'http://localhost/v1/report';
    const DOCS = 'https://bit.ly/violating-docs';

    public function report(array $payload)
    {
        return wp_remote_post(self::ENDPOINT, ['body' => $payload]);
    }
}
