<?php

/**
 * CRUD model for storing and retrieving form definitions.
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

namespace ForgeForms\Form;

defined('ABSPATH') || exit;

/**
 * Thin wrapper around the forge_form custom post type.
 *
 * Forms are stored as posts; field definitions and notifications are stored as post meta.
 */
class FormModel
{
    public int $id            = 0;
    public string $title         = '';
    public array $fields        = [];
    public array $notifications = [];
    public array $settings      = [];

    /**
     * Retrieves a form model by its post ID.
     *
     * @param int $form_id The post ID of the form.
     *
     * @return self|null The form model, or null if not found.
     */
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
     * Creates or updates a form. Returns the form ID on success or WP_Error on failure.
     *
     * @param array $data    Keys: title, fields, notifications, settings.
     * @param int   $form_id Existing post ID to update, or 0 to create.
     *
     * @return int|\WP_Error
     */
    public static function save(array $data, int $form_id = 0): int|\WP_Error
    {
        // Defense-in-depth: every current admin call site already gates on
        // Plugin::userCan('edit_forms') before reaching here, but this model
        // method should not rely solely on callers remembering to check —
        // a single missed gate anywhere in the admin layer would otherwise be
        // a full privilege-escalation/CSRF path with no second line of defense.
        if (!\ForgeForms\Plugin::userCan('edit_forms')) {
            return new \WP_Error('forbidden', __('Insufficient permissions.', 'form-forge'));
        }
        $title = sanitize_text_field(\ForgeForms\Utils\Sanitize::str($data['title'] ?? null, 'Untitled Form'));

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

        $fields        = $data['fields']        ?? [];
        $notifications = $data['notifications'] ?? [];
        $settings      = $data['settings']      ?? [];
        update_post_meta($id, 'forge_form_fields', $fields);
        update_post_meta($id, 'forge_form_notifications', $notifications);
        update_post_meta($id, 'forge_form_settings', $settings);

        return $id;
    }

    /**
     * Creates a copy of an existing form.
     *
     * @param int $form_id The post ID of the form to duplicate.
     *
     * @return int|\WP_Error New form post ID, or WP_Error on failure.
     */
    public static function duplicate(int $form_id): int|\WP_Error
    {
        $source = self::get($form_id);
        if (!$source) {
            return new \WP_Error('not_found', __('Form not found.', 'form-forge'));
        }
        return self::save(
            [
            'title'         => $source->title . ' (Kopie)',
            'fields'        => $source->fields,
            'notifications' => $source->notifications,
            'settings'      => $source->settings,
            ]
        );
    }

    /**
     * Permanently deletes a form post.
     *
     * @param int $form_id The post ID of the form to delete.
     *
     * @return bool True on success, false on failure.
     */
    public static function delete(int $form_id): bool
    {
        if (!\ForgeForms\Plugin::userCan('edit_forms')) {
            return false;
        }
        return (bool)wp_delete_post($form_id, true);
    }

    /**
     * Returns all forge_form posts as model instances.
     *
     * @return self[]
     */
    public static function getAll(): array
    {
        $posts = get_posts(
            [
            'post_type'      => 'forge_form',
            'posts_per_page' => -1,
            'post_status'    => 'publish',
            'orderby'        => 'title',
            'order'          => 'ASC',
            ]
        );

        /* Prime the meta cache for all posts in one query so subsequent
           get_post_meta() calls inside decodeMeta() hit the object cache only. */
        update_meta_cache('post', wp_list_pluck($posts, 'ID'));

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

    /**
     * Decodes a JSON post meta value into an array.
     *
     * @param int    $id  Post ID.
     * @param string $key Meta key to decode.
     *
     * @return array Decoded array, or empty array on failure.
     */
    private static function decodeMeta(int $id, string $key): array
    {
        $raw = get_post_meta($id, $key, true);
        if (is_array($raw)) {
            return $raw;
        }
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            return is_array($decoded) ? $decoded : [];
        }
        return [];
    }
}
