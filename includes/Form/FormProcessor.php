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
        $nonce   = sanitize_key(\ForgeForms\Utils\Sanitize::str($_POST['forge_nonce'] ?? null));
        $form_id = (int)($_POST['form_id'] ?? 0);

        if (!$form_id || !wp_verify_nonce($nonce, 'forge_forms_submit_' . $form_id)) {
            wp_send_json_error(['message' => __('Security check failed.', 'form-forge')], 403);
        }

        /* ---- Rate limit (per IP + form) to prevent replay/abuse ---- */
        if (self::isRateLimited($form_id)) {
            wp_send_json_error(
                ['message' => __('Too many submissions, please try again later.', 'form-forge')],
                429
            );
        }

        /* ---- Load form ---- */
        $form = FormModel::get($form_id);
        if (!$form) {
            wp_send_json_error(['message' => __('Form not found.', 'form-forge')], 404);
        }

        /* ---- Honeypot check (before any expensive work) ---- */
        if (!empty($_POST['forge_hp_field'])) {
            $hp_msg = $form->settings['success_message'] ?? __('Thank you for your submission!', 'form-forge');
            wp_send_json_success(['message' => $hp_msg]);
        }

        /* ---- Pass 1: extract all values (no validation yet) ----
           Two passes are required because conditional-visibility rules (Pass 2) can
           reference any other field's value, including ones defined later in the form —
           so every value must be extracted and flattened before any field can be validated. */
        $raw = [];

        foreach ($form->fields as $field_cfg) {
            $field_id   = $field_cfg['id'] ?? '';
            $field_type = $field_cfg['type'] ?? '';

            if (!$field_id || !$field_type) {
                continue;
            }

            $handler = FieldRegistry::get($field_type);
            if (!$handler || $handler->skipValidation()) {
                continue;
            }

            if ($handler->isGroupContainer()) {
                $children   = $field_cfg['children'] ?? [];
                $group_post = isset($_POST[$field_id]) && is_array($_POST[$field_id])
                    ? $_POST[$field_id] : [];
                $sanitized  = [];
                // Cap the number of repeatable-group copies processed per field —
                // otherwise a POST with an arbitrarily large number of copy
                // indices multiplies work by count(children) per copy with no
                // ceiling, a cheap amplification vector.
                $max_group_copies = 100;
                $processed_copies  = 0;

                if (!empty($group_post)) {
                    foreach ($group_post as $copy_idx => $copy_data) {
                        if ($processed_copies >= $max_group_copies) {
                            break;
                        }
                        $copy_idx = (int) $copy_idx;
                        if ($copy_idx < 1 || !is_array($copy_data)) {
                            continue;
                        }
                        $processed_copies++;
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
                            $sanitized[$copy_idx][$child_id] = $ch->extractFromRawWithOther(
                                $copy_data[$child_id] ?? '',
                                $copy_data[$child_id . '_other'] ?? null
                            );
                        }
                    }
                } else {
                    $flat_copy = [];
                    foreach ($children as $child_cfg) {
                        $child_id   = $child_cfg['id']   ?? '';
                        $child_type = $child_cfg['type'] ?? '';
                        if (!$child_id || !$child_type) {
                            continue;
                        }
                        $ch = FieldRegistry::get($child_type);
                        if (!$ch || $ch->skipValidation()) {
                            continue;
                        }
                        $flat_copy[$child_id] = $ch->extractValue($child_id);
                    }
                    if (!empty($flat_copy)) {
                        $sanitized[1] = $flat_copy;
                    }
                }

                $raw[$field_id] = $sanitized;
                continue;
            }

            $raw[$field_id] = $handler->extractValue($field_id);
        }

        /* ---- Build flat value map for condition evaluation ---- */
        /* Track which field IDs are group containers so the loop can tell
           a group's [copy_idx => [child_id => val]] structure apart from a
           top-level field that legitimately returns an array (checkbox, slider
           ranged mode, expanded address/name, post-data, etc.). */
        $group_ids = [];
        foreach ($form->fields as $fc) {
            $h = FieldRegistry::get($fc['type'] ?? '');
            if ($h && $h->isGroupContainer()) {
                $group_ids[$fc['id'] ?? ''] = true;
            }
        }

        $flat = [];
        foreach ($raw as $fid => $val) {
            if (isset($group_ids[$fid]) && is_array($val)) {
                foreach ($val as $copy) {
                    if (is_array($copy)) {
                        foreach ($copy as $cid => $cv) {
                            $flat[$cid] = $cv;
                        }
                    }
                }
            } else {
                $flat[$fid] = $val;
            }
        }

        /* ---- Pass 2: validate visible fields only ---- */
        $errors = [];

        foreach ($form->fields as $field_cfg) {
            $field_id   = $field_cfg['id'] ?? '';
            $field_type = $field_cfg['type'] ?? '';

            if (!$field_id || !$field_type) {
                continue;
            }

            $handler = FieldRegistry::get($field_type);
            if (!$handler || $handler->skipValidation()) {
                continue;
            }

            /* Skip the entire group + all its children when the group is hidden. */
            if (self::isHiddenByConditions($field_cfg, $flat)) {
                continue;
            }

            if ($handler->isGroupContainer()) {
                $children  = $field_cfg['children'] ?? [];
                $group_raw = $raw[$field_id] ?? [];

                foreach ($group_raw as $copy_idx => $copy_data) {
                    if (!is_array($copy_data)) {
                        continue;
                    }
                    foreach ($children as $child_cfg) {
                        $child_id   = $child_cfg['id']   ?? '';
                        $child_type = $child_cfg['type'] ?? '';
                        if (!$child_id || !$child_type) {
                            continue;
                        }
                        $ch = FieldRegistry::get($child_type);
                        if (!$ch || $ch->skipValidation()) {
                            continue;
                        }

                        /* Skip child if hidden by its own conditions. */
                        if (self::isHiddenByConditions($child_cfg, $flat)) {
                            continue;
                        }

                        $val  = $copy_data[$child_id] ?? '';
                        // Qualify the error key with group+copy index only when the group repeats
                        // (multiple copies) — otherwise a single copy just uses the bare child_id,
                        // since front.js's error-display lookup expects that shorter key by default
                        $ekey = count($group_raw) > 1
                            ? $field_id . '[' . $copy_idx . '][' . $child_id . ']'
                            : $child_id;

                        if (!empty($child_cfg['required']) && $val === '') {
                            $errors[$ekey] = sprintf(__('%s is a required field.', 'form-forge'), esc_html($child_cfg['label'] ?? $child_id));
                        } else {
                            $child_cfg['field_id'] = $child_id;
                            $result = $ch->validate($val, $child_cfg);
                            if ($result !== true) {
                                $errors[$ekey] = $result;
                            }
                        }
                    }
                }
                continue;
            }

            $value             = $raw[$field_id] ?? '';
            $field_cfg['field_id'] = $field_id;
            $result = $handler->validate($value, $field_cfg);
            if ($result !== true) {
                $errors[$field_id] = $result;
            }
        }

        if (!empty($errors)) {
            wp_send_json_error(['message' => __('Please correct the highlighted fields.', 'form-forge'), 'errors' => $errors], 422);
        }

        /* ---- Map to human-readable for PDF/email ---- */
        /* $flat is already built; use it to remove hidden field entries. */
        $hidden_ids = self::collectHiddenIds($form->fields, $flat, $raw);
        $mapped     = FieldRegistry::mapSubmission($form->fields, $raw, $_FILES);

        foreach ($hidden_ids as $hid) {
            unset($mapped[$hid]);
        }

        /* ---- Fire submission hook (PDF generation + mail happens here) ---- */
        do_action('forge_forms_submission', $form_id, $mapped, $form);

        /* ---- Respond ---- */
        $success_msg = esc_html($form->settings['success_message'] ?? __('Thank you for your submission!', 'form-forge'));
        wp_send_json_success(['message' => $success_msg]);
    }

    /**
     * Checks and increments a per-IP, per-form submission counter using a
     * short-lived transient, to slow down scripted replay/abuse of the
     * public submission endpoint.
     *
     * @param int $form_id The form being submitted.
     *
     * @return bool True when the caller has exceeded the allowed rate.
     */
    private static function isRateLimited(int $form_id): bool
    {
        // ClientIp::resolve() uses REMOTE_ADDR by default (unspoofable) and only trusts
        // X-Forwarded-For when REMOTE_ADDR is explicitly allowlisted via the
        // FORGE_TRUSTED_PROXIES constant — see includes/Utils/ClientIp.php
        $ip = \ForgeForms\Utils\ClientIp::resolve();
        if ($ip === '') {
            // Unknown client: fail closed rather than pooling every such
            // request into one shared rate-limit bucket.
            return true;
        }
        $key = 'forge_rl_' . md5($ip . '_' . $form_id);
        $count = (int) get_transient($key);
        if ($count >= 10) {
            return true;
        }
        set_transient($key, $count + 1, 5 * MINUTE_IN_SECONDS);
        return false;
    }

    /**
     * Returns all field IDs (top-level and group children) that should be hidden
     * based on the submitted values and the form's condition rules.
     *
     * @param array $fields     Form field configs.
     * @param array $flat       Flat map of field_id → submitted value.
     *
     * @return array<string>
     */
    private static function collectHiddenIds(array $fields, array $flat, array $raw): array
    {
        $hidden = [];

        foreach ($fields as $field_cfg) {
            $field_id = $field_cfg['id'] ?? '';
            if (!$field_id) {
                continue;
            }

            $group_hidden = self::isHiddenByConditions($field_cfg, $flat);

            /* Determine copy count to replicate GroupField::mapNormalized key suffixing.
               Only real group-container fields use the [copy_idx => [child_id => val]]
               shape — a plain Radio/Select field with "Other" selected also has an
               array-shaped raw value (['value' => ..., '__other_text__' => ...]),
               which must never be misread as repeatable-group copies. */
            $field_handler = FieldRegistry::get($field_cfg['type'] ?? '');
            $is_group      = $field_handler && $field_handler->isGroupContainer();
            $copies        = ($is_group && is_array($raw[$field_id] ?? null)) ? $raw[$field_id] : [];
            $copy_count    = count($copies);

            if ($group_hidden) {
                $hidden[] = $field_id;
                foreach ($field_cfg['children'] ?? [] as $child) {
                    $cid = $child['id'] ?? '';
                    if (!$cid) {
                        continue;
                    }
                    if ($copy_count > 1) {
                        foreach (array_keys($copies) as $idx) {
                            $hidden[] = $cid . '_copy_' . $idx;
                        }
                    } else {
                        $hidden[] = $cid;
                    }
                }
                continue;
            }

            /* Group visible — still check each child's own conditions. */
            foreach ($field_cfg['children'] ?? [] as $child) {
                $cid = $child['id'] ?? '';
                if (!$cid || !self::isHiddenByConditions($child, $flat)) {
                    continue;
                }
                if ($copy_count > 1) {
                    foreach (array_keys($copies) as $idx) {
                        $hidden[] = $cid . '_copy_' . $idx;
                    }
                } else {
                    $hidden[] = $cid;
                }
            }
        }

        return $hidden;
    }

    /**
     * Evaluates a single field's condition config against the flat value map.
     * Returns true when the field should be hidden.
     *
     * @param array $field_cfg Field or child config with optional 'conditions' key.
     * @param array $flat      Flat field_id → value map.
     *
     * @return bool
     */
    private static function isHiddenByConditions(array $field_cfg, array $flat): bool
    {
        $rules = $field_cfg['conditions']['rules'] ?? [];
        if (empty($rules)) {
            return false;
        }

        $action = $field_cfg['conditions']['action'] ?? 'show';
        $match  = $field_cfg['conditions']['match']  ?? 'all';

        $results = [];
        foreach ($rules as $r) {
            if (is_array($r)) {
                $results[] = self::evalConditionRule($r, $flat);
            }
        }

        $pass = $match === 'any'
            ? in_array(true, $results, strict: true)
            : !in_array(false, $results, strict: true);

        return $action === 'hide' ? $pass : !$pass;
    }

    /**
     * Evaluates one condition rule against the flat value map.
     *
     * @param array $rule Rule: field_id, operator, value.
     * @param array $flat Flat field_id → value map.
     *
     * @return bool
     */
    private static function evalConditionRule(array $rule, array $flat): bool
    {
        $fid   = $rule['field_id'] ?? '';
        $op    = $rule['operator'] ?? 'equals';
        $rv    = strtolower((string)($rule['value'] ?? ''));
        $val   = $flat[$fid] ?? '';
        // Fields with an "Other" free-text option (Checkbox/Select) attach the
        // typed text under this internal key alongside the actual selection(s)
        // — it must never be treated as a selectable option value here, or a
        // user's free-typed text could accidentally satisfy/break an
        // equals/contains condition rule aimed at the real options.
        if (is_array($val)) {
            unset($val['__other_text__']);
        }
        $isArr = is_array($val);
        $str   = $isArr ? strtolower(implode(',', $val)) : strtolower((string)$val);
        $lower = $isArr ? array_map('strtolower', $val) : [];

        return match ($op) {
            'equals'       => $isArr ? in_array($rv, $lower, strict: true) : $str === $rv,
            'not_equals'   => $isArr ? !in_array($rv, $lower, strict: true) : $str !== $rv,
            'contains'     => $rv !== '' && ($isArr
                ? (bool) array_filter($lower, static fn($v) => str_contains($v, $rv))
                : str_contains($str, $rv)),
            'not_contains' => $rv === '' || ($isArr
                ? !array_filter($lower, static fn($v) => str_contains($v, $rv))
                : !str_contains($str, $rv)),
            'empty'        => $isArr ? empty($val) : $str === '',
            'not_empty'    => $isArr ? !empty($val) : $str !== '',
            'greater'      => is_numeric($str) && is_numeric($rv) && (float)$str > (float)$rv,
            'less'         => is_numeric($str) && is_numeric($rv) && (float)$str < (float)$rv,
            default        => true,
        };
    }
}
