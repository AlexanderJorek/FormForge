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

defined('WP_UNINSTALL_PLUGIN') || exit;

/*
 * WARNING: This file runs when the plugin is deleted from the WordPress admin.
 * ALL plugin data — including PDF seal keys and key history — will be permanently
 * removed. There is no recovery. Back up your seal keys before uninstalling.
 */

/* Remove all stored forms (CPT posts + meta) */
$forms = get_posts([
    'post_type'      => 'forge_form',
    'posts_per_page' => -1,
    'post_status'    => 'any',
    'fields'         => 'ids',
]);
foreach ($forms as $id) {
    wp_delete_post($id, true);
}

/* Remove all plugin options — including seal keys and encryption state */
$options = [
    'forge_forms_from_email',
    'forge_forms_from_name',
    'forge_forms_recaptcha_site_key',
    'forge_forms_recaptcha_secret_key',
    'forge_forms_hover_color',
    'forge_forms_accent_color',
    'forge_forms_border_color',
    'forge_forms_pdf_settings',
    'forge_forms_pdf_layout',
    'forge_forms_version',
    // Seal key data — deleted on uninstall, NOT on reset.
    'forge_forms_seal_key',
    'forge_forms_seal_key_history',
    'forge_forms_seal_key_pending_download',
    'forge_forms_seal_encryption',
    'forge_forms_seal_setup_done',
    'forge_forms_access',
];
foreach ($options as $option) {
    delete_option($option);
}

/* Remove upload directory */
$upload_dir = wp_upload_dir();
$plugin_dir = $upload_dir['basedir'] . '/forge-secure-pdf';
if (is_dir($plugin_dir)) {
    $it    = new RecursiveDirectoryIterator($plugin_dir, FilesystemIterator::SKIP_DOTS);
    $files = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) {
        $file->isDir() ? rmdir($file->getRealPath()) : unlink($file->getRealPath());
    }
    rmdir($plugin_dir);
}
