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

class CurrencyField extends BaseField
{
    private const CURRENCIES = [
        'EUR' => '€', 'USD' => '$', 'GBP' => '£', 'CHF' => 'Fr.',
        'JPY' => '¥', 'CAD' => 'CA$', 'AUD' => 'A$', 'SEK' => 'kr',
        'NOK' => 'kr', 'DKK' => 'kr', 'PLN' => 'zł', 'CZK' => 'Kč',
    ];

    public function getLabel(): string
    {
        return 'Währung';
    }

    public function getStyles(): string
    {
        return <<<'CSS'
.forge-currency-wrap {
    display: flex;
    align-items: stretch;
    border: 1px solid var(--forge-border-input) !important;
    border-radius: var(--forge-radius);
    overflow: hidden;
    transition: border-color .15s, box-shadow .15s;
}
.forge-currency-wrap:focus-within {
    border-color: var(--forge-accent) !important;
    box-shadow: 0 0 0 3px
        color-mix(in srgb, var(--forge-accent) 15%, transparent) !important;
}
.forge-currency-symbol {
    display: flex;
    align-items: center;
    padding: 0 12px;
    background: var(--forge-bg-subtle);
    border-right: 1px solid var(--forge-border-input);
    font-size: 13px;
    font-weight: 600;
    color: var(--forge-text-muted);
    white-space: nowrap;
    flex-shrink: 0;
}
.forge-currency-wrap .forge-currency-input {
    border: none !important;
    box-shadow: none !important;
    border-radius: 0 !important;
    flex: 1;
    min-width: 0;
    -moz-appearance: textfield !important;
}
.forge-currency-wrap .forge-currency-input:focus,
.forge-currency-wrap .forge-currency-input:focus-visible {
    border: none !important;
    box-shadow: none !important;
    outline: none !important;
}
.forge-currency-input::-webkit-outer-spin-button,
.forge-currency-input::-webkit-inner-spin-button {
    -webkit-appearance: none;
    margin: 0;
}
CSS;
    }
    public function getIcon(): string
    {
        return 'fa-solid fa-euro-sign';
    }

    public function render(array $config, string $field_id, mixed $value = null): string
    {
        $symbol  = self::CURRENCIES[$config['currency'] ?? 'EUR'] ?? '€';
        $req     = !empty($config['required']) ? ' required aria-required="true"' : '';
        $min_attr = ($config['min_value'] ?? '') !== '' ? ' min="' . esc_attr((string)$config['min_value']) . '"' : '';
        $max_attr = ($config['max_value'] ?? '') !== '' ? ' max="' . esc_attr((string)$config['max_value']) . '"' : '';
        $inner   = '<div class="forge-currency-wrap">'
            . '<span class="forge-currency-symbol">' . esc_html($symbol) . '</span>'
            . '<input type="number" step="0.01" id="' . esc_attr($field_id) . '" name="' . esc_attr($field_id) . '"'
            . ' class="forge-input forge-currency-input" placeholder="0,00"'
            . ' value="' . esc_attr((string)($value ?? '')) . '"'
            . $min_attr . $max_attr . $req . '>'
            . '</div>';
        return $this->wrap($field_id, $config, $inner);
    }

    public function validate(mixed $value, array $config): bool|string
    {
        $base = parent::validate($value, $config);
        if ($base !== true) {
            return $base;
        }
        if ($value === '' || $value === null) {
            return true;
        }
        if (!is_numeric($value)) {
            return 'Bitte geben Sie einen gültigen Betrag ein.';
        }
        $num = (float)$value;
        if (($config['min_value'] ?? '') !== '' && $num < (float)$config['min_value']) {
            return 'Mindestwert: ' . $config['min_value'];
        }
        if (($config['max_value'] ?? '') !== '' && $num > (float)$config['max_value']) {
            return 'Maximalwert: ' . $config['max_value'];
        }
        return true;
    }

    public function getClientValidation(): array
    {
        return [['rule' => 'currency-range', 'fn' => <<<'JS'
            function (fieldEl) {
                var inp = fieldEl.querySelector('input[type="number"]');
                if (!inp || inp.value.trim() === '') return null;
                var val = parseFloat(inp.value);
                if (isNaN(val)) return 'Bitte geben Sie einen gültigen Betrag ein.';
                var min = inp.getAttribute('min');
                var max = inp.getAttribute('max');
                if (min !== null && min !== '' && val < parseFloat(min)) return 'Mindestwert: ' + min;
                if (max !== null && max !== '' && val > parseFloat(max)) return 'Maximalwert: ' + max;
                return null;
            }
            JS]];
    }

    public function map(mixed $value, array $config): string
    {
        if ($value === '' || $value === null) {
            return '[Kein Eintrag]';
        }
        $symbol = self::CURRENCIES[$config['currency'] ?? 'EUR'] ?? '€';
        $number = is_numeric($value) ? number_format((float)$value, 2, ',', '.') : (string)$value;
        return trim($number . ' ' . $symbol);
    }

    public function getDefaultConfig(): array
    {
        return array_merge(parent::getDefaultConfig(), [
            'currency'  => 'EUR',
            'min_value' => '',
            'max_value' => '',
        ]);
    }

    public function getGeneralSchema(): array
    {
        $currencyOptions = [];
        foreach (self::CURRENCIES as $code => $sym) {
            $currencyOptions[] = ['value' => $code, 'label' => $code . ' ' . $sym];
        }
        return array_merge($this->baseGeneralEntries(), [
            ['key' => 'currency',  'type' => 'select', 'label' => 'Währung', 'options' => $currencyOptions],
            ['key' => 'min_value', 'type' => 'number', 'label' => 'Mindestwert', 'hint' => 'Leer = kein Minimum'],
            ['key' => 'max_value', 'type' => 'number', 'label' => 'Maximalwert', 'hint' => 'Leer = kein Maximum'],
        ]);
    }
}
