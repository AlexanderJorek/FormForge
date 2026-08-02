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
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GPL-3.0-or-later
 * @version   1.0.0
 * @link      https://github.com/AlexanderJorek/FormForge
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 3
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
     * Caps an array-valued raw POST value to at most $max_keys entries before
     * it is handed to map_deep()/sanitize_text_field(). Composite fields (Name,
     * Address, SEPA, ...) only ever read a handful of known subfield keys back
     * out, so an attacker submitting a much larger array as $field_id[...] would
     * otherwise cost an unbounded number of sanitize calls before that read
     * happens — this must run BEFORE any map_deep()/recursive-sanitize pass,
     * not after, or the uncapped array has already been walked in full.
     *
     * @param mixed $raw      Raw (still wp_unslash()-only) POST value.
     * @param int   $max_keys Maximum number of array entries to keep.
     *
     * @return mixed The original scalar, or the array truncated to $max_keys entries.
     */
    protected static function capRawArray(mixed $raw, int $max_keys = 32): mixed
    {
        if (!is_array($raw)) {
            return $raw;
        }
        return array_slice($raw, 0, $max_keys, true);
    }

    /**
     * Hard security ceiling for the "Other" free-text companion value —
     * independent of (and always at least as large as) the admin-configurable
     * other_max_length. This must stay comfortably above any realistic
     * configured limit: capOtherText() truncates before validateOtherText()
     * ever runs, so if the hard cap were smaller than a configured limit, the
     * configured "too long" error could never fire — the value would already
     * be silently cut, not rejected. otherInputAttrs()/validateOtherText()
     * both clamp the configured limit to this same ceiling so the two checks
     * can never disagree.
     */
    private const OTHER_TEXT_HARD_CAP = 5000;

    /**
     * Shared cap for the free-text companion value of a "___other___" option
     * (Select/Radio/Checkbox fields). Kept as one helper so the limit can't
     * drift between the near-identical extractValue()/extractFromRaw()
     * implementations of those three field types.
     *
     * @param mixed $raw Raw (still wp_unslash()-only) "_other" POST value.
     *
     * @return string Sanitized value truncated to self::OTHER_TEXT_HARD_CAP characters.
     */
    protected static function capOtherText(mixed $raw): string
    {
        return mb_substr(sanitize_text_field(wp_unslash($raw)), 0, self::OTHER_TEXT_HARD_CAP);
    }

    /**
     * Returns the HTML attribute enforcing the configurable "Other" text limit
     * (Select/Radio/Checkbox fields), mirroring TextField's char/word limit UI.
     * Purely a client-side hint — validateOtherText() is the server-side backstop.
     *
     * @param array $config Field configuration.
     *
     * @return string HTML attribute string (possibly empty).
     */
    protected static function otherInputAttrs(array $config): string
    {
        $max = self::clampOtherMax((int)($config['other_max_length'] ?? 0));
        if ($max <= 0) {
            return '';
        }
        $type = $config['other_max_type'] ?? 'chars';
        return $type === 'words' ? ' data-word-limit="' . $max . '"' : ' maxlength="' . $max . '"';
    }

    /**
     * Validates the "Other" free-text companion value against the configurable
     * other_max_type/other_max_length limit (Select/Radio/Checkbox fields).
     *
     * @param string $other  The trimmed "Other" text value.
     * @param array  $config Field configuration.
     *
     * @return bool|string True on valid, error message string on invalid.
     */
    protected static function validateOtherText(string $other, array $config): bool|string
    {
        $max = self::clampOtherMax((int)($config['other_max_length'] ?? 0));
        if ($max <= 0 || $other === '') {
            return true;
        }
        if (($config['other_max_type'] ?? 'chars') === 'words') {
            $count = count(preg_split('/\s+/', trim($other), -1, PREG_SPLIT_NO_EMPTY));
            if ($count > $max) {
                // translators: %1$d: maximum word count allowed, %2$d: current word count.
                return sprintf(__('Please enter at most %1$d words for "Other" (currently: %2$d).', 'form-forge'), $max, $count);
            }
            return true;
        }
        $length = function_exists('mb_strlen') ? mb_strlen($other) : strlen($other);
        if ($length > $max) {
            // translators: %1$d: maximum character count allowed, %2$d: current character count.
            return sprintf(__('Please enter at most %1$d characters for "Other" (currently: %2$d).', 'form-forge'), $max, $length);
        }
        return true;
    }

    /**
     * Clamps an admin-configured other_max_length to self::OTHER_TEXT_HARD_CAP
     * so a config value above the hard cap can never make validateOtherText()
     * expect text longer than what capOtherText() would have already truncated.
     *
     * @param int $configured Admin-configured limit (0/negative = unlimited).
     *
     * @return int
     */
    private static function clampOtherMax(int $configured): int
    {
        return $configured > 0 ? min($configured, self::OTHER_TEXT_HARD_CAP) : $configured;
    }

    /**
     * Hard security ceiling for Text/Textarea field content, applied even when
     * the admin has left limit_max unset (0 = "no limit"). Unlike
     * OTHER_TEXT_HARD_CAP, this exists to bound worst-case PDF-generation and
     * PDF-verification cost — decompressed text volume drives the verifier's
     * parse-time budget (see Verificationpage.php) independently of the final
     * PDF's byte size, since text compresses well but must still be fully
     * decompressed and regex-scanned during verification. 100k characters is
     * generous for any legitimate single-field answer.
     */
    private const TEXT_FIELD_HARD_CAP = 100000;

    /**
     * Clamps an admin-configured limit_max to self::TEXT_FIELD_HARD_CAP, and
     * substitutes the hard cap itself when no limit is configured (0/negative),
     * so render()'s maxlength hint and validate()'s server-side backstop can
     * never disagree, and a Text/Textarea field can never be truly unbounded.
     *
     * @param int $configured Admin-configured limit_max (0/negative = unset).
     *
     * @return int
     */
    protected static function clampTextMax(int $configured): int
    {
        return $configured > 0 ? min($configured, self::TEXT_FIELD_HARD_CAP) : self::TEXT_FIELD_HARD_CAP;
    }

    /**
     * Absolute server-side character-count backstop for Text/Textarea fields,
     * enforced regardless of the admin's configured limit_type/limit_max — a
     * "words" limit doesn't bound character length (a single "word" can be
     * arbitrarily long), so this always checks raw character count against
     * self::TEXT_FIELD_HARD_CAP independent of that configured limit.
     *
     * @param string $value Submitted field value.
     *
     * @return bool|string True if within the hard cap, error message string otherwise.
     */
    protected static function validateTextHardCap(string $value): bool|string
    {
        $length = function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
        if ($length > self::TEXT_FIELD_HARD_CAP) {
            // translators: %1$d: absolute maximum character count allowed, %2$d: current character count.
            return sprintf(__('Please enter at most %1$d characters (currently: %2$d).', 'form-forge'), self::TEXT_FIELD_HARD_CAP, $length);
        }
        return true;
    }

    /**
     * Checks whether $value looks like a real signature-canvas data URI.
     *
     * Shared by SignatureField and SepaField so the two fields' "does this look
     * like real image data" prefix checks can't silently drift apart again —
     * both fields independently re-verify real PNG/JPEG magic bytes via
     * materializeSignature() before trusting either, so this is a cheap format
     * sanity check, not the security boundary.
     *
     * @param string $value           Submitted value.
     * @param string $expected_format Optional exact format ('png'|'jpeg'); when
     *                                 empty, any 'data:image/' prefix is accepted.
     *
     * @return bool
     */
    protected static function isSignatureDataUri(string $value, string $expected_format = ''): bool
    {
        if ($expected_format !== '') {
            return str_starts_with($value, 'data:image/' . $expected_format . ';base64,');
        }
        return str_starts_with($value, 'data:image/');
    }

    /**
     * Returns the shared client-side validation rule enforcing the "Other" text
     * word limit (the char limit is covered by the native maxlength attribute).
     *
     * @return array
     */
    protected static function otherTextClientRule(): array
    {
        return ['rule' => 'other-text-word-limit', 'fn' => <<<'JS'
            function (fieldEl) {
                var inp = fieldEl.querySelector('.forge-other-input[data-word-limit]');
                if (!inp || !inp.value.trim()) return null;
                var limit = parseInt(inp.dataset.wordLimit, 10);
                if (!limit) return null;
                var count = inp.value.trim().split(/\s+/).filter(Boolean).length;
                return count <= limit ? null
                    : 'Please enter at most ' + limit + ' words (currently: ' + count + ').';
            }
            JS];
    }

    /**
     * Returns the field type slug used to identify this field in forms and the registry.
     *
     * IMPORTANT: FieldRegistry::registerDefaults() instantiates every declared
     * BaseField subclass in this namespace (via `new $class()`) purely to read
     * this value during plugin bootstrap. Field constructors — including any
     * constructor added to BaseField itself — must therefore stay free of side
     * effects (no DB/HTTP calls, no static-state mutation). If that constraint
     * ever needs to be relaxed, switch registerDefaults() to a static type()
     * method on each field class instead of instantiating.
     *
     * @return string
     */
    abstract public function getType(): string;

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
     * The extractValue() counterpart for a field living inside a repeatable
     * Group. A normal field reads itself straight out of $_POST[$field_id].
     * A field inside a Group copy can't do that: the browser submits it
     * nested under the group's own key instead, e.g. for a "family members"
     * group repeated twice, with a "name" child field:
     *
     *   $_POST['group-5'][0]['name'] = 'Alice'
     *   $_POST['group-5'][1]['name'] = 'Bob'
     *
     * FormProcessor slices out each copy's raw value (e.g. 'Alice') and
     * passes it here as $raw — extractFromRaw() then applies the exact same
     * sanitizing extractValue() would have applied, just to a value handed
     * in as a parameter instead of read from $_POST directly.
     *
     * Rule of thumb: whatever sanitizing you write in extractValue(), mirror
     * it here so a field behaves identically whether it's used standalone
     * or inside a Group. The default sanitizes a scalar or a flat array with
     * sanitize_text_field() — override when extractValue() does something
     * else (textarea sanitizer, always-array shape like checkboxes, etc.).
     *
     * @param mixed $raw The raw value already sliced out of the group copy array.
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
     * extractFromRaw() variant for Checkbox/Radio/Select's "Other" free-text
     * option. Those fields submit the typed "Other" text as a *sibling*
     * top-level key, not nested under the child's own key, e.g.:
     *
     *   $_POST['group-5'][0]['choice']       = '__other__'
     *   $_POST['group-5'][0]['choice_other'] = 'Something custom'
     *
     * extractFromRaw($raw) alone only ever sees the 'choice' slice — it has
     * no way to reach the sibling 'choice_other' value. FormProcessor calls
     * this method instead when both pieces need to be combined, passing the
     * sibling in as $other_raw. Default ignores $other_raw and delegates to
     * extractFromRaw(); override only in fields whose extractValue() also
     * attaches a '__other_text__' key (mirror that shape here).
     *
     * @param mixed $raw       The raw value from the group copy array (same as extractFromRaw()).
     * @param mixed $other_raw The raw "{child_id}_other" sibling value from the same copy, if any.
     *
     * @return mixed
     */
    public function extractFromRawWithOther(mixed $raw, mixed $other_raw): mixed
    {
        return $this->extractFromRaw($raw);
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
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce is verified once in FormProcessor::handle() before field extraction runs.
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
            // translators: %s: field label.
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
     * Config keys that are always rendered as plain text (esc_html()/esc_attr()
     * at output time, never as raw/rich HTML) across every field type that
     * defines them — confirmed by inspecting every render()/wrap()/inputAttrs()
     * usage of these keys in includes/Fields/*.php. Kept deliberately small and
     * conservative: any key not on this list keeps the wp_kses_post() default
     * below, including known rich-text keys (consent_text, mandate_text,
     * mandate_note, html_content — the latter has its own sanitizeConfigValue()
     * override in HtmlField) and any key not fully audited across all
     * consumers (email/PDF templates), per-field sub-labels, etc.
     *
     * Note: FormEditor::sanitizeFields() already treats 'label'/'placeholder'/
     * 'description' as plain text via its own $plaintext_keys list before this
     * method is ever reached on that save path — this allowlist exists so
     * sanitizeConfigValue() is independently correct for any other caller.
     *
     * @var string[]
     */
    private const PLAIN_TEXT_CONFIG_KEYS = ['label', 'placeholder', 'description'];

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
        if (in_array($key, self::PLAIN_TEXT_CONFIG_KEYS, true)) {
            return \sanitize_text_field($value);
        }
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
