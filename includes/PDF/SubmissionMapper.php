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

use ForgeForms\Fields\FieldRegistry;

defined('ABSPATH') || exit;

/**
 * Maps raw submitted field values to a normalized structure for PDF/email.
 *
 * Output format per field:
 * [
 *   'label'              => string,
 *   'type'               => string,
 *   'value'              => string (human-readable),
 *   'materialized_files' => array  (for uploads/signatures),
 * ]
 */
class SubmissionMapper
{
    public static function map(array $fields, array $raw_values, array $files = []): array
    {
        $mapped = [];

        foreach ($fields as $field_cfg) {
            $field_id   = $field_cfg['id']   ?? '';
            $field_type = $field_cfg['type'] ?? '';
            $label      = $field_cfg['label'] ?? $field_id;

            if (!$field_id || !$field_type) {
                continue;
            }

            /* Layout-only: pagebreak produces no output */
            if (in_array($field_type, ['pagebreak'], true)) {
                continue;
            }

            /* HTML blocks: include text for PDF */
            if ($field_type === 'html') {
                $html_content = wp_kses_post($field_cfg['html_content'] ?? '');
                if ($html_content !== '') {
                    $mapped[] = [
                        'label' => $label ?: null,
                        'type'  => 'html',
                        'value' => $html_content,
                    ];
                }
                continue;
            }

            $value = $raw_values[$field_id] ?? null;

            /* ---- SIGNATURE ---- */
            if ($field_type === 'signature') {
                $mapped[$field_id] = self::mapSignature($field_id, $label, $value);
                continue;
            }

            /* ---- UPLOAD ---- */
            if ($field_type === 'upload') {
                $file_data = $files[$field_id] ?? null;
                $mapped[$field_id] = self::mapUpload($field_id, $label, $file_data);
                continue;
            }

            /* ---- SEPA ---- */
            if ($field_type === 'sepa') {
                $entries = self::mapSepa($field_id, $label, $field_cfg, $value, $raw_values);
                foreach ($entries as $entry) {
                    $mapped[$entry['_key'] ?? $field_id] = $entry;
                }
                continue;
            }

            /* ---- Standard field: delegate to field handler ---- */
            $handler = FieldRegistry::get($field_type);
            $mapped_value = $handler
                ? $handler->map($value, $field_cfg)
                : (($value !== null && $value !== '') ? (string)$value : '[Kein Eintrag]');

            $mapped[$field_id] = [
                'label' => $label,
                'type'  => $field_type,
                'value' => $mapped_value,
            ];
        }

        return $mapped;
    }

    /* ------------------------------------------------------------------ */
    /* Signature                                                            */
    /* ------------------------------------------------------------------ */

    private static function mapSignature(string $field_id, string $label, mixed $value): array
    {
        $materialized = [];

        if (!empty($value) && str_starts_with((string)$value, 'data:image/png;base64,')) {
            $b64 = preg_replace('#^data:image/[^;]+;base64,#', '', (string)$value);
            $b64 = str_replace(' ', '+', $b64);
            $b64 = preg_replace('/[^A-Za-z0-9+\/=]/', '', $b64);
            $pad = strlen($b64) % 4;
            if ($pad) {
                $b64 .= str_repeat('=', 4 - $pad);
            }

            $binary = base64_decode($b64, true);
            if ($binary !== false && str_starts_with($binary, "\x89PNG")) {
                $materialized[] = [
                    'name'   => 'signature.png',
                    'mime'   => 'image/png',
                    'size'   => strlen($binary),
                    'sha256' => hash('sha256', $binary),
                    'base64' => base64_encode($binary),
                ];
            }
        }

        return [
            'label'              => $label,
            'type'               => 'signature',
            'value'              => $materialized ? 'Erfasste Unterschrift' : '[Kein Eintrag]',
            'materialized_files' => $materialized,
        ];
    }

    /* ------------------------------------------------------------------ */
    /* Upload                                                               */
    /* ------------------------------------------------------------------ */

