<?php

/**
 * Main plugin class that registers all hooks and bootstraps the plugin.
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

namespace ForgeForms;

defined('ABSPATH') || exit;

/**
 * Logs a debug message when WP_DEBUG is enabled.
 *
 * @param string $message The message to log.
 *
 * @return void
 */
function forge_log(string $message): void
{
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log($message);
    }
}

/**
 * Bootstraps the FormForge plugin: loads dependencies, registers CPT, and wires all hooks.
 */
class Plugin
{
    private static bool $initialized = false;

    /**
     * Bootstraps the plugin on first call; subsequent calls are no-ops.
     *
     * @return void
     */
    public static function init(): void
    {
        if (self::$initialized) {
            return;
        }
        self::$initialized = true;

        self::load();
        self::hooks();
    }

    /**
     * Requires all plugin PHP files; loads admin files when in admin context.
     *
     * @return void
     */
    private static function load(): void
    {
        $files = [
            'Fields/FieldRegistry.php',
            'Fields/BaseField.php',
            'Fields/TextField.php',
            'Fields/TextareaField.php',
            'Fields/EmailField.php',
            'Fields/NameField.php',
            'Fields/PhoneField.php',
            'Fields/NumberField.php',
            'Fields/AddressField.php',
            'Fields/DateField.php',
            'Fields/TimeField.php',
            'Fields/CurrencyField.php',
            'Fields/SelectField.php',
            'Fields/RadioField.php',
            'Fields/CheckboxField.php',
            'Fields/UploadField.php',
            'Fields/SignatureField.php',
            'Fields/RatingField.php',
            'Fields/SliderField.php',
            'Fields/CaptchaField.php',
            'Fields/ConsentField.php',
            'Fields/GdprField.php',
            'Fields/HtmlField.php',
            'Fields/GroupField.php',
            'Fields/PageBreakField.php',
            'Fields/PostDataField.php',
            'Fields/WebsiteField.php',
            'Fields/SepaField.php',
            'Form/FormModel.php',
            'Form/FormSelectModel.php',
            'Admin/FormSelectList.php',
            'Form/FormProcessor.php',
            'Form/FormRenderer.php',
            'PDF/HashSeal.php',
            'PDF/Generator.php',
            'Form/MailSender.php',
            'Utils/Assets.php',
        ];

        foreach ($files as $file) {
            include_once FORGE_FORMS_PATH . 'includes/' . $file;
        }

        if (is_admin()) {
            $adminFiles = [
                'Admin/FormList.php', 'Admin/FormEditor.php', 'Admin/FormSettings.php',
                'Admin/PDFLayoutEditor.php', 'Admin/Verificationpage.php',
            ];
            foreach ($adminFiles as $file) {
                include_once FORGE_FORMS_PATH . 'includes/' . $file;
            }
        }
    }

    /**
     * Registers all WordPress actions, filters, and shortcodes.
     *
     * @return void
     */
    private static function hooks(): void
    {
        /* Register CPT */
        add_action('init', [self::class, 'registerCpt']);

        /* Register field types */
        add_action('init', [Fields\FieldRegistry::class, 'registerDefaults']);

        /* Shortcodes */
        add_shortcode('forge_form', [Form\FormRenderer::class, 'shortcode']);
        add_shortcode('forge_form_select', [Admin\FormSelectList::class, 'shortcode']);

        /* AJAX form submission */
        add_action('wp_ajax_forge_forms_submit', [Form\FormProcessor::class, 'handle']);
        add_action('wp_ajax_nopriv_forge_forms_submit', [Form\FormProcessor::class, 'handle']);

        /* IBAN → BIC lookup (proxied through WP to avoid CORS) */
        add_action('wp_ajax_forge_iban_bic', [self::class, 'ajaxIbanBic']);
        add_action('wp_ajax_nopriv_forge_iban_bic', [self::class, 'ajaxIbanBic']);

        /* PDF mail hook */
        add_action(
            'forge_forms_submission',
            [Form\MailSender::class, 'onSubmission'],
            10,
            3
        );

        /* Assets */
        add_action('wp_enqueue_scripts', [Utils\Assets::class, 'enqueueFront']);

        if (is_admin()) {
            Admin\FormList::init();
            Admin\FormEditor::init();
            Admin\FormSelectList::init();
            Admin\FormSettings::init();
            Admin\PDFLayoutEditor::init();
            Admin\Verificationpage::register();
            add_action('admin_enqueue_scripts', [Utils\Assets::class, 'enqueueAdmin']);
            add_action('admin_init', [self::class, 'maybeSealSetupRedirect']);
            add_filter('plugin_action_links_' . FORGE_FORMS_BASENAME, [self::class, 'addDeleteWarningLink']);
        }
    }

