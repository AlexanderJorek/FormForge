<?php

/**
 * Email address input field with format validation.
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
 * Email address input field with validation.
 */
class EmailField extends BaseField
{
    /**
     * Returns the field type label.
     *
     * @return string
     */
    public function getLabel(): string
    {
        return 'E-Mail';
    }

    /**
     * Returns the Font Awesome icon class.
     *
     * @return string
     */
    public function getIcon(): string
    {
        return 'fa-solid fa-envelope';
    }

    /**
     * Returns client-side validation rules.
     *
     * @return array
     */
    public function getClientValidation(): array
    {
        return [
            [
                'rule' => 'email',
                'fn'   => <<<'JS'
                function (fieldEl) {
                    var inp = fieldEl.querySelector('input[type="email"]');
                    if (!inp || !inp.value.trim()) return null;
                    var v = inp.value.trim().toLowerCase();
                    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v))
                        return 'Bitte geben Sie eine gültige E-Mail-Adresse ein.';
                    var mode = inp.dataset.filterMode || '';
                    if (!mode) return null;
                    var list = JSON.parse(inp.dataset.filterPatterns || '[]');
                    var matched = list.some(function (pat) {
                        var re = new RegExp(
                            '^' + pat.toLowerCase()
                                .replace(/[.+?^${}()|[\]\\]/g, '\\$&')
                                .replace(/\*/g, '.*') + '$'
                        );
                        return re.test(v);
                    });
                    if (mode === 'allow' && !matched) return 'Diese E-Mail-Adresse ist nicht zugelassen.';
                    if (mode === 'block' &&  matched) return 'Diese E-Mail-Adresse ist nicht zugelassen.';
                    return null;
                }
                JS,
            ],
        ];
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
        $attrs = $this->inputAttrs($config, $field_id, 'email', ['value' => esc_attr((string)($value ?? ''))]);
        $mode  = $config['filter_mode'] ?? '';
        if ($mode !== '') {
            $attrs .= ' data-filter-mode="' . esc_attr($mode) . '"';
            $patterns = trim((string)($config['filter_patterns'] ?? ''));
            if ($patterns !== '') {
                $list  = array_values(array_filter(array_map('trim', preg_split('/[\r\n;]+/', $patterns))));
                $attrs .= " data-filter-patterns='" . esc_attr(json_encode($list)) . "'";
            }
        }
        return $this->wrap($field_id, $config, '<input' . $attrs . '>');
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
        if (empty($value)) {
            return true;
        }
        $v = strtolower(trim((string)$value));
        if ($config['validate_format'] ?? true) {
            if (!is_email($v)) {
                return 'Bitte geben Sie eine gültige E-Mail-Adresse ein.';
            }
        }
        $mode     = $config['filter_mode']     ?? '';
        $patterns = trim((string)($config['filter_patterns'] ?? ''));
        if ($mode !== '' && $patterns !== '') {
            $list    = array_filter(array_map('trim', preg_split('/[\r\n;]+/', $patterns)));
            $matched = false;
            foreach ($list as $pat) {
                $regex = '/^' . str_replace('\*', '.*', preg_quote(strtolower($pat), '/')) . '$/';
                if (preg_match($regex, $v)) {
                    $matched = true;
                    break;
                }
            }
            if ($mode === 'allow' && !$matched) {
                return 'Diese E-Mail-Adresse ist nicht zugelassen.';
            }
            if ($mode === 'block' && $matched) {
                return 'Diese E-Mail-Adresse ist nicht zugelassen.';
            }
        }
        return true;
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
            'validate_format' => true,
            'filter_mode'     => '',
            'filter_patterns' => '',
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
        return array_merge(
            $this->baseGeneralEntries(), [
            ['key' => 'validate_format', 'type' => 'checkbox', 'label' => 'Auf gültiges E-Mail-Format prüfen'],
            ]
        );
    }

    /**
     * Returns the advanced settings schema for the field editor.
     *
     * @return array
     */
    public function getAdvancedSchema(): array
    {
        return [
            ['key'    => 'filter_mode',
             'type'   => 'pill3',
             'label'  => 'E-Mail-Filter',
             'values' => ['', 'allow', 'block'],
             'labels' => ['Aus', 'Erlaubt', 'Gesperrt']],
            ['key'        => 'filter_patterns',
             'type'       => 'textarea',
             'label'      => 'Muster (je Zeile oder mit ; trennen)',
             'hint'       => '*no-reply* · *.outlook.com · user@example.com',
             'depends_on' => ['key' => 'filter_mode', 'not' => '']],
        ];
    }
}
