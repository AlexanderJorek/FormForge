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

abstract class BaseField
{
    abstract public function getLabel(): string;
    abstract public function getIcon(): string;

    /** Whether clicking this field tile opens the settings panel. */
    public function hasSettingsPanel(): bool
    {
        return true;
    }

    /** Whether the "Pflichtfeld" (required) checkbox is shown in the settings panel. */
    public function hasRequired(): bool
    {
        return true;
    }

    /**
     * Render the field HTML for frontend display.
     * @param array $config   Field configuration from form definition.
     * @param string $field_id  Element ID (e.g. "field-3").
     * @param mixed  $value    Pre-filled value (for re-displaying on error).
     */
    abstract public function render(array $config, string $field_id, mixed $value = null): string;

    /**
     * Validate a submitted value.
     * Returns true on success, or an error message string on failure.
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
     * Map the submitted value to a human-readable string for PDF/email.
     * Returns a string. May also return an array with 'value' and 'files' keys
     * for upload/signature fields.
     */
    public function map(mixed $value, array $config): string
    {
        if ($this->isEmpty($value)) {
            return '[Kein Eintrag]';
        }
        return (string) $value;
    }

    /**
     * Client-side empty check for this field type.
     *
     * Return ['fn' => 'function(fieldEl){ return bool; }'] or [] to use the
     * generic fallback (first visible input is non-empty).
     * Collected by Assets::enqueueFront() into window.ForgeEmptyChecks keyed
     * by field type, so front.js needs no field-specific knowledge.
     */
    public function getClientEmptyCheck(): array
    {
        return [];
    }

    /**
     * Client-side validation rules for this field type.
     *
     * Return an array of rule definitions. Each entry:
     *   'rule' => unique rule key (string)
     *   'fn'   => JavaScript function body as a string:
     *             function(fieldEl) { ... return 'error message' | null; }
     *
     * Rules are collected by Assets::enqueueFront() and exposed as
     * window.ForgeValidators so front.js can run them without knowing
     * anything about specific field types.
     *
     * Required/empty is always handled implicitly — only declare FORMAT rules here.
     * Rules only run when the field has content.
     *
     * Example:
     *   return [['rule' => 'email', 'fn' => "function(f){ ... return null; }"]];
     */
    public function getClientValidation(): array
    {
        return [];
    }

    /**
     * Client-side initialisation script for this field type.
     *
     * Return a JavaScript function string:
     *   function(root) { /* init all instances inside root *\/ }
     *
     * Collected by Assets::enqueueFront() into window.ForgeFieldInits keyed
     * by field type. front.js calls each registered init function with the
     * container root element — no field-specific knowledge needed in front.js.
     */
    public function getClientInit(): string
    {
        return '';
    }

    /**
     * Field-specific CSS to inject inline on pages that load this form.
     * Return a raw CSS string (no <style> tags). Empty string = no output.
     * Collected by Assets::enqueueFront() into a single wp_add_inline_style call.
     */
    public function getStyles(): string
    {
        return '';
    }

    /**
     * Whether client-side validation should be skipped for this field type.
     * Set true for purely presentational fields (pagebreak, html) that carry
     * no user input. Collected into window.ForgeSkipValidation by Assets.
     */
    public function skipValidation(): bool
    {
        return false;
    }

    /**
     * Default config values for the builder.
     * Subclasses should merge their own keys.
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
     * Placeholder + description entries shared by most fields.
     * Call from getGeneralSchema() to include them.
     */
    protected function baseGeneralEntries(): array
    {
        return [
            ['key' => 'placeholder', 'type' => 'text', 'label' => 'Platzhalter'],
            ['key' => 'description', 'type' => 'text', 'label' => 'Beschreibung'],
        ];
    }

    /**
     * Settings shown in the General tab.
     * label / required / hide_label are always rendered by the JS and must NOT appear here.
     */
    public function getGeneralSchema(): array
    {
        return $this->baseGeneralEntries();
    }

    /**
     * Settings shown in the Advanced (Erweitert) tab.
     */
    public function getAdvancedSchema(): array
    {
        return [];
    }

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
     * Build standard wrapper HTML around a field's inner content.
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

    protected function inputAttrs(array $config, string $field_id, string $type = 'text', array $extra = []): string
    {
        $attrs = array_merge([
            'type'        => $type,
            'id'          => $field_id,
            'name'        => $field_id,
            'placeholder' => $config['placeholder'] ?? '',
            'class'       => 'forge-input',
        ], $extra);

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
