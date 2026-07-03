<?php

/**
 * Hidden field that captures WordPress post metadata.
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
 * Read-only field that displays WordPress post metadata.
 */
class PostDataField extends BaseField
{
    /**
     * Returns the field type label.
     *
     * @return string
     */
    public function getLabel(): string
    {
        return 'Beitragsdaten';
    }

    /**
     * Returns the Font Awesome icon class.
     *
     * @return string
     */
    public function getIcon(): string
    {
        return 'fa-solid fa-database';
    }

    /**
     * Returns false because post-data fields have no required-toggle in the editor.
     *
     * @return bool
     */
    public function hasRequired(): bool
    {
        return false;
    }

    private const ALLOWED_FIELDS = ['post_title', 'post_url', 'post_id', 'post_author'];

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
        $selected = (array)($config['post_field'] ?? ['post_title']);
        global $post;
        $out = '';
        foreach ($selected as $key) {
            if (!in_array($key, self::ALLOWED_FIELDS, true)) {
                continue;
            }
            $val = match ($key) {
                'post_title'  => get_the_title($post->ID ?? 0),
                'post_url'    => get_permalink($post->ID ?? 0),
                'post_id'     => (string)($post->ID ?? ''),
                'post_author' => get_the_author(),
                default       => '',
            };
            $out .= '<input type="hidden" name="' . esc_attr($field_id) . '[' . esc_attr($key) . ']"'
                . ' id="' . esc_attr($field_id . '_' . $key) . '"'
                . ' value="' . esc_attr($val) . '">';
        }
        return $out;
    }

    /**
     * Returns the sanitized post-data array submitted as $field_id[key].
     *
     * @param string $field_id The field element ID.
     *
     * @return mixed
     */
    public function extractValue(string $field_id): mixed
    {
        $raw = $_POST[$field_id] ?? [];
        if (!is_array($raw)) {
            return [];
        }
        return array_map(static fn($v) => sanitize_text_field(wp_unslash($v)), $raw);
    }

    /**
     * Returns the default field configuration.
     *
     * @return array
     */
    public function getDefaultConfig(): array
    {
        return [
            'label'       => 'Beitragsdaten',
            'post_field'  => ['post_title'],
            'description' => '',
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
            [
                'key'    => 'post_field',
                'type'   => 'pill_multi',
                'label'  => 'Post-Feld',
                'values' => ['post_title', 'post_url', 'post_id', 'post_author'],
                'labels' => ['Titel', 'URL', 'ID', 'Autor'],
            ],
        ];
    }
}
