<?php

/**
 * Example/template field showing the minimal field implementation.
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
 * ════════════════════════════════════════════════════════════
 *  HOW TO ADD A NEW FIELD — read this file top to bottom
 * ════════════════════════════════════════════════════════════
 *
 * This file is a teaching document. It is intentionally NOT
 * registered in FieldRegistry, so it has zero runtime effect.
 *
 * QUICK-START (3 steps)
 * ──────────────────────
 * 1. Copy this file → YourField.php, rename the class.
 * 2. Implement the three mandatory methods (getLabel, getIcon, render).
 * 3. Register in FieldRegistry::registerDefaults() — the comment there
 *    tells you exactly what to add and where. The first argument is the
 *    type key — it becomes the field type used throughout the builder,
 *    frontend, and stored form configs.
 *
 * Everything else (validate, map, settings schema, client-side validation,
 * client-side init, skip validation) has a sensible default in BaseField
 * and only needs overriding when you want custom behaviour.
 *
 * ARCHITECTURE SUMMARY
 * ─────────────────────
 * Every field type encapsulates all of its behavior in one PHP file:
 *
 *   render()               → frontend HTML
 *   getStyles()            → field-specific CSS injected inline on the page
 *   getClientInit()        → client-side init / interaction
 *                             (→ window.ForgeFieldInits)
 *   getClientEmptyCheck()  → client-side empty check
 *                             (→ window.ForgeEmptyChecks)
 *   getClientValidation()  → client-side format validators
 *                             (→ window.ForgeValidators)
 *   skipValidation()       → true for purely presentational fields (pagebreak, html)
 *   getGeneralSchema()     → settings schema for the General tab
 *   getAdvancedSchema()    → settings schema for the Advanced tab
 *   hasSettingsPanel()     → false hides the panel entirely (default true)
 *   hasRequired()          → false hides the Required checkbox (default true)
 *   getDefaultConfig()     → initial config when dropped onto canvas
 *   validate()             → server-side validation, runs after form submission
 *   map()                  → value → human-readable string for email / PDF
 *   mapNormalized()        → value → normalized output entries for PDF/email
 *                            (override for file-bearing or multi-entry fields)
 *
 * Assets::enqueueFront() collects getStyles() and the three client-side
 * callback methods and injects them inline before front.js loads.
 * front.js itself contains zero field-specific logic.
 *
 * WHEN TO OVERRIDE
 * ─────────────────
 * You are building...               Override
 * ──────────────────────────────────────────────────────────────────────
 * Any field (always required)       getLabel(), getIcon(), render()
 * Custom CSS                        getStyles()
 * Interactive widget                getClientInit()
 * Custom "blank" check              getClientEmptyCheck()
 * Format validation                 validate() + getClientValidation()
 * Composite or formatted value      map()
 * File-bearing or multi-entry       mapNormalized()
 * Custom PDF output                 pdfData()
 * Presentational / no user input    skipValidation()
 */

namespace ForgeForms\Fields;

defined('ABSPATH') || exit;

/**
 * Template/example field demonstrating the full field implementation pattern.
 */
class ExampleField extends BaseField
{
    // ═══════════════════════════════════════════════════════
    //  MANDATORY — getLabel(), getIcon(), and render() must
    //  always be implemented. render() has its own section
    //  below with usage examples.
    // ═══════════════════════════════════════════════════════

    /**
     * Returns the label shown in the field palette and builder panel header.
     *
     * @return string
     */
    public function getLabel(): string
    {
        return 'Beispielfeld';
    }

    /**
     * Returns the Font Awesome 6 class shown as the palette tile icon.
     *
     * @return string
     */
    public function getIcon(): string
    {
        return 'fa-solid fa-star';
    }


    // ═══════════════════════════════════════════════════════
    //  RENDER — build the frontend HTML
    // ═══════════════════════════════════════════════════════

