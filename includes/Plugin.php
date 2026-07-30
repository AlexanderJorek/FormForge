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
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GPL-3.0-or-later
 * @version   1.0.0
 * @link      https://github.com/AlexanderJorek/FormForge
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 3
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
            'PDF/PdfUtils.php',
            'PDF/PdfDescriptor.php',
            'PDF/Generator.php',
            'Form/MailSender.php',
            'Utils/Assets.php',
            'Utils/ClientIp.php',
            'Utils/Sanitize.php',
        ];

        foreach ($files as $file) {
            // phpcs:ignore PHPCS_SecurityAudit.Misc.IncludeMismatch.ErrMiscIncludeMismatchNoExt -- $file is drawn from the hardcoded $files array above (every entry already ends in .php); nothing here is attacker- or request-influenced.
            include_once FORGE_FORMS_PATH . 'includes/' . $file;
        }

        if (is_admin()) {
            $adminFiles = [
                'Admin/FormList.php', 'Admin/FormEditor.php', 'Admin/FormSettings.php',
                'Admin/PDFLayoutEditor.php', 'Admin/Verificationpage.php',
            ];
            // Dev-only test harness — never loaded (or registered as a menu page) on
            // a production site where WP_DEBUG is off
            if (defined('WP_DEBUG') && WP_DEBUG) {
                $adminFiles[] = 'Admin/FieldTestPage.php';
            }
            foreach ($adminFiles as $file) {
                // phpcs:ignore PHPCS_SecurityAudit.Misc.IncludeMismatch.ErrMiscIncludeMismatchNoExt -- $file is drawn from the hardcoded $adminFiles array above (every entry already ends in .php); nothing here is attacker- or request-influenced.
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
        add_filter('map_meta_cap', [self::class, 'mapCreateFormCap'], 10, 2);

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
        Form\MailSender::init();
        add_action(
            'forge_forms_submission',
            [Form\MailSender::class, 'onSubmission'],
            10,
            3
        );

        /* Fallback sweep for temp PDFs the Generator creates — safety net for
           when a request dies before its own SL_*.pdf/Entry_*.pdf cleanup runs.
           Registered unconditionally (not inside is_admin()) since generation
           happens on public form submissions and wp-cron.php requests aren't
           admin requests either. */
        add_action('forge_generator_sweep_tmp_dirs', [PDF\Generator::class, 'cronSweepTmpDirs']);
        if (!wp_next_scheduled('forge_generator_sweep_tmp_dirs')) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'hourly', 'forge_generator_sweep_tmp_dirs');
        }

        /* Remove deleted forms from all FormSelect lists */
        add_action('before_delete_post', [Form\FormSelectModel::class, 'removeFormId'], 10, 1);

        /* Assets */
        add_action('wp_enqueue_scripts', [Utils\Assets::class, 'enqueueFront']);

        if (is_admin()) {
            Admin\FormList::init();
            Admin\FormEditor::init();
            Admin\FormSelectList::init();
            Admin\FormSettings::init();
            Admin\PDFLayoutEditor::init();
            Admin\Verificationpage::register();
            if (defined('WP_DEBUG') && WP_DEBUG) {
                Admin\FieldTestPage::register();
            }
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
        // Proxied through this WP endpoint (rather than calling openiban.com from the
        // browser) to avoid CORS issues and to rate-limit our own usage of their API.
        // See includes/Utils/ClientIp.php for the trusted-proxy-aware IP resolution.
        $ip  = Utils\ClientIp::resolve();
        $key = 'iban_' . md5($ip);
        if (Utils\RateLimiter::increment($key, MINUTE_IN_SECONDS) > 20) {
            wp_send_json_error();
            return;
        }

        // 15 is the shortest valid IBAN length (Norway); the openiban.com call below
        // rejects anything malformed regardless, this is just an early cheap reject
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- anonymous read-only IBAN/BIC lookup proxied to openiban.com, rate-limited by IP above; no persisted state or form submission side effect.
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
            'forge_form',
            [
            'label'               => __('Forms', 'form-forge'),
            'labels'              => [
                'name'          => __('FormForge', 'form-forge'),
                'singular_name' => __('Form', 'form-forge'),
                'add_new_item'  => __('Add New Form', 'form-forge'),
                'edit_item'     => __('Edit Form', 'form-forge'),
            ],
            // public/show_ui/show_in_menu/show_in_rest are all false because forms are
            // managed entirely through this plugin's own custom admin UI (Admin\FormEditor,
            // Admin\FormList), not WordPress's default post-editing screens
            'public'              => false,
            'show_ui'             => false,
            'show_in_menu'        => false,
            'show_in_rest'        => false,
            'supports'            => ['title'],
            'capability_type'     => 'post',
            // create_posts uses a custom cap so it can be granted to users with the
            // plugin's own 'edit_forms' permission, not just WP admins (see mapCreateFormCap())
            'capabilities'        => ['create_posts' => 'create_forge_forms'],
            'map_meta_cap'        => true,
            ]
        );
    }

    /**
     * Grants the create_forge_forms capability to users with the plugin's own
     * edit_forms permission (Plugin::userCan() already lets admins through).
     *
     * @param string[] $caps    Required primitive capabilities.
     * @param string   $cap     Requested meta capability.
     *
     * @return string[]
     */
    public static function mapCreateFormCap(array $caps, string $cap): array
    {
        if ($cap === 'create_forge_forms') {
            return self::userCan('edit_forms') ? ['exist'] : ['do_not_allow'];
        }
        return $caps;
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
                '$1onclick="return confirm(\''
                    . esc_js(__('WARNING: Deleting the plugin will permanently delete all PDF seal keys. Make sure you have backed up your keys. Continue?', 'form-forge'))
                    . '\');" ',
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
        static $access = null;
        if ($access === null) {
            $access = get_option('forge_forms_access', []);
        }
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
        if (wp_doing_ajax() || !isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'GET') {
            return;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing decision (which admin page to redirect to); gated by manage_options above, no data written.
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
