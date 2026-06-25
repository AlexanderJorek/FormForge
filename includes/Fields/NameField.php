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

class NameField extends BaseField
{
    public function getLabel(): string
    {
        return 'Name';
    }

    public function getStyles(): string
    {
        return <<<'CSS'
.forge-name-group {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    align-items: flex-start;
}
.forge-name-sub {
    display: flex;
    flex-direction: column;
    flex: 1;
    min-width: 130px;
}
.forge-name-sub--prefix {
    flex: 0 0 auto;
    min-width: 0;
    width: 110px;
}
.forge-name-sub--prefix .forge-input { width: 100%; }
@media (max-width: 600px) {
    .forge-name-group { flex-direction: column; }
}
CSS;
    }
    public function getIcon(): string
    {
        return 'fa-solid fa-user';
    }

    private const SUBFIELDS = [
        ['key' => 'prefix', 'optional' => true, 'label' => 'Anrede',    'is_select' => true],
        ['key' => 'fname',  'optional' => true, 'label' => 'Vorname'],
        ['key' => 'mname',  'optional' => true, 'label' => 'Mittelname'],
        ['key' => 'lname',  'optional' => true, 'label' => 'Nachname'],
    ];

    public function render(array $config, string $field_id, mixed $value = null): string
    {
        if (empty($config['expanded'])) {
            $attrs = $this->inputAttrs(
                $config,
                $field_id,
                'text',
                ['value' => esc_attr((string)($value ?? ''))]
            );
            return $this->wrap($field_id, $config, '<input' . $attrs . '>');
        }

        $val   = is_array($value) ? $value : [];
        $inner = '<div class="forge-name-group">';

        foreach (self::SUBFIELDS as $sf) {
            $k = $sf['key'];
            if (empty($config[$k . '_enabled'])) {
                continue;
            }
            $label = esc_html($config[$k . '_label'] ?? $sf['label']);
            $ph    = esc_attr($config[$k . '_placeholder'] ?? '');
            $req   = !empty($config[$k . '_required']) ? ' required aria-required="true"' : '';

            $req_star = !empty($config[$k . '_required'])
                ? ' <span class="forge-required" aria-hidden="true">*</span>' : '';
            $sub_class = !empty($sf['is_select']) ? ' forge-name-sub--prefix' : '';
            $inner .= '<div class="forge-name-sub' . $sub_class . '">'
                . '<label class="forge-sub-label">' . $label . $req_star . '</label>';
            if (!empty($sf['is_select'])) {
                $cur    = esc_attr((string)($val[$k] ?? ''));
                $inner .= '<select name="' . esc_attr($field_id) . '[' . $k . ']"'
                    . ' class="forge-input forge-name-prefix" aria-label="' . $label . '"' . $req . '>';
                foreach (['', 'Herr', 'Frau', 'Divers', 'Dr.', 'Prof.', 'Dipl.', 'Ing.'] as $opt) {
                    $inner .= '<option value="' . esc_attr($opt) . '"' . selected($cur, $opt, false) . '>'
                        . ($opt === '' ? '—' : esc_html($opt)) . '</option>';
                }
                $inner .= '</select>';
            } else {
                $inner .= '<input type="text" name="' . esc_attr($field_id) . '[' . $k . ']"'
                    . ' class="forge-input" placeholder="' . $ph . '"'
                    . ' value="' . esc_attr((string)($val[$k] ?? '')) . '"' . $req . '>';
            }
            $inner .= '<div class="forge-field-error forge-sub-error"></div>';
            $inner .= '</div>';
        }

        $inner .= '</div>';
        $wrapper_config             = $config;
        $wrapper_config['required'] = false;
        return $this->wrap($field_id, $wrapper_config, $inner);
    }

    public function validate(mixed $value, array $config): bool|string
    {
        if (empty($config['expanded'])) {
            if (!empty($config['required']) && trim((string)($value ?? '')) === '') {
                return ($config['label'] ?? 'Name') . ': Pflichtfeld.';
            }
            return true;
        }
        $errors = [];
        foreach (self::SUBFIELDS as $sf) {
            $k = $sf['key'];
            if (empty($config[$k . '_enabled'])) {
                continue;
            }
            if (!empty($sf['is_select'])) {
                continue; // select always has a value; "—" is a valid no-preference answer
            }
            if (!empty($config[$k . '_required'])) {
                if (trim((string)(is_array($value) ? ($value[$k] ?? '') : '')) === '') {
                    $errors[] = $config[$k . '_label'] ?? $sf['label'];
                }
            }
        }
        return $errors ? implode(', ', $errors) . ': Pflichtfeld.' : true;
    }

    public function map(mixed $value, array $config): string
    {
        if (empty($config['expanded'])) {
            return trim((string)($value ?? '')) ?: '[Kein Eintrag]';
        }
        if (!is_array($value)) {
            return '[Kein Eintrag]';
        }
        $parts = [];
        foreach (self::SUBFIELDS as $sf) {
            $k = $sf['key'];
            if (empty($config[$k . '_enabled'])) {
                continue;
            }
            $v = trim((string)($value[$k] ?? ''));
            if ($v !== '') {
                $parts[] = $v;
            }
        }
        return $parts ? implode(' ', $parts) : '[Kein Eintrag]';
    }

    public function getDefaultConfig(): array
    {
        return array_merge(parent::getDefaultConfig(), [
            'expanded'           => false,
            'prefix_enabled'     => false,
            'prefix_label'       => 'Anrede',
            'prefix_placeholder' => '',
            'prefix_required'    => false,
            'fname_enabled'      => true,
            'fname_label'        => 'Vorname',
            'fname_placeholder'  => '',
            'fname_required'     => true,
            'mname_enabled'      => false,
            'mname_label'        => 'Mittelname',
            'mname_placeholder'  => '',
            'mname_required'     => false,
            'lname_enabled'      => true,
            'lname_label'        => 'Nachname',
            'lname_placeholder'  => '',
            'lname_required'     => true,
        ]);
    }

    public function getGeneralSchema(): array
    {
        return [
            ['key' => 'expanded', 'type' => 'bool_seg', 'label' => 'Modus',
             'false_label' => 'Einzeln', 'true_label' => 'Erweitert', 'rebuild' => true],
            ['key' => 'placeholder',  'type' => 'text', 'label' => 'Platzhalter',
             'depends_on' => ['expanded' => false]],
            ['key' => 'description',  'type' => 'text', 'label' => 'Hinweistext',
             'depends_on' => ['expanded' => false]],
            ['key' => 'subfields', 'type' => 'subfields', 'label' => 'Teilfelder',
             'depends_on' => ['expanded' => true], 'items' => self::SUBFIELDS],
        ];
    }
}
