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

class PhoneField extends BaseField
{
    public function getLabel(): string
    {
        return 'Telefon';
    }
    public function getIcon(): string
    {
        return 'fa-solid fa-phone';
    }


    public function getClientValidation(): array
    {
        return [['rule' => 'phone', 'fn' => <<<'JS'
            function (fieldEl) {
                var inp = fieldEl.querySelector('input[type="tel"]');
                if (!inp || !inp.value.trim()) return null;
                var mode = inp.dataset.phoneMode || '';
                if (!mode) return null;
                var v = inp.value.replace(/[\s\-\(\)\/]/g, '');
                if (mode === 'any') {
                    return /^\+?[0-9]{7,15}$/.test(v)
                        ? null : 'Bitte geben Sie eine gültige Telefonnummer ein.';
                }
                if (mode === 'countries') {
                    if (v.charAt(0) !== '+')
                        return 'Bitte geben Sie die Nummer mit internationaler Vorwahl (+...) ein.';
                    var digits = v.slice(1);
                    var cmode  = inp.dataset.phoneCountryMode || 'allow';
                    var list   = JSON.parse(inp.dataset.phoneCountryList || '[]')
                                    .map(function (c) { return c.replace('+', ''); });
                    list.sort(function (a, b) { return b.length - a.length; });
                    var inList = list.some(function (code) { return digits.indexOf(code) === 0; });
                    if (cmode === 'allow'    && !inList) return 'Diese Telefonnummer ist für Ihr Land nicht zugelassen.';
                    if (cmode === 'disallow' &&  inList) return 'Diese Telefonnummer ist für Ihr Land nicht zugelassen.';
                }
                return null;
            }
            JS]];
    }

    public function render(array $config, string $field_id, mixed $value = null): string
    {
        $mode  = $config['phone_mode'] ?? '';
        $attrs = $this->inputAttrs($config, $field_id, 'tel', ['value' => esc_attr((string)($value ?? ''))]);
        if ($mode !== '') {
            $attrs .= ' data-phone-mode="' . esc_attr($mode) . '"';
        }
        if ($mode === 'countries') {
            $cmode = $config['phone_country_mode'] ?? 'allow';
            $list  = array_values((array)($config['phone_country_list'] ?? []));
            $attrs .= ' data-phone-country-mode="' . esc_attr($cmode) . '"';
            if (!empty($list)) {
                $attrs .= " data-phone-country-list='" . esc_attr(json_encode($list)) . "'";
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

        $v    = preg_replace('/[\s\-\(\)\/]/', '', (string)$value);
        $mode = $config['phone_mode'] ?? '';

        switch ($mode) {
            case 'any':
                if (!preg_match('/^\+?[0-9]{7,15}$/', $v)) {
                    return 'Bitte geben Sie eine gültige Telefonnummer ein.';
                }
                break;

            case 'countries':
                if (!str_starts_with($v, '+')) {
                    return 'Bitte geben Sie die Nummer mit internationaler Vorwahl (+...) ein.';
                }
                $digits = substr($v, 1);
                $list   = (array)($config['phone_country_list'] ?? []); /* e.g. ['+49', '+43'] */
                $cmode  = $config['phone_country_mode'] ?? 'allow';
                $in     = false;
                /* Sort by code length descending so longer codes (e.g. +358) match before shorter (+35) */
                $sorted = $list;
                usort($sorted, static function ($a, $b) {
                    return strlen($b) - strlen($a);
                });
                foreach ($sorted as $entry) {
                    $code = ltrim((string)$entry, '+');
                    if (str_starts_with($digits, $code)) {
                        $in = true;
                        break;
                    }
                }
                if ($cmode === 'allow' && !$in) {
                    return 'Diese Telefonnummer ist für Ihr Land nicht zugelassen.';
                }
                if ($cmode === 'disallow' && $in) {
                    return 'Diese Telefonnummer ist für Ihr Land nicht zugelassen.';
                }
                break;
        }

        return true;
    }

    public function getDefaultConfig(): array
    {
        return array_merge(parent::getDefaultConfig(), [
            'phone_mode'         => '',
            'phone_country_mode' => 'allow',
            'phone_country_list' => [],
        ]);
    }

    public function getGeneralSchema(): array
    {
        return $this->baseGeneralEntries();
    }
}
