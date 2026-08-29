<?php

namespace WPMCP\Pro\Chat;

if (! defined('ABSPATH')) {
    exit;
}

class Key_Vault_Corrupted_Exception extends \Exception {}

class Key_Vault
{
    private const CIPHER = 'aes-256-gcm';
    private const PREFIX = 'wpmcp_v1:';
    private const META_KEY = '_wpmcp_chat_anthropic_key';

    public function __construct(private ?string $salt = null)
    {
    }

    private function get_encryption_key(int $user_id): string
    {
        $base_salt = $this->salt ?? (function_exists('wp_salt') ? wp_salt('auth') : 'wpmcp_default_fallback_salt');
        return hash_hmac('sha256', (string) $user_id, $base_salt, true);
    }

    /**
     * Stores an encrypted API key for the user.
     */
    public function store_key(int $user_id, string $api_key): bool
    {
        $api_key = trim($api_key);
        if ('' === $api_key) {
            return $this->delete_key($user_id);
        }

        $key = $this->get_encryption_key($user_id);
        $iv_len = openssl_cipher_iv_length(self::CIPHER);
        $iv = openssl_random_pseudo_bytes($iv_len);
        $tag = '';

        $ciphertext = openssl_encrypt(
            $api_key,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            16
        );

        if (false === $ciphertext) {
            return false;
        }

        $packed = self::PREFIX . base64_encode($iv . $tag . $ciphertext);
        return (bool) update_user_meta($user_id, self::META_KEY, $packed);
    }

    /**
     * Retrieves and decrypts the API key for the user.
     * Throws Key_Vault_Corrupted_Exception on bit-flip, tag mismatch, or truncation.
     */
    public function get_key(int $user_id): ?string
    {
        $raw = get_user_meta($user_id, self::META_KEY, true);
        if (! is_string($raw) || '' === $raw) {
            return null;
        }

        if (! str_starts_with($raw, self::PREFIX)) {
            throw new Key_Vault_Corrupted_Exception('Ciphertext prefix mismatch or corrupted storage format.');
        }

        $encoded = substr($raw, strlen(self::PREFIX));
        $decoded = base64_decode($encoded, true);
        if (false === $decoded) {
            throw new Key_Vault_Corrupted_Exception('Base64 decode failure.');
        }

        $iv_len = openssl_cipher_iv_length(self::CIPHER);
        $tag_len = 16;
        if (strlen($decoded) < $iv_len + $tag_len + 1) {
            throw new Key_Vault_Corrupted_Exception('Truncated ciphertext payload.');
        }

        $iv = substr($decoded, 0, $iv_len);
        $tag = substr($decoded, $iv_len, $tag_len);
        $ciphertext = substr($decoded, $iv_len + $tag_len);
        $key = $this->get_encryption_key($user_id);

        $plaintext = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if (false === $plaintext) {
            throw new Key_Vault_Corrupted_Exception('Authentication tag mismatch or bit-flip tampering detected.');
        }

        return $plaintext;
    }

    /**
     * Deletes the user API key.
     */
    public function delete_key(int $user_id): bool
    {
        return (bool) delete_user_meta($user_id, self::META_KEY);
    }

    /**
     * Returns masked key status without revealing plaintext.
     */
    public function get_status(int $user_id): array
    {
        try {
            $key = $this->get_key($user_id);
            if (null === $key) {
                return [
                    'configured' => false,
                    'status' => 'missing',
                    'masked' => null,
                ];
            }

            $masked = strlen($key) > 8
                ? substr($key, 0, 4) . '...' . substr($key, -4)
                : '****';

            return [
                'configured' => true,
                'status' => 'valid',
                'masked' => $masked,
            ];
        } catch (Key_Vault_Corrupted_Exception) {
            return [
                'configured' => true,
                'status' => 'corrupted',
                'masked' => null,
            ];
        }
    }
}
