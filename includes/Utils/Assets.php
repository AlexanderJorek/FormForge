<?php

/**
 * Enqueues and manages front-end CSS and JS assets.
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

namespace ForgeForms\Utils;

defined('ABSPATH') || exit;

/**
 * Enqueues front-end and admin CSS/JS assets for FormForge.
 */
class Assets
{
    // Subresource Integrity hash for the Font Awesome CDN stylesheet below, pinned to
    // its exact version so a compromised/MITM'd CDN response is rejected by the browser
    // instead of silently executing. Recompute if FONT_AWESOME_VERSION is ever bumped:
    // curl -s <url> | openssl dgst -sha512 -binary | openssl base64 -A
    // Public so other admin pages that also load Font Awesome directly (e.g.
    // FormEditor::ajaxPreview()) can reference the same version/hash instead
    // of keeping their own copy that could silently drift out of sync.
    public const FONT_AWESOME_VERSION = '6.5.2';
    public const FONT_AWESOME_SRI     = 'sha512-SnH5WK+bZxgPHs44uWIX+LLJAJ9/2PkPKZ5QiAj6Ta86w+fsb2TkcmfRyVX3pBnMFcV7oQPJkl9QevSCWr3W6A==';

    /**
     * Enqueues front-end CSS and JS for pages containing a forge form.
     *
     * @return void
     */
    public static function enqueueFront(): void
    {
        // Skip loading front-end CSS/JS entirely on pages that don't embed a form —
        // keeps the plugin's footprint at zero on the rest of the site
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

        \wp_localize_script(
            'forge-forms-front',
            'ForgeForms',
            [
            'ajaxUrl'    => \admin_url('admin-ajax.php'),
            'ibanBicUrl' => \admin_url('admin-ajax.php'),
            'i18n'       => [
                'submitting'              => __('Sending…', 'form-forge'),
                'error_server'            => __('Server error. Please try again.', 'form-forge'),
                // Upload field
                'upload_remove_prefix'    => __('Remove: ', 'form-forge'),
                // translators: %d: maximum number of files allowed (substituted client-side).
                'upload_too_many'         => __('Too many files. Maximum %d allowed.', 'form-forge'),
                'upload_no_types'         => __('No allowed file types in selection.', 'form-forge'),
                'upload_skipped_one'      => __('1 file was skipped due to file type.', 'form-forge'),
                // translators: %d: number of files skipped due to file type (substituted client-side).
                'upload_skipped_many'     => __('%d files were skipped due to file type.', 'form-forge'),
                // translators: %1$d: total number of files submitted, %2$d: maximum allowed per submission (both substituted client-side).
                'upload_overflow'         => __('Too many files total (%1$d). Max. %2$d per submission.', 'form-forge'),
                // Checkbox field
                // translators: %d: minimum number of options that must be selected (substituted client-side).
                'checkbox_min'            => __('Please select at least %d option(s).', 'form-forge'),
                // translators: %d: maximum number of options that may be selected (substituted client-side).
                'checkbox_max'            => __('Please select at most %d option(s).', 'form-forge'),
                // SEPA field
                'sepa_iban_invalid'       => __('Invalid IBAN (check digit incorrect).', 'form-forge'),
                'sepa_iban_incomplete'    => __('Please enter a complete and valid IBAN.', 'form-forge'),
                'sepa_bic_invalid'        => __('Please enter a valid BIC.', 'form-forge'),
                'sepa_iban_required'      => __('IBAN is required.', 'form-forge'),
                'sepa_bic_required'       => __('BIC is required.', 'form-forge'),
                'sepa_holder_required'    => __('Account holder is required.', 'form-forge'),
                'sepa_sig_required'       => __('Please sign.', 'form-forge'),
                'sepa_looking_up'         => __('Looking up', 'form-forge'),
                'sepa_iban_unvalidated'   => __('Could not be validated.', 'form-forge'),
                'sepa_country_blocked'    => __('This country is not allowed.', 'form-forge'),
            ],
            ]
        );

        /* Single pass over all field classes — collect CSS, empty-checks,
         * validators, inits, and skip-validation flags without re-instantiating. */
        $fieldCss    = [];
        $emptyChecks = [];
        $pairs       = [];
        $seenRules   = [];
        $inits       = [];
        $skip        = [];

        foreach (\ForgeForms\Fields\FieldRegistry::all() as $type => $class) {
            $handler = new $class();

            $css = trim($handler->getStyles());
            if ($css !== '') {
                $fieldCss[] = $css;
            }

            $entry = $handler->getClientEmptyCheck();
            if (!empty($entry['fn'])) {
                $emptyChecks[] = json_encode($type) . ':' . trim($entry['fn']);
            }

            foreach ($handler->getClientValidation() as $vEntry) {
                $rule = $vEntry['rule'] ?? '';
                $fn   = $vEntry['fn']   ?? '';
                if ($rule !== '' && $fn !== '' && !isset($seenRules[$rule])) {
                    $seenRules[$rule] = true;
                    $pairs[]          = json_encode($rule) . ':' . trim($fn);
                }
            }

            $fn = $handler->getClientInit();
            if ($fn !== '') {
                $inits[] = json_encode($type) . ':' . trim($fn);
            }

            if ($handler->skipValidation()) {
                $skip[] = json_encode($type);
            }
        }

        if (!empty($fieldCss)) {
            \wp_add_inline_style('forge-forms-front', implode("\n", $fieldCss));
        }
        if (!empty($emptyChecks)) {
            \wp_add_inline_script(
                'forge-forms-front',
                'window.ForgeEmptyChecks={' . implode(',', $emptyChecks) . '};',
                'before'
            );
        }
        if (!empty($pairs)) {
            \wp_add_inline_script(
                'forge-forms-front',
                'window.ForgeValidators={' . implode(',', $pairs) . '};',
                'before'
            );
        }
        if (!empty($inits)) {
            \wp_add_inline_script(
                'forge-forms-front',
                'window.ForgeFieldInits={' . implode(',', $inits) . '};',
                'before'
            );
        }
        if (!empty($skip)) {
            \wp_add_inline_script(
                'forge-forms-front',
                'window.ForgeSkipValidation=[' . implode(',', $skip) . '];',
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
    }

    /**
     * Enqueues the Font Awesome stylesheet from the CDN with a Subresource Integrity
     * hash, so a compromised/tampered CDN response is rejected by the browser instead
     * of silently loading.
     *
     * @return void
     */
    private static function enqueueFontAwesome(): void
    {
        \wp_enqueue_style(
            'font-awesome',
            'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/'
                . self::FONT_AWESOME_VERSION . '/css/all.min.css',
            [],
            self::FONT_AWESOME_VERSION
        );
        \add_filter(
            'style_loader_tag',
            static function (string $tag, string $handle): string {
                if ($handle !== 'font-awesome' || str_contains($tag, 'integrity=')) {
                    return $tag;
                }
                return str_replace(
                    ' rel=',
                    ' integrity="' . self::FONT_AWESOME_SRI . '" crossorigin="anonymous" rel=',
                    $tag
                );
            },
            10,
            2
        );
    }

    /**
     * Enqueues admin CSS and JS for FormForge admin pages.
     *
     * @param string $hook Current admin page hook suffix.
     *
     * @return void
     */
    public static function enqueueAdmin(string $hook): void
    {
        /* Form editor page */
        if (str_contains($hook, 'forge-forms-editor')) {
            self::enqueueFontAwesome();

            \wp_enqueue_style(
                'forge-forms-admin',
                FORGE_FORMS_URL . 'assets/css/admin.css',
                ['font-awesome'],
                FORGE_FORMS_VERSION
            );
            self::addAdminCssVars();

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

        /* General admin pages (non-editor) */
        } elseif (str_contains($hook, 'forge-forms')) {
            self::enqueueFontAwesome();
            \wp_enqueue_style(
                'forge-forms-admin',
                FORGE_FORMS_URL . 'assets/css/admin.css',
                ['font-awesome'],
                FORGE_FORMS_VERSION
            );
            self::addAdminCssVars();
            $needs_picker = str_contains($hook, 'forge-forms-settings')
                         || str_contains($hook, 'forge-forms-pdf-layout');
            if ($needs_picker) {
                \wp_enqueue_style('wp-color-picker');
                \wp_enqueue_script('wp-color-picker');
            }
            if (str_contains($hook, 'forge-forms-pdf-layout')) {
                $fn = 'window.forgePdfUpdatePreview';
                $cb = 'if(' . $fn . ')setTimeout(' . $fn . ',0);';
                $picker_js = 'jQuery(function($){'
                    . '$(".forge-iris-input").wpColorPicker({'
                    . 'change:function(){' . $cb . '},'
                    . 'clear:function(){' . $cb . '}'
                    . '});});';
                \wp_add_inline_script('wp-color-picker', $picker_js);
            }
        }

        /* Verification page */
        if (str_contains($hook, 'forge-pdf-verification')) {
            \wp_enqueue_style(
                'forge-forms-admin',
                FORGE_FORMS_URL . 'assets/css/admin.css',
                [],
                FORGE_FORMS_VERSION
            );
            self::addAdminCssVars();
            \wp_enqueue_script(
                'forge-pdfjs',
                FORGE_FORMS_URL . 'vendor/pdfjs/pdf.js',
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
            \wp_localize_script(
                'forge-forms-verification',
                'ForgeVerifier',
                [
                'ajaxUrl'     => \admin_url('admin-ajax.php'),
                'nonce'       => \wp_create_nonce('forge_verifier_nonce'),
                'pdfJsWorker' => FORGE_FORMS_URL . 'vendor/pdfjs/pdf.worker.js',
                'i18n'        => [
                    'loading'          => __('Loading…', 'form-forge'),
                    'pdf_loading'      => __('Loading PDF…', 'form-forge'),
                    // translators: %1$d: current page number, %2$d: total page count (both substituted client-side).
                    'page_reading'     => __('Reading page %1$d of %2$d…', 'form-forge'),
                    'text_extracted'   => __('Text extracted — server analyzing…', 'form-forge'),
                    'processing'       => __('Processing response…', 'form-forge'),
                    'done'             => __('Done', 'form-forge'),
                    // translators: %d: HTTP status code (substituted client-side).
                    'server_error'     => __('Server error (HTTP %d)', 'form-forge'),
                    'network_error'    => __('Network error', 'form-forge'),
                    'pdf_load_error'   => __('PDF load error: ', 'form-forge'),
                    'error_prefix'     => __('Error: ', 'form-forge'),
                    'unknown_error'    => __('Unknown server error', 'form-forge'),
                ],
                ]
            );
        }
    }

    /**
     * Injects --forge-admin-accent and --forge-hover-color onto the forge-forms-admin stylesheet.
     *
     * @return void
     */
    private static function addAdminCssVars(): void
    {
        $hover        = \get_option('forge_forms_hover_color', '#1d2327');
        $admin_accent = \get_option('forge_forms_admin_accent', '#2271b1');
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $hover)) {
            $hover = '#1d2327';
        }
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $admin_accent)) {
            $admin_accent = '#2271b1';
        }
        $r = hexdec(substr($admin_accent, 1, 2));
        $g = hexdec(substr($admin_accent, 3, 2));
        $b = hexdec(substr($admin_accent, 5, 2));
        $luminance   = (0.299 * $r + 0.587 * $g + 0.114 * $b) / 255;
        $accent_text = $luminance > 0.55 ? '#1d2327' : '#ffffff';
        $accent_fg   = $luminance > 0.55 ? '#1d2327' : $admin_accent;

