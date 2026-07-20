<?php

/**
 * Address composite field (street, city, postcode, country).
 *
 * PHP Version 8.1
 *
 * @category  FormForge
 * @package   FormForge
 * @author    Alexander Jorek
 * @copyright 2026 Alexander Jorek
 * @license   https://www.gnu.org/licenses/gpl-2.0.html GPL-2.0-or-later
 * @version   1.0.0
 * @link      https://github.com/AlexanderJorek/FormForge
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2
 * of the License, or (at your option) any later version.
 */

namespace ForgeForms\Fields;

defined('ABSPATH') || exit;

/**
 * Address input field with street, city, postal code, and country subfields.
 */
class AddressField extends BaseField
{
    /**
     * Returns the field type label.
     *
     * @return string
     */
    public function getLabel(): string
    {
        return __('Address', 'form-forge');
    }

    /**
     * Returns field-specific CSS styles.
     *
     * @return string
     */
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

    /**
     * Returns the Font Awesome icon class.
     *
     * @return string
     */
    public function getIcon(): string
    {
        return 'fa-solid fa-location-dot';
    }

    private const SUBFIELDS = [
        ['key' => 'street',  'optional' => true, 'label' => 'Street and house number'],
        ['key' => 'street2', 'optional' => true, 'label' => 'Address supplement'],
        ['key' => 'city',    'optional' => true, 'label' => 'City'],
        ['key' => 'state',   'optional' => true, 'label' => 'State / Canton'],
        ['key' => 'zip',     'optional' => true, 'label' => 'Postal code'],
        ['key' => 'country', 'optional' => true, 'label' => 'Country'],
    ];

    /**
     * Renders the field HTML.
     *
     * @param array  $config   Field configuration.
     * @param string $field_id Unique field identifier.
     * @param mixed  $value    Current field value.
     *
     * @return string Rendered HTML.
     */
    public function render(array $config, string $field_id, mixed $value = null): string
    {
        if (empty($config['expanded'])) {
            $attrs = $this->inputAttrs($config, $field_id, 'text', ['value' => esc_attr((string)(is_array($value) ? '' : ($value ?? '')))]);
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

            $req_star = !empty($config[$k . '_required']) ? ' <span class="forge-required" aria-hidden="true">*</span>' : '';
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

    /**
     * Returns the HTML autocomplete token for a given subfield key.
     *
     * @param string $key Subfield key.
     *
     * @return string HTML autocomplete attribute value.
     */
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

    /**
     * Returns the sanitized composite subfield array submitted as $field_id[key].
     *
     * @param string $field_id The field element ID.
     *
     * @return mixed
     */
    public function extractValue(string $field_id): mixed
    {
        $raw = $_POST[$field_id] ?? '';
        if (is_array($raw)) {
            return array_map(static fn($v) => sanitize_text_field(wp_unslash($v)), $raw);
        }
        return sanitize_text_field(wp_unslash((string) $raw));
    }

    /**
     * Validates the submitted value.
     *
     * @param mixed $value  Submitted value.
     * @param array $config Field configuration.
     *
     * @return bool|string True on valid, error message string on invalid.
     */
    public function validate(mixed $value, array $config): bool|string
    {
        if (empty($config['expanded'])) {
            $scalar = is_array($value) ? '' : trim((string)($value ?? ''));
            if (!empty($config['required']) && $scalar === '') {
                $label = $config['label'] ?? __('Address', 'form-forge');
                return sprintf(__('%s: Required field.', 'form-forge'), esc_html($label));
            }
            return true;
        }
        $errors = [];
        foreach (self::SUBFIELDS as $sf) {
            $k = $sf['key'];
            if ($sf['optional'] && empty($config[$k . '_enabled'])) {
                continue;
            }
            if (!empty($config[$k . '_required'])) {
                if (trim((string)($value[$k] ?? '')) === '') {
                    $errors[] = $config[$k . '_label'] ?? __($sf['label'], 'form-forge');
                }
            }
        }
        return $errors
            ? sprintf(__('%s: Required field.', 'form-forge'), implode(', ', $errors))
            : true;
    }

    /**
     * Maps the field value to a flat string for display in submissions.
     *
     * @param mixed $value  Submitted value.
     * @param array $config Field configuration.
     *
     * @return string Formatted address string.
     */
    public function map(mixed $value, array $config): string
    {
        if (!is_array($value)) {
            return __('[No entry]', 'form-forge');
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
        return $lines ? implode(', ', $lines) : __('[No entry]', 'form-forge');
    }

    /**
     * Returns the default field configuration.
     *
     * @return array
     */
    public function getDefaultConfig(): array
    {
        return array_merge(
            parent::getDefaultConfig(),
            [
            'expanded'            => false,
            'street_enabled'      => true,
            'street_label'        => __('Street and house number', 'form-forge'),
            'street_placeholder'  => '',
            'street_required'     => true,
            'street2_enabled'     => true,
            'street2_label'       => __('Address supplement', 'form-forge'),
            'street2_placeholder' => __('Apartment, floor, c/o ...', 'form-forge'),
            'street2_required'    => false,
            'city_enabled'        => true,
            'city_label'          => __('City', 'form-forge'),
            'city_placeholder'    => '',
            'city_required'       => true,
            'state_enabled'       => false,
            'state_label'         => __('State / Canton', 'form-forge'),
            'state_placeholder'   => '',
            'state_required'      => false,
            'zip_enabled'         => true,
            'zip_label'           => __('Postal code', 'form-forge'),
            'zip_placeholder'     => '',
            'zip_required'        => true,
            'country_enabled'     => false,
            'country_label'       => __('Country', 'form-forge'),
            'country_placeholder' => '',
            'country_required'    => false,
            ]
        );
    }

    /**
     * Returns the general settings schema for the field editor.
     *
     * @return array
     */
    public function getGeneralSchema(): array
    {
        return [
            [
                'key'         => 'expanded',
                'type'        => 'bool_seg',
                'label'       => __('Mode', 'form-forge'),
                'false_label' => __('Simple', 'form-forge'),
                'true_label'  => __('Extended', 'form-forge'),
                'rebuild'     => true,
            ],
            [
                'key'        => 'placeholder',
                'type'       => 'text',
                'label'      => __('Placeholder', 'form-forge'),
                'depends_on' => ['expanded' => false],
            ],
            [
                'key'        => 'description',
                'type'       => 'text',
                'label'      => __('Hint text', 'form-forge'),
                'depends_on' => ['expanded' => false],
            ],
            [
                'key'        => 'subfields',
                'type'       => 'subfields',
                'label'      => __('Sub-fields', 'form-forge'),
                'depends_on' => ['expanded' => true],
                'items'      => self::SUBFIELDS,
            ],
        ];
    }
}
