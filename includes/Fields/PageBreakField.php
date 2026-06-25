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

class PageBreakField extends BaseField
{
    public function getStyles(): string
    {
        return <<<'CSS'
.forge-form-page { display: none; }
.forge-form-page.forge-page-active { display: block; }
.forge-page-nav {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-top: var(--forge-gap);
    padding: 14px 0 0;
    border-top: 1px solid var(--forge-border);
    gap: 10px;
}
.forge-page-nav--top {
    display: flex;
    align-items: center;
    border-top: none;
    padding: 0;
    margin: 0 0 var(--forge-gap);
}
.forge-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 9px 20px;
    border-radius: var(--forge-radius);
    border: 1px solid var(--forge-border-input);
    background: var(--forge-bg);
    color: var(--forge-text-muted);
    font-size: 14px;
    font-family: var(--forge-font);
    cursor: pointer;
    transition: background .1s, border-color .1s;
}
.forge-btn:hover {
    background: var(--forge-bg-subtle);
    border-color: var(--forge-text-subtle);
}
.forge-btn-next {
    margin-left: auto;
    background: var(--forge-accent);
    border-color: var(--forge-accent);
    color: #fff;
    font-weight: 600;
}
.forge-btn-next:hover,
.forge-btn-next:focus,
.forge-btn-next:focus-visible {
    background: var(--forge-accent-dark);
    border-color: var(--forge-accent-dark);
    color: #fff;
    outline: none;
}
.forge-page-nav--top .forge-btn-prev {
    border-color: transparent;
    background: transparent;
    color: var(--forge-accent);
    padding-left: 0; padding-right: 0;
    font-size: 13px;
}
.forge-page-nav--top .forge-btn-prev:hover {
    background: transparent;
    border-color: transparent;
    color: var(--forge-accent-dark);
    text-decoration: underline;
}
CSS;
    }

    public function getLabel(): string
    {
        return 'Seitenumbruch';
    }
    public function getIcon(): string
    {
        return 'fa-solid fa-file';
    }
    public function hasSettingsPanel(): bool
    {
        return false;
    }
    public function hasRequired(): bool
    {
        return false;
    }
    public function skipValidation(): bool
    {
        return true;
    }

    public function render(array $config, string $field_id, mixed $value = null): string
    {
        $prev_label = esc_html($config['prev_btn'] ?? '← Zurück');
        $next_label = esc_html($config['next_btn'] ?? 'Weiter →');

        return '<div class="forge-page-break" data-field-id="' . esc_attr($field_id) . '" data-page-break="true">'
            . '<div class="forge-page-nav">'
            . '<button type="button" class="forge-btn forge-btn-prev">' . $prev_label . '</button>'
            . '<button type="button" class="forge-btn forge-btn-next">' . $next_label . '</button>'
            . '</div></div>';
    }

    public function map(mixed $value, array $config): string
    {
        return '';
    }

    public function getDefaultConfig(): array
    {
        return [
            'label'       => 'Seitenumbruch',
            'prev_btn'    => '← Zurück',
            'next_btn'    => 'Weiter →',
            'required'    => false,
            'description' => '',
        ];
    }

    public function getGeneralSchema(): array
    {
        return [
            ['key' => 'prev_btn', 'type' => 'text', 'label' => 'Zurück-Button'],
            ['key' => 'next_btn', 'type' => 'text', 'label' => 'Weiter-Button'],
        ];
    }
}
