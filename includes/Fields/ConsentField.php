<?php

/**
 * Single consent checkbox field.
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
 * Single-checkbox consent field.
 */
class ConsentField extends BaseField
{
    /**
     * Returns field-specific CSS styles.
     *
     * @return string
     */
    public function getStyles(): string
    {
        return <<<'CSS'
.forge-consent-label {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    cursor: pointer;
    font-size: 14px;
    color: var(--forge-text);
    line-height: 1.6;
    user-select: none;
}
.forge-consent-label input[type="checkbox"] {
    appearance: none;
    -webkit-appearance: none;
    width: 18px;
    height: 18px;
    flex-shrink: 0;
    border: 2px solid var(--forge-border-input);
    border-radius: var(--forge-radius-sm);
    background: var(--forge-bg);
    cursor: pointer;
    transition: border-color .15s, background .15s;
    margin-top: 3px;
}
.forge-consent-label input[type="checkbox"]:checked {
    border-color: var(--forge-accent);
    background: var(--forge-accent);
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 12 10'%3E%3Cpolyline points='1,5 4.5,8.5 11,1' stroke='%23ffffff' stroke-width='2' fill='none' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: center;
    background-size: 11px 9px;
}
.forge-consent-label input[type="checkbox"]:focus-visible {
    outline: none;
    box-shadow: 0 0 0 3px
        color-mix(in srgb, var(--forge-accent) 25%, transparent);
}
.forge-consent-text a { color: var(--forge-accent); text-decoration: underline; }
.forge-consent-text a:hover { color: var(--forge-accent-dark); }
CSS;
    }

    /**
     * Returns the field type label.
     *
     * @return string
     */
    public function getLabel(): string
    {
        return 'Einwilligung';
    }

    /**
     * Returns the Font Awesome icon class.
     *
     * @return string
     */
    public function getIcon(): string
    {
        return 'fa-solid fa-circle-check';
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
        $req     = !empty($config['required']) ? ' required aria-required="true"' : '';
        $checked = !empty($value) ? ' checked' : '';
        $text    = wp_kses_post($config['consent_text'] ?? 'Ich stimme zu.');

        $inner = '<label class="forge-consent-label">'
            . '<input type="checkbox" id="' . esc_attr($field_id)
            . '" name="' . esc_attr($field_id) . '" value="1"' . $checked . $req . '>'
            . '<span class="forge-consent-text">' . $text . '</span>'
            . '</label>';

        return $this->wrap($field_id, $config, $inner);
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
        if (!empty($config['required']) && empty($value)) {
            return ($config['label'] ?? 'Zustimmung') . ' ist erforderlich.';
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
        return (!empty($value) && $value !== '0') ? 'Ja' : 'Nein';
    }

    /**
     * Returns the default field configuration.
     *
     * @return array
     */
    public function getDefaultConfig(): array
    {
        return array_merge(parent::getDefaultConfig(), ['consent_text' => 'Ich stimme den Bedingungen zu.']);
    }

    /**
     * Returns the general settings schema for the field editor.
     *
     * @return array
     */
    public function getGeneralSchema(): array
    {
        return [
            ['key' => 'consent_text', 'type' => 'textarea', 'label' => 'Zustimmungstext'],
        ];
    }
}
