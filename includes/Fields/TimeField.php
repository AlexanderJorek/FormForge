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

class TimeField extends BaseField
{
    public function getStyles(): string
    {
        return <<<'CSS'
.forge-time-wrap { display: flex; align-items: center; gap: 8px; }
.forge-time-sep { font-weight: 600; color: var(--forge-text-muted); }
.forge-time-wrap .forge-input { width: 72px; text-align: center; padding: 0 8px; }
@media (max-width: 600px) { .forge-time-wrap { flex-wrap: wrap; } }
CSS;
    }

    public function getLabel(): string
    {
        return 'Uhrzeit';
    }
    public function getIcon(): string
    {
        return 'fa-solid fa-clock';
    }

    public function render(array $config, string $field_id, mixed $value = null): string
    {
        $is12h   = !empty($config['time_format']);
        $prefill = !empty($config['prefill_now']) ? ' data-prefill-now="true"' : '';
        $attrs   = $this->inputAttrs($config, $field_id, 'time', ['value' => esc_attr((string)($value ?? ''))]);
        if ($is12h) {
            $attrs .= ' data-time-format="12h"';
        }
        return $this->wrap($field_id, $config, '<input' . $attrs . $prefill . '>');
    }

    public function getDefaultConfig(): array
    {
        return array_merge(parent::getDefaultConfig(), [
            'time_format'  => false,
            'prefill_now'  => false,
        ]);
    }

    public function getGeneralSchema(): array
    {
        return array_merge($this->baseGeneralEntries(), [
            ['type' => 'time_row', 'format_key' => 'time_format', 'prefill_key' => 'prefill_now'],
        ]);
    }
}
