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

namespace ForgeForms\Form;

defined('ABSPATH') || exit;

/**
 * Thin wrapper around the forge_form custom post type.
 * Forms are stored as posts; field definitions and notifications are stored as post meta.
 */
class FormModel
{
    public int $id            = 0;
    public string $title         = '';
    public array $fields        = [];
    public array $notifications = [];
    public array $settings      = [];

    public static function get(int $form_id): ?self
    {
        $post = get_post($form_id);
        if (!$post || $post->post_type !== 'forge_form') {
            return null;
        }

        $model                = new self();
        $model->id            = $form_id;
        $model->title         = $post->post_title;
        $model->fields        = self::decodeMeta($form_id, 'forge_form_fields');
        $model->notifications = self::decodeMeta($form_id, 'forge_form_notifications');
        $model->settings      = self::decodeMeta($form_id, 'forge_form_settings');

        return $model;
    }

    /**
     * Create or update a form. Returns form ID on success or WP_Error.
     * @param array $data  Keys: title, fields, notifications, settings.
     */
    public static function save(array $data, int $form_id = 0): int|\WP_Error
    {
        $title = sanitize_text_field($data['title'] ?? 'Untitled Form');

        $post_data = [
            'post_title'  => $title,
            'post_type'   => 'forge_form',
            'post_status' => 'publish',
        ];

        if ($form_id > 0) {
            $post_data['ID'] = $form_id;
            $result = wp_update_post($post_data, true);
        } else {
            $result = wp_insert_post($post_data, true);
        }

        if (is_wp_error($result)) {
            return $result;
        }

        $id = (int)$result;

        $flags = JSON_UNESCAPED_UNICODE;
        update_post_meta($id, 'forge_form_fields', wp_json_encode($data['fields']        ?? [], $flags));
        update_post_meta($id, 'forge_form_notifications', wp_json_encode($data['notifications'] ?? [], $flags));
        update_post_meta($id, 'forge_form_settings', wp_json_encode($data['settings']      ?? [], $flags));

        return $id;
    }

    public static function duplicate(int $form_id): int|\WP_Error
    {
        $source = self::get($form_id);
        if (!$source) {
            return new \WP_Error('not_found', 'Formular nicht gefunden.');
        }
        return self::save([
            'title'         => $source->title . ' (Kopie)',
            'fields'        => $source->fields,
            'notifications' => $source->notifications,
            'settings'      => $source->settings,
        ]);
    }

    public static function delete(int $form_id): bool
    {
        return (bool)wp_delete_post($form_id, true);
    }

    /** @return self[] */
    public static function getAll(): array
    {
        $posts = get_posts([
            'post_type'      => 'forge_form',
            'posts_per_page' => -1,
            'post_status'    => 'publish',
            'orderby'        => 'title',
            'order'          => 'ASC',
        ]);

        $models = [];
        foreach ($posts as $post) {
            $m                = new self();
            $m->id            = $post->ID;
            $m->title         = $post->post_title;
            $m->fields        = self::decodeMeta($post->ID, 'forge_form_fields');
            $m->notifications = self::decodeMeta($post->ID, 'forge_form_notifications');
            $m->settings      = self::decodeMeta($post->ID, 'forge_form_settings');
            $models[]         = $m;
        }
        return $models;
    }

    private static function decodeMeta(int $id, string $key): array
    {
        $raw = get_post_meta($id, $key, true);
        if (!$raw || !is_string($raw)) {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }
}
