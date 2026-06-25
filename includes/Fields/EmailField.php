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

class EmailField extends BaseField
{
    public function getLabel(): string
    {
        return 'E-Mail';
    }
    public function getIcon(): string
    {
        return 'fa-solid fa-envelope';
    }

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

    public function getDefaultConfig(): array
    {
        return array_merge(parent::getDefaultConfig(), [
            'validate_format' => true,
            'filter_mode'     => '',
            'filter_patterns' => '',
        ]);
    }

    public function getGeneralSchema(): array
    {
        return array_merge($this->baseGeneralEntries(), [
            ['key' => 'validate_format', 'type' => 'checkbox', 'label' => 'Auf gültiges E-Mail-Format prüfen'],
        ]);
    }

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
