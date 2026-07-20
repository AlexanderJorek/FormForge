<?php

/**
 * Admin settings page for individual form configuration.
 *
 * PHP Version 8.1
 *
 * @category  FormForge
 * @package   FormForge
 * @author    Alexander Jorek
 * @copyright 2026 Alexander Jorek
 * @license   https://www.gnu.org/licenses/gpl-2.0.html GPL-2.0-or-later
 * @version   1.0.0
 * @link      https://github.com/AlexanderJorek/form-forge
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2
 * of the License, or (at your option) any later version.
 */

namespace ForgeForms\Admin;

defined('ABSPATH') || exit;

/**
 * Admin settings page for FormForge global configuration.
 */
class FormSettings
{
    /**
     * Registers all admin hooks for the settings page.
     *
     * @return void
     */
    public static function init(): void
    {
        add_action('admin_menu', [self::class, 'addSettingsPage']);
        add_action('admin_body_class', [self::class, 'bodyClass']);
        add_action('wp_ajax_forge_forms_save_pdf_settings', [self::class, 'savePdfSettings']);
        add_action('wp_ajax_forge_forms_factory_reset', [self::class, 'handleFactoryReset']);
        add_action('wp_ajax_forge_forms_rotate_key', [self::class, 'handleRotateKey']);
        add_action('wp_ajax_forge_setup_keep_default', [self::class, 'handleSetupKeepDefault']);
        add_action('wp_ajax_forge_setup_get_master_key', [self::class, 'handleSetupGetMasterKey']);
        add_action('wp_ajax_forge_setup_confirm_secure', [self::class, 'handleSetupConfirmSecure']);
        add_action('wp_ajax_forge_setup_reset_choice', [self::class, 'handleSetupResetChoice']);
        add_action('wp_ajax_forge_add_legacy_key', [self::class, 'handleAddLegacyKey']);
        add_action('wp_ajax_forge_save_access_settings', [self::class, 'handleSaveAccessSettings']);
        add_action('wp_ajax_forge_save_general_settings', [self::class, 'handleSaveGeneralSettings']);
    }

    /**
     * Appends a CSS class on the settings page.
     *
     * @param string $classes Existing admin body classes.
     *
     * @return string Modified body class string.
     */
    public static function bodyClass(string $classes): string
    {
        if (isset($_GET['page']) && $_GET['page'] === 'forge-forms-settings') {
            $classes .= ' forge-list-page';
        }
        return $classes;
    }

    /**
     * Registers the settings submenu page.
     *
     * @return void
     */
    public static function addSettingsPage(): void
    {
        if (\ForgeForms\Plugin::userCan('settings')) {
            add_submenu_page(
                'forge-forms',
                __('FormForge Settings', 'form-forge'),
                __('Settings', 'form-forge'),
                'read',
                'forge-forms-settings',
                [self::class, 'renderSettingsPage']
            );
        }
    }