    /**
     * Renders the field HTML for frontend display.
     *
     * @param array  $config   Field config merged with getDefaultConfig() defaults.
     * @param string $field_id Unique element id/name, e.g. "field-3".
     * @param mixed  $value    Pre-filled value when re-displaying after a server error.
     *
     * @return string
     */
    public function render(array $config, string $field_id, mixed $value = null): string
    {
        // ── Simple single input ──────────────────────────────────────────
        // inputAttrs() builds: id, name, class="forge-input", placeholder,
        // required + aria-required. Pass extra HTML attributes as 4th array.
        $attrs = $this->inputAttrs(
            $config, $field_id, 'text', [
            'value'     => esc_attr((string)($value ?? '')),
            'maxlength' => (int)($config['maxlength'] ?? 0) ?: false,
            ]
        );

        // wrap() adds: outer .forge-field div, label, description, error div,
        // required class/asterisk, and the data-validate attribute automatically.
        return $this->wrap($field_id, $config, '<input' . $attrs . '>');


        // ── Composite field (multiple sub-inputs, each with its own required) ──
        // Use this pattern when each sub-part can independently be required
        // (like Address / Name in expanded mode).
        //
        // Rules:
        //  • Put `required` HTML attr on each individual <input>/<select>
        //  • Add a .forge-field-error.forge-sub-error div after each input
        //    (front.js will write the error message there)
        //  • Add $req_star so the label shows a red *
        //  • Pass $wrapper_config with required=false to wrap() so the
        //    global * on the field label is suppressed
        /*
        $inner = '<div class="forge-example-group">';
        foreach (['part_a', 'part_b'] as $k) {
            $label    = esc_html($config[$k . '_label'] ?? $k);
            $req      = !empty($config[$k . '_required'])
                ? ' required aria-required="true"' : '';
            $req_star = !empty($config[$k . '_required'])
                ? ' <span class="forge-required" aria-hidden="true">*</span>'
                : '';
            $inner .= '<div class="forge-example-sub">';
            $inner .= '<label class="forge-sub-label">'
                . $label . $req_star . '</label>';
            $inner .= '<input type="text"'
                . ' name="' . esc_attr($field_id) . '[' . $k . ']"'
                . ' class="forge-input"' . $req . '>';
            $inner .= '<div class="forge-field-error forge-sub-error"></div>';
            $inner .= '</div>';
        }
        $inner .= '</div>';
        $wrapper_config             = $config;
        $wrapper_config['required'] = false;
        return $this->wrap($field_id, $wrapper_config, $inner);
        */
    }


    // ═══════════════════════════════════════════════════════
    //  STYLES — field-specific CSS injected on the page
    // ═══════════════════════════════════════════════════════
    //
    //  Return a raw CSS string (no <style> tags). Assets::enqueueFront()
    //  collects all non-empty getStyles() returns and emits them as a single
    //  wp_add_inline_style call after front.css loads, so CSS variables and
    //  the .forge-input base rules are already defined and available here.
    //
    //  Use a nowdoc (<<<'CSS' ... CSS) so the content is literal — no PHP
    //  variable expansion, no accidental escaping of $ or \.
    //
    //  Include @media blocks inside the same string when needed.
    //  Return '' (default from BaseField) when no custom CSS is required.

    /**
     * Returns field-specific CSS injected inline on pages that load this form.
     *
     * @return string
     */
    public function getStyles(): string
    {
        return ''; // no custom CSS needed for this field

        // Override example — custom wrapper with responsive behaviour:
        /*
        return <<<'CSS'
        .forge-example-group {
            display: flex;
            gap: 10px;
        }
        .forge-example-sub {
            flex: 1;
            min-width: 100px;
        }
        @media (max-width: 600px) {
            .forge-example-group { flex-direction: column; }
        }
        CSS;
        */
    }


    // ═══════════════════════════════════════════════════════
    //  VALIDATE — server-side validation, runs after form submission
    // ═══════════════════════════════════════════════════════
    // Default (BaseField): handles the generic required check automatically.
    // Override only to add format rules on top.
    // Return true = OK, return string = user-facing error message.

