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

class HtmlField extends BaseField
{
    public function getStyles(): string
    {
        return <<<'CSS'
.forge-field--html { font-size: 14px; line-height: 1.7; color: var(--forge-text); }
.forge-field--html a { color: var(--forge-accent); }
CSS;
    }

    public function getLabel(): string
    {
        return 'HTML-Block';
    }
    public function getIcon(): string
    {
        return 'fa-solid fa-code';
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
        $html = wp_kses_post($config['html_content'] ?? '');
        return '<div class="forge-field forge-field--html" data-field-id="'
            . esc_attr($field_id) . '">'
            . $html
            . '</div>';
    }

    public function map(mixed $value, array $config): string
    {
        return wp_strip_all_tags($config['html_content'] ?? '');
    }

    public function getDefaultConfig(): array
    {
        return [
            'label'        => 'HTML-Block',
            'html_content' => '<p>Text hier</p>',
            'required'     => false,
            'description'  => '',
        ];
    }

    public function getGeneralSchema(): array
    {
        return [
            ['key' => 'html_content', 'type' => 'html_editor', 'label' => 'HTML-Inhalt'],
        ];
    }
}
