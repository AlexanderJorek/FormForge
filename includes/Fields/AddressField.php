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

class AddressField extends BaseField
{
    public function getLabel(): string
    {
        return 'Adresse';
    }

    public function getStyles(): string
    {
        return <<<'CSS'
.forge-address-group { display: flex; flex-direction: column; gap: 10px; }
.forge-address-row { display: flex; gap: 10px; }
.forge-address-zip { width: 90px; flex-shrink: 0; }
.forge-address-city { flex: 1; }
@media (max-width: 600px) {
    .forge-address-row { flex-direction: column; }
    .forge-address-zip { width: 100%; }
}
CSS;
    }
    public function getIcon(): string
    {
        return 'fa-solid fa-location-dot';
    }

    private const SUBFIELDS = [
        ['key' => 'street',  'optional' => true, 'label' => 'Straße und Hausnummer'],
        ['key' => 'street2', 'optional' => true, 'label' => 'Adresszusatz'],
        ['key' => 'city',    'optional' => true, 'label' => 'Ort'],
        ['key' => 'state',   'optional' => true, 'label' => 'Bundesland / Kanton'],
        ['key' => 'zip',     'optional' => true, 'label' => 'Postleitzahl'],
        ['key' => 'country', 'optional' => true, 'label' => 'Land'],
    ];

    public function render(array $config, string $field_id, mixed $value = null): string
    {
        if (empty($config['expanded'])) {
            $attrs = $this->inputAttrs(
                $config,
                $field_id,
                'text',
                ['value' => esc_attr((string)(is_array($value) ? '' : ($value ?? '')))]
            );
            return $this->wrap($field_id, $config, '<input' . $attrs . '>');
        }

        $val   = is_array($value) ? $value : [];
        $inner = '<div class="forge-address-group">';

        foreach (self::SUBFIELDS as $sf) {
            $k = $sf['key'];
            if ($sf['optional'] && empty($config[$k . '_enabled'])) {
                continue;
            }
            $label = esc_html($config[$k . '_label'] ?? $sf['label']);
            $ph    = esc_attr($config[$k . '_placeholder'] ?? '');
            $req   = !empty($config[$k . '_required']) ? ' required aria-required="true"' : '';
            $ac    = esc_attr($this->autocompleteToken($k));

            $req_star = !empty($config[$k . '_required'])
                ? ' <span class="forge-required" aria-hidden="true">*</span>' : '';
            $inner .= '<div class="forge-address-sub">';
            $inner .= '<label class="forge-sub-label">' . $label . $req_star . '</label>';
            $inner .= '<input type="text"'
                . ' name="' . esc_attr($field_id) . '[' . esc_attr($k) . ']"'
                . ' class="forge-input" placeholder="' . $ph . '"'
                . ' value="' . esc_attr((string)($val[$k] ?? '')) . '"'
                . ' autocomplete="' . $ac . '"' . $req . '>';
            $inner .= '<div class="forge-field-error forge-sub-error"></div>';
            $inner .= '</div>';
        }

        $inner .= '</div>';
        /* In expanded mode each sub-input carries its own required — the wrapper
         * should not show a global * or forge-required-field class. */
        $wrapper_config             = $config;
        $wrapper_config['required'] = false;
        return $this->wrap($field_id, $wrapper_config, $inner);
    }

    private function autocompleteToken(string $key): string
    {
        return match ($key) {
            'street'  => 'address-line1',
            'street2' => 'address-line2',
            'city'    => 'address-level2',
            'state'   => 'address-level1',
            'zip'     => 'postal-code',
            'country' => 'country-name',
            default   => 'on',
        };
    }

    public function validate(mixed $value, array $config): bool|string
    {
        $errors = [];
        foreach (self::SUBFIELDS as $sf) {
            $k = $sf['key'];
            if ($sf['optional'] && empty($config[$k . '_enabled'])) {
                continue;
            }
            if (!empty($config[$k . '_required'])) {
                if (trim((string)($value[$k] ?? '')) === '') {
                    $errors[] = $config[$k . '_label'] ?? $sf['label'];
                }
            }
        }
        return $errors ? implode(', ', $errors) . ': Pflichtfeld.' : true;
    }

    public function map(mixed $value, array $config): string
    {
        if (!is_array($value)) {
            return '[Kein Eintrag]';
        }
        $sfMap = [];
        foreach (self::SUBFIELDS as $sf) {
            $sfMap[$sf['key']] = $sf;
        }
        $lines = [];
        foreach ([['street', 'street2'], ['zip', 'city'], ['state'], ['country']] as $group) {
            $parts = [];
            foreach ($group as $k) {
                if (isset($sfMap[$k]) && $sfMap[$k]['optional'] && empty($config[$k . '_enabled'])) {
                    continue;
                }
                $v = trim((string)($value[$k] ?? ''));
                if ($v !== '') {
                    $parts[] = $v;
                }
            }
            if ($parts) {
                $lines[] = implode(' ', $parts);
            }
        }
        return $lines ? implode(', ', $lines) : '[Kein Eintrag]';
    }

    public function getDefaultConfig(): array
    {
        return array_merge(parent::getDefaultConfig(), [
            'expanded'            => false,
            'street_enabled'      => true,
            'street_label'        => 'Straße und Hausnummer',
            'street_placeholder'  => '',
            'street_required'     => true,
            'street2_enabled'     => true,
            'street2_label'       => 'Adresszusatz',
            'street2_placeholder' => 'Apartment, Etage, c/o ...',
            'street2_required'    => false,
            'city_enabled'        => true,
            'city_label'          => 'Ort',
            'city_placeholder'    => '',
            'city_required'       => true,
            'state_enabled'       => false,
            'state_label'         => 'Bundesland / Kanton',
            'state_placeholder'   => '',
            'state_required'      => false,
            'zip_enabled'         => true,
            'zip_label'           => 'Postleitzahl',
            'zip_placeholder'     => '',
            'zip_required'        => true,
            'country_enabled'     => false,
            'country_label'       => 'Land',
            'country_placeholder' => '',
            'country_required'    => false,
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