    private static function mapUpload(string $field_id, string $label, mixed $file_data): array
    {
        if (!$file_data || (is_array($file_data) && empty($file_data['name']))) {
            return ['label' => $label, 'type' => 'upload', 'value' => '[Kein Eintrag]', 'materialized_files' => []];
        }

        /* Normalize to list of file entries */
        $files_list = [];
        if (isset($file_data['name']) && is_array($file_data['name'])) {
            // multiple files
            foreach ($file_data['name'] as $i => $name) {
                if ($file_data['error'][$i] === UPLOAD_ERR_OK) {
                    $files_list[] = [
                        'name'     => $name,
                        'tmp_name' => $file_data['tmp_name'][$i],
                        'type'     => $file_data['type'][$i],
                        'size'     => $file_data['size'][$i],
                        'error'    => UPLOAD_ERR_OK,
                    ];
                }
            }
        } elseif (isset($file_data['tmp_name']) && $file_data['error'] === UPLOAD_ERR_OK) {
            $files_list[] = $file_data;
        }

        $info_parts   = [];
        $materialized = [];

        foreach ($files_list as $file) {
            $tmp  = $file['tmp_name'] ?? '';
            $name = sanitize_file_name($file['name']   ?? 'unknown');
            $mime = sanitize_mime_type($file['type']   ?? 'application/octet-stream');
            $size = (int)($file['size'] ?? 0);

            $info_parts[] = sprintf('%s (%s, %s KB)', $name, $mime, round($size / 1024, 1));

            if (!$tmp || !is_readable($tmp) || !is_uploaded_file($tmp)) {
                \ForgeForms\forge_log("ForgeForms: Upload file not readable: {$name}");
                continue;
            }

            $binary = file_get_contents($tmp);
            if ($binary === false) {
                continue;
            }

            $finfo_check = new \finfo(FILEINFO_MIME_TYPE);
            $mime        = sanitize_mime_type($finfo_check->file($tmp) ?: 'application/octet-stream');

            $materialized[] = [
                'name'   => $name,
                'mime'   => $mime,
                'size'   => strlen($binary),
                'sha256' => hash('sha256', $binary),
                'base64' => base64_encode($binary),
            ];
        }

        return [
            'label'              => $label,
            'type'               => 'upload',
            'value'              => $info_parts ? implode('; ', $info_parts) : '[Kein Eintrag]',
            'materialized_files' => $materialized,
        ];
    }

    /* ------------------------------------------------------------------ */
    /* SEPA                                                                 */
    /* ------------------------------------------------------------------ */

    private static function mapSepa(
        string $field_id,
        string $label,
        array $config,
        mixed $value,
        array $raw_values
    ): array {
        $entries = [];
        if (!is_array($value)) {
            return [[
                '_key'               => $field_id,
                'label'              => $label,
                'type'               => 'sepa',
                'value'              => '[Kein Eintrag]',
                'materialized_files' => [],
            ]];
        }

        $iban   = strtoupper(preg_replace('/\s/', '', (string)($value['iban']   ?? '')));
        $bic    = strtoupper((string)($value['bic']    ?? ''));
        $holder = (string)($value['holder'] ?? '');

        $entries[] = [
            '_key'  => $field_id . '_iban',
            'label' => $config['iban_label'] ?? 'IBAN',
            'type'  => 'sepa',
            'value' => $iban !== '' ? chunk_split($iban, 4, ' ') : '[Kein Eintrag]',
        ];
        $entries[] = [
            '_key'  => $field_id . '_bic',
            'label' => $config['bic_label'] ?? 'BIC',
            'type'  => 'sepa',
            'value' => $bic !== '' ? $bic : '[Kein Eintrag]',
        ];
        $entries[] = [
            '_key'  => $field_id . '_holder',
            'label' => $config['holder_label'] ?? 'Kontoinhaber',
            'type'  => 'sepa',
            'value' => $holder !== '' ? $holder : '[Kein Eintrag]',
        ];

        /* Signatures */
        $sig1_key = $field_id . '-sig1';
        $sig2_key = $field_id . '-sig2';

        if (!empty($value['sig1']) || !empty($raw_values[$sig1_key])) {
            $sig1_val = $value['sig1'] ?? $raw_values[$sig1_key] ?? '';
            $sig1     = self::mapSignature($sig1_key, $config['sig1_label'] ?? 'Signatur SEPA', $sig1_val);
            $sig1['_key'] = $field_id . '_sig1';
            $entries[] = $sig1;
        }

        if (!empty($config['show_sig2']) && (!empty($value['sig2']) || !empty($raw_values[$sig2_key]))) {
            $sig2_val = $value['sig2'] ?? $raw_values[$sig2_key] ?? '';
            $sig2     = self::mapSignature($sig2_key, $config['sig2_label'] ?? 'Signatur Beitritt', $sig2_val);
            $sig2['_key'] = $field_id . '_sig2';
            $entries[] = $sig2;
        }

        return $entries;
    }
}
