<?php

/**
 * Plugin entry point and WordPress plugin header.
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

/**
 * Plugin Name: FormForge
 * Description: Lightweight form builder with tamper-evident PDF generation.
 * Version:     1.0.0
 * Author:      Alexander Jorek
 * License:     GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: forge-forms
 * Requires PHP: 8.1
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
        'admin_notices', static function (): void {
            echo '<div class="notice notice-error"><p>'
            . '<strong>FormForge:</strong> Composer autoloader not found. '
            . 'Run <code>composer install</code> in the plugin directory.'
            . '</p></div>';
        }
    );
}

require_once FORGE_FORMS_PATH . 'includes/Plugin.php';

add_action(
    'plugins_loaded', static function (): void {
        \ForgeForms\Plugin::init();
    }
);
