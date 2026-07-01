<?php

/**
 * Date picker field.
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
 * Date picker input field.
 */
class DateField extends BaseField
{
    /**
     * Returns field-specific CSS styles.
     *
     * @return string
     */
    public function getStyles(): string
    {
        return <<<'CSS'
.forge-date-wrap { display: flex; align-items: center; gap: 8px; }
.forge-date-text { flex: 1; }
.forge-date-cal-btn {
    flex-shrink: 0;
    height: var(--forge-input-height);
    padding: 0 12px;
    background: var(--forge-bg-muted);
    border: 1px solid var(--forge-border-input);
    border-radius: var(--forge-radius);
    cursor: pointer;
    color: var(--forge-text-muted);
    font-size: 14px;
    transition: background .1s;
}
.forge-date-cal-btn:hover { background: var(--forge-border); }
.forge-date-wrap { position: relative; }
.forge-date-picker-hidden {
    position: absolute;
    visibility: hidden;
    pointer-events: none;
    width: 0; height: 0;
    overflow: hidden;
}
CSS;
    }

    /**
     * Returns the field type label.
     *
     * @return string
     */
    public function getLabel(): string
    {
        return 'Datum';
    }

    /**
     * Returns the Font Awesome icon class.
     *
     * @return string
     */
    public function getIcon(): string
    {
        return 'fa-solid fa-calendar-days';
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
            root.querySelectorAll('.forge-date-cal-btn').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var wrap   = this.closest('.forge-date-wrap');
                    var picker = wrap && wrap.querySelector('.forge-date-picker-hidden');
                    if (!picker) return;
                    picker.showPicker ? picker.showPicker() : picker.click();
                });
            });
            root.querySelectorAll('.forge-date-picker-hidden').forEach(function (picker) {
                picker.addEventListener('change', function () {
                    var wrap = this.closest('.forge-date-wrap');
                    var text = wrap && wrap.querySelector('.forge-date-text');
                    if (!text || !this.value) return;
                    var parts = this.value.split('-');
                    if (parts.length === 3) {
                        text.value = parts[2] + '.' + parts[1] + '.' + parts[0];
                    }
                });
            });
            root.querySelectorAll('.forge-date-text[data-prefill-today="true"]').forEach(function (inp) {
                if (inp.value) return;
                var now = new Date();
                var d   = String(now.getDate()).padStart(2, '0');
                var m   = String(now.getMonth() + 1).padStart(2, '0');
                inp.value = d + '.' + m + '.' + now.getFullYear();
            });
        }
        JS;
    }

    /**
     * Returns client-side validation rules.
     *
     * @return array
     */
    public function getClientValidation(): array
    {
        return [['rule' => 'date-format', 'fn' => <<<'JS'
            function (fieldEl) {
                var inp = fieldEl.querySelector('.forge-date-text');
                if (!inp || !inp.value.trim()) return null;
                var v = inp.value.trim();
                if (!/^\d{2}\.\d{2}\.\d{4}$/.test(v))
                    return 'Bitte Datum im Format TT.MM.JJJJ eingeben.';
                var p  = v.split('.');
                var d  = parseInt(p[0], 10);
                var m  = parseInt(p[1], 10);
                var y  = parseInt(p[2], 10);
                var dt = new Date(y, m - 1, d);
                if (dt.getFullYear() !== y || dt.getMonth() !== m - 1 || dt.getDate() !== d)
                    return 'Bitte geben Sie ein gültiges Datum ein.';
                return null;
            }
            JS]];
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
        $req      = !empty($config['required']) ? ' required aria-required="true"' : '';
        $prefill  = !empty($config['prefill_today']) ? ' data-prefill-today="true"' : '';
        $picker   = !empty($config['show_picker']);

        $inner = '<div class="forge-date-wrap">'
            . '<input type="text" id="' . esc_attr($field_id) . '"'
            . ' name="' . esc_attr($field_id) . '"'
            . ' class="forge-input forge-date-text"'
            . ' placeholder="TT.MM.JJJJ"'
            . ' maxlength="10"'
            . ' autocomplete="bday"'
            . ' value="' . esc_attr((string)($value ?? '')) . '"'
            . $prefill . $req . '>';

        if ($picker) {
            $inner .= '<button type="button" class="forge-date-cal-btn" data-for="' . esc_attr($field_id) . '"'
                . ' aria-label="Kalender öffnen" title="Kalender öffnen">'
                . '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16"'
                . ' viewBox="0 0 24 24" fill="none" stroke="currentColor"'
                . ' stroke-width="2" stroke-linecap="round" stroke-linejoin="round"'
                . ' aria-hidden="true">'
                . '<rect x="3" y="4" width="18" height="18" rx="2"/>'
                . '<line x1="16" y1="2" x2="16" y2="6"/>'
                . '<line x1="8" y1="2" x2="8" y2="6"/>'
                . '<line x1="3" y1="10" x2="21" y2="10"/>'
                . '</svg>'
                . '</button>'
                . '<input type="date" class="forge-date-picker-hidden" aria-hidden="true" tabindex="-1"'
                . ' data-for="' . esc_attr($field_id) . '">';
        }

        $inner .= '</div>';
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
        if (empty($value) || trim((string)$value) === '') {
            if (!empty($config['required'])) {
                return ($config['label'] ?? 'Datum') . ': Pflichtfeld.';
            }
            return true;
        }
        $v = trim((string)$value);
        if (!preg_match('/^\d{2}\.\d{2}\.\d{4}$/', $v)) {
            return 'Bitte Datum im Format TT.MM.JJJJ eingeben.';
        }
        [$d, $m, $y] = explode('.', $v);
        if (!checkdate((int)$m, (int)$d, (int)$y)) {
            return 'Bitte geben Sie ein gültiges Datum ein.';
        }
        return true;
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
            'show_picker'   => true,
            'prefill_today' => false,
            'min_date'      => '',
            'max_date'      => '',
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
            ['key' => 'description',   'type' => 'text',     'label' => 'Beschreibung'],
            ['key' => 'show_picker',   'type' => 'checkbox', 'label' => 'Kalender-Icon anzeigen'],
            ['key' => 'prefill_today', 'type' => 'checkbox', 'label' => 'Heute vorausfüllen'],
        ];
    }
}
