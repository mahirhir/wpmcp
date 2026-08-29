<?php

namespace WPMCP\Pro\Chat;

if (! defined('ABSPATH')) {
    exit;
}

class Approval_Gate
{
    private const TRANSIENT_PREFIX = 'wpmcp_chat_appr_';
    private const DEFAULT_TTL = 300; // 5 minutes

    public function __construct(private ?string $salt = null)
    {
    }

    private function get_salt(): string
    {
        return $this->salt ?? (function_exists('wp_salt') ? wp_salt('auth') : 'wpmcp_approval_fallback_salt');
    }

    /**
     * Normalizes arguments into a deterministic canonical JSON string.
     */
    public static function normalize_args(array $args): string
    {
        ksort($args);
        return (string) wp_json_encode($args);
    }

    /**
     * Generates a single-use approval token bound to user, ability, args hash, and expiry.
     */
    public function issue_token(int $user_id, string $ability_name, array $args, int $ttl_seconds = self::DEFAULT_TTL): string
    {
        $expiry = time() + $ttl_seconds;
        $args_hash = hash('sha256', self::normalize_args($args));
        $salt = $this->get_salt();

        $payload = sprintf('%d|%s|%s|%d', $user_id, $ability_name, $args_hash, $expiry);
        $sig = hash_hmac('sha256', $payload, $salt);

        $token = base64_encode($payload . '|' . $sig);
        $token_hash = hash('sha256', $token);

        // Store server-side to enforce single-use consumption
        set_transient(self::TRANSIENT_PREFIX . $token_hash, 1, $ttl_seconds);

        return $token;
    }

    /**
     * Validates and consumes the single-use approval token.
     * Fails closed on signature mismatch, expiry, replay, arg tampering, wrong user, or wrong ability.
     */
    public function validate_and_consume(string $token, int $expected_user_id, string $expected_ability, array $actual_args): bool
    {
        $raw = base64_decode($token, true);
        if (false === $raw) {
            return false;
        }

        $parts = explode('|', $raw);
        if (5 !== count($parts)) {
            return false;
        }

        [$user_id_str, $ability_name, $args_hash, $expiry_str, $signature] = $parts;
        $user_id = (int) $user_id_str;
        $expiry = (int) $expiry_str;

        // 1. Expiration check
        if (time() > $expiry) {
            return false;
        }

        // 2. Signature verification
        $salt = $this->get_salt();
        $payload = sprintf('%d|%s|%s|%d', $user_id, $ability_name, $args_hash, $expiry);
        $expected_sig = hash_hmac('sha256', $payload, $salt);
        if (! hash_equals($expected_sig, $signature)) {
            return false;
        }

        // 3. User isolation check
        if ($user_id !== $expected_user_id) {
            return false;
        }

        // 4. Ability name check
        if ($ability_name !== $expected_ability) {
            return false;
        }

        // 5. Argument tamper check
        $actual_hash = hash('sha256', self::normalize_args($actual_args));
        if (! hash_equals($args_hash, $actual_hash)) {
            return false;
        }

        // 6. Single-use consumption check
        $token_hash = hash('sha256', $token);
        $transient_key = self::TRANSIENT_PREFIX . $token_hash;
        if (! get_transient($transient_key)) {
            return false; // Replay attempt or already consumed
        }

        delete_transient($transient_key);
        return true;
    }
}