    /**
     * Validates the submitted value (required check + five-digit format).
     *
     * @param mixed $value  The submitted value.
     * @param array $config Field configuration array.
     *
     * @return bool|string
     */
    public function validate(mixed $value, array $config): bool|string
    {
        $base = parent::validate($value, $config); // required check
        if ($base !== true) {
            return $base;
        }
        if ($this->isEmpty($value)) {
            return true; // optional + empty → skip format check
        }

        if (!preg_match('/^\d{5}$/', (string)$value)) {
            return 'Bitte geben Sie eine fünfstellige Zahl ein.';
        }
        return true;
    }


    // ═══════════════════════════════════════════════════════
    //  MAP — value → human-readable string for email / PDF
    // ═══════════════════════════════════════════════════════
    //
    //  map() — Override for composite (array) values or custom formatting.
    //  Default (BaseField): casts to string, returns '[Kein Eintrag]' when empty.
    //
    //  mapNormalized() — Override when your field needs any of:
    //    • Multiple normalized output entries
    //      (e.g. SEPA expands to IBAN + BIC + Kontoinhaber)
    //    • File materialization
    //      (upload reads $_FILES, signature decodes base64)
    //    • Custom output keys (default is [$field_id => entry])
    //
    //  Signature:
    //    mapNormalized(field_id, label, value, config, context)
    //    : array<key, entry>
    //  $context: ['files' => $_FILES subset, 'raw_values' => raw POST]
    //  Entry shape:
    //    ['label' => ..., 'type' => ..., 'value' => ...,
    //     'materialized_files' => []]
    //  Return [] to emit no normalized output entries (PageBreakField,
    //  empty HtmlField).
    //
    //  BaseField default wraps map() in a single entry — override only
    //  when needed.

    /**
     * Maps the submitted value to a human-readable string for email/PDF.
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
        if (is_array($value)) {
            return implode(', ', array_filter(array_map('trim', $value)));
        }
        return (string)$value;
    }


    // ═══════════════════════════════════════════════════════
    //  CLIENT-SIDE INIT — interaction logic for this field type
    // ═══════════════════════════════════════════════════════
    //
    //  Return a JS function string: function(root) { ... }
    //  Assets::enqueueFront() collects these into window.ForgeFieldInits
    //  keyed by field type. front.js calls each with the container root
    //  element — no field-specific knowledge lives in front.js.
    //
    //  Use querySelectorAll / addEventListener directly. The on() helper
    //  in front.js is scoped to its IIFE and is NOT available here.
    //
    //  Return '' (default) when no client-side init is needed.

    /**
     * Returns the client-side initialisation script for this field type.
     *
     * @return string
     */
    public function getClientInit(): string
    {
        return ''; // no client-side interaction needed

        // Override example — attach a click handler to every widget instance:
        /*
        return <<<'JS'
        function (root) {
            root.querySelectorAll('.forge-example-widget').forEach(function (widget) {
                widget.addEventListener('click', function () {
                    // ... interaction logic
                });
            });
        }
        JS;
        */
    }


    // ═══════════════════════════════════════════════════════
    //  CLIENT-SIDE VALIDATION — two separate concerns
    // ═══════════════════════════════════════════════════════
    //
    //  getClientEmptyCheck()  →  IS THE FIELD BLANK?
    //    Used by the required check. Only override when "blank" is not
    //    "first visible input has no value" — e.g. a checkbox group is blank
    //    when no checkbox is checked, not when the input value is ''.
    //    Return [] to use the generic fallback.
    //
    //  getClientValidation()  →  IS THE VALUE IN THE RIGHT FORMAT?
    //    Only runs when the field already HAS content.
    //    Define format rules here (email regex, IBAN check, etc.).
    //    Return [] if no format check is needed (plain text, dropdowns, etc.).
    //
    // Both are collected by Assets::enqueueFront() and injected as
    // window.ForgeEmptyChecks / window.ForgeValidators before front.js loads.
    // front.js is a pure runner — zero field-specific logic lives there.

