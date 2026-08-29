<?php

namespace WPMCP\Tests\Pro\Chat;

use WPMCP\Pro\Chat\Approval_Gate;

class ApprovalGateTest extends \WP_UnitTestCase
{
    private Approval_Gate $gate;
    private int $user_id;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gate = new Approval_Gate('test_approval_salt_secret');
        $this->user_id = self::factory()->user->create(['role' => 'administrator']);
    }

    public function test_happy_path_validation_and_single_use(): void
    {
        $args = ['post_id' => 10, 'force' => true];
        $token = $this->gate->issue_token($this->user_id, 'wpmcp/posts_delete', $args, 60);

        // 1. Initial valid consumption
        $this->assertTrue($this->gate->validate_and_consume($token, $this->user_id, 'wpmcp/posts_delete', $args));

        // 2. Replay of same token is rejected
        $this->assertFalse($this->gate->validate_and_consume($token, $this->user_id, 'wpmcp/posts_delete', $args));
    }

    public function test_arguments_mutation_rejected(): void
    {
        $original_args = ['post_id' => 10, 'force' => true];
        $token = $this->gate->issue_token($this->user_id, 'wpmcp/posts_delete', $original_args, 60);

        $tampered_args = ['post_id' => 999, 'force' => true];
        $this->assertFalse($this->gate->validate_and_consume($token, $this->user_id, 'wpmcp/posts_delete', $tampered_args));
    }

    public function test_cross_ability_rejected(): void
    {
        $args = ['post_id' => 10];
        $token = $this->gate->issue_token($this->user_id, 'wpmcp/posts_delete', $args, 60);

        $this->assertFalse($this->gate->validate_and_consume($token, $this->user_id, 'wpmcp/options_update', $args));
    }

    public function test_cross_user_rejected(): void
    {
        $other_user = self::factory()->user->create(['role' => 'administrator']);
        $args = ['post_id' => 10];
        $token = $this->gate->issue_token($this->user_id, 'wpmcp/posts_delete', $args, 60);

        $this->assertFalse($this->gate->validate_and_consume($token, $other_user, 'wpmcp/posts_delete', $args));
    }

    public function test_expired_token_rejected(): void
    {
        $args = ['post_id' => 10];
        // Expire immediately with negative TTL
        $token = $this->gate->issue_token($this->user_id, 'wpmcp/posts_delete', $args, -10);

        $this->assertFalse($this->gate->validate_and_consume($token, $this->user_id, 'wpmcp/posts_delete', $args));
    }
}
