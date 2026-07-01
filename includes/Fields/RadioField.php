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
        return 'Radio';
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
        $inner .= '</div>';

        return $this->wrap($field_id, $config, $inner);
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
        if (empty($value)) {
            return '[Kein Eintrag]';
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
            parent::getDefaultConfig(), [
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
            ['key' => 'layout',       'type' => 'bool_seg',     'label' => 'Anordnung',
             'false_label' => 'Vertikal', 'true_label' => 'Horizontal', 'swap' => true],
            ['key' => 'description',  'type' => 'text',         'label' => 'Hinweistext'],
            ['key' => 'other_option', 'type' => 'checkbox',     'label' => '"Sonstiges"-Option anzeigen'],
            ['key' => 'options',      'type' => 'options_list', 'label' => 'Optionen'],
        ];
    }
}
