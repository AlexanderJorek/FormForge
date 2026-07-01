<?php

/**
 * Static HTML content field for layout and display purposes.
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
 * Static HTML content field (non-interactive).
 */
class HtmlField extends BaseField
{
    /**
     * Returns field-specific CSS styles.
     *
     * @return string
     */
    public function getStyles(): string
    {
        return <<<'CSS'
.forge-field--html { font-size: 14px; line-height: 1.7; color: var(--forge-text); }
.forge-field--html a { color: var(--forge-accent); }
CSS;
    }

    /**
     * Returns the field type label.
     *
     * @return string
     */
    public function getLabel(): string
    {
        return 'HTML-Block';
    }

    /**
     * Returns the Font Awesome icon class.
     *
     * @return string
     */
    public function getIcon(): string
    {
        return 'fa-solid fa-code';
    }

    /**
     * Returns false because HTML fields have no required-toggle in the editor.
     *
     * @return bool
     */
    public function hasRequired(): bool
    {
        return false;
    }

    /**
     * Returns true if validation should be skipped for this field type.
     *
     * @return bool
     */
    public function skipValidation(): bool
    {
        return true;
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
        $html = wp_kses_post($config['html_content'] ?? '');
        return '<div class="forge-field forge-field--html" data-field-id="'
            . esc_attr($field_id) . '">'
            . $html
            . '</div>';
    }

    /**
     * Maps the field value to a human-readable string for email and PDF output.
     *
     * @param mixed $value  Submitted value.
     * @param array $config Field configuration.
     *
     * @return string Human-readable representation.
     */
    public function map(mixed $value, array $config): string
    {
        return wp_strip_all_tags($config['html_content'] ?? '');
    }

    /**
     * Returns the default field configuration.
     *
     * @return array
     */
    public function getDefaultConfig(): array
    {
        return [
            'label'        => 'HTML-Block',
            'html_content' => '<p>Text hier</p>',
            'required'     => false,
            'description'  => '',
        ];
    }

    /**
     * Returns the general settings schema for the field editor.
     *
     * @return array
     */
    public function getGeneralSchema(): array
    {
        return [
            ['key' => 'html_content', 'type' => 'html_editor', 'label' => 'HTML-Inhalt'],
        ];
    }
}
