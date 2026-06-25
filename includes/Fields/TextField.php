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

class TextField extends BaseField
{
    public function getLabel(): string
    {
        return 'Text';
    }
    public function getIcon(): string
    {
        return 'fa-solid fa-font';
    }

    public function render(array $config, string $field_id, mixed $value = null): string
    {
        $attrs = $this->inputAttrs($config, $field_id, 'text', ['value' => esc_attr((string)($value ?? ''))]);
        $max   = (int)($config['limit_max'] ?? 0);
        if ($max > 0 && ($config['limit_type'] ?? 'chars') === 'chars') {
            $attrs .= ' maxlength="' . $max . '"';
        }
        if ($max > 0 && ($config['limit_type'] ?? 'chars') === 'words') {
            $attrs .= ' data-word-limit="' . $max . '"';
        }
        return $this->wrap($field_id, $config, '<input' . $attrs . '>');
    }

    public function validate(mixed $value, array $config): bool|string
    {
        $base = parent::validate($value, $config);
        if ($base !== true) {
            return $base;
        }
        $max  = (int)($config['limit_max'] ?? 0);
        $type = $config['limit_type'] ?? 'chars';
        if ($max > 0 && $type === 'words' && !empty($value)) {
            $count = count(preg_split('/\s+/', trim((string)$value), -1, PREG_SPLIT_NO_EMPTY));
            if ($count > $max) {
                return 'Bitte maximal ' . $max . ' Wörter eingeben (aktuell: ' . $count . ').';
            }
        }
        return true;
    }

    public function getClientValidation(): array
    {
        return [['rule' => 'text-word-limit', 'fn' => <<<'JS'
            function (fieldEl) {
                var inp = fieldEl.querySelector('[data-word-limit]');
                if (!inp || !inp.value.trim()) return null;
                var limit = parseInt(inp.dataset.wordLimit, 10);
                if (!limit) return null;
                var count = inp.value.trim().split(/\s+/).filter(Boolean).length;
                return count <= limit ? null
                    : 'Bitte maximal ' + limit + ' Wörter eingeben (aktuell: ' + count + ').';
            }
            JS]];
    }

    public function getDefaultConfig(): array
    {
        return array_merge(parent::getDefaultConfig(), [
            'limit_type' => 'chars',
            'limit_max'  => '',
        ]);
    }

    public function getGeneralSchema(): array
    {
        return array_merge($this->baseGeneralEntries(), [
            ['key' => 'limit_type', 'type' => 'limit_row', 'label' => 'Begrenzung', 'count_key' => 'limit_max'],
        ]);
    }
}
