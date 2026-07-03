<?php

/**
 * Multi-line text area input field.
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
 * Multi-line textarea input field.
 */
class TextareaField extends BaseField
{
    /**
     * Returns the field type label.
     *
     * @return string
     */
    public function getLabel(): string
    {
        return 'Textbereich';
    }

    /**
     * Returns the Font Awesome icon class.
     *
     * @return string
     */
    public function getIcon(): string
    {
        return 'fa-solid fa-align-left';
    }

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
        $req   = !empty($config['required']) ? ' required aria-required="true"' : '';
        $ph    = esc_attr($config['placeholder'] ?? '');
        $rows  = (int)($config['rows'] ?? 5);
        $max   = (int)($config['limit_max'] ?? 0);
        $wlim  = ($config['limit_type'] ?? 'chars') === 'words' && $max > 0 ? ' data-word-limit="' . $max . '"' : '';
        $clim  = ($config['limit_type'] ?? 'chars') === 'chars' && $max > 0 ? ' maxlength="' . $max . '"' : '';
        $inner = '<textarea id="' . esc_attr($field_id) . '" name="' . esc_attr($field_id) . '" '
            . 'class="forge-input forge-textarea" rows="' . $rows . '" placeholder="' . $ph . '"'
            . $clim . $wlim . $req . '>'
            . esc_textarea((string)($value ?? ''))
            . '</textarea>';
        return $this->wrap($field_id, $config, $inner);
    }

    /**
     * Returns the submitted textarea content sanitized with sanitize_textarea_field()
     * to preserve intentional newlines.
     *
     * @param string $field_id The field element ID.
     *
     * @return mixed
     */
    public function extractValue(string $field_id): mixed
    {
        return isset($_POST[$field_id]) ? sanitize_textarea_field(wp_unslash($_POST[$field_id])) : '';
    }

    /**
     * Sanitizes a raw textarea value from a group copy array, preserving newlines.
     *
     * @param mixed $raw The raw value from the group copy array.
     *
     * @return mixed
     */
    public function extractFromRaw(mixed $raw): mixed
    {
        return sanitize_textarea_field(wp_unslash((string)$raw));
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

    /**
     * Returns client-side validation rules.
     *
     * @return array
     */
    public function getClientValidation(): array
    {
        return [['rule' => 'textarea-word-limit', 'fn' => <<<'JS'
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

    /**
     * Textarea fields expose a plain-text value suitable for PDF preview tokens.
     *
     * @return bool
     */
    public function hasTextPreview(): bool
    {
        return true;
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
            'rows'       => 5,
            'limit_type' => 'chars',
            'limit_max'  => '',
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
        return array_merge(
            $this->baseGeneralEntries(),
            [
            [
                'key'   => 'rows',
                'type'  => 'number',
                'label' => 'Zeilen',
                'hint'  => 'Sichtbare Höhe des Feldes (Anzahl Textzeilen)',
            ],
            [
                'key'       => 'limit_type',
                'type'      => 'limit_row',
                'label'     => 'Begrenzung',
                'count_key' => 'limit_max',
            ],
            ]
        );
    }
}
