<?php

namespace WPMCP\Tests\Free\MCP;

use WPMCP\MCP\Request_Log;

/**
 * Unit coverage for the MCP request outcome ring buffer (issue #134):
 * normalization, capping, ordering, and the redaction rules that keep
 * secrets and huge payloads out of the option even in debug mode.
 */
class RequestLogTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        delete_option(Request_Log::OPTION);
        delete_option(Request_Log::CAPTURE_OPTION);
        Request_Log::set_clock_for_tests(null);
    }

    protected function tearDown(): void
    {
        delete_option(Request_Log::OPTION);
        delete_option(Request_Log::CAPTURE_OPTION);
        Request_Log::set_clock_for_tests(null);
        remove_all_filters(Request_Log::CAPTURE_OPTION);
        remove_all_filters('wpmcp_request_log_cap');
        parent::tearDown();
    }

    public function test_record_normalizes_a_successful_row(): void
    {
        Request_Log::set_clock_for_tests(1700000000);

        Request_Log::record([
            'tool'         => 'wpmcp/get-post',
            'client'       => 'user:7',
            'ok'           => true,
            'duration_ms'  => 12,
            'operation_id' => '',
        ]);

        $rows = Request_Log::list();

        $this->assertCount(1, $rows);
        $this->assertSame(1700000000, $rows[0]['timestamp']);
        $this->assertSame('wpmcp/get-post', $rows[0]['tool']);
        $this->assertSame('user:7', $rows[0]['client']);
        $this->assertTrue($rows[0]['ok']);
        $this->assertSame('', $rows[0]['error_code']);
        $this->assertSame(12, $rows[0]['duration_ms']);
        $this->assertSame('', $rows[0]['operation_id']);
    }

    public function test_a_failed_row_without_an_explicit_code_still_records_one(): void
    {
        Request_Log::record(['tool' => 'wpmcp/query', 'ok' => false]);

        $rows = Request_Log::list();

        $this->assertFalse($rows[0]['ok']);
        $this->assertSame('unknown_error', $rows[0]['error_code']);
    }

    public function test_negative_durations_are_clamped_to_zero(): void
    {
        Request_Log::record(['tool' => 'wpmcp/query', 'ok' => true, 'duration_ms' => -5]);

        $this->assertSame(0, Request_Log::list()[0]['duration_ms']);
    }

    public function test_list_returns_newest_first(): void
    {
        Request_Log::record(['tool' => 'first', 'ok' => true]);
        Request_Log::record(['tool' => 'second', 'ok' => true]);

        $rows = Request_Log::list();

        $this->assertSame('second', $rows[0]['tool']);
        $this->assertSame('first', $rows[1]['tool']);
    }

    public function test_list_honors_its_limit(): void
    {
        for ($i = 0; $i < 5; $i++) {
            Request_Log::record(['tool' => 'tool-' . $i, 'ok' => true]);
        }

        $this->assertCount(2, Request_Log::list(2));
        $this->assertSame([], Request_Log::list(0));
    }

    public function test_the_ring_buffer_evicts_the_oldest_rows_at_the_cap(): void
    {
        add_filter('wpmcp_request_log_cap', fn() => 3);

        for ($i = 0; $i < 6; $i++) {
            Request_Log::record(['tool' => 'tool-' . $i, 'ok' => true]);
        }

        $rows = Request_Log::list();

        $this->assertCount(3, $rows);
        $this->assertSame(['tool-5', 'tool-4', 'tool-3'], array_column($rows, 'tool'));
    }

    public function test_a_zero_or_negative_cap_filter_still_keeps_one_row(): void
    {
        add_filter('wpmcp_request_log_cap', fn() => 0);

        Request_Log::record(['tool' => 'first', 'ok' => true]);
        Request_Log::record(['tool' => 'second', 'ok' => true]);

        $rows = Request_Log::list();

        $this->assertCount(1, $rows);
        $this->assertSame('second', $rows[0]['tool']);
    }

    public function test_clear_empties_the_log(): void
    {
        Request_Log::record(['tool' => 'first', 'ok' => true]);

        Request_Log::clear();

        $this->assertSame([], Request_Log::list());
    }

    public function test_a_corrupt_option_value_reads_as_an_empty_log(): void
    {
        update_option(Request_Log::OPTION, 'not-an-array', false);

        $this->assertSame([], Request_Log::list());

        Request_Log::record(['tool' => 'first', 'ok' => true]);

        $this->assertCount(1, Request_Log::list());
    }

    public function test_arguments_are_not_recorded_by_default(): void
    {
        Request_Log::record([
            'tool' => 'wpmcp/update-post',
            'ok'   => true,
            'args' => ['post_id' => 3, 'title' => 'Secret draft'],
        ]);

        $this->assertFalse(Request_Log::is_capturing_arguments());
        $this->assertArrayNotHasKey('args', Request_Log::list()[0]);
    }

    public function test_error_messages_are_not_recorded_by_default(): void
    {
        Request_Log::record([
            'tool'          => 'wpmcp/query',
            'ok'            => false,
            'error_code'    => 'db_error',
            'error_message' => 'SELECT ... FROM wp_users WHERE user_pass = ...',
        ]);

        $this->assertArrayNotHasKey('error_message', Request_Log::list()[0]);
    }

    public function test_the_capture_option_turns_argument_recording_on(): void
    {
        update_option(Request_Log::CAPTURE_OPTION, true);

        Request_Log::record([
            'tool'          => 'wpmcp/update-post',
            'ok'            => false,
            'error_code'    => 'boom',
            'error_message' => 'Something broke',
            'args'          => ['post_id' => 3, 'title' => 'Draft'],
        ]);

        $row = Request_Log::list()[0];

        $this->assertTrue(Request_Log::is_capturing_arguments());
        $this->assertSame(['post_id' => 3, 'title' => 'Draft'], $row['args']);
        $this->assertSame('Something broke', $row['error_message']);
    }

    public function test_the_capture_filter_can_force_recording_on(): void
    {
        add_filter(Request_Log::CAPTURE_OPTION, '__return_true');

        Request_Log::record(['tool' => 'wpmcp/query', 'ok' => true, 'args' => ['sql' => 'SELECT 1']]);

        $this->assertSame(['sql' => 'SELECT 1'], Request_Log::list()[0]['args']);
    }

    public function test_secret_looking_keys_are_redacted_even_while_capturing(): void
    {
        add_filter(Request_Log::CAPTURE_OPTION, '__return_true');

        Request_Log::record([
            'tool' => 'wpmcp/connect',
            'ok'   => true,
            'args' => [
                'user_pass'   => 'hunter2',
                'API_Key'     => 'sk-live-123',
                'auth_header' => 'Bearer abc',
                'nonce'       => 'abc123',
                'post_id'     => 9,
                'nested'      => ['access_token' => 'zzz', 'title' => 'ok'],
            ],
        ]);

        $args = Request_Log::list()[0]['args'];

        $this->assertSame(Request_Log::REDACTED, $args['user_pass']);
        $this->assertSame(Request_Log::REDACTED, $args['API_Key']);
        $this->assertSame(Request_Log::REDACTED, $args['auth_header']);
        $this->assertSame(Request_Log::REDACTED, $args['nonce']);
        $this->assertSame(9, $args['post_id']);
        $this->assertSame(Request_Log::REDACTED, $args['nested']['access_token']);
        $this->assertSame('ok', $args['nested']['title']);
    }

    public function test_captured_values_are_truncated_and_deep_arrays_collapsed(): void
    {
        add_filter(Request_Log::CAPTURE_OPTION, '__return_true');

        Request_Log::record([
            'tool' => 'wpmcp/update-post',
            'ok'   => true,
            'args' => [
                'content' => str_repeat('a', Request_Log::MAX_VALUE_LENGTH + 50),
                'deep'    => ['a' => ['b' => ['c' => ['d' => 'too far']]]],
                'object'  => new \stdClass(),
            ],
        ]);

        $args = Request_Log::list()[0]['args'];

        $this->assertSame(Request_Log::MAX_VALUE_LENGTH + 3, strlen($args['content']));
        $this->assertStringEndsWith('...', $args['content']);
        $this->assertSame('[array]', $args['deep']['a']['b']['c']);
        $this->assertSame('[object]', $args['object']);
    }

    public function test_non_array_args_while_capturing_record_an_empty_payload(): void
    {
        add_filter(Request_Log::CAPTURE_OPTION, '__return_true');

        Request_Log::record(['tool' => 'wpmcp/query', 'ok' => true, 'args' => 'nope']);

        $this->assertSame([], Request_Log::list()[0]['args']);
    }

    public function test_the_log_option_is_not_autoloaded(): void
    {
        Request_Log::record(['tool' => 'wpmcp/query', 'ok' => true]);

        $this->assertNotContains(Request_Log::OPTION, array_keys(wp_load_alloptions()));
    }
}
