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

class CheckboxField extends BaseField
{
    public function getStyles(): string
    {
        return <<<'CSS'
.forge-checkbox-group {
    display: flex;
    flex-direction: column;
    gap: 10px;
}
.forge-checkbox-group--horizontal {
    flex-direction: row;
    flex-wrap: wrap;
    gap: 16px;
}
.forge-checkbox-label {
    display: flex;
    align-items: center;
    gap: 9px;
    cursor: pointer;
    font-size: 14px;
    color: var(--forge-text);
    line-height: 1.4;
    user-select: none;
}
.forge-checkbox-label input[type="checkbox"] {
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
    position: relative;
}
.forge-checkbox-label input[type="checkbox"]:checked {
    border-color: var(--forge-accent);
    background-color: var(--forge-accent);
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 12 10'%3E%3Cpolyline points='1,5 4.5,8.5 11,1' stroke='%23ffffff' stroke-width='2' fill='none' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: center;
    background-size: 11px 9px;
}
.forge-checkbox-label input[type="checkbox"]:focus-visible {
    outline: none;
    box-shadow: 0 0 0 3px
        color-mix(in srgb, var(--forge-accent) 25%, transparent);
}
.forge-checkbox-label:hover input[type="checkbox"]:not(:checked) {
    border-color: var(--forge-accent);
}
CSS;
    }

    public function getLabel(): string
    {
        return 'Checkboxen';
    }
    public function getIcon(): string
    {
        return 'fa-solid fa-square-check';
    }

    public function getClientValidation(): array
    {
        return [['rule' => 'checkbox-count', 'fn' => <<<'JS'
            function (fieldEl) {
                var group = fieldEl.querySelector('.forge-checkbox-group');
                if (!group) return null;
                var min = parseInt(group.dataset.minSelections || '0', 10);
                var max = parseInt(group.dataset.maxSelections || '0', 10);
                if (!min && !max) return null;
                var cnt = fieldEl.querySelectorAll('input[type="checkbox"]:checked').length;
                if (min > 0 && cnt < min) return 'Bitte mindestens ' + min + ' Option(en) wählen.';
                if (max > 0 && cnt > max) return 'Bitte höchstens ' + max + ' Option(en) wählen.';
                return null;
            }
            JS]];
    }

    public function getClientEmptyCheck(): array
    {
        return ['fn' => "function(f){return !f.querySelector('input[type=\"checkbox\"]:checked');}"];
    }

    public function render(array $config, string $field_id, mixed $value = null): string
    {
        $options  = $config['options'] ?? [];

        /* When no submitted value, pre-check options marked as default */
        if ($value === null) {
            $defaults = [];
            foreach ($options as $opt) {
                if (is_array($opt) && !empty($opt['default'])) {
                    $defaults[] = $opt['value'] ?? '';
                }
            }
            $value = $defaults ?: null;
        }

        $selected = is_array($value) ? $value : (array)$value;
        $layout   = !empty($config['layout']) ? ' forge-checkbox-group--horizontal' : '';

        $min_sel   = (int)($config['min_selections'] ?? 0);
        $max_sel   = (int)($config['max_selections'] ?? 0);
        $sel_attrs = '';
        if ($min_sel > 0) {
            $sel_attrs .= ' data-min-selections="' . $min_sel . '"';
        }
        if ($max_sel > 0) {
            $sel_attrs .= ' data-max-selections="' . $max_sel . '"';
        }

        $inner = '<div class="forge-checkbox-group' . $layout . '" role="group"' . $sel_attrs . '>';
        foreach ($options as $i => $opt) {
            $opt_val   = is_array($opt) ? ($opt['value'] ?? '') : $opt;
            $opt_label = is_array($opt) ? ($opt['label'] ?? $opt_val) : $opt;
            $id_i      = $field_id . '-' . $i;
            $checked   = in_array((string)$opt_val, array_map('strval', $selected), true) ? ' checked' : '';
            $inner .= '<label class="forge-checkbox-label">'
                . '<input type="checkbox" id="' . esc_attr($id_i)
                . '" name="' . esc_attr($field_id) . '[]"'
                . ' value="' . esc_attr((string)$opt_val) . '" autocomplete="off"' . $checked . '> '
                . esc_html((string)$opt_label) . '</label>';
        }
        if (!empty($config['other_option'])) {
            $other_id  = $field_id . '-other';
            $other_chk = in_array('__other__', array_map('strval', $selected), true) ? ' checked' : '';
            $inner .= '<label class="forge-checkbox-label">'
                . '<input type="checkbox" id="' . esc_attr($other_id) . '" name="' . esc_attr($field_id) . '[]"'
                . ' value="__other__"' . $other_chk . '> Sonstiges…</label>';
            $show  = $other_chk ? '' : ' style="display:none"';
            $inner .= '<input type="text" name="' . esc_attr($field_id) . '_other"'
                . ' class="forge-input forge-other-input" placeholder="Bitte angeben"' . $show . '>';
        }
        $inner .= '</div>';

        return $this->wrap($field_id, $config, $inner);
    }

