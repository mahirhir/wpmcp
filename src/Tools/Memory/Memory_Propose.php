<?php

namespace WPMCP\Tools\Memory;

use WPMCP\Memory\Memory_Config;
use WPMCP\Memory\Memory_Store;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Propose one durable memory entry. The result is always a PENDING entry:
 * inert, not injected into any future session, and not enforced, until an
 * administrator publishes it in wp-admin.
 *
 * A severity=block proposal is how an agent suggests a guardrail ("never
 * touch the header template"); publishing it is what turns the suggestion
 * into an actual server-side denial in Registrar::is_permitted(). The
 * response says which state the entry is in so an agent never reports a
 * guardrail as active when a human has not approved it.
 */
class Memory_Propose
{
    public function handle(array $args)
    {
        if (! Memory_Config::tools_enabled()) {
            return Memory_Config::disabled_error();
        }

        $id = Memory_Store::propose([
            'title'    => $args['title'] ?? '',
            'text'     => $args['text'] ?? '',
            'kind'     => $args['kind'] ?? 'fact',
            'severity' => $args['severity'] ?? 'note',
            'targets'  => $args['targets'] ?? [],
        ]);

        if (is_wp_error($id)) {
            return $id;
        }

        $entry = Memory_Store::get((int) $id);

        return [
            'id'       => (int) $id,
            'status'   => 'pending',
            'enforced' => false,
            'kind'     => (string) ($entry['kind'] ?? ''),
            'severity' => (string) ($entry['severity'] ?? ''),
            'targets'  => (array) ($entry['targets'] ?? []),
            'note'     => 'Stored as a pending proposal. It is not injected into future sessions and, '
                . 'if severity=block, is not enforced until an administrator publishes it.',
        ];
    }
}
