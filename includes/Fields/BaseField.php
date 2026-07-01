<?php

/**
 * Abstract base class providing shared behaviour for all field types.
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
 * Abstract base class for all FormForge field types.
 */
abstract class BaseField
{
    /**
     * Returns the human-readable field type label.
     *
     * @return string
     */
    abstract public function getLabel(): string;

    /**
     * Returns the icon identifier for the field type tile.
     *
     * @return string
     */
    abstract public function getIcon(): string;

    /**
     * Whether clicking this field tile opens the settings panel.
     *
     * @return bool
     */
    public function hasSettingsPanel(): bool
    {
        return true;
    }

    /**
     * Whether the "Pflichtfeld" (required) checkbox is shown in the settings panel.
     *
     * @return bool
     */
    public function hasRequired(): bool
    {
        return true;
    }

    /**
     * Renders the field HTML for frontend display.
     *
     * @param array  $config   Field configuration from form definition.
     * @param string $field_id Element ID (e.g. "field-3").
     * @param mixed  $value    Pre-filled value (for re-displaying on error).
     *
     * @return string
     */
    abstract public function render(array $config, string $field_id, mixed $value = null): string;

    /**
     * Validates a submitted value.
     *
     * Returns true on success, or an error message string on failure.
     *
     * @param mixed $value  The submitted value.
     * @param array $config Field configuration array.
     *
     * @return bool|string
     */
    public function validate(mixed $value, array $config): bool|string
    {
        if (!empty($config['required']) && $this->isEmpty($value)) {
            $label = $config['label'] ?? 'Field';
            return esc_html($label) . ' ist ein Pflichtfeld.';
        }
        return true;
    }

    /**
     * Maps the submitted value to a human-readable string for PDF/email.
     *
     * May return an array with 'value' and 'files' keys for upload/signature fields.
     *
     * @param mixed $value  The submitted value.
     * @param array $config Field configuration array.
     *
     * @return string
     */
    public function map(mixed $value, array $config): string
    {
        if ($this->isEmpty($value)) {
            return '[Kein Eintrag]';
        }
        return (string) $value;
    }

    /**
     * Returns the client-side empty-check function for this field type.
     *
     * Return ['fn' => 'function(fieldEl){ return bool; }'] or [] to use the
     * generic fallback (first visible input is non-empty).
     * Collected by Assets::enqueueFront() into window.ForgeEmptyChecks keyed
     * by field type, so front.js needs no field-specific knowledge.
     *
     * @return array
     */
    public function getClientEmptyCheck(): array
    {
        return [];
    }

    /**
     * Returns client-side validation rules for this field type.
     *
     * Each entry: ['rule' => unique rule key, 'fn' => JS function string].
     * Collected by Assets::enqueueFront() into window.ForgeValidators.
     * Required/empty is handled implicitly — only declare FORMAT rules here.
     *
     * @return array
     */
    public function getClientValidation(): array
    {
        return [];
    }

    /**
     * Returns the client-side initialisation script for this field type.
     *
     * Return a JS function string: function(root) { ... }
     * Collected by Assets::enqueueFront() into window.ForgeFieldInits.
     *
     * @return string
     */
    public function getClientInit(): string
    {
        return '';
    }

    /**
     * Returns field-specific CSS to inject inline on pages that load this form.
     *
     * Return raw CSS (no style tags). Empty string = no output.
     *
     * @return string
     */
    public function getStyles(): string
    {
        return '';
    }

    /**
     * Whether client-side validation should be skipped for this field type.
     *
     * Set true for purely presentational fields (pagebreak, html).
     *
     * @return bool
     */
    public function skipValidation(): bool
    {
        return false;
    }

    /**
     * Returns default config values for the builder.
     *
     * @return array
     */
    public function getDefaultConfig(): array
    {
        return [
            'label'       => '',
            'required'    => false,
            'hide_label'  => false,
            'placeholder' => '',
            'description' => '',
        ];
    }

