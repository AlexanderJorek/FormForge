<?php

/**
 * Plugin Name:       FormForge
 * Plugin URI:        https://github.com/AlexanderJorek/FormForge
 * Description:       Custom drag-and-drop form builder with PDF generation and email delivery.
 * Version:           1.0.0
 * Requires at least: 7.0.0
 * Requires PHP:      8.1
 * Author:            Alexander Jorek
 * Author URI:        https://github.com/AlexanderJorek
 * License:           GPL-3.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       form-forge
 */

defined('ABSPATH') || exit;

define('FORGE_FORMS_PATH', plugin_dir_path(__FILE__));
define('FORGE_FORMS_URL', plugin_dir_url(__FILE__));
define('FORGE_FORMS_VERSION', '1.0.0');
define('FORGE_FORMS_BASENAME', plugin_basename(__FILE__));

$composer_autoload = FORGE_FORMS_PATH . 'vendor/autoload.php';
if (file_exists($composer_autoload)) {
    include_once $composer_autoload;
} else {
    add_action(
        'admin_notices',
        static function (): void {
            echo '<div class="notice notice-error"><p>'
            . '<strong>FormForge:</strong> '
            . esc_html__('Composer autoloader not found. Run <code>composer install</code> in the plugin directory.', 'form-forge')
            . '</p></div>';
        }
    );
}

// Plugin.php is loaded explicitly (not via the Composer PSR-4 autoloader, which only
// covers vendor/ dependencies) since it's the class that wires up autoloading for the
// rest of includes/ and must exist before anything else in this plugin can run.
require_once FORGE_FORMS_PATH . 'includes/Plugin.php';

// Deferred to plugins_loaded so translations/other plugins are ready before hooks register
add_action(
    'plugins_loaded',
    static function (): void {
        \ForgeForms\Plugin::init();
    }
);

// Prevent orphaned recurring cron events (the PDF-verifier and PDF-generator
// temp-file sweeps) from continuing to fire after the plugin is deactivated.
register_deactivation_hook(
    __FILE__,
    static function (): void {
        wp_clear_scheduled_hook('forge_verifier_sweep_tmp_dirs');
        wp_clear_scheduled_hook('forge_generator_sweep_tmp_dirs');
    }
);
