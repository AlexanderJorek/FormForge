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
     * Whether this field acts as a page-break marker in multi-page forms.
     *
     * FormRenderer uses this to emit page-navigation HTML and page <div> wrappers
     * instead of calling render(). Only PageBreakField returns true.
     *
     * @return bool
     */
    public function isPageBreak(): bool
    {
        return false;
    }

    /**
     * Page-navigation markup for a page-break field. Only called when
     * isPageBreak() returns true; PageBreakField overrides this.
     *
     * @param array $config Field configuration.
     * @param int   $page   The page number being closed/opened.
     *
     * @return string
     */
    public function renderBreak(array $config, int $page): string
    {
        return '';
    }

    /**
     * Whether this field is a group container whose children are rendered inline.
     *
     * FormRenderer uses this to call openTag()/closeTag() and recurse into children
     * instead of calling render(). Only GroupField returns true.
     *
     * @return bool
     */
    public function isGroupContainer(): bool
    {
        return false;
    }

    /**
     * Opening wrapper markup for a group container field. Only called when
     * isGroupContainer() returns true; group field classes override this.
     *
     * @param array  $config   Field configuration.
     * @param string $field_id Resolved field identifier.
     *
     * @return string
     */
    public function openTag(array $config, string $field_id): string
    {
        return '';
    }

    /**
     * Closing wrapper markup for a group container field. Only called when
     * isGroupContainer() returns true; group field classes override this.
     *
     * @return string
     */
    public function closeTag(): string
    {
        return '';
    }

    /**
     * Whether this field requires multipart/form-data encoding on the form element.
     *
     * FormRenderer checks all fields and sets enctype="multipart/form-data" when any
     * returns true. Only UploadField returns true.
     *
     * @return bool
     */
    public function needsMultipartEncoding(): bool
    {
        return false;
    }

    /**
     * Enqueues any front-end scripts required by this field type.
     *
     * Called once per unique field type present in the form, before rendering.
     * Override to call wp_enqueue_script() for third-party libraries (e.g. reCAPTCHA).
     *
     * @return void
     */
    public function enqueueFrontScripts(): void
    {
    }

    /**
     * Whether this field's entry is included in the {all_fields} email summary block.
     *
     * MailSender skips entries where this returns false. Override in layout-only
     * fields (HtmlField, PageBreakField) that carry no user-submitted value.
     *
     * @return bool
     */
    public function includeInEmailSummary(): bool
    {
        return true;
    }

    /**
     * Whether this field's value is included in the HMAC integrity seal.
     *
     * Generator::buildSealFields() sets the value to '' when this returns false.
     * Override in fields whose value is a data URI or binary blob that must be
     * excluded from the seal text (e.g. SignatureField).
     *
     * @return bool
     */
    public function includeValueInSeal(): bool
    {
        return true;
    }

    /**
     * Whether this field represents a plain-text value suitable for PDF preview tokens.
     *
     * PDFLayoutEditor uses this to filter dummy fields for the token-picker preview,
     * keeping only fields whose value can be represented as a short text string.
     * Override to true in text-like fields (TextField, EmailField, TextareaField).
     *
     * @return bool
     */
    public function hasTextPreview(): bool
    {
        return false;
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
     * Sanitizes a raw value already extracted from a group copy array.
     *
     * Group fields submit as $_POST[$group_id][$copy_idx][$child_id], so
     * FormProcessor reads the nested copy slice first, then calls this method
     * on the child handler to sanitize correctly — the same way top-level
     * fields use extractValue() but without direct $_POST/$_FILES access.
     * The default handles both scalar (sanitize_text_field) and flat arrays.
     * Override in fields that need a different sanitizer (textarea) or always
     * return an array (checkbox).
     *
     * @param mixed $raw The raw value from the group copy array.
     *
     * @return mixed
     */
    public function extractFromRaw(mixed $raw): mixed
    {
        if (is_array($raw)) {
            return array_map(static fn($v) => sanitize_text_field(wp_unslash($v)), $raw);
        }
        return sanitize_text_field(wp_unslash((string)$raw));
    }

    /**
     * Extracts the submitted value for this field from $_POST or $_FILES.
     *
     * Called by FormProcessor before validate(). The default reads a single text value from
     * $_POST and sanitizes it with sanitize_text_field(). Override in fields whose value shape
     * differs: array POST keys (name, address, checkbox), textarea sanitization, $_FILES
     * (upload), or composite keys (sepa uses $field_id . '-sig' for the signature canvas).
     *
     * @param string $field_id The field element ID.
     *
     * @return mixed
     */
    public function extractValue(string $field_id): mixed
    {
        return isset($_POST[$field_id]) ? sanitize_text_field(wp_unslash($_POST[$field_id])) : '';
    }

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
            $label = $config['label'] ?? __('Field', 'form-forge');
            return sprintf(__('%s is a required field.', 'form-forge'), esc_html($label));
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
            return __('[No entry]', 'form-forge');
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
            [
                'key'   => 'placeholder',
                'type'  => 'text',
                'label' => __('Placeholder', 'form-forge'),
            ],
            [
                'key'   => 'description',
                'type'  => 'text',
                'label' => __('Description', 'form-forge'),
            ],
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
     * Sanitizes a single string config value for this field type.
     * Override in subclasses that need a different allowlist (e.g. HtmlField).
     *
     * @param string $key   Config key.
     * @param string $value Raw value.
     *
     * @return string
     */
    public function sanitizeConfigValue(string $key, string $value): string
    {
        return \wp_kses_post($value);
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
     * Returns what the Generator needs to render this field in the PDF.
     *
     * The default is correct for every plain text field: show the escaped
     * value as a labeled row in the main body, no images or attachments.
     * Only override this when your field needs raw HTML, attaches a file,
     * or should appear in the media section below the text fields.
     * Use $this->pdf($field) to build the return value — see _ExampleField.php.
     *
     * @param array $field Normalized entry from FieldRegistry::mapSubmission().
     *
     * @return array PDF render descriptor.
     */
    public function pdfData(array $field): array
    {
        return $this->pdf($field)->build();
    }

    /**
     * Creates a PdfDescriptor pre-filled with this field's escaped text value.
     * Chain methods on it, then call ->build() to get the array pdfData() returns.
     *
     * @param array $field Normalized entry from FieldRegistry::mapSubmission().
     *
     * @return \ForgeForms\PDF\PdfDescriptor
     */
    protected function pdf(array $field): \ForgeForms\PDF\PdfDescriptor
    {
        return new \ForgeForms\PDF\PdfDescriptor(
            esc_html((string)($field['value'] ?? ''))
        );
    }

    /**
     * Maps the field's submitted value to one or more normalized output entries.
     *
     * Returns array<string, array> keyed by output key → normalized entry.
     * Default wraps map() in a single entry keyed by $field_id.
     * Override for multi-entry fields (SEPA) or fields that materialize files.
     *
     * $context carries: ['files' => $_FILES subset, 'raw_values' => raw POST values]
     *
     * @param string $field_id Field identifier.
     * @param string $label    Field label.
     * @param mixed  $value    Raw submitted value.
     * @param array  $config   Field configuration.
     * @param array  $context  Submission context.
     *
     * @return array<string, array>
     */
    public function mapNormalized(
        string $field_id,
        string $label,
        mixed $value,
        array $config,
        array $context
    ): array {
        return [$field_id => [
            'label' => $label,
            'type'  => $config['type'] ?? '',
            'value' => $this->map($value, $config),
        ]];
    }

    /**
     * Materializes a base64 data-URI signature into a file descriptor array.
     *
     * @param mixed  $value    Raw signature value (data: URI).
     * @param string $filename Output filename hint.
     *
     * @return array File descriptor array, or empty array if invalid.
     */
    protected static function materializeSignature(
        mixed $value,
        string $filename = 'signature.png'
    ): array {
        if (empty($value) || !str_starts_with((string)$value, 'data:image/')) {
            return [];
        }
        $b64 = preg_replace('#^data:image/[^;]+;base64,#', '', (string)$value);
        $b64 = str_replace(' ', '+', $b64);
        $b64 = preg_replace('/[^A-Za-z0-9+\/=]/', '', $b64);
        $pad = strlen($b64) % 4;
        if ($pad) {
            $b64 .= str_repeat('=', 4 - $pad);
        }
        $binary = base64_decode($b64, true);
        if ($binary === false) {
            return [];
        }
        if (str_starts_with($binary, "\x89PNG")) {
            $mime = 'image/png';
        } elseif (str_starts_with($binary, "\xff\xd8")) {
            $mime = 'image/jpeg';
        } else {
            return [];
        }
        return [[
            'name'   => $filename,
            'mime'   => $mime,
            'size'   => strlen($binary),
            'sha256' => hash('sha256', $binary),
            'base64' => base64_encode($binary),
        ]];
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
        $desc_html   = $description !== '' ? '<p class="forge-field-description">' . $description . '</p>' : '';

        $label_html = (!$hide_label && $label !== '') ? '<label class="forge-label" for="' . esc_attr($field_id) . '">' . $label . $req_attr . '</label>' : '';

        $client_rules  = $this->getClientValidation();
        $validate_attr = !empty($client_rules) ? ' data-validate="' . esc_attr(wp_json_encode(array_column($client_rules, 'rule'))) . '"' : '';

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
            ],
            $extra
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