    /**
     * Returns the client-side empty-check function for this field type.
     *
     * @return array
     */
    public function getClientEmptyCheck(): array
    {
        return []; // generic fallback: first visible input non-empty

        // Override example — field is empty when no checkbox is checked:
        // return ['fn' => "function(f){ return !f.querySelector('input[type=\"checkbox\"]:checked'); }"];
    }

    /**
     * Returns client-side format validation rules for this field type.
     *
     * @return array
     */
    public function getClientValidation(): array
    {
        return []; // no format validation needed

        // Override example — five-digit number:
        /*
        return [['rule' => 'example-zip', 'fn' => <<<'JS'
            function (fieldEl) {
                var inp = fieldEl.querySelector('input');
                if (!inp || !inp.value.trim()) return null;
                return /^\d{5}$/.test(inp.value.trim())
                    ? null : 'Bitte geben Sie eine fünfstellige Zahl ein.';
            }
            JS]];
        */
        // Rule keys must be globally unique — prefix with the field type if unsure.
        // The function receives the outer .forge-field wrapper element, not the input.
        // Return null = valid, return string = error message shown below the field.
    }


    // ═══════════════════════════════════════════════════════
    //  PDF DATA — pdfData() — how this field appears in the generated PDF
    // ═══════════════════════════════════════════════════════
    //
    //  pdfData() tells the Generator what to put in the PDF for this field.
    //  You almost certainly do NOT need to implement this — BaseField's default
    //  handles every plain text field correctly out of the box:
    //
    //    • Shows the field label and escaped value as a standard row
    //    • Puts it in the main body of the PDF
    //    • Includes no images or file attachments
    //
    //  Only override when your field needs one of these two things:
    //    1. The value is raw HTML, not plain text  →  see HtmlField
    //    2. The field embeds an image in the PDF  →  see SignatureField / UploadField
    //       (non-image uploads appear as filename text only;
    //        only attachImage() exists — there is no attachPdf())
    //
    //  $this->pdf($field) returns a PdfDescriptor. Chain methods, then build():
    //
    //  ->text(string $escaped)
    //    Replace the default cell text (pre-escaped value) with something else.
    //    Pass '' to suppress it entirely (e.g. signature — image speaks for itself).
    //
    //  ->rawHtml(string $html)
    //    Use when the value itself IS trusted HTML (HtmlField).
    //
    //  ->unlabeled()
    //    Suppress the label row — only for HTML blocks without a heading.
    //
    //  ->attachImage(string $binary, string $filename, string $mime = 'image/png')
    //    Embeds the image after the cell text and records its perceptual hash
    //    in the HMAC seal. TIFF is auto-converted to PNG.
    //    PdfUtils::thumbnailHash($binary) is used internally — no manual call needed.
    //
    //  ->build()
    //    Returns the array Generator consumes. Always call last.

    // Example — a field that also renders a QR code image of its value:
    /*
    public function pdfData(array $field): array
    {
        $binary = $this->generateQrPng((string)($field['value'] ?? ''));
        return $this->pdf($field)
            ->attachImage($binary, 'qr.png')
            ->build();
    }
    */

    // Example — a field whose value is raw HTML (like HtmlField):
    /*
    public function pdfData(array $field): array
    {
        return $this->pdf($field)
            ->rawHtml((string)($field['value'] ?? ''))
            ->build();
    }
    */


    // ═══════════════════════════════════════════════════════
    //  SKIP VALIDATION — presentational fields only
    // ═══════════════════════════════════════════════════════
    //
    //  Return true for fields that carry no user input and should never be
    //  evaluated by validatePage() — e.g. PageBreakField, HtmlField.
    //  Assets::enqueueFront() collects these into window.ForgeSkipValidation.
    //  Default (BaseField): false. Almost every field should leave this alone.

    // public function skipValidation(): bool { return true; }


