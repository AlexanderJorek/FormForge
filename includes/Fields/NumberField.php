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

class NumberField extends BaseField
{
    public function getStyles(): string
    {
        return <<<'CSS'
input[type="number"].forge-input { -moz-appearance: textfield !important; }
input[type="number"].forge-input::-webkit-outer-spin-button,
input[type="number"].forge-input::-webkit-inner-spin-button {
    -webkit-appearance: none;
    margin: 0;
}
CSS;
    }

    public function getLabel(): string
    {
        return 'Nummer';
    }
    public function getIcon(): string
    {
        return 'fa-solid fa-hashtag';
    }

    public function render(array $config, string $field_id, mixed $value = null): string
    {
        $extra = ['value' => esc_attr((string)($value ?? ''))];
        if ($config['min'] ?? '' !== '') {
            $extra['min'] = $config['min'];
        }
        if ($config['max'] ?? '' !== '') {
            $extra['max'] = $config['max'];
        }
        if ($config['step'] ?? '' !== '') {
            $extra['step'] = $config['step'];
        }
        $attrs = $this->inputAttrs($config, $field_id, 'number', $extra);
        return $this->wrap($field_id, $config, '<input' . $attrs . '>');
    }

    public function validate(mixed $value, array $config): bool|string
    {
        $base = parent::validate($value, $config);
        if ($base !== true) {
            return $base;
        }
        if ($value !== '' && $value !== null && !is_numeric($value)) {
            return 'Bitte geben Sie eine gültige Zahl ein.';
        }
        $num = (float)$value;
        if (($config['min'] ?? '') !== '' && $num < (float)$config['min']) {
            return 'Mindestwert: ' . $config['min'];
        }
        if (($config['max'] ?? '') !== '' && $num > (float)$config['max']) {
            return 'Maximalwert: ' . $config['max'];
        }
        return true;
    }

    public function getClientValidation(): array
    {
        return [['rule' => 'number-range', 'fn' => <<<'JS'
            function (fieldEl) {
                var inp = fieldEl.querySelector('input[type="number"]');
                if (!inp || inp.value.trim() === '') return null;
                var val = parseFloat(inp.value);
                if (isNaN(val)) return 'Bitte geben Sie eine gültige Zahl ein.';
                var min = inp.getAttribute('min');
                var max = inp.getAttribute('max');
                if (min !== null && min !== '' && val < parseFloat(min))
                    return 'Mindestwert: ' + min;
                if (max !== null && max !== '' && val > parseFloat(max))
                    return 'Maximalwert: ' + max;
                return null;
            }
            JS]];
    }

    public function getDefaultConfig(): array
    {
        return array_merge(parent::getDefaultConfig(), ['min' => '', 'max' => '', 'step' => 1]);
    }

    public function getGeneralSchema(): array
    {
        return array_merge($this->baseGeneralEntries(), [
            ['key' => 'min',  'type' => 'number', 'label' => 'Mindestwert'],
            ['key' => 'max',  'type' => 'number', 'label' => 'Maximalwert'],
            ['key' => 'step', 'type' => 'number', 'label' => 'Schrittweite'],
        ]);
    }
}
