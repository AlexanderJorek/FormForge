<?php

/**
 * Radio button group field for single-choice selection.
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
 * Radio button group field.
 */
class RadioField extends BaseField
{
    /**
     * Returns field-specific CSS styles.
     *
     * @return string
     */
    public function getStyles(): string
    {
        return <<<'CSS'
.forge-radio-group {
    display: flex;
    flex-direction: column;
    gap: 10px;
}
.forge-radio-group--horizontal {
    flex-direction: row;
    flex-wrap: wrap;
    gap: 16px;
}
.forge-radio-label {
    display: flex;
    align-items: center;
    gap: 9px;
    cursor: pointer;
    font-size: 14px;
    color: var(--forge-text);
    line-height: 1.4;
    user-select: none;
}
.forge-radio-label input[type="radio"] {
    appearance: none;
    -webkit-appearance: none;
    width: 18px;
    height: 18px;
    flex-shrink: 0;
    border: 2px solid var(--forge-border-input);
    border-radius: 50%;
    background: var(--forge-bg);
    cursor: pointer;
    transition: border-color .15s, background .15s, box-shadow .15s;
    position: relative;
}
.forge-radio-label input[type="radio"]:checked {
    border-color: var(--forge-accent);
    background: var(--forge-accent);
    box-shadow: inset 0 0 0 3px var(--forge-bg);
}
.forge-radio-label input[type="radio"]:focus-visible {
    outline: none;
    box-shadow: 0 0 0 3px
        color-mix(in srgb, var(--forge-accent) 25%, transparent);
}
.forge-radio-label:hover input[type="radio"]:not(:checked) {
    border-color: var(--forge-accent);
}
.forge-other-input { margin-top: 4px; }
CSS;
    }

    /**
     * Returns the field type label.
     *
     * @return string
     */
    public function getLabel(): string
    {
        return __('Radio', 'form-forge');
    }

    /**
     * Returns the Font Awesome icon class.
     *
     * @return string
     */
    public function getIcon(): string
    {
        return 'fa-solid fa-circle-dot';
    }

    /**
     * Returns client-side initialization JavaScript function body.
     *
     * @return string
     */
    public function getClientInit(): string
    {
        return <<<'JS'
        function (root) {
            root.querySelectorAll('input[value="__other__"]').forEach(function (inp) {
                inp.addEventListener('change', function () {
                    var wrap  = this.closest('.forge-radio-group, .forge-checkbox-group');
                    var other = wrap && wrap.querySelector('.forge-other-input');
                    if (!other) return;
                    other.style.display = this.checked ? '' : 'none';
                });
            });
        }
        JS;
    }

    /**
     * Returns the client-side empty-check function for required validation.
     *
     * @return array
     */
    public function getClientEmptyCheck(): array
    {
        return ['fn' => "function(f){return !f.querySelector('input[type=\"radio\"]:checked');}"];
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
        $options = $config['options'] ?? [];
        $req     = !empty($config['required']) ? ' required' : '';

        /* extractValue() returns ['value' => ..., '__other_text__' => ...] when
           "Other" was selected with typed text — unwrap for every comparison below. */
        $other_text = is_array($value) ? trim((string)($value['__other_text__'] ?? '')) : '';
        if (is_array($value)) {
            $value = $value['value'] ?? '';
        }

        if ($value === null) {
            foreach ($options as $opt) {
                if (is_array($opt) && !empty($opt['default'])) {
                    $value = $opt['value'] ?? '';
                    break;
                }
            }
        }

        $layout = !empty($config['layout']) ? ' forge-radio-group--horizontal' : '';
        $inner  = '<div class="forge-radio-group' . $layout . '" role="group">';
        foreach ($options as $i => $opt) {
            $opt_val   = is_array($opt) ? ($opt['value'] ?? '') : $opt;
            $opt_label = is_array($opt) ? ($opt['label'] ?? $opt_val) : $opt;
            $id_i      = $field_id . '-' . $i;
            $checked   = checked((string)($value ?? ''), (string)$opt_val, false);
            $inner .= '<label class="forge-radio-label">'
                . '<input type="radio" id="' . esc_attr($id_i)
                . '" name="' . esc_attr($field_id)
                . '" value="' . esc_attr((string)$opt_val) . '" autocomplete="off"'
                . $checked . $req . '> ' . esc_html((string)$opt_label) . '</label>';
        }
        if (!empty($config['other_option'])) {
            $other_id  = $field_id . '-other';
            $other_chk = checked((string)($value ?? ''), '__other__', false);
            $inner .= '<label class="forge-radio-label">'
                . '<input type="radio" id="' . esc_attr($other_id) . '" name="' . esc_attr($field_id) . '"'
                . ' value="__other__" autocomplete="off"' . $other_chk . $req . '> '
                . esc_html__('Other…', 'form-forge') . '</label>';
        }
        $inner .= '</div>';
        if (!empty($config['other_option'])) {
            $show = (string)($value ?? '') === '__other__' ? '' : ' style="display:none"';
            $inner .= '<input type="text" name="' . esc_attr($field_id) . '_other"'
                . ' class="forge-input forge-other-input" value="' . esc_attr($other_text) . '"'
                . ' placeholder="' . esc_attr__('Please specify', 'form-forge') . '"' . $show . '>';
        }

        return $this->wrap($field_id, $config, $inner);
    }

