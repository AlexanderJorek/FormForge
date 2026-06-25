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

namespace ForgeForms\Fields;

defined('ABSPATH') || exit;

class CaptchaField extends BaseField
{
    public function getLabel(): string
    {
        return 'CAPTCHA';
    }
    public function getIcon(): string
    {
        return 'fa-solid fa-robot';
    }

    public function render(array $config, string $field_id, mixed $value = null): string
    {
        $site_key = get_option('forge_forms_recaptcha_site_key', '');
        if ($site_key === '') {
            return '<div class="forge-field forge-field--captcha">'
                . '<p class="forge-notice">reCAPTCHA: Bitte Site-Key in den Plugin-Einstellungen hinterlegen.</p>'
                . '</div>';
        }

        $inner = '<div class="g-recaptcha" data-sitekey="' . esc_attr($site_key) . '"></div>';
        return $this->wrap($field_id, $config, $inner);
    }

    public function validate(mixed $value, array $config): bool|string
    {
        $secret = get_option('forge_forms_recaptcha_secret_key', '');
        if ($secret === '' || empty($value)) {
            return 'Bitte bestätigen Sie das CAPTCHA.';
        }

        $response = wp_remote_post('https://www.google.com/recaptcha/api/siteverify', [
            'body' => ['secret' => $secret, 'response' => sanitize_text_field((string)$value)],
        ]);

        if (is_wp_error($response)) {
            return 'CAPTCHA-Überprüfung fehlgeschlagen.';
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (empty($data['success'])) {
            return 'Bitte bestätigen Sie das CAPTCHA.';
        }
        return true;
    }

    public function map(mixed $value, array $config): string
    {
        return '[CAPTCHA bestätigt]';
    }

    public function getDefaultConfig(): array
    {
        return ['label' => 'CAPTCHA', 'required' => true, 'description' => ''];
    }

    public function getGeneralSchema(): array
    {
        return [];
    }
}