        $hr = hexdec(substr($hover, 1, 2));
        $hg = hexdec(substr($hover, 3, 2));
        $hb = hexdec(substr($hover, 5, 2));
        $hover_lum = (0.299 * $hr + 0.587 * $hg + 0.114 * $hb) / 255;
        $hover_fg  = $hover_lum > 0.55 ? '#1d2327' : $hover;

        \wp_add_inline_style(
            'forge-forms-admin',
            ':root { --forge-admin-accent: ' . $admin_accent
                . '; --forge-admin-accent--rgb: ' . $r . ',' . $g . ',' . $b
                . '; --forge-hover-color: ' . $hover
                . '; --forge-hover-color-fg: ' . $hover_fg
                . '; --forge-accent-text: ' . $accent_text
                . '; --forge-admin-accent-fg: ' . $accent_fg . '; }'
        );
    }

    /**
     * Returns true when the current post contains a [forge_form] shortcode.
     *
     * @return bool True when a forge form shortcode is present in the post content.
     */
    private static function pageHasForm(): bool
    {
        global $post;
        if (!$post || !\is_a($post, 'WP_Post')) {
            return false;
        }
        // NOTE: the unanchored '[forge_form' prefix also matches '[forge_form_select' —
        // harmless here since a form-select page needs these front-end assets too
        return str_contains((string)$post->post_content, '[forge_form');
    }

    /**
     * Returns true when the current post contains a [forge_form_select] shortcode.
     *
     * @return bool True when a [forge_form_select] shortcode is found in the post.
     */
    private static function pageHasFormSelect(): bool
    {
        global $post;
        if (!$post || !\is_a($post, 'WP_Post')) {
            return false;
        }
        return str_contains((string)$post->post_content, '[forge_form_select');
    }
}