    /**
     * AJAX handler that proxies IBAN/BIC lookup to openiban.com.
     *
     * @return void
     */
    public static function ajaxIbanBic(): void
    {
        $iban = preg_replace('/[^A-Z0-9]/', '', strtoupper(sanitize_text_field(wp_unslash($_POST['iban'] ?? ''))));
        if (strlen($iban) < 15) {
            wp_send_json_error();
            return;
        }

        $url      = 'https://openiban.com/validate/' . rawurlencode($iban) . '?getBIC=true&validateBankCode=true';
        $response = wp_remote_get($url, ['timeout' => 8]);

        if (is_wp_error($response)) {
            wp_send_json_error();
            return;
        }

        $body  = json_decode(wp_remote_retrieve_body($response), true);
        $valid = !empty($body['valid']);
        $bic   = $body['bankData']['bic'] ?? '';

        wp_send_json_success(
            [
            'valid'          => $valid,
            'bic'            => $valid ? sanitize_text_field($bic) : '',
            'bankCodeFound'  => !empty($body['checkResults']['bankCodeCheck']),
            ]
        );
    }

    /**
     * Registers the forge_form custom post type.
     *
     * @return void
     */
    public static function registerCpt(): void
    {
        register_post_type(
            'forge_form', [
            'label'               => 'Forms',
            'labels'              => [
                'name'          => 'FormForge',
                'singular_name' => 'Form',
                'add_new_item'  => 'Add New Form',
                'edit_item'     => 'Edit Form',
            ],
            'public'              => false,
            'show_ui'             => false,
            'show_in_menu'        => false,
            'show_in_rest'        => false,
            'supports'            => ['title'],
            'capability_type'     => 'post',
            'capabilities'        => ['create_posts' => 'manage_options'],
            'map_meta_cap'        => true,
            ]
        );
    }

    /**
     * Adds an inline JS confirmation to the plugin list delete link.
     *
     * @param string[] $links Plugin action links array.
     *
     * @return string[]
     */
    public static function addDeleteWarningLink(array $links): array
    {
        if (isset($links['delete'])) {
            $links['delete'] = preg_replace(
                '/(<a\s)/i',
                '$1onclick="return confirm('
                    . "'"
                    . 'ACHTUNG: Beim Löschen des Plugins werden alle PDF-Siegelschlüssel unwiderruflich gelöscht. '
                    . 'Stellen Sie sicher, dass Sie Ihre Schlüssel gesichert haben. Fortfahren?'
                    . "'"
                    . ');" ',
                (string) $links['delete']
            );
        }
        return $links;
    }

    /**
     * Checks if the given user has a specific FormForge capability.
     *
     * Valid caps: view_forms, edit_forms, edit_pdf_layout, use_verifier, settings.
     * Administrators always pass; user-specific override wins over role setting.
     *
     * @param string $cap     The capability slug to check.
     * @param int    $user_id User ID, or 0 for the current user.
     *
     * @return bool
     */
    public static function userCan(string $cap, int $user_id = 0): bool
    {
        $user_id = $user_id ?: get_current_user_id();
        if (!$user_id) {
            return false;
        }
        if (user_can($user_id, 'manage_options')) {
            return true;
        }
        $access = get_option('forge_forms_access', []);
        $user_overrides = $access['users'] ?? [];
        if (isset($user_overrides[$user_id]) && is_array($user_overrides[$user_id])) {
            return !empty($user_overrides[$user_id][$cap]);
        }
        $user = get_userdata($user_id);
        if (!$user) {
            return false;
        }
        $role_perms = $access['roles'] ?? [];
        foreach ($user->roles as $role) {
            if (!empty($role_perms[$role][$cap])) {
                return true;
            }
        }
        return false;
    }

    /**
     * Redirects admins to settings page until PDF seal setup is complete.
     *
     * @return void
     */
    public static function maybeSealSetupRedirect(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        if (get_option('forge_forms_seal_setup_done', false)) {
            return;
        }
        if (wp_doing_ajax() || $_SERVER['REQUEST_METHOD'] !== 'GET') {
            return;
        }
        $current_page = sanitize_text_field(wp_unslash($_GET['page'] ?? ''));
        // Only redirect within FormForge pages, not the whole WP admin.
        if (strncmp($current_page, 'forge-forms', 11) !== 0) {
            return;
        }
        if ($current_page === 'forge-forms-settings') {
            return;
        }
        wp_safe_redirect(admin_url('admin.php?page=forge-forms-settings'));
        exit;
    }
}
