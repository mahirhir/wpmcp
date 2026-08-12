<?php

namespace WPMCP\Tools\Skills;

use WPMCP\Skills\Skill_Library;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Read-only: the agent-skill catalog (issue #74).
 *
 * Returns one compact entry per skill (slug, name, one-line description,
 * version, tier, tags, source) and never a body: loading instructions is
 * get-skill's job, so a client can discover the whole library for a few
 * hundred tokens and pay for only the playbook it actually needs.
 *
 * Skills whose declared `requires` abilities are not registered on this site
 * are omitted by default, because a playbook an agent cannot execute is
 * worse than no playbook. `include_unavailable` brings them back with the
 * missing ability names attached, which is what makes "why is that skill not
 * here?" answerable instead of mysterious.
 *
 * A search that matches nothing returns the full catalog with an explanatory
 * note rather than an empty list, so a bad guess at a keyword never dead-ends
 * the agent.
 */
class List_Skills
{
    public function handle(array $args): array
    {
        $search = isset($args['search']) && is_string($args['search']) ? trim($args['search']) : '';
        $tag    = isset($args['tag']) && is_string($args['tag']) ? trim($args['tag']) : '';
        $all    = Skill_Library::all();

        if (empty($args['include_unavailable'])) {
            $all = array_values(array_filter($all, static fn ($s) => true === $s['available']));
        }

        $skills = $all;

        if ('' !== $tag) {
            $skills = array_values(array_filter(
                $skills,
                static fn ($s) => in_array($tag, $s['tags'], true)
            ));
        }

        if ('' !== $search) {
            $matched = array_values(array_filter(
                $skills,
                static fn ($s) => false !== stripos(
                    $s['slug'] . ' ' . $s['name'] . ' ' . $s['description'] . ' ' . implode(' ', $s['tags']),
                    $search
                )
            ));

            if ([] === $matched && [] !== $skills) {
                return [
                    'skills' => $skills,
                    'count'  => count($skills),
                    'note'   => sprintf(
                        'No skill matched "%s". Showing all %d available skills; call wpmcp/get-skill with a slug to load one.',
                        $search,
                        count($skills)
                    ),
                ];
            }

            $skills = $matched;
        }

        return [
            'skills' => $skills,
            'count'  => count($skills),
        ];
    }
}
