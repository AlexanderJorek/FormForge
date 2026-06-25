<?php

/**
 * @package   FormForge
 * @copyright 2026 Alexander Jorek
 * @license   GPL-2.0-or-later
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
 *    tells you exactly what to add and where.
 *
 * Everything else (validate, map, schema, client validation, frontend init,
 * skip-validation flag) has a safe default in BaseField and only needs
 * overriding when you want custom behaviour.
 *
 * ARCHITECTURE SUMMARY
 * ─────────────────────
 * Every field type owns ALL of its traits in one PHP file:
 *
 *   render()               → frontend HTML
 *   getStyles()            → field-specific CSS injected inline on the page
 *   getClientInit()        → frontend JS init / interaction  (→ window.ForgeFieldInits)
 *   getClientEmptyCheck()  → frontend "is field blank?" fn   (→ window.ForgeEmptyChecks)
 *   getClientValidation()  → frontend format validators      (→ window.ForgeValidators)
 *   skipValidation()       → true for purely presentational fields (pagebreak, html)
 *   getGeneralSchema()     → builder General-tab settings UI
 *   getAdvancedSchema()    → builder Advanced-tab settings UI
 *   hasSettingsPanel()     → false hides the panel entirely (default true)
 *   hasRequired()          → false hides the Required checkbox (default true)
 *   getDefaultConfig()     → initial config when dropped onto canvas
 *   validate()             → server-side value validation
 *   map()                  → value → display string for email / PDF
 *
 * Assets::enqueueFront() collects getStyles() + the three client-* methods
 * and injects them inline before front.js loads. front.js itself contains
 * zero field-specific logic.
 */

namespace ForgeForms\Fields;

defined('ABSPATH') || exit;

class ExampleField extends BaseField
{
    // ═══════════════════════════════════════════════════════
    //  MANDATORY — these three must always be implemented
    // ═══════════════════════════════════════════════════════

    /** Label shown in the field palette and the builder panel header. */
    public function getLabel(): string
    {
        return 'Beispielfeld';
    }

    /** Font Awesome 6 class, e.g. 'fa-solid fa-star'. Shown as the palette tile icon. */
    public function getIcon(): string
    {
        return 'fa-solid fa-star';
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
    //  RENDER — build the frontend HTML
    // ═══════════════════════════════════════════════════════

    /**
     * @param array  $config    Saved field config merged with getDefaultConfig() defaults.
     * @param string $field_id  Unique element id/name, e.g. "field-3".
     * @param mixed  $value     Pre-filled value when re-displaying after a server error.
     */
    public function render(array $config, string $field_id, mixed $value = null): string
    {
        // ── Simple single input ──────────────────────────────────────────
        // inputAttrs() builds: id, name, class="forge-input", placeholder,
        // required + aria-required. Pass any extra HTML attributes as the 4th array.
        $attrs = $this->inputAttrs($config, $field_id, 'text', [
            'value'     => esc_attr((string)($value ?? '')),
            'maxlength' => (int)($config['maxlength'] ?? 0) ?: false,  // false = attribute omitted
        ]);

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
            $req      = !empty($config[$k . '_required']) ? ' required aria-required="true"' : '';
            $req_star = !empty($config[$k . '_required'])
                ? ' <span class="forge-required" aria-hidden="true">*</span>' : '';
            $inner .= '<div class="forge-example-sub">';
            $inner .= '<label class="forge-sub-label">' . $label . $req_star . '</label>';
            $inner .= '<input type="text" name="' . esc_attr($field_id) . '[' . $k . ']"'
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
    //  VALIDATE — server-side, runs on form submission
    // ═══════════════════════════════════════════════════════
    // Default (BaseField): handles the generic required check automatically.
    // Override only to add format rules on top.
    // Return true = OK, return string = user-facing error message.

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
    //  MAP — value → readable string for email / PDF
    // ═══════════════════════════════════════════════════════
    // Default (BaseField): casts to string, returns '[Kein Eintrag]' when empty.
    // Override for composite (array) values or custom formatting.

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
    //  FRONTEND INIT — interaction logic for this field type
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
    //  Return '' (default) when no frontend init is needed.

    public function getClientInit(): string
    {
        return ''; // no frontend interaction needed

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
    //  CLIENT VALIDATION — two separate concerns
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

    public function getClientEmptyCheck(): array
    {
        return []; // generic fallback: first visible input non-empty

        // Override example — field is empty when no checkbox is checked:
        // return ['fn' => "function(f){ return !f.querySelector('input[type=\"checkbox\"]:checked'); }"];
    }

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
    //  SKIP VALIDATION FLAG — presentational fields only
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

    public function getDefaultConfig(): array
    {
        return array_merge(parent::getDefaultConfig(), [
            'maxlength' => '',
            'my_toggle' => false,
            'my_select' => 'option-a',
        ]);
    }


    // ═══════════════════════════════════════════════════════
    //  BUILDER SCHEMA — what the right panel renders
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

    public function getGeneralSchema(): array
    {
        return array_merge($this->baseGeneralEntries(), [ // placeholder + description

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
        ]);
    }

    // Advanced tab — return [] to hide the tab entirely.
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