    // ═══════════════════════════════════════════════════════
    //  DEFAULT CONFIG — every config key the field uses
    // ═══════════════════════════════════════════════════════
    // The builder reads this when a new field is dropped onto the canvas.
    // Always merge with parent to inherit: label, required, hide_label,
    // placeholder, description.

    /**
     * Returns default config values for the builder canvas.
     *
     * @return array
     */
    public function getDefaultConfig(): array
    {
        return array_merge(
            parent::getDefaultConfig(), [
            'maxlength' => '',
            'my_toggle' => false,
            'my_select' => 'option-a',
            ]
        );
    }


    // ═══════════════════════════════════════════════════════
    //  SETTINGS SCHEMA — what the builder's right-hand panel renders
    // ═══════════════════════════════════════════════════════
    //
    //  ┌──────────────────┬──────────────────────────────────────────────────────┐
    //  │ type             │ renders as                                           │
    //  ├──────────────────┼──────────────────────────────────────────────────────┤
    //  │ text             │ single-line text input                               │
    //  │ textarea         │ multi-line textarea                                  │
    //  │ number           │ numeric input                                        │
    //  │ checkbox         │ single toggle checkbox                               │
    //  │ bool_seg         │ two-button segmented switch (false_label/true_label) │
    //  │ pill3            │ three-way pill (values[] + labels[] required)        │
    //  │ options_list     │ editable draggable option list (select/radio/chk)    │
    //  │ icon_row         │ icon picker row (options[] required)                 │
    //  │ limit_row        │ char/word limit row (count_key required)             │
    //  │ subfields        │ sub-field configurator (items[] required)            │
    //  │ rating_preview   │ live star-rating preview widget (no extra keys)      │
    //  │ html_editor      │ raw HTML textarea (for HtmlField)                    │
    //  │ media_upload     │ WP media library picker + thumbnail preview          │
    //  │ notice           │ informational banner (level: 'info'|'warning'|'error', text) │
    //  └──────────────────┴──────────────────────────────────────────────────────┘
    //
    //  Optional keys on any entry:
    //    hint       → small grey hint text shown below the control
    //    rebuild    => true  → re-renders the canvas preview when value changes
    //    depends_on => ['key' => value]   → hide entry unless another key equals value
    //    depends_on => ['key' => 'x', 'not' => 'y']  → hide when key equals 'y'
    //
    //  IMPORTANT: label / required / hide_label are rendered automatically by
    //  the builder JS and must NOT appear in any schema array.

    /**
     * Returns the settings schema for the General tab.
     *
     * @return array
     */
    public function getGeneralSchema(): array
    {
        return array_merge(
            $this->baseGeneralEntries(), [ // placeholder + description

            ['key' => 'my_toggle', 'type' => 'bool_seg', 'label' => 'Modus',
             'false_label' => 'Einfach', 'true_label' => 'Erweitert', 'rebuild' => true],

            ['key' => 'maxlength', 'type' => 'number', 'label' => 'Zeichenlimit',
             'hint' => 'Leer = kein Limit', 'depends_on' => ['my_toggle' => true]],

            ['key' => 'my_select', 'type' => 'pill3', 'label' => 'Filtertyp',
             'values' => ['option-a', 'option-b', 'option-c'],
             'labels' => ['Aus',      'Erlaubt',  'Gesperrt']],

            // Media upload example — stores a URL string:
            // ['key' => 'icon_url', 'type' => 'media_upload', 'label' => 'Icon'],

            // Notice (no key — display only, no config value):
            // ['type' => 'notice', 'level' => 'warning', 'text' => 'Hinweis…'],
            ]
        );
    }

    /**
     * Returns the settings schema for the Advanced tab.
     *
     * @return array
     */
    public function getAdvancedSchema(): array
    {
        return [
            ['key'        => 'maxlength',
             'type'       => 'textarea',
             'label'      => 'Muster',
             'hint'       => 'Eines pro Zeile',
             'depends_on' => ['key' => 'my_select', 'not' => 'option-a']], // visible unless my_select = 'option-a'
        ];
    }
}
