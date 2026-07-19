<?php

/**
 * Full name input field (first and last name).
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
 * Name input field.
 */
class NameField extends BaseField
{
    /**
     * Returns the field type label.
     *
     * @return string
     */
    public function getLabel(): string
    {
        return __('Name', 'form-forge');
    }

    /**
     * Returns field-specific CSS styles.
     *
     * @return string
     */
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

    /**
     * Returns the Font Awesome icon class.
     *
     * @return string
     */
    public function getIcon(): string
    {
        return 'fa-solid fa-user';
    }

    private const SUBFIELDS = [
        ['key' => 'prefix', 'optional' => true, 'label' => 'Salutation',  'is_select' => true],
        ['key' => 'fname',  'optional' => true, 'label' => 'First name'],
        ['key' => 'mname',  'optional' => true, 'label' => 'Middle name'],
        ['key' => 'lname',  'optional' => true, 'label' => 'Last name'],
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
            $attrs = $this->inputAttrs($config, $field_id, 'text', ['value' => esc_attr((string)($value ?? ''))]);
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

            $req_star = !empty($config[$k . '_required']) ? ' <span class="forge-required" aria-hidden="true">*</span>' : '';
            $sub_class = !empty($sf['is_select']) ? ' forge-name-sub--prefix' : '';
            $inner .= '<div class="forge-name-sub' . $sub_class . '">'
                . '<label class="forge-sub-label">' . $label . $req_star . '</label>';
            if (!empty($sf['is_select'])) {
                $cur    = esc_attr((string)($val[$k] ?? ''));
                $inner .= '<select name="' . esc_attr($field_id) . '[' . $k . ']"'
                    . ' class="forge-input forge-name-prefix" aria-label="' . $label . '"' . $req . '>';
                foreach (['', __('Mr.', 'form-forge'), __('Ms.', 'form-forge'), __('Diverse', 'form-forge'), 'Dr.', 'Prof.', 'Dipl.', 'Ing.'] as $opt) {
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
            if (!empty($config['required']) && trim((string)($value ?? '')) === '') {
                $label = $config['label'] ?? __('Name', 'form-forge');
                return sprintf(__('%s: Required field.', 'form-forge'), esc_html($label));
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
     * @return string Formatted name string.
     */
    public function map(mixed $value, array $config): string
    {
        if (empty($config['expanded'])) {
            return trim((string)($value ?? '')) ?: __('[No entry]', 'form-forge');
        }
        if (!is_array($value)) {
            return __('[No entry]', 'form-forge');
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
        return $parts ? implode(' ', $parts) : __('[No entry]', 'form-forge');
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
            'expanded'           => false,
            'prefix_enabled'     => false,
            'prefix_label'       => __('Salutation', 'form-forge'),
            'prefix_placeholder' => '',
            'prefix_required'    => false,
            'fname_enabled'      => true,
            'fname_label'        => __('First name', 'form-forge'),
            'fname_placeholder'  => '',
            'fname_required'     => true,
            'mname_enabled'      => false,
            'mname_label'        => __('Middle name', 'form-forge'),
            'mname_placeholder'  => '',
            'mname_required'     => false,
            'lname_enabled'      => true,
            'lname_label'        => __('Last name', 'form-forge'),
            'lname_placeholder'  => '',
            'lname_required'     => true,
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