    /**
     * Renders the full settings page HTML.
     *
     * @return void
     */
    public static function renderSettingsPage(): void
    {
        if (!\ForgeForms\Plugin::userCan('settings')) {
            wp_die(__('Permission denied.', 'form-forge'));
        }

        $saved = false;

        if (isset($_POST['forge_settings_nonce'])
            && wp_verify_nonce(sanitize_key($_POST['forge_settings_nonce']), 'forge_forms_settings')
        ) {
            self::saveGeneralSettings();
            $saved = true;
        }

        $from_email        = get_option('forge_forms_from_email', '');
        $from_name         = get_option('forge_forms_from_name', '');
        $recaptcha_site    = get_option('forge_forms_recaptcha_site_key', '');
        $recaptcha_secret  = get_option('forge_forms_recaptcha_secret_key', '');
        $hover_color       = get_option('forge_forms_hover_color', '#1d2327');
        $accent_color      = get_option('forge_forms_accent_color', '#f59e0b');
        $border_color      = get_option('forge_forms_border_color', '#c9cdd4');
        $admin_accent      = get_option('forge_forms_admin_accent', '#2271b1');
        $field_layout_mode = get_option('forge_forms_field_layout', 'block');
        $wp_admin_email    = get_option('admin_email');
        $setup_done_early = (bool) get_option('forge_forms_seal_setup_done', false);
        /* Users granted access only via the plugin's own user-control system
           (not real WP admins) don't see PDF-seal security, recaptcha keys,
           or the user-access/reset tile — those stay reserved for site admins. */
        $is_full_admin = current_user_can('manage_options');
        ?>
        <?php if ($saved) : ?>
        <div class="forge-settings-notice forge-settings-notice--success">
            <i class="fa-solid fa-circle-check"></i> <?php echo esc_html__('Settings saved.', 'form-forge'); ?>
        </div>
        <?php endif; ?>

        <?php if (!$setup_done_early) : ?>
        <div id="forge-setup-blocker"
             style="position:fixed;z-index:100001;background:rgba(0,0,0,.55);
                    display:flex;align-items:center;justify-content:center;">
            <div style="background:#fff;border-radius:8px;padding:28px 28px 24px;max-width:560px;
                        width:calc(100% - 32px);box-shadow:0 4px 24px rgba(0,0,0,.22);
                        max-height:calc(100vh - 80px);overflow-y:auto;">
                <!-- Step 1: choose storage mode -->
                <div id="forge-blocker-step1">
                <h2 style="margin:0 0 14px;font-size:17px;font-weight:700;color:#1d2327;">
                    <i class="fa-solid fa-shield-halved" style="color:#f59e0b;margin-right:7px;"></i>
                    <?php echo esc_html__('FormForge — Initial Setup', 'form-forge'); ?>
                </h2>
                <p style="margin:0 0 6px;font-size:13px;color:#1d2327;font-weight:600;">
                    <?php echo esc_html__('What are PDF seal keys?', 'form-forge'); ?>
                </p>
                <p style="margin:0 0 10px;font-size:13px;color:#50575e;line-height:1.55;">
                    <?php
                    echo esc_html__('FormForge can generate PDFs from form submissions. Each of these PDFs receives an invisible cryptographic signature when created — similar to a seal on a letter. This signature is calculated from the document content and a secret key.', 'form-forge'); // phpcs:ignore Generic.Files.LineLength
                    ?>
                </p>
                <p style="margin:0 0 10px;font-size:13px;color:#50575e;line-height:1.55;">
                    <?php
                    echo esc_html__('On the verification page you can upload a PDF: the system recalculates the signature and compares it. If it matches, the document is authentic and unaltered. If even a single character was changed, the check fails.', 'form-forge'); // phpcs:ignore Generic.Files.LineLength
                    ?>
                </p>
                <p style="margin:0 0 14px;font-size:13px;color:#50575e;line-height:1.55;">
                    <?php
                    echo esc_html__('You receive the key as a downloadable file. Keep it safely off the server (e.g. in a password manager or an encrypted USB drive) — so you can restore it as a legacy key after a server failure and continue verifying older PDFs.', 'form-forge'); // phpcs:ignore Generic.Files.LineLength
                    ?>
                </p>
                <p style="margin:0 0 6px;font-size:13px;color:#1d2327;font-weight:600;">
                    <i class="fa-solid fa-circle-question" style="color:#787c82;margin-right:5px;"></i>
                    <?php echo esc_html__('Why choose encrypted?', 'form-forge'); ?>
                </p>
                <p style="margin:0 0 8px;font-size:13px;color:#50575e;line-height:1.55;">
                    <?php
                    echo esc_html__('In standard mode, the key is stored in plaintext in the database. Anyone who gains access to the database — for example through a compromised plugin or a data backup — could read the key and sign forged PDFs that pass as authentic.', 'form-forge'); // phpcs:ignore Generic.Files.LineLength
                    ?>
                </p>
                <p style="margin:0 0 16px;font-size:13px;color:#50575e;line-height:1.55;">
                    <?php
                    printf(
                        /* translators: %s: wp-config.php as inline code element */
                        __('In encrypted mode, the key in the database is worthless without the master key, which is stored separately on the server in %s. A database-only leak is therefore not sufficient.', 'form-forge'),
                        '<code style="background:#f0f0f1;border:1px solid #c3c4c7;border-radius:3px;padding:1px 5px;font-size:12px;color:#1d2327;">wp-config.php</code>'
                    );
                    ?>
                </p>
                <p style="margin:0 0 10px;font-size:13px;color:#1d2327;font-weight:600;">
                    <?php echo esc_html__('How should the keys be stored?', 'form-forge'); ?>
                </p>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:18px;">
                    <button type="button" id="forge-blocker-default"
                            style="text-align:left;padding:16px;border:2px solid #dcdcde;
                                   border-radius:6px;background:#fff;cursor:pointer;
                                   font-family:inherit;transition:border-color .15s;">
                        <span style="display:flex;align-items:center;gap:8px;margin-bottom:6px;">
                            <i class="fa-solid fa-database" style="font-size:15px;color:#787c82;"></i>
                            <strong style="font-size:13px;color:#1d2327;"><?php echo esc_html__('Standard', 'form-forge'); ?></strong>
                        </span>
                        <span style="font-size:12px;color:#50575e;line-height:1.4;">
                            <?php echo esc_html__('Stored unencrypted in the database. Compatible with all installations.', 'form-forge'); ?>
                        </span>
                    </button>
                    <button type="button" id="forge-blocker-secure"
                            style="text-align:left;padding:16px;border:2px solid #dcdcde;
                                   border-radius:6px;background:#fff;cursor:pointer;
                                   font-family:inherit;transition:border-color .15s;">
                        <span style="display:flex;align-items:center;gap:8px;margin-bottom:6px;">
                            <i class="fa-solid fa-lock" style="font-size:15px;color:#2271b1;"></i>
                            <strong style="font-size:13px;color:#1d2327;"><?php echo esc_html__('Encrypted', 'form-forge'); ?></strong>
                        </span>
                        <span style="font-size:12px;color:#50575e;line-height:1.4;">
                            <?php
                            printf(
                                /* translators: %s: wp-config.php as inline code element */
                                __('AES-256-GCM — requires a master key in %s.', 'form-forge'),
                                '<code style="background:#f0f0f1;border:1px solid #c3c4c7;border-radius:3px;padding:1px 5px;font-size:11px;color:#1d2327;">wp-config.php</code>'
                            );
                            ?>
                        </span>
                    </button>
                </div>
                <p id="forge-blocker-error"
                   style="color:#b32d2e;display:none;margin:0 0 10px;font-size:13px;"></p>
                </div><!-- /#forge-blocker-step1 -->

                <!-- Step 2: master-key setup (shown after choosing Encrypted) -->
                <div id="forge-blocker-mk-step" style="display:none;">
                    <h2 style="margin:0 0 12px;font-size:17px;font-weight:700;color:#1d2327;">
                        <i class="fa-solid fa-lock" style="color:#2271b1;margin-right:7px;"></i>
                        <?php echo esc_html__('Set up master key', 'form-forge'); ?>
                    </h2>
                    <p style="margin:0 0 10px;font-size:13px;color:#50575e;line-height:1.55;">
                        <?php
                        printf(
                            /* translators: %s: wp-config.php as inline code element */
                            __('Copy the following line and add it to the %s on your server. Here\'s how:', 'form-forge'),
                            '<code style="background:#f0f0f1;border:1px solid #c3c4c7;border-radius:3px;padding:1px 5px;font-size:12px;color:#1d2327;">wp-config.php</code>'
                        );
                        ?>
                    </p>
                    <ol style="margin:0 0 12px 18px;font-size:13px;color:#50575e;line-height:1.7;">
                        <li><?php
                            echo esc_html__('Open the file manager of your server — either via the hosting control panel, an FTP client (e.g. FileZilla), or SSH.', 'form-forge'); // phpcs:ignore Generic.Files.LineLength
                        ?></li>
                        <li><?php
                            $code_wplogin = '<code style="background:#f0f0f1;border:1px solid #c3c4c7;border-radius:3px;padding:1px 4px;font-size:11px;color:#1d2327;">wp-login.php</code>';
                            $code_wpconfig = '<code style="background:#f0f0f1;border:1px solid #c3c4c7;border-radius:3px;padding:1px 4px;font-size:11px;color:#1d2327;">wp-config.php</code>';
                            printf(
                                /* translators: 1: wp-login.php as code element, 2: wp-config.php as code element */
                                __('Navigate to the root directory of your WordPress installation (where %1$s and %2$s are also located).', 'form-forge'),
                                $code_wplogin,
                                $code_wpconfig
                            );
                            ?></li>
                        <li><?php
                            printf(
                                /* translators: %s: wp-config.php as inline code element */
                                __('Open %s for editing.', 'form-forge'),
                                '<code style="background:#f0f0f1;border:1px solid #c3c4c7;border-radius:3px;padding:1px 4px;font-size:11px;color:#1d2327;">wp-config.php</code>'
                            );
                            ?></li>
                        <li><?php
                            printf(
                                /* translators: 1: stop-editing comment as code element, 2: "direkt davor" as strong element */
                                __('Find the line %1$s and insert the line below %2$s.', 'form-forge'),
                                '<code style="background:#f0f0f1;border:1px solid #c3c4c7;border-radius:3px;padding:1px 4px;font-size:11px;color:#1d2327;">/* That\'s all, stop editing! */</code>',
                                '<strong>' . esc_html__('directly before it', 'form-forge') . '</strong>'
                            );
                            ?></li>
                        <li><?php echo esc_html__('Save the file and return here.', 'form-forge'); ?></li>
                    </ol>
                    <div style="background:#f6f7f7;border:1px solid #dcdcde;border-radius:4px;
                                padding:12px 14px;margin-bottom:12px;">
                        <div style="font-size:11px;font-family:monospace;word-break:break-all;color:#1d2327;"
                             id="forge-blocker-mk-line">—</div>
                    </div>
                    <p id="forge-blocker-mk-error"
                       style="color:#b32d2e;display:none;margin:0 0 10px;font-size:13px;"></p>
                    <div style="display:flex;gap:12px;">
                        <button type="button" id="forge-blocker-mk-back" class="button"
                                style="margin-right:auto;">
                            <i class="fa-solid fa-arrow-left"></i> <?php echo esc_html__('Back', 'form-forge'); ?>
                        </button>
                        <button type="button" id="forge-blocker-mk-confirm" class="button button-primary">
                            <i class="fa-solid fa-check"></i> <?php echo esc_html__('Entered — Continue', 'form-forge'); ?>
                        </button>
                    </div>
                </div>

                <!-- Step 2b: master key already present, just finalise -->
                <div id="forge-blocker-ready-step" style="display:none;">
                    <h2 style="margin:0 0 12px;font-size:17px;font-weight:700;color:#1d2327;">
                        <i class="fa-solid fa-circle-check" style="color:#00a32a;margin-right:7px;"></i>
                        <?php echo esc_html__('Master key detected', 'form-forge'); ?>
                    </h2>
                    <p style="margin:0 0 16px;font-size:13px;color:#50575e;line-height:1.55;">
                        <?php
                        $code_master = '<code style="background:#f0f0f1;border:1px solid #c3c4c7;border-radius:3px;padding:1px 5px;font-size:12px;color:#1d2327;">FORGE_SEAL_MASTER_KEY</code>';
                        $code_wpcfg  = '<code style="background:#f0f0f1;border:1px solid #c3c4c7;border-radius:3px;padding:1px 5px;font-size:12px;color:#1d2327;">wp-config.php</code>';
                        printf(
                            /* translators: 1: FORGE_SEAL_MASTER_KEY constant as code element, 2: wp-config.php as code element */
                            __('A valid %1$s is entered in %2$s. Click "Complete setup" to generate the first seal key and finish the setup.', 'form-forge'),
                            $code_master,
                            $code_wpcfg
                        );
                        ?>
                    </p>
                    <p id="forge-blocker-ready-error"
                       style="color:#b32d2e;display:none;margin:0 0 10px;font-size:13px;"></p>
                    <div style="display:flex;gap:12px;justify-content:flex-end;">
                        <button type="button" id="forge-blocker-ready-confirm" class="button button-primary">
                            <i class="fa-solid fa-bolt"></i> <?php echo esc_html__('Complete setup', 'form-forge'); ?>
                        </button>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <canvas id="forge-particle-canvas"></canvas>
        <div class="wrap forge-list-wrap">
            <hr class="wp-header-end" style="display:none">

            <form method="post" id="forge-settings-form">
                <?php wp_nonce_field('forge_forms_settings', 'forge_settings_nonce'); ?>

                <div class="forge-settings-topbar">
                    <div class="forge-title-pill"><?php echo esc_html__('Settings', 'form-forge'); ?></div>
                    <div class="forge-settings-topbar-actions">
                        <button type="submit" class="button button-primary">
                            <i class="fa-solid fa-floppy-disk"></i> <?php echo esc_html__('Save', 'form-forge'); ?>
                        </button>
                    </div>
                </div>

                <div class="forge-settings-tiles">

                    <div class="forge-settings-section-header">
                        <i class="fa-solid fa-display"></i> <?php echo esc_html__('Frontend', 'form-forge'); ?>
                    </div>

                    <div class="forge-settings-card">
                        <h2 class="forge-settings-card-title">
                            <i class="fa-solid fa-paintbrush"></i> <?php echo esc_html__('Form colors', 'form-forge'); ?>
                        </h2>

                        <div class="forge-settings-field">
                            <label for="accent_color"><?php echo esc_html__('Accent color', 'form-forge'); ?></label>
                            <input type="text" id="accent_color" name="accent_color"
                                   value="<?php echo esc_attr($accent_color); ?>"
                                   class="forge-iris-input" data-default-color="#f59e0b"
                                   autocomplete="off" data-lpignore="true"
                                   data-1p-ignore data-bwignore spellcheck="false">
                        </div>

                        <div class="forge-settings-field">
                            <label for="border_color"><?php echo esc_html__('Input border', 'form-forge'); ?></label>
                            <input type="text" id="border_color" name="border_color"
                                   value="<?php echo esc_attr($border_color); ?>"
                                   class="forge-iris-input" data-default-color="#c9cdd4"
                                   autocomplete="off" data-lpignore="true"
                                   data-1p-ignore data-bwignore spellcheck="false">
                        </div>
                    </div>

                    <div class="forge-settings-card">
                        <h2 class="forge-settings-card-title">
                            <i class="fa-solid fa-table-list"></i> <?php echo esc_html__('Field output', 'form-forge'); ?>
                        </h2>
                        <div class="forge-settings-field">
                            <label><?php echo esc_html__('Layout in email & PDF', 'form-forge'); ?></label>
                            <div class="forge-card-radio-group">
                                <label class="forge-card-radio">
                                    <input type="radio" name="field_layout_mode" value="block"
                                        <?php checked($field_layout_mode, 'block'); ?>>
                                    <span class="forge-card-radio-head">
                                        <i class="fa-solid fa-list"></i>
                                        <strong><?php echo esc_html__('Block', 'form-forge'); ?></strong>
                                    </span>
                                    <span class="forge-card-radio-desc">
                                        <?php echo esc_html__('Label above the value.', 'form-forge'); ?>
                                    </span>
                                </label>
                                <label class="forge-card-radio">
                                    <input type="radio" name="field_layout_mode" value="inline"
                                        <?php checked($field_layout_mode, 'inline'); ?>>
                                    <span class="forge-card-radio-head">
                                        <i class="fa-solid fa-grip-lines"></i>
                                        <strong><?php echo esc_html__('Inline', 'form-forge'); ?></strong>
                                    </span>
                                    <span class="forge-card-radio-desc">
                                        <?php echo esc_html__('Label: value on one line.', 'form-forge'); ?>
                                    </span>
                                </label>
                            </div>
                        </div>
                    </div>

                    <?php if ($is_full_admin) : ?>
                    <div class="forge-settings-card">
                        <h2 class="forge-settings-card-title">
                            <i class="fa-solid fa-shield-halved"></i> <?php echo esc_html__('reCAPTCHA v2', 'form-forge'); ?>
                        </h2>

                        <div class="forge-settings-field">
                            <label for="recaptcha_site"><?php echo esc_html__('Site Key', 'form-forge'); ?></label>
                            <input type="text" id="recaptcha_site" name="recaptcha_site"
                                   value="<?php echo esc_attr($recaptcha_site); ?>"
                                   placeholder="6Le…"
                                   autocomplete="off" data-lpignore="true"
                                   data-1p-ignore data-bwignore spellcheck="false">
                        </div>

                        <div class="forge-settings-field">
                            <label for="recaptcha_secret"><?php echo esc_html__('Secret Key', 'form-forge'); ?></label>
                            <input type="text" id="recaptcha_secret" name="recaptcha_secret"
                                   value="<?php echo esc_attr($recaptcha_secret); ?>"
                                   placeholder="6Le…"
                                   autocomplete="off" data-lpignore="true"
                                   data-1p-ignore data-bwignore spellcheck="false">
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="forge-settings-section-header">
                        <i class="fa-solid fa-server"></i> <?php echo esc_html__('Backend', 'form-forge'); ?>
                    </div>

                    <div class="forge-settings-card">
                        <h2 class="forge-settings-card-title">
                            <i class="fa-solid fa-envelope"></i> <?php echo esc_html__('Email delivery', 'form-forge'); ?>
                        </h2>

                        <div class="forge-settings-field">
                            <label for="forge_cfg_a"><?php echo esc_html__('Sender email', 'form-forge'); ?></label>
                            <input type="text" inputmode="email" id="forge_cfg_a" name="forge_cfg_a"
                                   value="<?php echo esc_attr($from_email); ?>"
                                   placeholder="<?php echo esc_attr($wp_admin_email); ?>"
                                   autocomplete="off" data-lpignore="true"
                                   data-1p-ignore data-bwignore data-form-type="other"
                                   spellcheck="false">
                            <p class="forge-settings-hint">
                                <?php
                                printf(
                                    /* translators: %s: admin e-mail address */
                                    __('Leave blank to use the WordPress admin email (%s).', 'form-forge'),
                                    esc_html($wp_admin_email)
                                );
                                ?>
                            </p>
                        </div>

                        <div class="forge-settings-field">
                            <label for="forge_cfg_b"><?php echo esc_html__('Sender name', 'form-forge'); ?></label>
                            <input type="text" id="forge_cfg_b" name="forge_cfg_b"
                                   value="<?php echo esc_attr($from_name); ?>"
                                   autocomplete="off" data-lpignore="true"
                                   data-1p-ignore data-bwignore data-form-type="other"
                                   spellcheck="false">
                        </div>
                    </div>

                    <div class="forge-settings-card">
                        <h2 class="forge-settings-card-title">
                            <i class="fa-solid fa-pen-ruler"></i> <?php echo esc_html__('Editor', 'form-forge'); ?>
                        </h2>

                        <div class="forge-settings-field">
                            <label for="admin_accent"><?php echo esc_html__('Admin accent color', 'form-forge'); ?></label>
                            <input type="text" id="admin_accent" name="admin_accent"
                                   value="<?php echo esc_attr($admin_accent); ?>"
                                   class="forge-iris-input" data-default-color="#2271b1"
                                   autocomplete="off" data-lpignore="true"
                                   data-1p-ignore data-bwignore spellcheck="false">
                            <p class="forge-settings-hint">
                                <?php echo esc_html__('Color for buttons, sliders, and toggles in the admin area.', 'form-forge'); ?>
                            </p>
                        </div>

                        <div class="forge-settings-field">
                            <label for="hover_color"><?php echo esc_html__('Hover color', 'form-forge'); ?></label>
                            <input type="text" id="hover_color" name="hover_color"
                                   value="<?php echo esc_attr($hover_color); ?>"
                                   class="forge-iris-input" data-default-color="#1d2327"
                                   autocomplete="off" data-lpignore="true"
                                   data-1p-ignore data-bwignore spellcheck="false">
                        </div>
                    </div>

                    <?php
                    $setup_done       = $setup_done_early;
                    $key_history      = \ForgeForms\PDF\HashSeal::getHistory();
                    $rotate_nonce     = wp_create_nonce('forge_rotate_key');
                    $setup_nonce      = wp_create_nonce('forge_seal_setup');
                    $pending_download = $setup_done
                        ? \ForgeForms\PDF\HashSeal::claimPendingDownload()
                        : null;
                    $enc_enabled      = \ForgeForms\PDF\HashSeal::isEncryptionEnabled();
                    ?>
                    <div class="forge-settings-card forge-settings-card--security"
                         <?php echo $is_full_admin ? '' : 'hidden'; ?>>
                        <h2 class="forge-settings-card-title">
                            <i class="fa-solid fa-shield-halved"></i> <?php echo esc_html__('Security', 'form-forge'); ?>
                        </h2>

                        <?php if ($setup_done) : ?>
                            <?php if ($enc_enabled) : ?>
                        <div style="border:1px solid #b8e6c1;border-radius:4px;padding:10px 14px;
                                    margin-bottom:14px;background:#f0faf2;display:flex;align-items:center;gap:6px;">
                            <i class="fa-solid fa-lock" style="color:#00a32a;"></i>
                            <strong style="color:#007017;"><?php echo esc_html__('Encrypted', 'form-forge'); ?></strong>
                            <span style="color:#50575e;font-size:13px;">
                                <?php echo esc_html__('Keys are secured with AES-256-GCM.', 'form-forge'); ?></span>
                        </div>
                            <?php else : ?>
                        <button type="button" id="forge-upgrade-enc-btn"
                                class="forge-security-action-btn" style="margin-bottom:14px;">
                            <span class="forge-security-action-icon" style="color:#787c82;">
                                <i class="fa-solid fa-database"></i>
                            </span>
                            <span class="forge-security-action-body">
                                <strong><?php echo esc_html__('Standard — unencrypted', 'form-forge'); ?></strong>
                                <span><?php echo esc_html__('Click to switch to AES-256-GCM', 'form-forge'); ?></span>
                            </span>
                            <i class="fa-solid fa-chevron-right forge-security-action-arrow"></i>
                        </button>
                            <?php endif; ?>
                        <?php endif; ?>

                        <div class="forge-security-actions" <?php echo !$setup_done ? 'hidden' : ''; ?>>
                            <button type="button" id="forge-key-view-trigger" class="forge-security-action-btn">
                                <span class="forge-security-action-icon" style="color:#2271b1;">
                                    <i class="fa-solid fa-magnifying-glass"></i>
                                </span>
                                <span class="forge-security-action-body">
                                    <strong><?php echo esc_html__('View PDF keys', 'form-forge'); ?></strong>
                                </span>
                                <i class="fa-solid fa-chevron-right forge-security-action-arrow"></i>
                            </button>

                            <button type="button" id="forge-legacy-key-trigger" class="forge-security-action-btn">
                                <span class="forge-security-action-icon" style="color:#a9a9a9;">
                                    <i class="fa-solid fa-file-import"></i>
                                </span>
                                <span class="forge-security-action-body">
                                    <strong><?php echo esc_html__('Add legacy key', 'form-forge'); ?></strong>
                                </span>
                                <i class="fa-solid fa-chevron-right forge-security-action-arrow"></i>
                            </button>

                            <button type="button" id="forge-rotate-key-trigger" class="forge-security-action-btn">
                                <span class="forge-security-action-icon" style="color:#c07a00;">
                                    <i class="fa-solid fa-arrows-rotate"></i>
                                </span>
                                <span class="forge-security-action-body">
                                    <strong><?php echo esc_html__('Rotate PDF key', 'form-forge'); ?></strong>
                                </span>
                                <i class="fa-solid fa-chevron-right forge-security-action-arrow"></i>
                            </button>
                        </div>
                    </div>

                    <div class="forge-settings-card" <?php echo $is_full_admin ? '' : 'hidden'; ?>>
                        <h2 class="forge-settings-card-title">
                            <i class="fa-solid fa-screwdriver-wrench"></i> <?php echo esc_html__('Miscellaneous', 'form-forge'); ?>
                        </h2>

                        <div class="forge-security-actions">
                            <button type="button" id="forge-access-tile-btn"
                                    class="forge-security-action-btn">
                                <span class="forge-security-action-icon">
                                    <i class="fa-solid fa-users-gear"></i>
                                </span>
                                <span class="forge-security-action-body">
                                    <strong><?php echo esc_html__('User access', 'form-forge'); ?></strong>
                                </span>
                                <i class="fa-solid fa-chevron-right forge-security-action-arrow"></i>
                            </button>
                            <button type="button" id="forge-reset-tile-btn"
                                    class="forge-security-action-btn forge-security-action-btn--danger">
                                <span class="forge-security-action-icon forge-security-action-icon--danger">
                                    <i class="fa-solid fa-arrow-rotate-left"></i>
                                </span>
                                <span class="forge-security-action-body">
                                    <strong><?php echo esc_html__('Reset to factory defaults', 'form-forge'); ?></strong>
                                </span>
                                <i class="fa-solid fa-chevron-right forge-security-action-arrow"></i>
                            </button>
                        </div>

                    </div>

                </div><!-- /.forge-settings-tiles -->
            </form>
        </div>

        <!-- Key-rotation modal -->
        <div id="forge-key-overlay" class="forge-reset-overlay" hidden>
            <div class="forge-modal-box forge-key-rotate-modal"
                 role="dialog" aria-modal="true" aria-labelledby="forge-key-modal-title">
                <h2 id="forge-key-modal-title" style="color:#c07a00;">
                    <i class="fa-solid fa-key"></i> <?php echo esc_html__('Rotate PDF key', 'form-forge'); ?>
                </h2>
                <p>
                    <?php
                    printf(
                        /* translators: %s: "rotiert" as an em element */
                        __('Derives a new key via PBKDF2. PDFs sealed with the previous key remain verifiable and are marked as %s.', 'form-forge'),
                        '<em>' . esc_html__('rotated', 'form-forge') . '</em>'
                    );
                    ?>
                </p>

                <div class="forge-key-rotate-fields">
                    <div class="forge-settings-field">
                        <label for="forge_key_pw"><?php echo esc_html__('New key password', 'form-forge'); ?></label>
                        <input type="text" id="forge_key_pw"
                               class="forge-fake-password"
                               autocomplete="off" data-lpignore="true"
                               data-1p-ignore data-bwignore spellcheck="false"
                               readonly
                               placeholder="<?php echo esc_attr__('Min. 12 characters…', 'form-forge'); ?>">
                        <div id="forge-key-strength" class="forge-key-strength-bar">
                            <span></span><span></span><span></span><span></span><span></span>
                        </div>
                        <ul id="forge-key-pw-errors" class="forge-key-pw-errors"></ul>
                    </div>

                    <div class="forge-settings-field">
                        <label for="forge_key_pw2"><?php echo esc_html__('Confirm password', 'form-forge'); ?></label>
                        <input type="text" id="forge_key_pw2"
                               class="forge-fake-password"
                               autocomplete="off" data-lpignore="true"
                               data-1p-ignore data-bwignore spellcheck="false"
                               readonly>
                    </div>

                    <label class="forge-reset-check-wrap">
                        <input type="checkbox" id="forge_key_compromised" value="1">
                        <span><?php
                                $strong_cmp = '<strong>' . esc_html__('compromised', 'form-forge') . '</strong>';
                                printf(
                                    /* translators: %s: "kompromittiert" as a strong element */
                                    __('Mark the previous key as %s', 'form-forge'),
                                    $strong_cmp
                                );
                                ?></span>
                    </label>
                    <p id="forge-key-compromised-hint" class="forge-settings-hint" hidden
                       style="color:#b32d2e;margin:0 0 12px;">
                        <i class="fa-solid fa-circle-exclamation"></i>
                        <?php echo esc_html__('PDFs sealed with the previous key will be shown as compromised during verification.', 'form-forge'); ?>
                    </p>
                </div>

                <div class="forge-reset-actions">
                    <button type="button" id="forge-key-cancel" class="button"><?php echo esc_html__('Cancel', 'form-forge'); ?></button>
                    <button type="button" id="forge-key-confirm" class="button button-primary forge-reset-confirm"
                            disabled>
                        <i class="fa-solid fa-rotate"></i> <?php echo esc_html__('Rotate key', 'form-forge'); ?>
                    </button>
                </div>
                <p id="forge-key-modal-msg" class="forge-settings-hint" hidden
                   style="margin-top:10px;text-align:right;"></p>
            </div>
        </div>

        <!-- Key-view modal -->
        <div id="forge-key-view-overlay" class="forge-reset-overlay" hidden>
            <div class="forge-modal-box forge-key-view-modal"
                 role="dialog" aria-modal="true" aria-labelledby="forge-key-view-title">
                <h2 id="forge-key-view-title" class="forge-key-view-title">
                    <i class="fa-solid fa-magnifying-glass"></i> <?php echo esc_html__('PDF keys', 'form-forge'); ?>
                </h2>

                <?php if (!empty($key_history)) : ?>
                <div class="forge-key-view-scroll">
                <table class="forge-key-master-table">
                    <thead>
                        <tr>
                            <th><?php echo esc_html__('Fingerprint', 'form-forge'); ?></th>
                            <th><?php echo esc_html__('Status', 'form-forge'); ?></th>
                            <th><?php echo esc_html__('Date', 'form-forge'); ?></th>
                            <th><?php echo esc_html__('By', 'form-forge'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach (array_reverse($key_history) as $entry) :
                        $raw_entry_key = (string)($entry['key'] ?? '');
                        $fp_mid        = '';
                        if (strlen($raw_entry_key) >= 6) {
                            $mid_pos = (int)floor(strlen($raw_entry_key) / 2) - 3;
                            $fp_mid  = substr($raw_entry_key, $mid_pos, 6);
                        }
                        $uuid      = esc_html((string)($entry['uuid'] ?? '—'));
                        $sta       = (string)($entry['status'] ?? 'rotated');
                        $cmp       = !empty($entry['compromised']);
                        $at        = esc_html((string)($entry['retired_at'] ?? '—'));
                        $by        = esc_html((string)($entry['retired_by_login'] ?? '—'));

                        $extra_badge = '';
                        if ($sta === 'rotated-legacy') {
                            $badge_cls   = 'forge-key-badge forge-key-badge--rotated';
                            $badge_lbl   = __('ROTATED', 'form-forge');
                            $extra_badge = '<span class="forge-key-badge forge-key-badge--legacy">' . esc_html__('LEGACY', 'form-forge') . '</span>';
                        } elseif ($sta === 'compromised-legacy') {
                            $badge_cls   = 'forge-key-badge forge-key-badge--compromised';
                            $badge_lbl   = __('COMPROMISED', 'form-forge');
                            $extra_badge = '<span class="forge-key-badge forge-key-badge--legacy">' . esc_html__('LEGACY', 'form-forge') . '</span>';
                        } elseif ($cmp) {
                            $badge_cls = 'forge-key-badge forge-key-badge--compromised';
                            $badge_lbl = __('COMPROMISED', 'form-forge');
                        } else {
                            $badge_cls = 'forge-key-badge forge-key-badge--rotated';
                            $badge_lbl = __('ROTATED', 'form-forge') . ($sta === 'initial' ? ' (Initial)' : '');
                        }
                        ?>
                        <tr class="forge-key-uuid-row">
                            <td colspan="4">
                                <span class="forge-key-uuid-lbl"><?php echo esc_html__('UUID:', 'form-forge'); ?></span>
                                <code class="forge-key-uuid-code"><?php echo $uuid; ?></code>
                            </td>
                        </tr>
                        <tr class="forge-key-data-row">
                            <td><code class="forge-key-fp-cell"><?php echo esc_html($fp_mid); ?></code></td>
                            <td>
                                <span class="forge-key-card-badges">
                                    <span class="<?php echo $badge_cls; ?>"><?php echo esc_html($badge_lbl); ?></span>
                                    <?php
                                    echo $extra_badge; ?>
                                </span>
                            </td>
                            <td><?php echo $at; ?></td>
                            <td><?php echo $by; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div><!-- /.forge-key-view-scroll -->
                <?php else : ?>
                <p class="forge-settings-hint" style="margin-top:12px;">
                    <?php echo esc_html__('No rotations performed yet — no log available.', 'form-forge'); ?>
                </p>
                <?php endif; ?>

                <div class="forge-reset-actions" style="margin-top:20px;">
                    <span></span>
                    <button type="button" id="forge-key-view-close" class="button"><?php echo esc_html__('Close', 'form-forge'); ?></button>
                </div>
            </div>
        </div>

        <!-- Factory-reset modal -->
        <div id="forge-reset-overlay" class="forge-reset-overlay" hidden>
            <div class="forge-modal-box forge-factory-reset-modal"
                 role="dialog" aria-modal="true" aria-labelledby="forge-reset-title">
                <div class="forge-reset-header">
                    <span class="forge-reset-icon"><i class="fa-solid fa-triangle-exclamation"></i></span>
                    <div>
                        <h2 id="forge-reset-title"><?php echo esc_html__('Confirm reset', 'form-forge'); ?></h2>
                        <p class="forge-reset-subtitle"><?php echo esc_html__('This action cannot be undone.', 'form-forge'); ?></p>
                    </div>
                </div>

                <p class="forge-reset-lead"><?php echo esc_html__('The following settings will be permanently deleted:', 'form-forge'); ?></p>
                <ul class="forge-reset-list">
                    <li><i class="fa-solid fa-envelope"></i> <?php echo esc_html__('Email sender (address & name)', 'form-forge'); ?></li>
                    <li><i class="fa-solid fa-shield-halved"></i> <?php echo esc_html__('reCAPTCHA Site- & Secret Key', 'form-forge'); ?></li>
                    <li><i class="fa-solid fa-paintbrush"></i> <?php echo esc_html__('Display colors', 'form-forge'); ?></li>
                    <li><i class="fa-solid fa-file-pdf"></i> <?php echo esc_html__('PDF layout settings', 'form-forge'); ?></li>
                    <li><i class="fa-solid fa-users"></i>
                        <?php echo esc_html__('User management & access rights', 'form-forge'); ?></li>
                </ul>

                <div class="forge-reset-key-notice">
                    <i class="fa-solid fa-key"></i>
                    <span><?php
                            $strong_nicht = '<strong>' . esc_html__('not', 'form-forge') . '</strong>';
                            printf(
                                /* translators: %s: "nicht" as a strong element */
                                __('PDF seal keys will %s be deleted.', 'form-forge'),
                                $strong_nicht
                            );
                            ?></span>
                </div>

                <label class="forge-reset-check-wrap" id="forge-reset-forms-label">
                    <input type="checkbox" id="forge-reset-delete-forms">
                    <div class="forge-reset-check-content">
                        <span class="forge-reset-check-title"><?php echo esc_html__('Delete forms & selection groups', 'form-forge'); ?></span>
                        <span class="forge-reset-check-desc"><?php echo esc_html__('All forms and form selection groups will be permanently removed.', 'form-forge'); ?></span>
                    </div>
                </label>

                <div class="forge-reset-actions">
                    <button type="button" id="forge-reset-cancel" class="button"><?php echo esc_html__('Cancel', 'form-forge'); ?></button>
                    <button type="button" id="forge-reset-confirm" class="button forge-reset-confirm">
                        <i class="fa-solid fa-rotate-left"></i> <?php echo esc_html__('Reset', 'form-forge'); ?>
                    </button>
                </div>
            </div>
        </div>

        <!-- Key-download modal -->
        <div id="forge-key-dl-overlay" class="forge-reset-overlay" hidden>
            <div class="forge-modal-box forge-key-download-modal" role="dialog" aria-modal="true"
                 aria-labelledby="forge-key-dl-title">
                <h2 id="forge-key-dl-title" style="color:#1a56db;">
                    <i class="fa-solid fa-key"></i> <?php echo esc_html__('Back up key', 'form-forge'); ?>
                </h2>
                <p>
                    <?php
                    echo esc_html__('A new PDF seal key has been created. Download the key file and store it securely — e.g. in a password manager. You will need it to re-verify old PDFs after a total server loss.', 'form-forge'); // phpcs:ignore Generic.Files.LineLength
                    ?>
                </p>
                <div class="forge-key-dl-info">
                    <div><strong><?php echo esc_html__('UUID:', 'form-forge'); ?></strong>
                        <code id="forge-key-dl-uuid" class="forge-key-fp-cell forge-key-uuid-cell"></code>
                    </div>
                    <div><strong><?php echo esc_html__('Created:', 'form-forge'); ?></strong> <span id="forge-key-dl-date"></span></div>
                </div>
                <div class="forge-reset-actions" style="margin-top:20px;flex-direction:column;gap:10px;">
                    <button type="button" id="forge-key-dl-btn"
                            class="button button-primary" style="width:100%;justify-content:center;">
                        <i class="fa-solid fa-download"></i> <?php echo esc_html__('Download key file', 'form-forge'); ?>
                    </button>
                    <button type="button" id="forge-key-dl-confirm" class="button" disabled
                            style="width:100%;justify-content:center;">
                        <i class="fa-solid fa-check"></i> <?php echo esc_html__('Saved — Continue', 'form-forge'); ?>
                    </button>
                </div>
                <p class="forge-settings-hint" style="margin-top:12px;text-align:center;">
                    <?php echo esc_html__('You must download the file before you can continue.', 'form-forge'); ?>
                </p>
            </div>
        </div>

        <!-- Master-key setup modal (Increase Security path) -->
        <div id="forge-master-key-overlay" class="forge-reset-overlay" hidden>
            <div class="forge-modal-box forge-master-key-modal" role="dialog" aria-modal="true"
                 aria-labelledby="forge-master-key-title">
                <h2 id="forge-master-key-title" style="color:#1a56db;">
                    <i class="fa-solid fa-lock"></i> <?php echo esc_html__('Set up master key', 'form-forge'); ?>
                </h2>
                <p>
                    <?php
                    printf(
                        /* translators: 1: wp-config.php as code element, 2: "bevor" as strong element */
                        __('Add this line to your %1$s %2$s clicking "Continue". The key never leaves the server — it lives only in your configuration file.', 'form-forge'),
                        '<code>wp-config.php</code>',
                        '<strong>' . esc_html__('before', 'form-forge') . '</strong>'
                    );
                    ?>
                </p>
                <div class="forge-key-dl-info" style="margin-bottom:14px;">
                    <div style="font-size:11px;font-family:monospace;word-break:break-all;"
                         id="forge-master-key-line">—</div>
                </div>
                <p class="forge-settings-hint">
                    <?php echo esc_html__('If you lose this line, all stored keys become unrecoverable. Keep it as safe as a password.', 'form-forge'); // phpcs:ignore Generic.Files.LineLength ?>
                </p>
                <p id="forge-master-key-error" class="forge-settings-hint"
                   style="color:#b32d2e;display:none;margin-top:8px;"></p>
                <div class="forge-reset-actions" style="margin-top:20px;">
                    <button type="button" id="forge-master-key-cancel" class="button"><?php echo esc_html__('Cancel', 'form-forge'); ?></button>
                    <button type="button" id="forge-master-key-confirm" class="button button-primary">
                        <i class="fa-solid fa-check"></i> <?php echo esc_html__('Entered — Continue', 'form-forge'); ?>
                    </button>
                </div>
            </div>
        </div>

        <!-- Legacy-key import modal -->
        <div id="forge-legacy-key-overlay" class="forge-reset-overlay" hidden>
            <div class="forge-modal-box forge-legacy-import-modal" role="dialog" aria-modal="true"
                 aria-labelledby="forge-legacy-key-title">
                <h2 id="forge-legacy-key-title" style="color:#a9a9a9;">
                    <i class="fa-solid fa-file-import"></i> <?php echo esc_html__('Add legacy key', 'form-forge'); ?>
                </h2>
                <p style="margin-bottom:4px;">
                    <?php echo esc_html__('Paste the full contents of the saved key file.', 'form-forge'); ?>
                </p>
                <textarea id="forge-legacy-key-json" rows="10"
                          style="width:100%;font-family:monospace;font-size:12px;resize:vertical;"
                          placeholder='{"plugin":"FormForge PDF Seal Key","uuid":"...","key":"...","created_at":"..."}'
                          autocomplete="off" spellcheck="false"></textarea>
                <p style="margin:12px 0 6px;font-weight:600;"><?php echo esc_html__('Status of this key:', 'form-forge'); ?></p>
                <label style="display:flex;align-items:center;gap:8px;margin-bottom:6px;cursor:pointer;">
                    <input type="radio" name="forge_legacy_status" id="forge-legacy-status-rotated"
                           value="rotated-legacy" checked>
                    <?php echo esc_html__('Rotated — key was regularly replaced', 'form-forge'); ?>
                </label>
                <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                    <input type="radio" name="forge_legacy_status" id="forge-legacy-status-compromised"
                           value="compromised-legacy">
                    <?php echo esc_html__('Compromised — key was classified as unsafe', 'form-forge'); ?>
                </label>
                <p id="forge-legacy-key-error" class="forge-settings-hint"
                   style="color:#b32d2e;display:none;margin-top:10px;"></p>
                <p id="forge-legacy-key-mismatch-msg"
                   style="display:none;margin-top:10px;font-size:12px;color:#50575e;
                          background:#fff8e1;border:1px solid #f0c040;border-radius:4px;padding:8px 10px;">
                </p>
                <div class="forge-reset-actions" style="margin-top:16px;">
                    <button type="button" id="forge-legacy-key-cancel" class="button"><?php echo esc_html__('Cancel', 'form-forge'); ?></button>
                    <button type="button" id="forge-legacy-key-force" class="button button-primary"
                            style="display:none;">
                        <i class="fa-solid fa-plus"></i> <?php echo esc_html__('Import anyway', 'form-forge'); ?>
                    </button>
                    <button type="button" id="forge-legacy-key-confirm" class="button button-primary">
                        <i class="fa-solid fa-plus"></i> <?php echo esc_html__('Add', 'form-forge'); ?>
                    </button>
                </div>
            </div>
        </div>

        <!-- User-access modal -->
        <div id="forge-access-overlay" class="forge-reset-overlay" hidden>
            <div class="forge-modal-box forge-access-modal"
                 role="dialog" aria-modal="true" aria-labelledby="forge-access-modal-title">
                <h2 id="forge-access-modal-title">
                    <i class="fa-solid fa-users-gear"></i> <?php echo esc_html__('User access', 'form-forge'); ?>
                </h2>
                <p><?php echo esc_html__('Administrators always have full access. User settings override the role setting.', 'form-forge'); ?></p>

                <div id="forge-access-loading" style="text-align:center;padding:24px 0;">
                    <i class="fa-solid fa-spinner fa-spin"></i> <?php echo esc_html__('Loading…', 'form-forge'); ?>
                </div>
                <div id="forge-access-content" style="display:none;">
                    <h3 class="forge-access-section-title"><?php echo esc_html__('Roles', 'form-forge'); ?></h3>
                    <div class="forge-access-scroll">
                        <table class="forge-access-table">
                            <thead><tr id="forge-access-roles-head"></tr></thead>
                            <tbody id="forge-access-roles-body"></tbody>
                        </table>
                    </div>

                    <h3 class="forge-access-section-title"><?php echo esc_html__('User exceptions', 'form-forge'); ?></h3>
                    <div class="forge-access-scroll" id="forge-access-users-scroll">
                        <table class="forge-access-table">
                            <thead><tr id="forge-access-users-head"></tr></thead>
                            <tbody id="forge-access-users-body">
                                <tr id="forge-access-no-users">
                                    <td colspan="6" class="forge-access-empty">
                                        <?php echo esc_html__('No user exceptions configured.', 'form-forge'); ?>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="forge-access-search-section">
                        <input type="text" id="forge-access-user-search"
                               class="forge-access-search-input"
                               placeholder="<?php echo esc_attr__('Add user…', 'form-forge'); ?>"
                               autocomplete="off" spellcheck="false">
                        <div id="forge-access-user-dropdown"
                             class="forge-access-dropdown" hidden></div>
                    </div>
                    <p id="forge-access-error"
                       style="color:#b32d2e;display:none;margin-top:8px;font-size:12px;"></p>
                </div>

                <div class="forge-reset-actions" style="margin-top:16px;">
                    <button type="button" id="forge-access-cancel" class="button"><?php echo esc_html__('Cancel', 'form-forge'); ?></button>
                    <button type="button" id="forge-access-save" class="button button-primary">
                        <i class="fa-solid fa-floppy-disk"></i> <?php echo esc_html__('Save', 'form-forge'); ?>
                    </button>
                </div>
            </div>
        </div>

        <?php
        /* Build access data inline so the modal opens instantly.
           This includes the full site user list and every user's/role's
           permission matrix — restricted to real WP admins, since the
           access-management UI itself is admin-only by design (a user with
           only the plugin's own 'settings' capability must not be able to
           read other users' permission grants from page source). */
        $access_inline_data = null;
        if ($is_full_admin) {
            global $wp_roles;
            $access_option = get_option('forge_forms_access', ['roles' => [], 'users' => []]);
            $access_role_names = [];
            foreach ($wp_roles->roles as $_slug => $_data) {
                $access_role_names[$_slug] = translate_user_role($_data['name']);
            }
            $access_all_users = get_users(['fields' => ['ID', 'display_name', 'user_login']]);
            $access_user_list = [];
            foreach ($access_all_users as $_u) {
                $access_user_list[] = [
                    'id'   => (int) $_u->ID,
                    'name' => $_u->display_name ?: $_u->user_login,
                ];
            }
            $access_user_overrides = [];
            foreach (($access_option['users'] ?? []) as $_uid => $_perms) {
                $_ud = get_userdata((int) $_uid);
                $access_user_overrides[] = [
                    'id'    => (int) $_uid,
                    'name'  => $_ud
                        ? ($_ud->display_name ?: $_ud->user_login)
                        : 'Unknown (#' . (int) $_uid . ')',
                    'perms' => is_array($_perms) ? $_perms : [],
                ];
            }
            $access_inline_data = [
                'roles'          => (object) ($access_option['roles'] ?? []),
                'role_names'     => $access_role_names,
                'user_overrides' => $access_user_overrides,
                'user_list'      => $access_user_list,
            ];
        }
        ?>
        <script>
        window._forgeIsFullAdmin       = <?php echo wp_json_encode($is_full_admin); ?>;
        window._forgePendingDownload    = <?php echo wp_json_encode($pending_download ?? null); ?>;
        window._forgeSetupDone         = <?php echo wp_json_encode($setup_done); ?>;
        window._forgeSetupNonce        = <?php echo wp_json_encode($setup_nonce); ?>;
        window._forgeLegacyKeyNonce    = <?php echo wp_json_encode(wp_create_nonce('forge_add_legacy_key')); ?>;
        <?php if ($is_full_admin) : ?>
        window._forgeAccessNonce       = <?php echo wp_json_encode(wp_create_nonce('forge_access_settings')); ?>;
        window._forgeAccessData        = <?php echo wp_json_encode($access_inline_data); ?>;
        <?php endif; ?>
        <?php
        // Determine which step the blocker should open on.
        // Use the raw DB option — isEncryptionEnabled() also requires the constant, which may not
        // be present yet (that is exactly the 'masterkey' state we need to detect).
        $enc_chosen = get_option('forge_forms_seal_encryption') === 'enabled';
        $mk_defined = defined('FORGE_SEAL_MASTER_KEY') && (string) FORGE_SEAL_MASTER_KEY !== '';
        if (!$setup_done_early && $enc_chosen) {
            $setup_state = $mk_defined ? 'ready' : 'masterkey';
        } else {
            $setup_state = 'choose';
        }
        ?>
        window._forgeSetupState = <?php echo wp_json_encode($setup_state); ?>;
        </script>

        <script>
        jQuery(function ($) {
function forgeLuminance(hex) {
    var r = parseInt(hex.slice(1, 3), 16);
    var g = parseInt(hex.slice(3, 5), 16);
    var b = parseInt(hex.slice(5, 7), 16);
    return (0.299 * r + 0.587 * g + 0.114 * b) / 255;
}
$('.forge-iris-input').wpColorPicker({
    change: function (event, ui) {
        var hex = ui.color.toString();
        var id  = this.id;
        if (id === 'admin_accent') {
            var lum = forgeLuminance(hex);
            document.documentElement.style.setProperty('--forge-admin-accent', hex);
            document.documentElement.style.setProperty('--forge-accent-text', lum > 0.55 ? '#1d2327' : '#ffffff');
            document.documentElement.style.setProperty('--forge-admin-accent-fg', lum > 0.55 ? '#1d2327' : hex);
        } else if (id === 'hover_color') {
            document.documentElement.style.setProperty('--forge-hover-color', hex);
            document.documentElement.style.setProperty('--forge-hover-color-fg', forgeLuminance(hex) > 0.55 ? '#1d2327' : hex);
        }
    },
});

            /* The Security/Miscellaneous cards stay in the DOM (hidden) for
               non-admins so existing JS bindings don't null-crash. This is
               UI-only — the real boundary is server-side (manage_options
               checks in the AJAX handlers) — but re-hide on tamper anyway. */
            if (!window._forgeIsFullAdmin) {
                var adminOnlySel = '.forge-settings-card--security, ' +
                    '#forge-access-tile-btn, #forge-reset-tile-btn';
                /* Modal overlays for the hidden tiles — toggled via the same
                   bare hidden attribute, but not nested inside a card. */
                var adminOnlyOverlayIds = [
                    'forge-key-overlay', 'forge-key-view-overlay',
                    'forge-reset-overlay', 'forge-key-dl-overlay',
                    'forge-master-key-overlay', 'forge-legacy-key-overlay',
                    'forge-access-overlay'
                ];
                function rehideAdminCards() {
                    document.querySelectorAll(adminOnlySel).forEach(function (el) {
                        var card = el.closest('.forge-settings-card') || el;
                        if (!card.hidden) card.hidden = true;
                    });
                    adminOnlyOverlayIds.forEach(function (id) {
                        var el = document.getElementById(id);
                        if (el && !el.hidden) el.hidden = true;
                    });
                }
                rehideAdminCards();
                new MutationObserver(rehideAdminCards).observe(document.body, {
                    attributes: true,
                    attributeFilter: ['hidden'],
                    subtree: true
                });
            }

            /* ── Factory-reset modal ── */
            var overlay   = document.getElementById('forge-reset-overlay');
            var chk       = document.getElementById('forge-reset-delete-forms');
            var confirmBtn = document.getElementById('forge-reset-confirm');
            var countdownTimer = null;

            var confirmed = false;

            document.getElementById('forge-reset-tile-btn').addEventListener('click', function () {
                chk.checked = false;
                confirmed = false;
                resetConfirmBtn();
                overlay.hidden = false;
                document.getElementById('forge-reset-cancel').focus();
            });

            document.getElementById('forge-reset-cancel').addEventListener('click', closeModal);

            overlay.addEventListener('click', function (e) {
                if (e.target === overlay) closeModal();
            });

            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape' && !overlay.hidden) closeModal();
            });

            confirmBtn.addEventListener('click', function () {
                if (confirmBtn.disabled) return;

                if (!confirmed) {
                    confirmed = true;
                    startCountdown();
                    return;
                }

                confirmBtn.disabled = true;
                confirmBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Resetting…';

                $.post(ajaxurl, {
                    action:    'forge_forms_factory_reset',
                    nonce:     '<?php echo esc_js(wp_create_nonce('forge_factory_reset')); ?>',
                    del_forms: chk.checked ? '1' : '0'
                }, function (res) {
                    if (res.success) {
                        overlay.hidden = true;
                        window.location.reload();
                    } else {
                        confirmed = false;
                        confirmBtn.disabled = false;
                        confirmBtn.innerHTML = 'Error — try again';
                    }
                });
            });

            function startCountdown() {
                var sec = 10;
                confirmBtn.disabled = true;
                confirmBtn.innerHTML = 'Are you sure? (' + sec + ')';
                countdownTimer = setInterval(function () {
                    sec--;
                    if (sec <= 0) {
                        clearInterval(countdownTimer);
                        confirmBtn.disabled = false;
                        confirmBtn.innerHTML = '<i class="fa-solid fa-check"></i> Yes, reset';
                    } else {
                        confirmBtn.innerHTML = 'Are you sure? (' + sec + ')';
                    }
                }, 1000);
            }

            function resetConfirmBtn() {
                clearInterval(countdownTimer);
                confirmed = false;
                confirmBtn.disabled = false;
                confirmBtn.innerHTML = 'Reset';
            }

            function closeModal() {
                clearInterval(countdownTimer);
                overlay.hidden = true;
            }
        });

