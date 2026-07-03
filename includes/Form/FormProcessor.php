<?php

/**
 * Handles form submission validation and processing.
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

namespace ForgeForms\Form;

defined('ABSPATH') || exit;

use ForgeForms\Fields\FieldRegistry;

/**
 * Handles forge_form AJAX submissions, validates fields, and fires the
 * submission action.
 */
class FormProcessor
{
    /**
     * AJAX handler for the forge_forms_submit action.
     *
     * Expects form_id, nonce, and field values in POST/FILES.
     *
     * @return void
     */
    public static function handle(): void
    {
        /* ---- Nonce ---- */
        $nonce   = sanitize_key($_POST['forge_nonce'] ?? '');
        $form_id = (int)($_POST['form_id'] ?? 0);

        if (!$form_id || !wp_verify_nonce($nonce, 'forge_forms_submit_' . $form_id)) {
            wp_send_json_error(['message' => 'Sicherheitsprüfung fehlgeschlagen.'], 403);
        }

        /* ---- Honeypot check (before any expensive work) ---- */
        if (!empty($_POST['forge_hp_field'])) {
            $form    = FormModel::get($form_id);
            $hp_msg  = $form->settings['success_message'] ?? 'Vielen Dank für Ihre Einsendung!';
            wp_send_json_success(['message' => $hp_msg]);
        }

        /* ---- Load form ---- */
        $form = FormModel::get($form_id);
        if (!$form) {
            wp_send_json_error(['message' => 'Formular nicht gefunden.'], 404);
        }

        /* ---- Validate all fields ---- */
        $errors = [];
        $raw    = [];

        foreach ($form->fields as $field_cfg) {
            $field_id   = $field_cfg['id'] ?? '';
            $field_type = $field_cfg['type'] ?? '';

            if (!$field_id || !$field_type) {
                continue;
            }

            $handler = FieldRegistry::get($field_type);
            if (!$handler) {
                continue;
            }

            /* Layout/presentation-only fields have no submission value */
            if ($handler->skipValidation()) {
                continue;
            }

            /* Group field: validate children across all submitted copies */
            if ($handler->isGroupContainer()) {
                $children   = $field_cfg['children'] ?? [];
                $group_post = isset($_POST[$field_id]) && is_array($_POST[$field_id])
                    ? $_POST[$field_id] : [];

                $sanitized = [];
                foreach ($group_post as $copy_idx => $copy_data) {
                    $copy_idx = (int) $copy_idx;
                    if ($copy_idx < 1 || !is_array($copy_data)) {
                        continue;
                    }
                    $sanitized[$copy_idx] = [];
                    foreach ($children as $child_cfg) {
                        $child_id   = $child_cfg['id']   ?? '';
                        $child_type = $child_cfg['type'] ?? '';
                        if (!$child_id || !$child_type) {
                            continue;
                        }
                        $ch = FieldRegistry::get($child_type);
                        if (!$ch) {
                            continue;
                        }
                        $raw_v = $copy_data[$child_id] ?? '';
                        $val   = $ch->extractFromRaw($raw_v);
                        $sanitized[$copy_idx][$child_id] = $val;

                        $ekey = $field_id . '[' . $copy_idx . '][' . $child_id . ']';
                        if (!empty($child_cfg['required']) && $val === '') {
                            $errors[$ekey] = ($child_cfg['label'] ?? $child_id) . ' ist ein Pflichtfeld.';
                        } else {
                            $result = $ch->validate($val, $child_cfg);
                            if ($result !== true) {
                                $errors[$ekey] = $result;
                            }
                        }
                    }
                }
                $raw[$field_id] = $sanitized;
                continue;
            }

            /* Collect raw value */
            $value = $handler->extractValue($field_id);
            $raw[$field_id] = $value;

            /* Validate — pass field_id so UploadField can resolve $_FILES correctly */
            $field_cfg['field_id'] = $field_id;
            $result = $handler->validate($value, $field_cfg);
            if ($result !== true) {
                $errors[$field_id] = $result;
            }
        }

        /* ---- Honeypot check ---- */
        if (!empty($_POST['forge_hp_field'])) {
            wp_send_json_success(['message' => $form->settings['success_message'] ?? 'Vielen Dank für Ihre Einsendung!']);
        }

        if (!empty($errors)) {
            wp_send_json_error(['message' => 'Bitte korrigieren Sie die markierten Felder.', 'errors' => $errors], 422);
        }

        /* ---- Map to human-readable for PDF/email ---- */
        $mapped = FieldRegistry::mapSubmission($form->fields, $raw, $_FILES);

        /* ---- Fire submission hook (PDF generation + mail happens here) ---- */
        do_action('forge_forms_submission', $form_id, $mapped, $form);

        /* ---- Respond ---- */
        $success_msg = esc_html($form->settings['success_message'] ?? 'Vielen Dank für Ihre Einsendung!');
        wp_send_json_success(['message' => $success_msg]);
    }
}
