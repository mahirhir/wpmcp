<?php

namespace WPMCP\Tests\Pro\Cli;

use WPMCP\Governance\Opt_In_Gates;
use WPMCP\MCP\{Ability, Registrar};
use WPMCP\Pro\Gate;
use WPMCP\Tools\Cli\Cancel_Cli_Job;
use WPMCP\Tools\Cli\Dispatch_Cli_Job;
use WPMCP\Tools\Cli\Get_Cli_Job;
use WPMCP\Tools\Cli\List_Cli_Jobs;

/**
 * The background CLI job tools (issue #84) are PRO at manage_options, domain
 * 'cli': dispatching a command asynchronously is the same capability as
 * running it synchronously, so none of them may be reachable at a lower tier
 * or a weaker capability than run-wp-cli. Mirrors
 * RunWpCliAbilitiesRegistrationTest: Plugin::boot() registers abilities once
 * at wp_abilities_api_init, so this builds the same Abilities the boot path
 * constructs and drives them through a fresh Registrar directly.
 */
class CliJobAbilitiesRegistrationTest extends \WP_UnitTestCase
{
    protected function tearDown(): void
    {
        Gate::set_pro_for_tests(null);
        parent::tearDown();
    }

    /** @return Ability[] */
    private function make_cli_job_abilities(): array
    {
        return [
            new Ability(
                'wpmcp/dispatch-cli-job',
                'pro',
                'Queue a guarded wp-cli command as a background job.',
                [
                    'type'       => 'object',
                    'properties' => ['command' => ['type' => 'string'], 'timeout' => ['type' => 'integer']],
                    'required'   => ['command'],
                ],
                [new Dispatch_Cli_Job(), 'handle'],
                'manage_options',
                'cli',
                'create'
            ),
            new Ability(
                'wpmcp/get-cli-job',
                'pro',
                'Return a background CLI job record by id.',
                [
                    'type'       => 'object',
                    'properties' => ['job_id' => ['type' => 'integer']],
                    'required'   => ['job_id'],
                ],
                [new Get_Cli_Job(), 'handle'],
                'manage_options',
                'cli',
                'read'
            ),
            new Ability(
                'wpmcp/list-cli-jobs',
                'pro',
                'List background CLI jobs.',
                [
                    'type'       => 'object',
                    'properties' => ['status' => ['type' => 'string']],
                ],
                [new List_Cli_Jobs(), 'handle'],
                'manage_options',
                'cli',
                'read'
            ),
            new Ability(
                'wpmcp/cancel-cli-job',
                'pro',
                'Cancel a queued background CLI job.',
                [
                    'type'       => 'object',
                    'properties' => ['job_id' => ['type' => 'integer']],
                    'required'   => ['job_id'],
                ],
                [new Cancel_Cli_Job(), 'handle'],
                'manage_options',
                'cli',
                'update'
            ),
        ];
    }

    private function register_all(): Registrar
    {
        $registrar = new Registrar();
        foreach ($this->make_cli_job_abilities() as $ability) {
            $registrar->register($ability);
        }
        return $registrar;
    }

    public function test_registrar_skips_every_cli_job_ability_when_free(): void
    {
        Gate::set_pro_for_tests(false);

        $this->assertCount(0, $this->register_all()->all());
    }

    public function test_registrar_keeps_every_cli_job_ability_when_pro(): void
    {
        Gate::set_pro_for_tests(true);

        $names = array_map(fn($a) => $a->name, $this->register_all()->all());

        $this->assertContains('wpmcp/dispatch-cli-job', $names);
        $this->assertContains('wpmcp/get-cli-job', $names);
        $this->assertContains('wpmcp/list-cli-jobs', $names);
        $this->assertContains('wpmcp/cancel-cli-job', $names);
    }

    public function test_every_cli_job_ability_requires_manage_options(): void
    {
        Gate::set_pro_for_tests(true);

        foreach ($this->register_all()->all() as $ability) {
            $this->assertSame('manage_options', $ability->capability, "{$ability->name} must require manage_options.");
            $this->assertSame('cli', $ability->domain);
        }
    }

    public function test_the_read_only_poll_tools_are_annotated_read_only(): void
    {
        Gate::set_pro_for_tests(true);

        $by_name = [];
        foreach ($this->register_all()->all() as $ability) {
            $by_name[ $ability->name ] = $ability;
        }

        $this->assertTrue($by_name['wpmcp/get-cli-job']->read_only_hint);
        $this->assertTrue($by_name['wpmcp/list-cli-jobs']->read_only_hint);
        $this->assertFalse($by_name['wpmcp/dispatch-cli-job']->read_only_hint);
    }

    /**
     * The dispatcher reaches the wp-cli executor, so the ability grid must
     * mark it with the same default-off warning and the same master opt-in
     * filter as run-wp-cli. Its poll/cancel siblings deliberately are not
     * gated: they cannot run a command, and flagging them dangerous would
     * blunt the warning that matters.
     */
    public function test_only_the_dispatcher_is_registered_as_a_dangerous_opt_in_gate(): void
    {
        $this->assertTrue(Opt_In_Gates::is_gated('wpmcp/dispatch-cli-job'));
        $this->assertSame('wpmcp_allow_wp_cli', Opt_In_Gates::filter_for('wpmcp/dispatch-cli-job'));
        $this->assertSame(
            Opt_In_Gates::filter_for('wpmcp/run-wp-cli'),
            Opt_In_Gates::filter_for('wpmcp/dispatch-cli-job'),
            'The async dispatcher must share the synchronous tool\'s master gate, not have its own.'
        );

        $this->assertFalse(Opt_In_Gates::is_gated('wpmcp/get-cli-job'));
        $this->assertFalse(Opt_In_Gates::is_gated('wpmcp/list-cli-jobs'));
        $this->assertFalse(Opt_In_Gates::is_gated('wpmcp/cancel-cli-job'));
    }

    public function test_the_dispatcher_gate_reads_closed_by_default(): void
    {
        $this->assertFalse(Opt_In_Gates::is_open('wpmcp/dispatch-cli-job'));
    }
}
