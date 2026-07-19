<?php

/**
 * Google reCAPTCHA v2 field.
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

namespace ForgeForms\Fields;

defined('ABSPATH') || exit;

/**
 * Google reCAPTCHA v2 field.
 */
class CaptchaField extends BaseField
{
    /**
     * Returns the field type label.
     *
     * @return string
     */
    public function getLabel(): string
    {
        return __('CAPTCHA', 'form-forge');
    }

    /**
     * Returns the Font Awesome icon class.
     *
     * @return string
     */
    public function getIcon(): string
    {
        return 'fa-solid fa-robot';
    }

    /**
     * Enqueues the Google reCAPTCHA v2 script required by this field.
     *
     * @return void
     */
    public function enqueueFrontScripts(): void
    {
        wp_enqueue_script('google-recaptcha', 'https://www.google.com/recaptcha/api.js', [], false, true);
    }

    /**
     * Renders the field HTML.
     *
     * @param array  $config   Field configuration.
     * @param string $field_id Unique field identifier.
     * @param mixed  $value    Current field value.
     *
     * @return string Rendered HTML.
     */
    public function render(array $config, string $field_id, mixed $value = null): string
    {
        $site_key = get_option('forge_forms_recaptcha_site_key', '');
        if ($site_key === '') {
            return '<div class="forge-field forge-field--captcha">'
                . '<p class="forge-notice">' . esc_html(__('reCAPTCHA: Please enter the site key in the plugin settings.', 'form-forge')) . '</p>'
                . '</div>';
        }

        $inner = '<div class="g-recaptcha" data-sitekey="' . esc_attr($site_key) . '"></div>';
        return $this->wrap($field_id, $config, $inner);
    }

    /**
     * Reads the reCAPTCHA token from the fixed POST key injected by the widget.
     *
     * @param string $field_id Unused — reCAPTCHA always posts to 'g-recaptcha-response'.
     *
     * @return string
     */
    public function extractValue(string $field_id): mixed
    {
        return isset($_POST['g-recaptcha-response'])
            ? sanitize_text_field(wp_unslash($_POST['g-recaptcha-response']))
            : '';
    }

    /**
     * Validates the submitted value.
     *
     * @param mixed $value  Submitted value.
     * @param array $config Field configuration.
     *
     * @return bool|string True on valid, error message string on invalid.
     */
    public function validate(mixed $value, array $config): bool|string
    {
        $secret = get_option('forge_forms_recaptcha_secret_key', '');
        if ($secret === '' || empty($value)) {
            return __('Please confirm the CAPTCHA.', 'form-forge');
        }

        $response = wp_remote_post('https://www.google.com/recaptcha/api/siteverify', ['body' => ['secret' => $secret, 'response' => sanitize_text_field((string)$value)]]);

        if (is_wp_error($response)) {
            return __('CAPTCHA verification failed.', 'form-forge');
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (empty($data['success'])) {
            return __('Please confirm the CAPTCHA.', 'form-forge');
        }
        return true;
    }

    /**
     * Maps the field value to a human-readable string for email and PDF output.
     *
     * @param mixed $value  Submitted value.
     * @param array $config Field configuration.
     *
     * @return string Human-readable representation.
     */
    public function map(mixed $value, array $config): string
    {
        return __('[CAPTCHA confirmed]', 'form-forge');
    }

    /**
     * Returns the default field configuration.
     *
     * @return array
     */
    public function getDefaultConfig(): array
    {
        return [
            'label'       => 'CAPTCHA',
            'required'    => true,
            'description' => '',
        ];
    }

    /**
     * Returns the general settings schema for the field editor.
     *
     * @return array
     */
    public function getGeneralSchema(): array
    {
        return [];
    }
}
