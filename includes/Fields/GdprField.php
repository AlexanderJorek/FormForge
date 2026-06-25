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

class GdprField extends BaseField
{
    public function getStyles(): string
    {
        return <<<'CSS'
.forge-gdpr-label {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    cursor: pointer;
    font-size: 14px;
    color: var(--forge-text);
    line-height: 1.6;
    user-select: none;
}
.forge-gdpr-label input[type="checkbox"] {
    appearance: none;
    -webkit-appearance: none;
    width: 18px;
    height: 18px;
    flex-shrink: 0;
    border: 2px solid var(--forge-border-input);
    border-radius: var(--forge-radius-sm);
    background: var(--forge-bg);
    cursor: pointer;
    transition: border-color .15s, background .15s;
    margin-top: 3px;
}
.forge-gdpr-label input[type="checkbox"]:checked {
    border-color: var(--forge-accent);
    background: var(--forge-accent);
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 12 10'%3E%3Cpolyline points='1,5 4.5,8.5 11,1' stroke='%23ffffff' stroke-width='2' fill='none' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: center;
    background-size: 11px 9px;
}
.forge-gdpr-label input[type="checkbox"]:focus-visible {
    outline: none;
    box-shadow: 0 0 0 3px
        color-mix(in srgb, var(--forge-accent) 25%, transparent);
}
.forge-gdpr-text a { color: var(--forge-accent); text-decoration: underline; }
.forge-gdpr-text a:hover { color: var(--forge-accent-dark); }
CSS;
    }

    public function getLabel(): string
    {
        return 'DSGVO-Checkbox';
    }
    public function getIcon(): string
    {
        return 'fa-solid fa-shield-halved';
    }

    public function render(array $config, string $field_id, mixed $value = null): string
    {
        $req     = ' required aria-required="true"';
        $checked = !empty($value) ? ' checked' : '';
        $policy_url  = esc_url($config['privacy_policy_url'] ?? get_privacy_policy_url());
        $policy_text = esc_html($config['privacy_policy_text'] ?? 'Datenschutzerklärung');

        $text = sprintf(
            'Ich habe die <a href="%s" target="_blank" rel="noopener">%s</a> gelesen und akzeptiere diese.',
            $policy_url,
            $policy_text
        );

        $inner = '<label class="forge-consent-label forge-gdpr-label">'
            . '<input type="checkbox" id="' . esc_attr($field_id)
            . '" name="' . esc_attr($field_id) . '" value="1"' . $checked . $req . '>'
            . '<span class="forge-consent-text">' . $text . '</span>'
            . '</label>';

        return $this->wrap($field_id, $config, $inner);
    }

    public function validate(mixed $value, array $config): bool|string
    {
        if (empty($value)) {
            return 'Bitte akzeptieren Sie die Datenschutzerklärung.';
        }
        return true;
    }

    public function map(mixed $value, array $config): string
    {
        return !empty($value) ? 'Datenschutz akzeptiert' : 'Datenschutz nicht akzeptiert';
    }

    public function getDefaultConfig(): array
    {
        return array_merge(parent::getDefaultConfig(), [
            'privacy_policy_url'  => '',
            'privacy_policy_text' => 'Datenschutzerklärung',
        ]);
    }

    public function getGeneralSchema(): array
    {
        return [
            ['key' => 'privacy_policy_url',  'type' => 'text', 'label' => 'Datenschutz-URL'],
            ['key' => 'privacy_policy_text', 'type' => 'text', 'label' => 'Datenschutz-Linktext'],
        ];
    }
}