        /* ── Key-rotation modal ── */
        (function () {
            var keyOverlay  = document.getElementById('forge-key-overlay');
            var triggerBtn  = document.getElementById('forge-rotate-key-trigger');
            var cancelBtn   = document.getElementById('forge-key-cancel');
            var confirmBtn  = document.getElementById('forge-key-confirm');
            var pwInput     = document.getElementById('forge_key_pw');
            var pw2Input    = document.getElementById('forge_key_pw2');
            var chk         = document.getElementById('forge_key_compromised');
            var cmpHint     = document.getElementById('forge-key-compromised-hint');
            var errList     = document.getElementById('forge-key-pw-errors');
            var bars        = document.querySelectorAll('#forge-key-strength span');
            var modalMsg    = document.getElementById('forge-key-modal-msg');

            if (!triggerBtn) { return; }

            function rules(pw) {
                return [
                    { ok: pw.length >= 12,          label: 'Min. 12 characters' },
                    { ok: /[A-Z]/.test(pw),         label: 'Uppercase letter' },
                    { ok: /[a-z]/.test(pw),         label: 'Lowercase letter' },
                    { ok: /[0-9]/.test(pw),         label: 'Digit' },
                    { ok: /[^A-Za-z0-9]/.test(pw), label: 'Special character' },
                ];
            }

            function validate() {
                var pw  = pwInput.value;
                var rs  = rules(pw);
                var passed = rs.filter(function (r) { return r.ok; }).length;

                bars.forEach(function (b, i) {
                    b.className = i < passed ? 'forge-key-bar-' + Math.min(passed, 5) : '';
                });

                errList.innerHTML = '';
                rs.forEach(function (r) {
                    if (!r.ok) {
                        var li = document.createElement('li');
                        li.textContent = r.label;
                        errList.appendChild(li);
                    }
                });

                var allPass = rs.every(function (r) { return r.ok; });
                var match   = pw !== '' && pw === pw2Input.value;
                confirmBtn.disabled = !(allPass && match);
            }

            function openModal() {
                pwInput.value  = '';
                pw2Input.value = '';
                chk.checked    = false;
                cmpHint.hidden = true;
                modalMsg.hidden = true;
                errList.innerHTML = '';
                bars.forEach(function (b) { b.className = ''; });
                confirmBtn.disabled = true;
                keyOverlay.hidden = false;
                pwInput.focus();
            }

            function closeKeyModal() {
                keyOverlay.hidden = true;
            }

            triggerBtn.addEventListener('click', openModal);
            cancelBtn.addEventListener('click', closeKeyModal);

            keyOverlay.addEventListener('click', function (e) {
                if (e.target === keyOverlay) { closeKeyModal(); }
            });

            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape' && !keyOverlay.hidden) { closeKeyModal(); }
            });