    public function validate(mixed $value, array $config): bool|string
    {
        if (!empty($config['required'])) {
            $vals = is_array($value) ? array_filter($value) : [];
            if (empty($vals)) {
                return ($config['label'] ?? 'Feld') . ': Bitte mindestens eine Option wählen.';
            }
        }
        $selected = is_array($value) ? array_filter($value) : [];
        $cnt      = count($selected);
        $min      = (int)($config['min_selections'] ?? 0);
        $max      = (int)($config['max_selections'] ?? 0);
        if ($min > 0 && $cnt < $min) {
            return 'Bitte mindestens ' . $min . ' Option(en) wählen.';
        }
        if ($max > 0 && $cnt > $max) {
            return 'Bitte höchstens ' . $max . ' Option(en) wählen.';
        }
        return true;
    }

    public function map(mixed $value, array $config): string
    {
        $selected = is_array($value) ? array_filter($value) : [];
        if (empty($selected)) {
            return '[Kein Eintrag]';
        }
        $labels = [];
        foreach ($selected as $v) {
            if ((string)$v === '__other__') {
                $labels[] = '[Sonstiges]';
                continue;
            }
            $found = false;
            foreach ($config['options'] ?? [] as $opt) {
                $opt_val = is_array($opt) ? ($opt['value'] ?? '') : $opt;
                if ((string)$opt_val === (string)$v) {
                    $labels[] = is_array($opt) ? ($opt['label'] ?? (string)$opt_val) : (string)$opt;
                    $found    = true;
                    break;
                }
            }
            if (!$found) {
                $labels[] = (string)$v;
            }
        }
        return implode(', ', $labels);
    }

    public function getDefaultConfig(): array
    {
        return array_merge(parent::getDefaultConfig(), [
            'layout'         => true,
            'other_option'   => false,
            'min_selections' => '',
            'max_selections' => '',
            'options'        => [
                ['value' => 'option-1', 'label' => 'Option 1', 'default' => false],
                ['value' => 'option-2', 'label' => 'Option 2', 'default' => false],
            ],
        ]);
    }

    public function getGeneralSchema(): array
    {
        return [
            ['key' => 'layout',         'type' => 'bool_seg',     'label' => 'Anordnung',
             'false_label' => 'Vertikal', 'true_label' => 'Horizontal', 'swap' => true],
            ['key' => 'description',    'type' => 'text',         'label' => 'Beschreibung'],
            ['key' => 'other_option',   'type' => 'checkbox',     'label' => '"Sonstiges"-Option anzeigen'],
            ['key' => 'options',        'type' => 'options_list', 'label' => 'Optionen'],
            ['key' => 'min_selections', 'type' => 'number',       'label' => 'Min. Auswahl',
             'hint' => 'Leer = keine Pflicht'],
            ['key' => 'max_selections', 'type' => 'number',       'label' => 'Max. Auswahl',
             'hint' => 'Leer = unbegrenzt'],
        ];
    }
}
