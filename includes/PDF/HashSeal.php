<?php

/**
 * Creates and verifies cryptographic hash seals on generated PDFs.
 *
 * PHP Version 8.1
 *
 * @category  FormForge
 * @package   FormForge
 * @author    Alexander Jorek
 * @copyright 2026 Alexander Jorek
 * @license   https://www.gnu.org/licenses/gpl-2.0.html GPL-2.0-or-later
 * @version   1.0.0
 * @link      https://github.com/AlexanderJorek/FormForge
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2
 * of the License, or (at your option) any later version.
 */

namespace ForgeForms\PDF;

defined('ABSPATH') || exit;

/**
 * Manages PDF seal key generation, encryption, HMAC signing, and verification.
 */
class HashSeal
{
    private const PEPPER     = 'forge_seal_kdf_v1';
    private const KDF_ROUNDS = 200000;
    private const KDF_LEN    = 32;
    private const ENC_PREFIX = 'enc::';

    /* ------------------------------------------------------------------ */
    /* UUID                                                                 */
    /* ------------------------------------------------------------------ */

    /**
     * Generates a random UUID v4.
     *
     * @return string UUID v4 string.
     */
    private static function generateUuid(): string
    {
        $data    = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /* ------------------------------------------------------------------ */
    /* Encryption layer                                                     */
    /* ------------------------------------------------------------------ */

    /**
     * True when the admin has opted in and FORGE_SEAL_MASTER_KEY is defined.
     *
     * @return bool True when encryption is active and the master key constant is set.
     */
    public static function isEncryptionEnabled(): bool
    {
        return get_option('forge_forms_seal_encryption') === 'enabled'
            && defined('FORGE_SEAL_MASTER_KEY')
            && (string) FORGE_SEAL_MASTER_KEY !== '';
    }

    /**
     * Returns the binary master key from the FORGE_SEAL_MASTER_KEY constant.
     *
     * @return string Binary master key.
     */
    private static function masterKey(): string
    {
        if (!defined('FORGE_SEAL_MASTER_KEY') || (string) FORGE_SEAL_MASTER_KEY === '') {
            throw new \RuntimeException(
                'ForgeForms: FORGE_SEAL_MASTER_KEY is not defined. '
                . 'Add it to wp-config.php or disable encryption in plugin settings.'
            );
        }
        $bin = hex2bin((string) FORGE_SEAL_MASTER_KEY);
        if ($bin === false || strlen($bin) !== 32) {
            throw new \RuntimeException('ForgeForms: FORGE_SEAL_MASTER_KEY must be a 64-char hex string.');
        }
        return $bin;
    }

    /**
     * Encrypts a key value using AES-256-GCM.
     *
     * @param string $plaintext Plaintext key value.
     *
     * @return string Encrypted value prefixed with nonce and tag.
     */
    private static function encryptKey(string $plaintext): string
    {
        $iv  = random_bytes(12);
        $tag = '';
        $ct  = openssl_encrypt(
            $plaintext,
            'aes-256-gcm',
            self::masterKey(),
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );
        if ($ct === false) {
            throw new \RuntimeException('ForgeForms: key encryption failed.');
        }
        return self::ENC_PREFIX . base64_encode($iv . $tag . $ct);
    }

    /**
     * Decrypts an encrypted key value; returns plaintext if not encrypted.
     *
     * @param string $value Encrypted or plaintext key value.
     *
     * @return string Decrypted plaintext key.
     */
    private static function decryptKey(string $value): string
    {
        if (strncmp($value, self::ENC_PREFIX, strlen(self::ENC_PREFIX)) !== 0) {
            return $value; // unencrypted — plain hex
        }
        $data = base64_decode(substr($value, strlen(self::ENC_PREFIX)));
        if ($data === false || strlen($data) < 29) {
            throw new \RuntimeException('ForgeForms: encrypted key data is malformed.');
        }
        $iv  = substr($data, 0, 12);
        $tag = substr($data, 12, 16);
        $ct  = substr($data, 28);
        $pt  = openssl_decrypt($ct, 'aes-256-gcm', self::masterKey(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($pt === false) {
            throw new \RuntimeException(
                'ForgeForms: key decryption failed — master key may be incorrect or missing.'
            );
        }
        return $pt;
    }

    /**
     * Encrypts a value only when encryption is enabled; otherwise returns it as-is.
     *
     * @param string $plaintext Plaintext value to conditionally encrypt.
     *
     * @return string Encrypted value or original plaintext.
     */
    private static function maybeEncrypt(string $plaintext): string
    {
        return self::isEncryptionEnabled() ? self::encryptKey($plaintext) : $plaintext;
    }

    /**
     * After the admin enables encryption, re-encrypt all existing plaintext keys in-place.
     * Safe to call multiple times — already-encrypted values are left untouched.
     *
     * @return void
     */
    public static function encryptExistingKeys(): void
    {
        // Active key
        $raw = get_option('forge_forms_seal_key');
        if ($raw) {
            $rec = json_decode((string) $raw, true);
            $not_yet_encrypted = strncmp((string)($rec['key'] ?? ''), self::ENC_PREFIX, strlen(self::ENC_PREFIX)) !== 0;
            if (is_array($rec) && isset($rec['uuid'], $rec['key']) && $not_yet_encrypted) {
                $rec['key'] = self::encryptKey($rec['key']);
                update_option('forge_forms_seal_key', wp_json_encode($rec), false);
            }
        }

        // History
        $history = get_option('forge_forms_seal_key_history', []);
        if (!is_array($history)) {
            return;
        }
        $changed = false;
        foreach ($history as &$entry) {
            $prefix              = self::ENC_PREFIX;
            $entry_not_encrypted = strncmp((string)($entry['key'] ?? ''), $prefix, strlen($prefix)) !== 0;
            if (isset($entry['key']) && $entry_not_encrypted) {
                $entry['key'] = self::encryptKey($entry['key']);
                $changed      = true;
            }
        }
        unset($entry);
        if ($changed) {
            update_option('forge_forms_seal_key_history', $history, false);
        }
    }

    /* ------------------------------------------------------------------ */
    /* Key management                                                       */
    /* ------------------------------------------------------------------ */

    /**
     * Return the active key record as ['uuid' => string, 'key' => plaintext string].
     * Auto-generates and flags pending download when no valid key exists.
     *
     * @return array|null Active key record, or null when none can be resolved.
     */
    private static function getActiveKeyRecord(): array
    {
        $raw = get_option('forge_forms_seal_key');
        if ($raw) {
            $decoded = json_decode((string) $raw, true);
            if (is_array($decoded) && isset($decoded['uuid'], $decoded['key'])) {
                return [
                    'uuid' => $decoded['uuid'],
                    'key'  => self::decryptKey($decoded['key']),
                ];
            }
        }

        // No valid key — generate the initial one.
        $uuid    = self::generateUuid();
        $raw_key = bin2hex(random_bytes(self::KDF_LEN));
        update_option(
            'forge_forms_seal_key',
            wp_json_encode(['uuid' => $uuid, 'key' => self::maybeEncrypt($raw_key)]),
            false
        );
        self::setPendingDownload($uuid, $raw_key);
        return ['uuid' => $uuid, 'key' => $raw_key];
    }

    /**
     * Stores a pending key download in the WordPress options table.
     *
     * @param string $uuid          UUID of the key.
     * @param string $plaintext_key Plaintext key value.
     *
     * @return void
     */
    private static function setPendingDownload(string $uuid, string $plaintext_key): void
    {
        update_option(
            'forge_forms_seal_key_pending_download',
            wp_json_encode(
                [
                'uuid'       => $uuid,
                'key'        => $plaintext_key,
                'created_at' => gmdate('Y-m-d H:i:s') . ' UTC',
                ]
            ),
            false
        );
    }

    /**
     * Returns the active plaintext seal key.
     *
     * @return string Active seal key.
     */
    private static function getKey(): string
    {
        return self::getActiveKeyRecord()['key'];
    }

    /**
     * Returns the UUID of the currently active seal key.
     *
     * @return string UUID of the active key.
     */
    public static function getCurrentKeyId(): string
    {
        return self::getActiveKeyRecord()['uuid'];
    }

    /**
     * Derives a key hex string from a password using PBKDF2-SHA256.
     *
     * @param string $password The password to derive from.
     *
     * @return string Derived key as hex string.
     */
    private static function deriveKey(string $password): string
    {
        return bin2hex(
            hash_pbkdf2('sha256', $password, self::PEPPER, self::KDF_ROUNDS, self::KDF_LEN, true)
        );
    }

    /**
     * Validates a password against the active seal key.
     *
     * @param string $password The password to validate.
     *
     * @return string[] Array of validation error messages; empty when valid.
     */
    public static function validatePassword(string $password): array
    {
        $errors = [];
        if (strlen($password) < 12) {
            $errors[] = 'Mindestens 12 Zeichen erforderlich.';
        }
        if (!preg_match('/[A-Z]/', $password)) {
            $errors[] = 'Mindestens ein Großbuchstabe erforderlich.';
        }
        if (!preg_match('/[a-z]/', $password)) {
            $errors[] = 'Mindestens ein Kleinbuchstabe erforderlich.';
        }
        if (!preg_match('/[0-9]/', $password)) {
            $errors[] = 'Mindestens eine Ziffer erforderlich.';
        }
        if (!preg_match('/[^A-Za-z0-9]/', $password)) {
            $errors[] = 'Mindestens ein Sonderzeichen erforderlich.';
        }
        return $errors;
    }

    /**
     * Rotates the active seal key, optionally protecting it with a password.
     *
     * @param string $password    Password used to derive the new key via PBKDF2.
     * @param bool   $compromised True to flag the retiring key as compromised.
     *
     * @return array{uuid: string, key: string, created_at: string}
     */
    public static function rotateKey(string $password, bool $compromised): array
    {
        $user       = wp_get_current_user();
        $user_id    = (int) $user->ID;
        $user_login = (string) $user->user_login;
        $retired_at = gmdate('Y-m-d H:i:s') . ' UTC';

        $current = self::getActiveKeyRecord();
        $history = get_option('forge_forms_seal_key_history', []);
        if (!is_array($history)) {
            $history = [];
        }

        $history[] = [
            'uuid'             => $current['uuid'],
            'key'              => self::maybeEncrypt($current['key']),
            'status'           => empty($history) ? 'initial' : 'rotated',
            'compromised'      => $compromised,
            'retired_at'       => $retired_at,
            'retired_by_id'    => $user_id,
            'retired_by_login' => $user_login,
        ];

        $new_uuid    = self::generateUuid();
        $new_raw_key = self::deriveKey($password);

        update_option(
            'forge_forms_seal_key',
            wp_json_encode(['uuid' => $new_uuid, 'key' => self::maybeEncrypt($new_raw_key)]),
            false
        );
        update_option('forge_forms_seal_key_history', $history, false);
        self::setPendingDownload($new_uuid, $new_raw_key);

        return ['uuid' => $new_uuid, 'key' => $new_raw_key, 'created_at' => $retired_at];
    }

    /**
     * Claims and removes a pending key download transient.
     *
     * @return array{uuid: string, key: string, created_at: string}|null
     *                Key record, or null if none is pending.
     */
    public static function claimPendingDownload(): ?array
    {
        $raw = get_option('forge_forms_seal_key_pending_download');
        if (!$raw) {
            return null;
        }
        delete_option('forge_forms_seal_key_pending_download');
        $record = json_decode((string) $raw, true);
        if (is_array($record) && isset($record['uuid'], $record['key'])) {
            return $record;
        }
        return null;
    }

    /**
     * Verifies an HMAC seal against the given data payload.
     *
     * @param array  $data The payload that was originally sealed.
     * @param string $hmac The HMAC seal to verify.
     *
     * @return array{valid: bool, key_status: string|null, compromised: bool}
     */
    public static function verify(array $data, string $hmac): array
    {
        $json = wp_json_encode($data);
        if ($json === false) {
            return ['valid' => false, 'key_status' => null, 'compromised' => false];
        }

        $key_id = isset($data['key_id']) && is_string($data['key_id']) ? $data['key_id'] : null;
        if ($key_id === null) {
            return ['valid' => false, 'key_status' => null, 'compromised' => false];
        }

        $active = self::getActiveKeyRecord();
        if ($active['uuid'] === $key_id) {
            if (hash_equals(hash_hmac('sha256', $json, $active['key']), $hmac)) {
                return ['valid' => true, 'key_status' => 'active', 'compromised' => false];
            }
            return ['valid' => false, 'key_status' => null, 'compromised' => false];
        }

        $history = get_option('forge_forms_seal_key_history', []);
        if (!is_array($history)) {
            return ['valid' => false, 'key_status' => null, 'compromised' => false];
        }

        foreach ($history as $entry) {
            if (($entry['uuid'] ?? null) !== $key_id) {
                continue;
            }
            try {
                $entry_key = self::decryptKey((string)($entry['key'] ?? ''));
            } catch (\Exception $e) {
                return ['valid' => false, 'key_status' => null, 'compromised' => false];
            }
            if ($entry_key !== '' && hash_equals(hash_hmac('sha256', $json, $entry_key), $hmac)) {
                return [
                    'valid'       => true,
                    'key_status'  => (string)($entry['status'] ?? 'rotated'),
                    'compromised' => !empty($entry['compromised']),
                ];
            }
            return ['valid' => false, 'key_status' => null, 'compromised' => false];
        }

        return ['valid' => false, 'key_status' => null, 'compromised' => false];
    }

    /**
     * Returns history entries with keys decrypted (plaintext) for display and verification.
     *
     * @return array[]
     */
    public static function getHistory(): array
    {
        $history = get_option('forge_forms_seal_key_history', []);
        if (!is_array($history)) {
            return [];
        }
        return array_map(
            function (array $entry): array {
                if (isset($entry['key'])) {
                    try {
                        $entry['key'] = self::decryptKey($entry['key']);
                    } catch (\Exception $e) {
                        $entry['key'] = '';
                    }
                }
                return $entry;
            }, $history
        );
    }

    /**
     * Manually imports a key into history as a legacy entry.
     *
     * Used when recovering keys after a server loss.
     *
     * @param string $uuid       UUID of the key to import.
     * @param string $key_value  Raw key value (hex string).
     * @param string $created_at ISO 8601 creation timestamp, or empty for now.
     * @param string $status     One of 'rotated-legacy' or 'compromised-legacy'.
     *
     * @return void
     */
    public static function addLegacyKey(
        string $uuid,
        string $key_value,
        string $created_at = '',
        string $status = 'rotated-legacy'
    ): void {
        $allowed_statuses = ['rotated-legacy', 'compromised-legacy'];
        $safe_status      = in_array($status, $allowed_statuses, true) ? $status : 'rotated-legacy';
        $compromised      = $safe_status === 'compromised-legacy';

        $user    = wp_get_current_user();
        $history = get_option('forge_forms_seal_key_history', []);
        if (!is_array($history)) {
            $history = [];
        }
        $history[] = [
            'uuid'             => $uuid,
            'key'              => self::maybeEncrypt($key_value),
            'status'           => $safe_status,
            'compromised'      => $compromised,
            'retired_at'       => $created_at ?: gmdate('Y-m-d H:i:s') . ' UTC',
            'retired_by_id'    => (int) $user->ID,
            'retired_by_login' => (string) $user->user_login,
        ];
        update_option('forge_forms_seal_key_history', $history, false);
    }

    /* ------------------------------------------------------------------ */
    /* Seal generation (used by Generator.php)                             */
    /* ------------------------------------------------------------------ */

    /**
     * Generates an HMAC-SHA256 seal over the given data payload.
     *
     * @param array $data Payload to seal.
     *
     * @return string Hex-encoded HMAC seal.
     */
    public static function generate(array $data): string
    {
        $json = wp_json_encode($data);
        if ($json === false) {
            throw new \RuntimeException('ForgeForms HashSeal: failed to JSON-encode payload for HMAC');
        }
        return hash_hmac('sha256', $json, self::getKey());
    }
}
