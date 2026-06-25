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

class WebsiteField extends BaseField
{
    public function getLabel(): string
    {
        return 'Website';
    }
    public function getIcon(): string
    {
        return 'fa-solid fa-globe';
    }

    public function getClientValidation(): array
    {
        return [['rule' => 'website-url', 'fn' => <<<'JS'
            function (fieldEl) {
                var inp = fieldEl.querySelector('input[type="url"]');
                if (!inp || !inp.value.trim() || inp.dataset.validateUrl !== '1') return null;
                return /^https?:\/\/.+\..+/.test(inp.value.trim())
                    ? null : 'Bitte geben Sie eine gültige URL ein (z.B. https://beispiel.de).';
            }
            JS]];
    }

    public function render(array $config, string $field_id, mixed $value = null): string
    {
        $ph    = $config['placeholder'] ?? 'https://';
        $attrs = $this->inputAttrs($config, $field_id, 'url', [
            'value'       => esc_attr((string)($value ?? '')),
            'placeholder' => $ph,
        ]);
        if (!empty($config['validate_url'])) {
            $attrs .= ' data-validate-url="1"';
        }
        return $this->wrap($field_id, $config, '<input' . $attrs . '>');
    }

    public function validate(mixed $value, array $config): bool|string
    {
        $base = parent::validate($value, $config);
        if ($base !== true) {
            return $base;
        }
        if (!empty($value) && !empty($config['validate_url'])) {
            if (!filter_var((string)$value, FILTER_VALIDATE_URL)) {
                return 'Bitte geben Sie eine gültige URL ein (z.B. https://beispiel.de).';
            }
        }
        return true;
    }

    public function getDefaultConfig(): array
    {
        return array_merge(parent::getDefaultConfig(), ['validate_url' => true]);
    }

    public function getGeneralSchema(): array
    {
        return array_merge($this->baseGeneralEntries(), [
            ['key' => 'validate_url', 'type' => 'checkbox', 'label' => 'URL-Format prüfen'],
        ]);
    }
}