            pwInput.addEventListener('input', validate);
            pw2Input.addEventListener('input', validate);

            chk.addEventListener('change', function () {
                cmpHint.hidden = !chk.checked;
            });

            confirmBtn.addEventListener('click', function () {
                if (confirmBtn.disabled) { return; }
                confirmBtn.disabled = true;

                var fd = new FormData();
                fd.append('action',               'forge_forms_rotate_key');
                fd.append('nonce',                <?php echo wp_json_encode($rotate_nonce); ?>);
                fd.append('key_password',         pwInput.value);
                fd.append('key_password_confirm', pw2Input.value);
                fd.append('key_compromised',      chk.checked ? '1' : '0');

                fetch(ajaxurl, { method: 'POST', body: fd })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        var ok  = data.success;
                        var txt = (data.data && data.data.message)
                            ? data.data.message
                            : (ok ? 'Success.' : 'Error.');
                        modalMsg.hidden      = false;
                        modalMsg.textContent = txt;
                        modalMsg.style.color = ok ? '#1a5c28' : '#b32d2e';
                        if (ok) {
                            closeKeyModal();
                            showKeyDownloadModal({
                                uuid:       data.data.key_uuid,
                                key:        data.data.key_value,
                                created_at: data.data.created_at,
                            });
                        } else {
                            confirmBtn.disabled = false;
                        }
                    })
                    .catch(function () {
                        modalMsg.hidden      = false;
                        modalMsg.textContent = 'Network error.';
                        modalMsg.style.color = '#b32d2e';
                        confirmBtn.disabled  = false;
                    });
            });
        }());

        /* ── Key-download modal ── */
        function showKeyDownloadModal(keyData) {
            var dlOverlay  = document.getElementById('forge-key-dl-overlay');
            var dlUuid     = document.getElementById('forge-key-dl-uuid');
            var dlDate     = document.getElementById('forge-key-dl-date');
            var dlBtn      = document.getElementById('forge-key-dl-btn');
            var dlConfirm  = document.getElementById('forge-key-dl-confirm');
            if (!dlOverlay) { location.reload(); return; }

            dlUuid.textContent    = keyData.uuid || '—';
            dlDate.textContent    = keyData.created_at || '—';
            dlConfirm.disabled    = true;
            dlOverlay._keyData    = keyData;
            dlOverlay.hidden      = false;

            dlBtn.onclick = function () {
                var payload = JSON.stringify({
                    plugin:     'FormForge PDF Seal Key',
                    uuid:       keyData.uuid,
                    key:        keyData.key,
                    created_at: keyData.created_at,
                }, null, 2);
                var blob = new Blob([payload], { type: 'application/json' });
                var a    = document.createElement('a');
                a.href     = URL.createObjectURL(blob);
                a.download = 'formforge-key-' + (keyData.uuid || 'key').substring(0, 8) + '.json';
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
                URL.revokeObjectURL(a.href);
                dlConfirm.disabled = false;
            };

            dlConfirm.onclick = function () {
                dlOverlay.hidden = true;
                location.reload();
            };
        }

        /* Show download modal on page load if a key was just auto-generated */
        (function () {
            var pending = window._forgePendingDownload;
            if (pending && pending.uuid && pending.key) {
                showKeyDownloadModal(pending);
            }
        }());

        /* ── Legacy-key import modal ── */
        (function () {
            var overlay       = document.getElementById('forge-legacy-key-overlay');
            var trigger       = document.getElementById('forge-legacy-key-trigger');
            var cancelBtn     = document.getElementById('forge-legacy-key-cancel');
            var confirmBtn    = document.getElementById('forge-legacy-key-confirm');
            var textarea      = document.getElementById('forge-legacy-key-json');
            var errEl         = document.getElementById('forge-legacy-key-error');
            var mismatchMsg   = document.getElementById('forge-legacy-key-mismatch-msg');
            var forceBtn      = document.getElementById('forge-legacy-key-force');
            var radioRotated = document.getElementById('forge-legacy-status-rotated');
            if (!trigger) { return; }

            function open() {
                textarea.value            = '';
                errEl.style.display       = 'none';
                mismatchMsg.style.display = 'none';
                confirmBtn.style.display  = '';
                forceBtn.style.display    = 'none';
                radioRotated.checked      = true;
                overlay.hidden            = false;
                textarea.focus();
            }
            function close() { overlay.hidden = true; }

            forceBtn.addEventListener('click', function () { submitLegacy(true); });

            trigger.addEventListener('click', open);
            cancelBtn.addEventListener('click', close);
            overlay.addEventListener('click', function (e) {
                if (e.target === overlay) { close(); }
            });
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape' && !overlay.hidden) { close(); }
            });

            function submitLegacy(confirmMismatch) {
                confirmBtn.disabled = true;
                errEl.style.display       = 'none';
                mismatchMsg.style.display = 'none';
                var statusRadio = overlay.querySelector('input[name="forge_legacy_status"]:checked');
                var fd = new FormData();
                fd.append('action',      'forge_add_legacy_key');
                fd.append('nonce',       window._forgeLegacyKeyNonce);
                fd.append('key_json',    textarea.value);
                fd.append('key_status',  statusRadio ? statusRadio.value : 'rotated-legacy');
                if (confirmMismatch) { fd.append('confirm_mismatch', '1'); }
                fetch(ajaxurl, { method: 'POST', body: fd })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        if (data.success) {
                            close();
                            location.reload();
                            return;
                        }
                        if (data.data && data.data.code === 'plugin_mismatch') {
                            mismatchMsg.textContent  = '';
                            var msgNode = document.createTextNode(data.data.text || '');
                            var brNode  = document.createElement('br');
                            var q2Node  = document.createTextNode(data.data.confirm || '');
                            mismatchMsg.appendChild(msgNode);
                            mismatchMsg.appendChild(brNode);
                            mismatchMsg.appendChild(q2Node);
                            mismatchMsg.style.display = 'block';
                            confirmBtn.style.display  = 'none';
                            forceBtn.style.display    = '';
                            confirmBtn.disabled = false;
                            return;
                        }
                        errEl.textContent   = (data.data && data.data.message) || 'Error.';
                        errEl.style.display = 'block';
                        confirmBtn.disabled = false;
                    })
                    .catch(function () {
                        errEl.textContent   = 'Network error.';
                        errEl.style.display = 'block';
                        confirmBtn.disabled = false;
                    });
            }

            confirmBtn.addEventListener('click', function () { submitLegacy(false); });
        }());

        /* ── Blocking setup overlay ── */
        (function () {
            var blocker     = document.getElementById('forge-setup-blocker');
            if (!blocker) { return; } // already setup_done, element not rendered

            // Scope backdrop to the WP content area, not the full viewport.
            function positionBlocker() {
                var wpc = document.getElementById('wpcontent');
                if (wpc) {
                    var r = wpc.getBoundingClientRect();
                    blocker.style.top    = r.top  + window.scrollY + 'px';
                    blocker.style.left   = r.left + window.scrollX + 'px';
                    blocker.style.width  = r.width  + 'px';
                    blocker.style.height = Math.max(r.height, window.innerHeight - r.top) + 'px';
                }
            }
            positionBlocker();
            window.addEventListener('resize', positionBlocker);

            var btnDefault  = document.getElementById('forge-blocker-default');
            var btnSecure   = document.getElementById('forge-blocker-secure');
            var blockerErr  = document.getElementById('forge-blocker-error');

            // Card hover effect.
            [btnDefault, btnSecure].forEach(function (btn) {
                btn.addEventListener('mouseenter', function () {
                    btn.style.borderColor = '#2271b1';
                });
                btn.addEventListener('mouseleave', function () {
                    btn.style.borderColor = '#dcdcde';
                });
            });
            var secActions    = document.getElementById('forge-security-actions');
            var mkStep        = document.getElementById('forge-blocker-mk-step');
            var mkLine        = document.getElementById('forge-blocker-mk-line');
            var mkBack        = document.getElementById('forge-blocker-mk-back');
            var mkConfirm     = document.getElementById('forge-blocker-mk-confirm');
            var mkError       = document.getElementById('forge-blocker-mk-error');
            var readyStep     = document.getElementById('forge-blocker-ready-step');
            var readyConfirm  = document.getElementById('forge-blocker-ready-confirm');
            var readyError    = document.getElementById('forge-blocker-ready-error');

            var step1Div = document.getElementById('forge-blocker-step1');

            function showBlockerError(msg) {
                blockerErr.textContent   = msg;
                blockerErr.style.display = 'block';
            }

            function postBlocker(action, extra, onSuccess) {
                var fd = new FormData();
                fd.append('action', action);
                fd.append('nonce',  window._forgeSetupNonce);
                if (extra) {
                    Object.keys(extra).forEach(function (k) { fd.append(k, extra[k]); });
                }
                fetch(ajaxurl, { method: 'POST', body: fd })
                    .then(function (r) { return r.json(); })
                    .then(onSuccess)
                    .catch(function () { showBlockerError('Network error.'); });
            }

            function hideStep1() { step1Div.style.display = 'none'; }
            function showStep1() { step1Div.style.display = ''; }

            function showMkStep(defineLine) {
                mkLine.textContent     = defineLine || '…';
                mkError.style.display  = 'none';
                hideStep1();
                mkStep.style.display   = 'block';
                mkConfirm.disabled     = !defineLine;
            }

            function showReadyStep() {
                readyError.style.display = 'none';
                hideStep1();
                readyStep.style.display  = 'block';
            }

            function finishSetup(data) {
                blocker.style.display = 'none';
                showKeyDownloadModal(data.data);
                if (secActions) { secActions.removeAttribute('hidden'); }
            }

            // ── Auto-advance based on server-detected state ──
            var setupState = window._forgeSetupState || 'choose';
            if (setupState === 'masterkey') {
                // Encryption chosen but master key not in wp-config.php yet.
                showMkStep(''); // immediate — define line filled in on response
                postBlocker('forge_setup_get_master_key', null, function (data) {
                    if (data.success) {
                        mkLine.textContent = data.data.define_line;
                        mkConfirm.disabled = false;
                    } else {
                        showBlockerError((data.data && data.data.message) || 'Error.');
                    }
                });
            } else if (setupState === 'ready') {
                // Encryption chosen AND master key already present — skip straight to finalise.
                showReadyStep();
            }

            // ── Step-1 card buttons ──
            if (btnDefault) {
                btnDefault.addEventListener('click', function () {
                    btnDefault.disabled = true;
                    btnSecure.disabled  = true;
                    blockerErr.style.display = 'none';
                    postBlocker('forge_setup_keep_default', null, function (data) {
                        if (data.success) {
                            finishSetup(data);
                        } else {
                            btnDefault.disabled = false;
                            btnSecure.disabled  = false;
                            showBlockerError((data.data && data.data.message) || 'Error.');
                        }
                    });
                });
            }

            if (btnSecure) {
                btnSecure.addEventListener('click', function () {
                    blockerErr.style.display = 'none';
                    showMkStep(''); // immediate transition; define line filled in on response
                    postBlocker('forge_setup_get_master_key', null, function (data) {
                        if (!data.success) {
                            // Roll back to step 1 on error.
                            mkStep.style.display = 'none';
                            showStep1();
                            showBlockerError((data.data && data.data.message) || 'Error.');
                            return;
                        }
                        mkLine.textContent = data.data.define_line;
                        mkConfirm.disabled = false;
                    });
                });
            }

            // ── Step-2a back button ──
            if (mkBack) {
                mkBack.addEventListener('click', function () {
                    mkBack.disabled = true;
                    postBlocker('forge_setup_reset_choice', null, function () {
                        mkBack.disabled = false;
                        mkStep.style.display = 'none';
                        showStep1();
                        btnDefault.disabled = false;
                        btnSecure.disabled  = false;
                        blockerErr.style.display = 'none';
                    });
                });
            }

            // ── Step-2a: confirm master key added ──
            if (mkConfirm) {
                mkConfirm.addEventListener('click', function () {
                    mkConfirm.disabled    = true;
                    mkError.style.display = 'none';
                    postBlocker('forge_setup_confirm_secure', null, function (data) {
                        if (!data.success) {
                            mkConfirm.disabled    = false;
                            mkError.textContent   = (data.data && data.data.message) || 'Error.';
                            mkError.style.display = 'block';
                            return;
                        }
                        finishSetup(data);
                    });
                });
            }

            // ── Step-2b: master key already present, finalise ──
            if (readyConfirm) {
                readyConfirm.addEventListener('click', function () {
                    readyConfirm.disabled    = true;
                    readyError.style.display = 'none';
                    postBlocker('forge_setup_confirm_secure', null, function (data) {
                        if (!data.success) {
                            readyConfirm.disabled    = false;
                            readyError.textContent   = (data.data && data.data.message) || 'Error.';
                            readyError.style.display = 'block';
                            return;
                        }
                        finishSetup(data);
                    });
                });
            }
        }());


        /* ── Encryption upgrade (Standard → AES-256-GCM) ── */
        (function () {
            var upgradeBtn = document.getElementById('forge-upgrade-enc-btn');
            if (!upgradeBtn) { return; }

            var mkOverlay = document.getElementById('forge-master-key-overlay');
            var mkLine    = document.getElementById('forge-master-key-line');
            var mkConfirm = document.getElementById('forge-master-key-confirm');
            var mkCancel  = document.getElementById('forge-master-key-cancel');
            var mkError   = document.getElementById('forge-master-key-error');

            function openMkModal(defineLine) {
                mkLine.textContent    = defineLine || '…';
                mkError.style.display = 'none';
                mkConfirm.disabled    = !defineLine;
                mkOverlay.hidden      = false;
            }
            function closeMkModal() { mkOverlay.hidden = true; }

            upgradeBtn.addEventListener('click', function () {
                upgradeBtn.disabled = true;
                openMkModal('');
                var fd = new FormData();
                fd.append('action', 'forge_setup_get_master_key');
                fd.append('nonce',  window._forgeSetupNonce);
                fetch(ajaxurl, { method: 'POST', body: fd })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        upgradeBtn.disabled = false;
                        if (data.success) {
                            mkLine.textContent = data.data.define_line;
                            mkConfirm.disabled = false;
                        } else {
                            closeMkModal();
                        }
                    })
                    .catch(function () {
                        upgradeBtn.disabled = false;
                        closeMkModal();
                    });
            });

            mkCancel.addEventListener('click', closeMkModal);
            mkOverlay.addEventListener('click', function (e) {
                if (e.target === mkOverlay) { closeMkModal(); }
            });
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape' && !mkOverlay.hidden) { closeMkModal(); }
            });

            mkConfirm.addEventListener('click', function () {
                mkConfirm.disabled    = true;
                mkError.style.display = 'none';
                var fd = new FormData();
                fd.append('action', 'forge_setup_confirm_secure');
                fd.append('nonce',  window._forgeSetupNonce);
                fetch(ajaxurl, { method: 'POST', body: fd })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        if (!data.success) {
                            mkConfirm.disabled    = false;
                            mkError.textContent   = (data.data && data.data.message) || 'Error.';
                            mkError.style.display = 'block';
                            return;
                        }
                        closeMkModal();
                        if (data.data && data.data.upgrade) {
                            location.reload();
                            return;
                        }
                        showKeyDownloadModal(data.data);
                    })
                    .catch(function () {
                        mkConfirm.disabled    = false;
                        mkError.textContent   = 'Network error.';
                        mkError.style.display = 'block';
                    });
            });
        }());

        /* ── Fake-password: remove readonly on focus so managers can't pre-fill ── */
        (function () {
            document.querySelectorAll('.forge-fake-password').forEach(function (el) {
                el.addEventListener('focus', function () { el.removeAttribute('readonly'); });
            });
        }());

        /* ── Key-view modal ── */
        (function () {
            var viewOverlay = document.getElementById('forge-key-view-overlay');
            var viewTrigger = document.getElementById('forge-key-view-trigger');
            var viewClose   = document.getElementById('forge-key-view-close');
            if (!viewTrigger) { return; }

            function openView()  { viewOverlay.hidden = false; }
            function closeView() { viewOverlay.hidden = true; }

            viewTrigger.addEventListener('click', openView);
            viewClose.addEventListener('click', closeView);
            viewOverlay.addEventListener('click', function (e) {
                if (e.target === viewOverlay) { closeView(); }
            });
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape' && !viewOverlay.hidden) { closeView(); }
            });
        }());

        (function() {
            var canvas = document.getElementById('forge-particle-canvas');
            if (!canvas) return;
            var ctx = canvas.getContext('2d');
            var mouse = { x: -9999, y: -9999 };
            var _ah = getComputedStyle(document.documentElement).getPropertyValue('--forge-admin-accent').trim()||'#2271b1';
            var _rgb = function(h){return parseInt(h.slice(1,3),16)+','+parseInt(h.slice(3,5),16)+','+parseInt(h.slice(5,7),16);};
            var DOTS = Math.min(120, Math.max(40, Math.round(window.innerWidth * window.innerHeight / 26000)));
            var LINK = 150, SPEED = 1.0, COLOR = _rgb(_ah);
            var particles = [], paused = false, FRAME_MS = 1000 / 30;
            function resize() { canvas.width = window.innerWidth; canvas.height = window.innerHeight; }
            function rand(a, b) { return a + Math.random() * (b - a); }
            function init() {
                particles = [];
                for (var i = 0; i < DOTS; i++) {
                    particles.push({ x: rand(0, canvas.width), y: rand(0, canvas.height),
                        vx: rand(-SPEED, SPEED), vy: rand(-SPEED, SPEED), r: rand(2, 3.5) });
                }
            }
            function draw() {
                if (paused) return;
                ctx.clearRect(0, 0, canvas.width, canvas.height);
                for (var i = 0; i < particles.length; i++) {
                    var p = particles[i];
                    p.x += p.vx; p.y += p.vy;
                    if (p.x < 0 || p.x > canvas.width)  p.vx *= -1;
                    if (p.y < 0 || p.y > canvas.height) p.vy *= -1;
                }
                ctx.lineWidth = 1;
                for (var i = 0; i < particles.length; i++) {
                    for (var j = i + 1; j < particles.length; j++) {
                        var dx = particles[i].x - particles[j].x, dy = particles[i].y - particles[j].y;
                        var d = Math.sqrt(dx*dx + dy*dy);
                        if (d < LINK) {
                            ctx.beginPath(); ctx.moveTo(particles[i].x, particles[i].y);
                            ctx.lineTo(particles[j].x, particles[j].y);
                            ctx.strokeStyle = 'rgba(' + COLOR + ',' + (1 - d/LINK) * 0.3 + ')';
                            ctx.stroke();
                        }
                    }
                    var mdx = particles[i].x - mouse.x, mdy = particles[i].y - mouse.y;
                    var md = Math.sqrt(mdx*mdx + mdy*mdy);
                    if (md < LINK) {
                        ctx.beginPath(); ctx.moveTo(particles[i].x, particles[i].y);
                        ctx.lineTo(mouse.x, mouse.y);
                        ctx.strokeStyle = 'rgba(' + COLOR + ',' + (1 - md/LINK) * 0.55 + ')';
                        ctx.stroke();
                    }
                }
                ctx.fillStyle = 'rgba(' + COLOR + ', 0.5)';
                for (var i = 0; i < particles.length; i++) {
                    ctx.beginPath(); ctx.arc(particles[i].x, particles[i].y, particles[i].r, 0, Math.PI*2); ctx.fill();
                }
                setTimeout(function() { requestAnimationFrame(draw); }, FRAME_MS - 2);
            }
            document.addEventListener('mousemove', function(e) { mouse.x = e.clientX; mouse.y = e.clientY; });
            document.addEventListener('visibilitychange', function() {
                paused = document.hidden;
                if (!paused) requestAnimationFrame(draw);
            });
            window.addEventListener('resize', function() { resize(); init(); });
            resize(); init(); requestAnimationFrame(draw);
        }());

        /* ── User-access modal ── */
        (function () {
            var CAPS = [
                ['view_forms',      'List'],
                ['edit_forms',      'Forms'],
                ['edit_pdf_layout', 'PDF Layout'],
                ['use_verifier',    'Verifier'],
                ['settings',        'Settings'],
            ];

            var overlay    = document.getElementById('forge-access-overlay');
            var loading    = document.getElementById('forge-access-loading');
            var content    = document.getElementById('forge-access-content');
            var rolesHead  = document.getElementById('forge-access-roles-head');
            var rolesBody  = document.getElementById('forge-access-roles-body');
            var usersHead  = document.getElementById('forge-access-users-head');
            var usersBody  = document.getElementById('forge-access-users-body');
            var noUsers    = document.getElementById('forge-access-no-users');
            var searchInput = document.getElementById('forge-access-user-search');
            var dropdown   = document.getElementById('forge-access-user-dropdown');
            var saveBtn    = document.getElementById('forge-access-save');
            var cancelBtn  = document.getElementById('forge-access-cancel');
            var errorEl    = document.getElementById('forge-access-error');

            /* roles  = { slug: { view_forms: bool, ... } }
               users  = [{ id, name, perms: { view_forms: bool, ... } }]
               roleNames = { slug: label } */
            var roles = {}, roleNames = {}, users = [], userList = [];

            document.getElementById('forge-access-tile-btn').addEventListener('click', function () {
                var d    = window._forgeAccessData || {};
                roles     = d.roles      || {};
                roleNames = d.role_names || {};
                users     = (d.user_overrides || []).map(function (u) {
                    return { id: u.id, name: u.name, perms: u.perms || {} };
                });
                userList  = d.user_list  || [];
                overlay.hidden = false;
                loading.style.display = 'none';
                content.style.display = '';
                errorEl.style.display = 'none';
                buildHeaders();
                renderRoles();
                renderUsers();
                renderUserSelect();
            });

            function closeModal() { overlay.hidden = true; }
            cancelBtn.addEventListener('click', closeModal);

            var suppressNextOverlayClose = false;
            overlay.addEventListener('mousedown', function (e) {
                if (e.target === overlay && !dropdown.hidden) {
                    suppressNextOverlayClose = true;
                }
            });
            overlay.addEventListener('click', function (e) {
                if (e.target !== overlay) return;
                if (suppressNextOverlayClose) {
                    suppressNextOverlayClose = false;
                    return;
                }
                closeModal();
            });

            function buildHeaders() {
                [rolesHead, usersHead].forEach(function (head, isUsers) {
                    head.innerHTML = '';
                    var thName = document.createElement('th');
                    thName.textContent = isUsers ? 'User' : 'Role';
                    thName.className = 'forge-access-th forge-access-th--name';
                    head.appendChild(thName);
                    CAPS.forEach(function (cap) {
                        var th = document.createElement('th');
                        th.textContent = cap[1];
                        th.className = 'forge-access-th forge-access-th--cap';
                        head.appendChild(th);
                    });
                });
            }

            function emptyPerms() {
                var p = {};
                CAPS.forEach(function (c) { p[c[0]] = false; });
                return p;
            }

            function renderRoles() {
                rolesBody.innerHTML = '';
                Object.keys(roleNames).forEach(function (slug) {
                    var isAdmin = slug === 'administrator';
                    var perms   = roles[slug] || emptyPerms();
                    var tr = document.createElement('tr');
                    tr.className = 'forge-access-row';

                    var tdName = document.createElement('td');
                    tdName.className = 'forge-access-td forge-access-td--name';
                    tdName.textContent = roleNames[slug];
                    tr.appendChild(tdName);

                    CAPS.forEach(function (cap) {
                        var td = document.createElement('td');
                        td.className = 'forge-access-td forge-access-td--cap';
                        if (isAdmin) {
                            var icon = document.createElement('i');
                            icon.className = 'fa-solid fa-check forge-access-always';
                            td.appendChild(icon);
                        } else {
                            var cb = document.createElement('input');
                            cb.type = 'checkbox';
                            cb.className = 'forge-access-cb';
                            cb.checked = !!perms[cap[0]];
                            (function (s, key) {
                                cb.addEventListener('change', function () {
                                    if (!roles[s]) roles[s] = emptyPerms();
                                    roles[s][key] = this.checked;
                                });
                            }(slug, cap[0]));
                            td.appendChild(cb);
                        }
                        tr.appendChild(td);
                    });
                    rolesBody.appendChild(tr);
                });
            }

            function renderUsers() {
                Array.from(usersBody.querySelectorAll('tr.forge-u-row')).forEach(function (r) { r.remove(); });
                noUsers.style.display = users.length ? 'none' : '';
                users.forEach(function (u, i) {
                    var tr = document.createElement('tr');
                    tr.className = 'forge-access-row forge-u-row';

                    var tdName = document.createElement('td');
                    tdName.className = 'forge-access-td forge-access-td--name';
                    var nameBtn = document.createElement('button');
                    nameBtn.type = 'button';
                    nameBtn.className = 'forge-access-name-btn';
                    nameBtn.title = 'Click to remove';
                    nameBtn.textContent = u.name;
                    (function (idx) {
                        nameBtn.addEventListener('click', function () {
                            users.splice(idx, 1);
                            renderUsers();
                            renderUserSelect();
                        });
                    }(i));
                    tdName.appendChild(nameBtn);
                    tr.appendChild(tdName);

                    CAPS.forEach(function (cap) {
                        var td = document.createElement('td');
                        td.className = 'forge-access-td forge-access-td--cap';
                        var cb = document.createElement('input');
                        cb.type = 'checkbox';
                        cb.className = 'forge-access-cb';
                        cb.checked = !!(u.perms && u.perms[cap[0]]);
                        (function (userObj, key) {
                            cb.addEventListener('change', function () {
                                if (!userObj.perms) userObj.perms = emptyPerms();
                                userObj.perms[key] = this.checked;
                            });
                        }(u, cap[0]));
                        td.appendChild(cb);
                        tr.appendChild(td);
                    });
                    usersBody.insertBefore(tr, noUsers);
                });
            }

            function renderUserSelect() {
                searchInput.value = '';
                dropdown.hidden = true;
                dropdown.innerHTML = '';
            }

            function availableUsers() {
                var usedIds = users.map(function (u) { return u.id; });
                return userList.filter(function (u) { return usedIds.indexOf(u.id) === -1; });
            }

            function positionDropdown() {
                var r = searchInput.getBoundingClientRect();
                dropdown.style.top   = (r.bottom + 4) + 'px';
                dropdown.style.left  = r.left + 'px';
                dropdown.style.width = r.width + 'px';
            }

            function showDropdown(term) {
                var list = availableUsers().filter(function (u) {
                    return !term || u.name.toLowerCase().indexOf(term.toLowerCase()) !== -1;
                });
                dropdown.innerHTML = '';
                if (!list.length) {
                    dropdown.hidden = true;
                    return;
                }
                list.forEach(function (u) {
                    var item = document.createElement('div');
                    item.className = 'forge-access-dropdown-item';

                    var nameSpan = document.createElement('span');
                    nameSpan.textContent = u.name;
                    item.appendChild(nameSpan);

                    var inlineAdd = document.createElement('button');
                    inlineAdd.type = 'button';
                    inlineAdd.className = 'forge-access-inline-add';
                    inlineAdd.textContent = '+ Add';
                    inlineAdd.addEventListener('mousedown', function (e) {
                        e.preventDefault();
                        users.push({ id: u.id, name: u.name, perms: emptyPerms() });
                        renderUsers();
                        var term = searchInput.value;
                        renderUserSelect();
                        searchInput.value = term;
                        showDropdown(term);
                        searchInput.focus();
                    });
                    item.appendChild(inlineAdd);

                    dropdown.appendChild(item);
                });
                positionDropdown();
                dropdown.hidden = false;
            }

            searchInput.addEventListener('input', function () {
                showDropdown(this.value);
            });

            searchInput.addEventListener('focus', function () {
                if (availableUsers().length) showDropdown(this.value);
            });

            searchInput.addEventListener('blur', function () {
                dropdown.hidden = true;
            });

            saveBtn.addEventListener('click', function () {
                saveBtn.disabled = true;
                errorEl.style.display = 'none';
                var rolesData = {}, usersData = {};
                Object.keys(roleNames).forEach(function (slug) {
                    if (slug === 'administrator') return;
                    rolesData[slug] = {};
                    CAPS.forEach(function (cap) {
                        rolesData[slug][cap[0]] = (roles[slug] && roles[slug][cap[0]]) ? '1' : '0';
                    });
                });
                users.forEach(function (u) {
                    usersData[u.id] = {};
                    CAPS.forEach(function (cap) {
                        usersData[u.id][cap[0]] = (u.perms && u.perms[cap[0]]) ? '1' : '0';
                    });
                });
                jQuery.post(ajaxurl, {
                    action: 'forge_save_access_settings',
                    nonce: window._forgeAccessNonce,
                    roles: rolesData,
                    users: usersData
                }, function (resp) {
                    saveBtn.disabled = false;
                    if (resp.success) {
                        window._forgeAccessData.roles = roles;
                        window._forgeAccessData.user_overrides = users.map(function (u) {
                            return { id: u.id, name: u.name, perms: u.perms || {} };
                        });
                        closeModal();
                    } else {
                        showError('Error saving.');
                    }
                });
            });

            function showError(msg) {
                loading.style.display = 'none';
                content.style.display = '';
                errorEl.textContent = msg;
                errorEl.style.display = '';
            }
        }());

        /* ---- AJAX save for #forge-settings-form (no page reload) ---- */
        (function(){
            var form = document.getElementById('forge-settings-form');
            if (!form) return;
            function showNotice(msg, isError) {
                var existing = document.querySelector('.forge-settings-notice');
                if (existing) existing.remove();
                var n = document.createElement('div');
                n.className = 'forge-settings-notice forge-settings-notice--'
                    + (isError ? 'error' : 'success');
                n.innerHTML = '<i class="fa-solid fa-'
                    + (isError ? 'circle-xmark' : 'circle-check') + '"></i> ' + msg;
                form.parentNode.insertBefore(n, form);
                n.scrollIntoView({behavior:'smooth', block:'nearest'});
                if (!isError) {
                    setTimeout(function() {
                        n.style.transition = 'opacity .4s';
                        n.style.opacity = '0';
                        setTimeout(function() { n.remove(); }, 420);
                    }, 3000);
                }
            }
            form.addEventListener('submit', function(e){
                e.preventDefault();
                var btn = form.querySelector('button[type="submit"]');
                var origHtml = btn ? btn.innerHTML : '';
                if (btn) { btn.disabled = true; btn.innerHTML = '<span class="forge-spinner"></span> Saving…'; }
                var fd = new FormData(form);
                fd.set('action', 'forge_save_general_settings');
                requestAnimationFrame(function(){ requestAnimationFrame(function(){
                fetch(ajaxurl, {method:'POST', body:fd})
                    .then(function(r){ return r.json(); })
                    .then(function(data){
                        if (btn) { btn.disabled = false; btn.innerHTML = origHtml; }
                        if (data.success) {
                            showNotice(data.data.message, false);
                        } else {
                            showNotice((data.data && data.data.message) || 'Error saving.', true);
                        }
                    })
                    .catch(function(){
                        if (btn) { btn.disabled = false; btn.innerHTML = origHtml; }
                        showNotice('Network error.', true);
                    });
                }); }); // requestAnimationFrame double-frame
            });
        }());
        </script>
        <?php
    }

    /**
     * AJAX handler that saves general plugin settings.
     *
     * @return void
     */
    public static function handleSaveGeneralSettings(): void
    {
        if (!\ForgeForms\Plugin::userCan('settings') || !current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Forbidden'], 403);
        }
        check_ajax_referer('forge_forms_settings', 'forge_settings_nonce');
        self::saveGeneralSettings();
        wp_send_json_success(['message' => __('Settings saved.', 'form-forge')]);
    }

    /**
     * Saves general settings (email, reCAPTCHA keys, colors) from POST.
     *
     * @return void
     */
    private static function saveGeneralSettings(): void
    {
        $from_email_input = sanitize_email(\ForgeForms\Utils\Sanitize::str(wp_unslash($_POST['forge_cfg_a'] ?? '')));
        if ($from_email_input !== '') {
            update_option('forge_forms_from_email', $from_email_input);
        }
        $from_name_input = sanitize_text_field(\ForgeForms\Utils\Sanitize::str(wp_unslash($_POST['forge_cfg_b'] ?? '')));
        if ($from_name_input !== '') {
            update_option('forge_forms_from_name', $from_name_input);
        }
        // reCAPTCHA keys are hidden from non-full-admins in the UI (the
        // Security card only renders for $is_full_admin) — enforce the same
        // boundary server-side so a user with only the plugin's 'settings'
        // capability can't set them via a raw POST to this handler.
        if (current_user_can('manage_options')) {
            $site_key = sanitize_text_field(\ForgeForms\Utils\Sanitize::str(wp_unslash($_POST['recaptcha_site'] ?? '')));
            update_option('forge_forms_recaptcha_site_key', $site_key);
            $secret_key = sanitize_text_field(\ForgeForms\Utils\Sanitize::str(wp_unslash($_POST['recaptcha_secret'] ?? '')));
            update_option('forge_forms_recaptcha_secret_key', $secret_key);
        }

        $hover        = sanitize_hex_color(\ForgeForms\Utils\Sanitize::str(wp_unslash($_POST['hover_color']    ?? ''))) ?? '#1d2327';
        $accent       = sanitize_hex_color(\ForgeForms\Utils\Sanitize::str(wp_unslash($_POST['accent_color']   ?? ''))) ?? '#f59e0b';
        $border       = sanitize_hex_color(\ForgeForms\Utils\Sanitize::str(wp_unslash($_POST['border_color']   ?? ''))) ?? '#c9cdd4';
        $admin_accent = sanitize_hex_color(\ForgeForms\Utils\Sanitize::str(wp_unslash($_POST['admin_accent']   ?? ''))) ?? '#2271b1';
        update_option('forge_forms_hover_color', $hover);
        update_option('forge_forms_accent_color', $accent);
        update_option('forge_forms_border_color', $border);
        update_option('forge_forms_admin_accent', $admin_accent);

        $layout_mode = \ForgeForms\Utils\Sanitize::str(wp_unslash($_POST['field_layout_mode'] ?? 'block'), 'block');
        update_option(
            'forge_forms_field_layout',
            $layout_mode === 'inline' ? 'inline' : 'block'
        );
    }

    /**
     * AJAX handler that resets all plugin settings (and optionally forms).
     *
     * @return void
     */
    public static function handleFactoryReset(): void
    {
        $allowed = \ForgeForms\Plugin::userCan('settings')
            && current_user_can('manage_options');
        if (!$allowed) {
            wp_send_json_error(['message' => 'Forbidden'], 403);
        }
        check_ajax_referer('forge_factory_reset', 'nonce');

        $options = [
            'forge_forms_from_email',
            'forge_forms_from_name',
            'forge_forms_recaptcha_site_key',
            'forge_forms_recaptcha_secret_key',
            'forge_forms_hover_color',
            'forge_forms_accent_color',
            'forge_forms_border_color',
            'forge_forms_admin_accent',
            'forge_forms_pdf_settings',
            'forge_forms_pdf_layout',
            'forge_forms_field_layout',
            'forge_forms_access',
        ];
        foreach ($options as $opt) {
            delete_option($opt);
        }

        if (!empty($_POST['del_forms']) && $_POST['del_forms'] === '1') {
            $ids = get_posts(
                [
                'post_type'      => 'forge_form',
                'posts_per_page' => -1,
                'fields'         => 'ids',
                ]
            );
            foreach ($ids as $id) {
                wp_delete_post((int)$id, true);
            }
            delete_option('forge_form_selects');
        }

        wp_send_json_success();
    }

    /**
     * AJAX handler to save PDF attachment settings per notification.
     *
     * @return void
     */
    public static function savePdfSettings(): void
    {
        if (!\ForgeForms\Plugin::userCan('settings') || !current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Forbidden'], 403);
        }
        check_ajax_referer('forge_forms_admin_nonce', 'nonce');

        $raw = json_decode(\ForgeForms\Utils\Sanitize::str(wp_unslash($_POST['settings'] ?? '')), true);
        if (!is_array($raw)) {
            wp_send_json_error(['message' => 'Invalid data'], 400);
        }

        $saved = get_option('forge_forms_pdf_settings', []);
        if (!is_array($saved)) {
            $saved = [];
        }

        // Key format "form_id|template_slug" mirrors the lookup key shouldAttachPdf() builds
        // when deciding whether to attach a PDF for a given submission
        foreach ($raw as $key => $value) {
            if (preg_match('/^\d+\|[\w-]+$/', $key)) {
                $saved[$key] = ((int)$value === 1) ? 1 : 0;
            }
        }

        update_option('forge_forms_pdf_settings', $saved);
        wp_send_json_success(['saved' => $raw]);
    }

    /**
     * AJAX handler to rotate the PDF seal key with a password.
     *
     * @return void
     */
    public static function handleRotateKey(): void
    {
        $allowed = \ForgeForms\Plugin::userCan('settings')
            && current_user_can('manage_options');
        if (!$allowed) {
            wp_send_json_error(['message' => 'Forbidden'], 403);
        }
        check_ajax_referer('forge_rotate_key', 'nonce');

        $password = sanitize_text_field(wp_unslash($_POST['key_password']         ?? ''));
        $confirm  = sanitize_text_field(wp_unslash($_POST['key_password_confirm'] ?? ''));
        $compromised = !empty($_POST['key_compromised']) && $_POST['key_compromised'] === '1';

        if ($password === '' || $password !== $confirm) {
            wp_send_json_error(['message' => __('Passwords do not match.', 'form-forge')]);
            return;
        }

        $errors = \ForgeForms\PDF\HashSeal::validatePassword($password);
        if (!empty($errors)) {
            wp_send_json_error(['message' => implode(' ', $errors)]);
            return;
        }

        $new_key = \ForgeForms\PDF\HashSeal::rotateKey($password, $compromised);
        wp_send_json_success(
            [
            'message'    => __('Key rotated successfully.', 'form-forge'),
            'key_uuid'   => $new_key['uuid'],
            'key_value'  => $new_key['key'],
            'created_at' => $new_key['created_at'],
            ]
        );
    }

    /**
     * AJAX handler to finalize setup using default (unencrypted) key storage.
     *
     * @return void
     */
    public static function handleSetupKeepDefault(): void
    {
        if (!\ForgeForms\Plugin::userCan('settings') || !current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Forbidden'], 403);
        }
        check_ajax_referer('forge_seal_setup', 'nonce');
        update_option('forge_forms_seal_encryption', 'disabled', false);
        update_option('forge_forms_seal_setup_done', true, false);
        \ForgeForms\PDF\HashSeal::getCurrentKeyId(); // ensure initial key exists
        $dl = \ForgeForms\PDF\HashSeal::claimPendingDownload();
        if (!$dl) {
            wp_send_json_error(['message' => __('No pending key download found.', 'form-forge')]);
            return;
        }
        wp_send_json_success($dl);
    }

    /**
     * AJAX handler that generates and returns the master key define line for wp-config.php.
     *
     * @return void
     */
    public static function handleSetupGetMasterKey(): void
    {
        if (!\ForgeForms\Plugin::userCan('settings') || !current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Forbidden'], 403);
        }
        check_ajax_referer('forge_seal_setup', 'nonce');
        // Commit the encryption choice immediately — the user cannot go back to Standard.
        update_option('forge_forms_seal_encryption', 'enabled', false);
        $master_hex = bin2hex(random_bytes(32));
        set_transient('forge_setup_master_key_' . get_current_user_id(), $master_hex, 600);
        // Invalidate OPcache so the next request (confirm) re-reads wp-config.php from disk.
        if (function_exists('opcache_invalidate')) {
            opcache_invalidate(\ABSPATH . 'wp-config.php', true);
        }
        wp_send_json_success(
            [
            'define_line' => "define('FORGE_SEAL_MASTER_KEY', '" . $master_hex . "');",
            ]
        );
    }

    /**
     * AJAX handler that resets the encryption mode choice during setup.
     *
     * @return void
     */
    public static function handleSetupResetChoice(): void
    {
        if (!\ForgeForms\Plugin::userCan('settings') || !current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Forbidden'], 403);
        }
        check_ajax_referer('forge_seal_setup', 'nonce');
        delete_option('forge_forms_seal_encryption');
        delete_transient('forge_setup_master_key_' . get_current_user_id());
        wp_send_json_success();
    }

    /**
     * AJAX handler that checks the master key and completes encrypted setup.
     *
     * @return void
     */
    public static function handleSetupConfirmSecure(): void
    {
        if (!\ForgeForms\Plugin::userCan('settings') || !current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Forbidden'], 403);
        }
        check_ajax_referer('forge_seal_setup', 'nonce');

        if (!defined('FORGE_SEAL_MASTER_KEY') || (string) FORGE_SEAL_MASTER_KEY === '') {
            wp_send_json_error(
                [
                'message' => __('FORGE_SEAL_MASTER_KEY not found. Please check wp-config.php and try again.', 'form-forge'), // phpcs:ignore Generic.Files.LineLength
                ]
            );
            return;
        }

        // A missing/expired transient must be rejected, not silently skipped — otherwise
        // this check would accept whatever FORGE_SEAL_MASTER_KEY currently is instead of
        // requiring it to match the value this plugin itself issued during setup.
        $expected = get_transient('forge_setup_master_key_' . get_current_user_id());
        if (!$expected) {
            wp_send_json_error(
                [
                'message' => __('Setup session expired. Please start again.', 'form-forge'),
                ]
            );
            return;
        }
        if (!hash_equals($expected, strtolower((string) FORGE_SEAL_MASTER_KEY))) {
            wp_send_json_error(
                [
                'message' => __('The entered master key does not match the expected value. Please check wp-config.php.', 'form-forge'), // phpcs:ignore Generic.Files.LineLength
                ]
            );
            return;
        }

        $was_setup_done = (bool) get_option('forge_forms_seal_setup_done');

        delete_transient('forge_setup_master_key_' . get_current_user_id());
        update_option('forge_forms_seal_encryption', 'enabled', false);
        update_option('forge_forms_seal_setup_done', true, false);

        // Encrypt any keys that were generated before setup completed.
        \ForgeForms\PDF\HashSeal::encryptExistingKeys();

        // Ensure active key exists (may have been generated during encryption pass).
        \ForgeForms\PDF\HashSeal::getCurrentKeyId();
        $dl = \ForgeForms\PDF\HashSeal::claimPendingDownload();

        if (!$dl) {
            if ($was_setup_done) {
                // Upgrade path: existing key encrypted in-place, no new key download needed.
                wp_send_json_success(['upgrade' => true]);
                return;
            }
            wp_send_json_error(
                [
                'message' => __('Setup complete, but no download entry found. Please rotate the key to generate a download.', 'form-forge'), // phpcs:ignore Generic.Files.LineLength
                ]
            );
            return;
        }
        wp_send_json_success($dl);
    }

    /**
     * AJAX handler to import a legacy PDF seal key from JSON.
     *
     * @return void
     */
    public static function handleAddLegacyKey(): void
    {
        $allowed = \ForgeForms\Plugin::userCan('settings')
            && current_user_can('manage_options');
        if (!$allowed) {
            wp_send_json_error(['message' => 'Forbidden'], 403);
        }
        check_ajax_referer('forge_add_legacy_key', 'nonce');

        $raw = sanitize_textarea_field(wp_unslash($_POST['key_json'] ?? ''));
        if ($raw === '') {
            wp_send_json_error(['message' => __('No content pasted.', 'form-forge')]);
            return;
        }

        $parsed = json_decode($raw, true);
        if (!is_array($parsed)) {
            wp_send_json_error(['message' => __('Invalid JSON format.', 'form-forge')]);
            return;
        }

        $uuid = sanitize_text_field(\ForgeForms\Utils\Sanitize::str($parsed['uuid'] ?? null));
        $key  = sanitize_text_field(\ForgeForms\Utils\Sanitize::str($parsed['key']  ?? null));

        if ($uuid === '' || $key === '') {
            wp_send_json_error(['message' => __('Missing required fields: uuid and key.', 'form-forge')]);
            return;
        }
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $uuid)) {
            wp_send_json_error(['message' => __('UUID has invalid format.', 'form-forge')]);
            return;
        }
        if (!preg_match('/^[0-9a-f]{64}$/i', $key)) {
            wp_send_json_error(['message' => __('Key value must be exactly 64 hex characters.', 'form-forge')]); // phpcs:ignore Generic.Files.LineLength
            return;
        }

        // Guard: hard-reject only when the exact same payload already exists.
        $incoming_created = sanitize_text_field(\ForgeForms\Utils\Sanitize::str($parsed['created_at'] ?? null));

        $active_raw = get_option('forge_forms_seal_key');
        if ($active_raw) {
            $active_rec = json_decode((string) $active_raw, true);
            $active_dup = is_array($active_rec)
                && ($active_rec['uuid'] ?? '') === $uuid
                && ($active_rec['key']  ?? '') === $key;
            if ($active_dup) {
                wp_send_json_error(['message' => __('This key already exists as the active key.', 'form-forge')]); // phpcs:ignore Generic.Files.LineLength
                return;
            }
        }

        foreach (\ForgeForms\PDF\HashSeal::getHistory() as $entry) {
            $history_dup = ($entry['uuid']       ?? '') === $uuid
                && ($entry['key']       ?? '') === $key
                && ($entry['retired_at'] ?? '') === $incoming_created;
            if ($history_dup) {
                wp_send_json_error(
                    [
                    'message' => __('This key already exists in the history (identical entry).', 'form-forge'), // phpcs:ignore Generic.Files.LineLength
                    ]
                );
                return;
            }
        }

        // Warn if the JSON plugin field doesn't match this plugin.
        $plugin_field    = isset($parsed['plugin']) ? (string) $parsed['plugin'] : '';
        $expected_plugin = 'FormForge PDF Seal Key';
        $confirm_mismatch = (bool) (sanitize_text_field(wp_unslash($_POST['confirm_mismatch'] ?? '')) === '1');
        if ($plugin_field !== $expected_plugin && !$confirm_mismatch) {
            wp_send_json_error(
                [
                'code'    => 'plugin_mismatch',
                'message' => __('Plugin name does not match.', 'form-forge'),
                'text'    => sprintf(
                    /* translators: %s: the plugin field value from the uploaded JSON */
                    __('The "plugin" field reads "%s" — this does not appear to be a key from this plugin.', 'form-forge'), // phpcs:ignore Generic.Files.LineLength
                    esc_html($plugin_field)
                ),
                'confirm' => __('Are you sure you want to import this key?', 'form-forge'), // phpcs:ignore Generic.Files.LineLength
                ]
            );
            return;
        }

        $raw_status = sanitize_text_field(wp_unslash($_POST['key_status'] ?? ''));
        $allowed    = ['rotated-legacy', 'compromised-legacy'];
        $key_status = in_array($raw_status, $allowed, true) ? $raw_status : 'rotated-legacy';

        \ForgeForms\PDF\HashSeal::addLegacyKey(
            $uuid,
            $key,
            $incoming_created,
            $key_status
        );
        wp_send_json_success(['message' => __('Legacy key added successfully.', 'form-forge')]);
    }

    /**
     * Returns the list of FormForge capability slugs.
     *
     * @return array List of capability slug strings.
     */
    private static function accessCaps(): array
    {
        return [
            'view_forms',
            'edit_forms',
            'edit_pdf_layout',
            'use_verifier',
            'settings',
        ];
    }

    /**
     * Sanitizes a permissions array for a role or user.
     *
     * @param array $raw Raw permissions array from POST.
     *
     * @return array Sanitized permissions array.
     */
    private static function sanitizePerms(array $raw): array
    {
        $perms = [];
        foreach (self::accessCaps() as $cap) {
            $perms[$cap] = isset($raw[$cap]) && $raw[$cap] === '1';
        }
        return $perms;
    }

    /**
     * AJAX handler to save role and user access permissions.
     *
     * @return void
     */
    public static function handleSaveAccessSettings(): void
    {
        /* Grants/revokes capabilities for other users — must stay reserved
           for real WP admins, never just the broader "settings" cap. */
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Forbidden'], 403);
        }
        check_ajax_referer('forge_access_settings', 'nonce');

        global $wp_roles;
        $raw_roles = $_POST['roles'] ?? [];
        $roles = [];
        if (is_array($raw_roles)) {
            foreach ($raw_roles as $slug => $raw_perms) {
                $slug = sanitize_key($slug);
                if ($slug === 'administrator' || !array_key_exists($slug, $wp_roles->roles)) {
                    continue;
                }
                $roles[$slug] = self::sanitizePerms(
                    is_array($raw_perms) ? $raw_perms : []
                );
            }
        }

        $raw_users = $_POST['users'] ?? [];
        $users = [];
        if (is_array($raw_users)) {
            foreach ($raw_users as $uid => $raw_perms) {
                $uid = (int) $uid;
                if ($uid > 0 && get_userdata($uid)) {
                    $users[$uid] = self::sanitizePerms(
                        is_array($raw_perms) ? $raw_perms : []
                    );
                }
            }
        }

        update_option('forge_forms_access', ['roles' => $roles, 'users' => $users]);
        wp_send_json_success();
    }

    /**
     * Returns whether a PDF should be attached for the given form and notification.
     *
     * @param int    $form_id Form post ID.
     * @param string $slug    Notification slug.
     *
     * @return bool True if PDF should be attached.
     */
    public static function shouldAttachPdf(int $form_id, string $slug): bool
    {
        $saved = get_option('forge_forms_pdf_settings', []);
        return !empty($saved[$form_id . '|' . $slug]);
    }
}
