<?php

namespace WPMCP\MCP;

use WPMCP\Governance\Governance;
use WPMCP\Governance\Governance_Audit_Log;
use WPMCP\Identity\Identity_Context;
use WPMCP\Pro\Gate;
use WPMCP\RateLimit\Rate_Limiter;
use WPMCP\Safety\Operation_Context;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Also enforces per-request scoped-identity narrowing and records every
 * governance decision to the audit log (see Governance::is_within_identity_scope()
 * and Governance_Audit_Log), on top of the pre-existing capability +
 * Governance gating (issue #50).
 */
class Registrar
{
    /** @var Ability[] */
    private array $abilities = [];

    /** @var Ability[] every ability handed to register(), before any gating. */
    private array $declared = [];

    public function register(Ability $a): void
    {
        // Record the declaration BEFORE the tier/governance gates: the
        // ability grid (issue #78) must list governance-disabled and
        // unlicensed pro abilities so an admin can see and re-enable them.
        // Only all()/get() feed the exposed MCP surface; declared() is a
        // display catalog and grants nothing.
        $this->declared[ $a->name ] = $a;
        if ('pro' === $a->tier && ! Gate::is_pro()) {
            return;
        }
        if (! Governance::is_ability_enabled($a)) {
            return;
        }
        $this->abilities[ $a->name ] = $a;
        if (function_exists('wp_register_ability') && doing_action('wp_abilities_api_init')) {
            wp_register_ability($a->name, [
                'label'               => $a->description,
                'description'         => $a->description,
                'category'            => 'wpmcp',
                'input_schema'        => $a->input_schema,
                'execute_callback'    => $this->throttled($a),
                'permission_callback' => fn () => $this->is_permitted($a),
            ]);
        }
    }

    /**
     * Permission decision for one ability invocation. On top of the
     * pre-existing capability + Governance + identity-scope gating, 'pro'
     * tier abilities re-check the live license here (issue #54): the
     * Abilities API runs this before every execution, so a license that
     * lapses after registration cannot keep a pro tool usable. The
     * decision is audited exactly as before.
     */
    public function is_permitted(Ability $a): bool
    {
        $allowed = ('pro' !== $a->tier || Gate::is_pro())
            && current_user_can($a->capability)
            && Governance::is_ability_enabled($a)
            && Governance::is_within_identity_scope($a);
        $this->record_audit($a, $allowed);
        return $allowed;
    }

    /** @return Ability[] */
    public function all(): array
    {
        return array_values($this->abilities);
    }

    /**
     * The full declared surface: every ability register() was handed,
     * including ones the pro gate or governance then dropped. Display-only
     * (the ability grid, issue #78) — nothing here is registered with the
     * Abilities API or reachable over MCP unless it also passed the gates
     * into all().
     *
     * @return Ability[]
     */
    public function declared(): array
    {
        return array_values($this->declared);
    }

    /**
     * Look up one registered ability by name. Used by the meta-tools
     * (issue #79): list-tools/get-tool-schema read the registered contract,
     * and call-tool allowlists dispatch to wpmcp's own surface with it.
     */
    public function get(string $name): ?Ability
    {
        return $this->abilities[ $name ] ?? null;
    }

    /**
     * Record a governance-decision outcome to Governance_Audit_Log. Wrapped
     * in a try/catch so a logging failure (e.g. an option-write error) can
     * never turn an otherwise-successful permission check into a fatal
     * error; the allow/deny decision itself is always returned regardless
     * of whether this succeeds.
     */
    private function record_audit(Ability $a, bool $allowed): void
    {
        try {
            $identity = Identity_Context::current() ?? 'none';
            Governance_Audit_Log::record($a->name, $identity, $allowed);
        } catch (\Throwable $e) {
            // Auditing must never break the permission check it is observing.
        }
    }

    /**
     * Wraps an ability's handler with a rate-limit check that runs BEFORE the
     * real tool, and records the outcome of every call that gets past it. The
     * permission_callback contract (capability + Governance) is untouched;
     * this only sits in front of execute_callback, so a client over budget
     * never reaches the tool at all. The budget is a single counter per client
     * shared across every ability (Rate_Limiter::check() keys only on client
     * identity, not on ability name), matching "global per-client counter
     * across all abilities".
     *
     * Because every ability passes through this single choke point, the
     * outcome log (issue #134) covers reads as well as writes with no
     * per-tool changes: a throttled call, a WP_Error return, a thrown
     * exception and a success all leave exactly one row.
     */
    private function throttled(Ability $a): callable
    {
        return function (...$args) use ($a) {
            $client = Rate_Limiter::client_key();
            $status = Rate_Limiter::check($client);
            if (! $status['allowed']) {
                $error = new \WP_Error(
                    'wpmcp_rate_limited',
                    sprintf(
                        'Rate limit exceeded for "%s". Retry after %d second(s).',
                        $a->name,
                        $status['retry_after']
                    ),
                    [
                        'retry_after' => $status['retry_after'],
                        'remaining'   => $status['remaining'],
                    ]
                );
                $this->record_outcome($a, $client, $error, 0, $args, null);
                return $error;
            }

            // Bracket the call rather than resetting the context, so a tool
            // that dispatches another tool cannot steal this call's undo point.
            $mark    = Operation_Context::mark();
            $started = microtime(true);
            try {
                $result = ($a->handler)(...$args);
            } catch (\Throwable $e) {
                // A failed write still leaves a snapshot behind, so the row
                // keeps its undo point; the exception itself is re-thrown
                // untouched.
                $this->record_outcome(
                    $a,
                    $client,
                    $e,
                    self::elapsed_ms($started),
                    $args,
                    Operation_Context::since($mark)
                );
                throw $e;
            }

            $this->record_outcome(
                $a,
                $client,
                $result,
                self::elapsed_ms($started),
                $args,
                Operation_Context::since($mark)
            );
            return $result;
        };
    }

    private static function elapsed_ms(float $started): int
    {
        return (int) round((microtime(true) - $started) * 1000);
    }

    /**
     * Append one Request_Log row for a finished (or throttled) call. Wrapped
     * in a try/catch for the same reason as record_audit(): observability must
     * never turn a working tool call into a failure.
     *
     * @param mixed        $outcome The handler's return value, or the Throwable it threw.
     * @param array<mixed> $args    Positional handler arguments, as passed by the Abilities API.
     */
    private function record_outcome(
        Ability $a,
        string $client,
        $outcome,
        int $duration_ms,
        array $args,
        ?string $operation_id
    ): void {
        try {
            $entry = [
                'tool'        => $a->name,
                'client'      => $client,
                'ok'          => true,
                'duration_ms' => $duration_ms,
                'args'        => isset($args[0]) && is_array($args[0]) ? $args[0] : [],
            ];

            if ($outcome instanceof \Throwable) {
                $parts          = explode('\\', get_class($outcome));
                $entry['ok']            = false;
                $entry['error_code']    = 'exception:' . end($parts);
                $entry['error_message'] = $outcome->getMessage();
            } elseif (is_wp_error($outcome)) {
                $entry['ok']            = false;
                $entry['error_code']    = (string) $outcome->get_error_code();
                $entry['error_message'] = (string) $outcome->get_error_message();
            }

            // Tools that report their own operation_id (the Safe_Mutation
            // return shape) are honored as a fallback for any write that does
            // not route through Safe_Mutation itself.
            if (null === $operation_id && is_array($outcome) && ! empty($outcome['operation_id'])) {
                $operation_id = (string) $outcome['operation_id'];
            }
            $entry['operation_id'] = (string) $operation_id;

            Request_Log::record($entry);
        } catch (\Throwable $e) {
            // Logging must never break the call it is observing.
        }
    }
}
