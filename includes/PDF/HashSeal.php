<?php

/**
 * @package   FormForge
 * @copyright 2026 Alexander Jorek
 * @license   GPL-2.0-or-later
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2
 * of the License, or (at your option) any later version.
 */

namespace ForgeForms\PDF;

defined('ABSPATH') || exit;

class HashSeal
{
    private const PEPPER     = 'forge_seal_kdf_v1';
    private const KDF_ROUNDS = 200000;
    private const KDF_LEN    = 32;
    private const ENC_PREFIX = 'enc::';

    /* ------------------------------------------------------------------ */
    /* UUID                                                                 */
    /* ------------------------------------------------------------------ */

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
     */
    public static function isEncryptionEnabled(): bool
    {
        return get_option('forge_forms_seal_encryption') === 'enabled'
            && defined('FORGE_SEAL_MASTER_KEY')
            && (string) FORGE_SEAL_MASTER_KEY !== '';
    }

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
     * Encrypt $value only when encryption is enabled; otherwise return as-is.
     */
    private static function maybeEncrypt(string $plaintext): string
    {
        return self::isEncryptionEnabled() ? self::encryptKey($plaintext) : $plaintext;
    }

    /**
     * After the admin enables encryption, re-encrypt all existing plaintext keys in-place.
     * Safe to call multiple times — already-encrypted values are left untouched.
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

    private static function setPendingDownload(string $uuid, string $plaintext_key): void
    {
        update_option(
            'forge_forms_seal_key_pending_download',
            wp_json_encode([
                'uuid'       => $uuid,
                'key'        => $plaintext_key,
                'created_at' => gmdate('Y-m-d H:i:s') . ' UTC',
            ]),
            false
        );
    }

    private static function getKey(): string
    {
        return self::getActiveKeyRecord()['key'];
    }

    public static function getCurrentKeyId(): string
    {
        return self::getActiveKeyRecord()['uuid'];
    }

    private static function deriveKey(string $password): string
    {
        return bin2hex(
            hash_pbkdf2('sha256', $password, self::PEPPER, self::KDF_ROUNDS, self::KDF_LEN, true)
        );
    }

    /**
     * @return string[]
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
     * @return array{uuid: string, key: string, created_at: string}|null
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
        return array_map(function (array $entry): array {
            if (isset($entry['key'])) {
                try {
                    $entry['key'] = self::decryptKey($entry['key']);
                } catch (\Exception $e) {
                    $entry['key'] = '';
                }
            }
            return $entry;
        }, $history);
    }

    /**
     * Manually import a key into history as 'legacy'.
     * Used when recovering keys after a server loss.
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

    public static function generate(array $data): string
    {
        $json = wp_json_encode($data);
        if ($json === false) {
            throw new \RuntimeException('ForgeForms HashSeal: failed to JSON-encode payload for HMAC');
        }
        return hash_hmac('sha256', $json, self::getKey());
    }
}
