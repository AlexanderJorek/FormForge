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

class PostDataField extends BaseField
{
    public function getLabel(): string
    {
        return 'Beitragsdaten';
    }
    public function getIcon(): string
    {
        return 'fa-solid fa-database';
    }
    public function hasRequired(): bool
    {
        return false;
    }

    private const ALLOWED_FIELDS = ['post_title', 'post_url', 'post_id', 'post_author'];

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

    public function getDefaultConfig(): array
    {
        return ['label' => 'Beitragsdaten', 'post_field' => ['post_title'], 'description' => ''];
    }

    public function getGeneralSchema(): array
    {
        return [
            ['key'    => 'post_field',
             'type'   => 'pill_multi',
             'label'  => 'Post-Feld',
             'values' => ['post_title', 'post_url', 'post_id', 'post_author'],
             'labels' => ['Titel', 'URL', 'ID', 'Autor']],
        ];
    }
}