    /**
     * Returns the selected option, plus the typed "Other" text when present.
     *
     * @param string $field_id The field element ID.
     *
     * @return mixed String selection, or ['value' => ..., '__other_text__' => ...]
     *               when "Other" was selected and a companion text field was submitted.
     */
    public function extractValue(string $field_id): mixed
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce is verified once in FormProcessor::handle() before field extraction runs.
        $selected = isset($_POST[$field_id]) ? sanitize_text_field(wp_unslash($_POST[$field_id])) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce is verified once in FormProcessor::handle() before field extraction runs.
        if ($selected === '__other__' && isset($_POST[$field_id . '_other'])) {
            return [
                'value'          => $selected,
                // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce is verified once in FormProcessor::handle() before field extraction runs.
                '__other_text__' => mb_substr(sanitize_text_field(wp_unslash($_POST[$field_id . '_other'])), 0, 500),
            ];
        }
        return $selected;
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
        $base = parent::validate($value, $config);
        if ($base !== true) {
            return $base;
        }
        $selected = is_array($value) ? ($value['value'] ?? '') : $value;
        if ($selected === '' || $selected === null) {
            return true;
        }
        if ((string)$selected === '__other__') {
            if (empty($config['other_option'])) {
                return __('Please select a valid option.', 'form-forge');
            }
            // "Other" selected with a blank companion text field is effectively no
            // answer — don't let it satisfy a required field.
            $other = is_array($value) ? trim((string)($value['__other_text__'] ?? '')) : '';
            if (!empty($config['required']) && $other === '') {
                $label = $config['label'] ?? __('Field', 'form-forge');
                // translators: %s: field label.
                return sprintf(__('%s is a required field.', 'form-forge'), esc_html($label));
            }
            return true;
        }
        $allowed = array_map(
            static fn($o) => (string)(is_array($o) ? ($o['value'] ?? '') : $o),
            $config['options'] ?? []
        );
        if (!in_array((string)$selected, $allowed, true)) {
            return __('Please select a valid option.', 'form-forge');
        }
        return true;
    }

    /**
     * Like extractFromRaw(), but also captures the group copy's sibling
     * "{child_id}_other" free-text value, mirroring extractValue().
     *
     * @param mixed $raw       The raw value from the group copy array.
     * @param mixed $other_raw The raw "{child_id}_other" value from the same copy, if any.
     *
     * @return mixed
     */
    public function extractFromRawWithOther(mixed $raw, mixed $other_raw): mixed
    {
        $selected = $this->extractFromRaw($raw);
        if ($selected === '__other__' && $other_raw !== null) {
            return [
                'value'          => $selected,
                '__other_text__' => sanitize_text_field(wp_unslash($other_raw)),
            ];
        }
        return $selected;
    }

    /**
     * Maps the field value to the normalized submission entry.
     *
     * @param mixed $value  Submitted value.
     * @param array $config Field configuration.
     *
     * @return string Normalized field entry.
     */
    public function map(mixed $value, array $config): string
    {
        $other_text = '';
        if (is_array($value)) {
            $other_text = trim((string)($value['__other_text__'] ?? ''));
            $value      = $value['value'] ?? '';
        }
        if ($value === '' || $value === null) {
            return __('[No entry]', 'form-forge');
        }
        if ((string)$value === '__other__') {
            return $other_text !== ''
                ? sprintf('%s (%s)', __('Other', 'form-forge'), $other_text)
                : __('[Other]', 'form-forge');
        }
        foreach ($config['options'] ?? [] as $opt) {
            $opt_val = is_array($opt) ? ($opt['value'] ?? '') : $opt;
            if ((string)$opt_val === (string)$value) {
                return is_array($opt) ? ($opt['label'] ?? (string)$opt_val) : (string)$opt;
            }
        }
        return (string)$value;
    }

    /**
     * Returns the default field configuration.
     *
     * @return array
     */
    public function getDefaultConfig(): array
    {
        return array_merge(
            parent::getDefaultConfig(),
            [
            'layout'       => true,
            'other_option' => false,
            'options'      => [
                ['value' => 'option-1', 'label' => 'Option 1', 'default' => false],
                ['value' => 'option-2', 'label' => 'Option 2', 'default' => false],
            ],
            ]
        );
    }

    /**
     * Returns the general settings schema for the field editor.
     *
     * @return array
     */
    public function getGeneralSchema(): array
    {
        return [
            [
                'key'         => 'layout',
                'type'        => 'bool_seg',
                'label'       => __('Layout', 'form-forge'),
                'false_label' => __('Vertical', 'form-forge'),
                'true_label'  => __('Horizontal', 'form-forge'),
                'swap'        => true,
            ],
            [
                'key'   => 'description',
                'type'  => 'text',
                'label' => __('Description', 'form-forge'),
            ],
            [
                'key'   => 'other_option',
                'type'  => 'checkbox',
                'label' => __('Show "Other" option', 'form-forge'),
            ],
            [
                'key'   => 'options',
                'type'  => 'options_list',
                'label' => __('Options', 'form-forge'),
            ],
        ];
    }
}
