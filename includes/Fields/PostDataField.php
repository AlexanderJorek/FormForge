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
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GPL-3.0-or-later
 * @version   1.0.0
 * @link      https://github.com/AlexanderJorek/FormForge
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 3
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
    public function getType(): string
    {
        return 'postdata';
    }

    public function getLabel(): string
    {
        return __('Post data', 'form-forge');
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
                'post_title'  => get_the_title($post?->ID ?? 0),
                'post_url'    => get_permalink($post?->ID ?? 0),
                'post_id'     => (string)($post?->ID ?? ''),
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
     * Regenerates the post-data array from the current post, ignoring any
     * client-submitted value — this is server-authoritative metadata and
     * must never be trusted from $_POST.
     *
     * @param string $field_id The field element ID.
     *
     * @return mixed
     */
    public function extractValue(string $field_id): mixed
    {
        global $post;
        $out = [];
        foreach (self::ALLOWED_FIELDS as $key) {
            $out[$key] = match ($key) {
                'post_title'  => get_the_title($post?->ID ?? 0),
                'post_url'    => get_permalink($post?->ID ?? 0),
                'post_id'     => (string)($post?->ID ?? ''),
                'post_author' => get_the_author(),
                default       => '',
            };
        }
        return $out;
    }

    /**
     * Maps the post-data array to a comma-separated string for email/PDF output,
     * limited to the fields selected in the field configuration.
     *
     * @param mixed $value  Server-regenerated value (array of post field strings).
     * @param array $config Field configuration.
     *
     * @return string
     */
    public function map(mixed $value, array $config): string
    {
        if (!is_array($value) || empty($value)) {
            return __('[No entry]', 'form-forge');
        }
        $selected = (array)($config['post_field'] ?? ['post_title']);
        $filtered = array_intersect_key($value, array_flip($selected));
        return implode(', ', array_filter(array_map('strval', $filtered), static fn($v) => $v !== ''));
    }

    /**
     * Returns the default field configuration.
     *
     * @return array
     */
    public function getDefaultConfig(): array
    {
        return [
            'label'       => __('Post data', 'form-forge'),
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
                'label'  => __('Post field', 'form-forge'),
                'values' => ['post_title', 'post_url', 'post_id', 'post_author'],
                'labels' => [__('Title', 'form-forge'), __('URL', 'form-forge'), __('ID', 'form-forge'), __('Author', 'form-forge')],
            ],
        ];
    }
}