    /**
     * Returns placeholder and description schema entries shared by most fields.
     *
     * @return array
     */
    protected function baseGeneralEntries(): array
    {
        return [
            ['key' => 'placeholder', 'type' => 'text', 'label' => 'Platzhalter'],
            ['key' => 'description', 'type' => 'text', 'label' => 'Beschreibung'],
        ];
    }

    /**
     * Returns settings schema for the General tab.
     *
     * @return array
     */
    public function getGeneralSchema(): array
    {
        return $this->baseGeneralEntries();
    }

    /**
     * Returns settings schema for the Advanced tab.
     *
     * @return array
     */
    public function getAdvancedSchema(): array
    {
        return [];
    }

    /**
     * Checks whether a submitted value is considered empty.
     *
     * @param mixed $value The value to check.
     *
     * @return bool
     */
    protected function isEmpty(mixed $value): bool
    {
        if ($value === null || $value === '') {
            return true;
        }
        if (is_array($value) && empty(array_filter($value, static fn($v) => $v !== ''))) {
            return true;
        }
        return false;
    }

    /**
     * Builds standard wrapper HTML around a field's inner content.
     *
     * @param string $field_id    Element ID for the field.
     * @param array  $config      Field configuration array.
     * @param string $inner       Inner HTML content.
     * @param string $extra_class Additional CSS class(es) for the wrapper.
     *
     * @return string
     */
    protected function wrap(string $field_id, array $config, string $inner, string $extra_class = ''): string
    {
        $label       = esc_html($config['label'] ?? '');
        $required    = !empty($config['required']);
        $hide_label  = !empty($config['hide_label']);
        $description = esc_html($config['description'] ?? '');
        $req_attr    = $required ? ' <span class="forge-required" aria-hidden="true">*</span>' : '';
        $req_class   = $required ? ' forge-required-field' : '';
        $desc_html   = $description !== ''
            ? '<p class="forge-field-description">' . $description . '</p>'
            : '';

        $label_html = (!$hide_label && $label !== '')
            ? '<label class="forge-label" for="' . esc_attr($field_id) . '">' . $label . $req_attr . '</label>'
            : '';

        $client_rules  = $this->getClientValidation();
        $validate_attr = !empty($client_rules)
            ? ' data-validate="' . esc_attr(wp_json_encode(array_column($client_rules, 'rule'))) . '"'
            : '';

        return '<div class="forge-field forge-field--' . esc_attr($config['type'] ?? 'text')
            . $req_class . ' ' . esc_attr($extra_class) . '" data-field-id="' . esc_attr($field_id) . '"'
            . $validate_attr . '>'
            . $label_html
            . $desc_html
            . $inner
            . '<div class="forge-field-error" id="' . esc_attr($field_id)
            . '-error" role="alert" aria-live="polite"></div>'
            . '</div>';
    }

    /**
     * Builds an HTML attribute string for an input element.
     *
     * @param array  $config   Field configuration array.
     * @param string $field_id Element ID for the input.
     * @param string $type     Input type attribute value.
     * @param array  $extra    Additional attributes to merge.
     *
     * @return string
     */
    protected function inputAttrs(array $config, string $field_id, string $type = 'text', array $extra = []): string
    {
        $attrs = array_merge(
            [
            'type'        => $type,
            'id'          => $field_id,
            'name'        => $field_id,
            'placeholder' => $config['placeholder'] ?? '',
            'class'       => 'forge-input',
            ], $extra
        );

        if (!empty($config['required'])) {
            $attrs['required'] = 'required';
            $attrs['aria-required'] = 'true';
        }

        $html = '';
        foreach ($attrs as $k => $v) {
            if ($v === true || $v === $k) {
                $html .= ' ' . esc_attr($k);
            } elseif ($v !== '' && $v !== false) {
                $html .= ' ' . esc_attr($k) . '="' . esc_attr($v) . '"';
            }
        }
        return $html;
    }
}
