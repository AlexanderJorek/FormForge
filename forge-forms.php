<?php

/**
 * Plugin Name:       FormForge
 * Plugin URI:        https://github.com/AlexanderJorek/FormForge
 * Description:       Custom drag-and-drop form builder with PDF generation and email delivery.
 * Version:           1.0.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Alexander Jorek
 * Author URI:        https://github.com/AlexanderJorek
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
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
            . __('Composer autoloader not found. Run <code>composer install</code> in the plugin directory.', 'form-forge')
            . '</p></div>';
        }
    );
}

require_once FORGE_FORMS_PATH . 'includes/Plugin.php';

add_action(
    'plugins_loaded',
    static function (): void {
        \ForgeForms\Plugin::init();
    }
);
