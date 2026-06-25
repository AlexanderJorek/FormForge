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

namespace ForgeForms\Utils;

defined('ABSPATH') || exit;

class Assets
{
    public static function enqueueFront(): void
    {
        if (!self::pageHasForm()) {
            return;
        }

        \wp_enqueue_style(
            'forge-forms-front',
            FORGE_FORMS_URL . 'assets/css/front.css',
            [],
            FORGE_FORMS_VERSION
        );

        /* Only override CSS variables when the user has explicitly saved a value.
           If no option exists yet, front.css defaults apply (or the theme wins). */
        $accent = \get_option('forge_forms_accent_color', false);
        $border = \get_option('forge_forms_border_color', false);
        $vars   = [];
        if ($accent && preg_match('/^#[0-9a-fA-F]{6}$/', $accent)) {
            $vars[] = '--forge-accent: ' . $accent;
        }
        if ($border && preg_match('/^#[0-9a-fA-F]{6}$/', $border)) {
            $vars[] = '--forge-border-input: ' . $border;
        }
        if (!empty($vars)) {
            \wp_add_inline_style('forge-forms-front', ':root { ' . implode('; ', $vars) . '; }');
        }

        \wp_enqueue_script(
            'forge-forms-front',
            FORGE_FORMS_URL . 'assets/js/front.js',
            [],
            FORGE_FORMS_VERSION,
            true
        );

        \wp_localize_script('forge-forms-front', 'ForgeForms', [
            'ajaxUrl'    => \admin_url('admin-ajax.php'),
            'ibanBicUrl' => \admin_url('admin-ajax.php'),
            'i18n'       => [
                'submitting'   => 'Wird gesendet…',
                'error_server' => 'Serverfehler. Bitte versuchen Sie es erneut.',
            ],
        ]);

        /* Collect field-specific CSS from each field's getStyles().
         * Deduplicates by class name (first definition wins — same type won't
         * appear twice in the registry). Injected as a single inline style block. */
        $fieldCss = [];
        foreach (\ForgeForms\Fields\FieldRegistry::all() as $class) {
            $css = trim((new $class())->getStyles());
            if ($css !== '') {
                $fieldCss[] = $css;
            }
        }
        if (!empty($fieldCss)) {
            \wp_add_inline_style('forge-forms-front', implode("\n", $fieldCss));
        }

        /* Collect empty-check functions defined in each field's getClientEmptyCheck().
         * Keyed by field type (matching the forge-field--{type} CSS class). */
        $emptyChecks = [];
        foreach (\ForgeForms\Fields\FieldRegistry::all() as $type => $class) {
            $entry = (new $class())->getClientEmptyCheck();
            if (!empty($entry['fn'])) {
                $emptyChecks[] = json_encode($type) . ':' . trim($entry['fn']);
            }
        }
        if (!empty($emptyChecks)) {
            \wp_add_inline_script(
                'forge-forms-front',
                'window.ForgeEmptyChecks={' . implode(',', $emptyChecks) . '};',
                'before'
            );
        }

        /* Collect client validators defined in each field's getClientValidation().
         * Deduplicates by rule name (first definition wins). Outputs an inline
         * script before front.js that populates window.ForgeValidators so the
         * validation loop in front.js needs zero knowledge of specific field types. */
        $pairs    = [];
        $seen     = [];
        foreach (\ForgeForms\Fields\FieldRegistry::all() as $class) {
            $handler = new $class();
            foreach ($handler->getClientValidation() as $entry) {
                $rule = $entry['rule'] ?? '';
                $fn   = $entry['fn']   ?? '';
                if ($rule === '' || $fn === '' || isset($seen[$rule])) {
                    continue;
                }
                $seen[$rule] = true;
                $pairs[]     = json_encode($rule) . ':' . trim($fn);
            }
        }
        if (!empty($pairs)) {
            \wp_add_inline_script(
                'forge-forms-front',
                'window.ForgeValidators={' . implode(',', $pairs) . '};',
                'before'
            );
        }

        /* Collect per-field-type init functions from getClientInit().
         * Exposed as window.ForgeFieldInits keyed by field type.
         * front.js iterates and calls each with the root element. */
        $inits = [];
        foreach (\ForgeForms\Fields\FieldRegistry::all() as $type => $class) {
            $fn = (new $class())->getClientInit();
            if ($fn !== '') {
                $inits[] = json_encode($type) . ':' . trim($fn);
            }
        }
        if (!empty($inits)) {
            \wp_add_inline_script(
                'forge-forms-front',
                'window.ForgeFieldInits={' . implode(',', $inits) . '};',
                'before'
            );
        }

        /* Form-select shortcode assets — must be enqueued before wp_head() */
        if (self::pageHasFormSelect()) {
            \wp_enqueue_style(
                'forge-form-select',
                FORGE_FORMS_URL . 'assets/css/form-select.css',
                [],
                FORGE_FORMS_VERSION
            );
            \wp_enqueue_script(
                'forge-form-select',
                FORGE_FORMS_URL . 'assets/js/form-select.js',
                [],
                FORGE_FORMS_VERSION,
                true
            );
        }

        /* Collect field types that opt out of client-side validation entirely. */
        $skip = [];
        foreach (\ForgeForms\Fields\FieldRegistry::all() as $type => $class) {
            if ((new $class())->skipValidation()) {
                $skip[] = json_encode($type);
            }
        }
        if (!empty($skip)) {
            \wp_add_inline_script(
                'forge-forms-front',
                'window.ForgeSkipValidation=[' . implode(',', $skip) . '];',
                'before'
            );
        }
    }

    public static function enqueueAdmin(string $hook): void
    {
        /* Form editor page */
        if (str_contains($hook, 'forge-forms-editor')) {
            \wp_enqueue_style(
                'font-awesome',
                'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css',
                [],
                '6.5.2'
            );

            \wp_enqueue_style(
                'forge-forms-admin',
                FORGE_FORMS_URL . 'assets/css/admin.css',
                ['font-awesome'],
                filemtime(FORGE_FORMS_PATH . 'assets/css/admin.css')
            );

            \wp_enqueue_style('wp-color-picker');
            \wp_enqueue_script('wp-color-picker');

            \wp_enqueue_script(
                'forge-forms-builder',
                FORGE_FORMS_URL . 'assets/js/admin-builder.js',
                [],
                FORGE_FORMS_VERSION,
                true
            );

            \wp_enqueue_media();
        }

        /* General admin pages */
        if (str_contains($hook, 'forge-forms')) {
            \wp_enqueue_style(
                'font-awesome',
                'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css',
                [],
                '6.5.2'
            );
            \wp_enqueue_style(
                'forge-forms-admin',
                FORGE_FORMS_URL . 'assets/css/admin.css',
                ['font-awesome'],
                filemtime(FORGE_FORMS_PATH . 'assets/css/admin.css')
            );
            $hover = \get_option('forge_forms_hover_color', '#1d2327');
            if (!preg_match('/^#[0-9a-fA-F]{6}$/', $hover)) {
                $hover = '#1d2327';
            }
            \wp_add_inline_style('forge-forms-admin', ':root { --forge-hover-color: ' . $hover . '; }');
            if (str_contains($hook, 'forge-forms-settings')) {
                \wp_enqueue_style('wp-color-picker');
                \wp_enqueue_script('wp-color-picker');
            }
        }

        /* Verification page */
        if (str_contains($hook, 'forge-pdf-verification')) {
            \wp_enqueue_style(
                'forge-forms-admin',
                FORGE_FORMS_URL . 'assets/css/admin.css',
                [],
                filemtime(FORGE_FORMS_PATH . 'assets/css/admin.css')
            );
            \wp_enqueue_script(
                'forge-pdfjs',
                FORGE_FORMS_URL . 'assets/vendor/pdfjs/pdf.js',
                [],
                FORGE_FORMS_VERSION,
                true
            );
            \wp_enqueue_script(
                'forge-forms-verification',
                FORGE_FORMS_URL . 'assets/js/verification.js',
                ['forge-pdfjs'],
                FORGE_FORMS_VERSION,
                true
            );
            \wp_localize_script('forge-forms-verification', 'ForgeVerifier', [
                'ajaxUrl'     => \admin_url('admin-ajax.php'),
                'nonce'       => \wp_create_nonce('forge_verifier_nonce'),
                'pdfJsWorker' => FORGE_FORMS_URL . 'assets/vendor/pdfjs/pdf.worker.js',
            ]);
        }
    }

    private static function pageHasForm(): bool
    {
        global $post;
        if (!$post || !\is_a($post, 'WP_Post')) {
            return true;
        }
        return str_contains((string)$post->post_content, '[forge_form');
    }

    private static function pageHasFormSelect(): bool
    {
        global $post;
        if (!$post || !\is_a($post, 'WP_Post')) {
            return false;
        }
        return str_contains((string)$post->post_content, '[forge_form_select');
    }
}
