<?php

/**
 * Admin drag-and-drop form builder page.
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

namespace ForgeForms\Admin;

defined('ABSPATH') || exit;

use ForgeForms\Form\FormModel;
use ForgeForms\Fields\FieldRegistry;

/**
 * Admin drag-and-drop form builder page controller.
 */
class FormEditor
{
    /**
     * Registers admin hooks for the form editor page.
     *
     * @return void
     */
    public static function init(): void
    {
        \add_action('admin_menu', [self::class, 'menu']);
        \add_action('wp_ajax_forge_forms_save_form', [self::class, 'ajaxSave']);
        \add_action('wp_ajax_forge_forms_preview', [self::class, 'ajaxPreview']);
        \add_filter('admin_body_class', [self::class, 'bodyClass']);
    }

    /**
     * Appends a CSS class on the editor page.
     *
     * @param string $classes Existing admin body classes.
     * @return string Modified body class string.
     */
    public static function bodyClass(string $classes): string
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only admin body-class check, no data written.
        if (isset($_GET['page']) && $_GET['page'] === 'forge-forms-editor') {
            $classes .= ' forge-editor-page';
        }
        return $classes;
    }

    /**
     * Registers the editor submenu page.
     *
     * @return void
     */
    public static function menu(): void
    {
        if (\ForgeForms\Plugin::userCan('edit_forms')) {
            \add_submenu_page(
                'forge-forms',
                __('FormForge Editor', 'form-forge'),
                __('New Form', 'form-forge'),
                'read',
                'forge-forms-editor',
                [self::class, 'render']
            );
        }
    }

    /**
     * Renders the drag-and-drop builder page HTML.
     *
     * @return void
     */
    public static function render(): void
    {
        if (!\ForgeForms\Plugin::userCan('edit_forms')) {
            \wp_die(esc_html__('Permission denied.', 'form-forge'));
        }

        $perf_mode   = defined('WP_DEBUG') && WP_DEBUG && current_user_can('manage_options');
        $perf_start  = $perf_mode ? microtime(true) : 0.0;

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only page load (which form to display), gated by edit_forms capability above, no data written.
        $form_id = isset($_GET['form_id']) ? absint(wp_unslash($_GET['form_id'])) : 0;
        $form    = $form_id ? FormModel::get($form_id) : null;
        $palette = FieldRegistry::paletteGroups();

        if ($form) {
            $form_data = [
                'id'            => $form->id,
                'title'         => $form->title,
                'fields'        => $form->fields,
                'notifications' => $form->notifications,
                'settings'      => $form->settings,
            ];
        } else {
            $form_data = [
                'id'            => 0,
                'title'         => __('New Form', 'form-forge'),
                'fields'        => [],
                'notifications' => [self::defaultNotification()],
                'settings'      => [
                    'submit_label'    => __('Submit', 'form-forge'),
                    'success_message' => __('Thank you for your submission!', 'form-forge'),
                ],
            ];
        }

        $nonce    = \wp_create_nonce('forge_forms_admin_nonce');
        $data_form    = \wp_json_encode($form_data, JSON_HEX_APOS | JSON_HEX_QUOT);
        $data_palette = \wp_json_encode($palette, JSON_HEX_APOS | JSON_HEX_QUOT);
        $ajax_url     = \esc_attr(\admin_url('admin-ajax.php'));

        /* assets/js/admin-builder.js (the drag-and-drop builder) is enqueued by
           Assets::enqueueAdmin() under the 'forge-forms-builder' handle — that
           enqueue always runs before this render() callback (admin_enqueue_scripts
           fires before the admin_menu page-render callback), so localizing the
           already-registered handle here is safe; wp_localize_script() only
           stores the data, the inline <script> is printed later at footer time. */
        \wp_localize_script('forge-forms-builder', 'ForgeBuilderI18n', self::builderI18n());

        if ($perf_mode) {
            $php_ms = round((microtime(true) - $perf_start) * 1000, 2);
            \wp_enqueue_script(
                'forge-perf-debug',
                FORGE_FORMS_URL . 'assets/js/forge-perf-debug.js',
                [],
                FORGE_FORMS_VERSION,
                false  /* in <head> so it registers its DOMContentLoaded listener BEFORE admin-builder.js */
            );
            \wp_localize_script('forge-perf-debug', 'ForgePerfData', [
                'phpRenderMs' => $php_ms,
                'formId'      => $form_id,
                'fieldCount'  => count($form_data['fields']),
                'i18n'        => [
                    'toggleShow' => __('Show performance overlay', 'form-forge'),
                    'toggleHide' => __('Hide performance overlay', 'form-forge'),
                ],
            ]);
        }
        ?>
        <canvas id="forge-particle-canvas"></canvas>
        <div class="wrap forge-editor-wrap" style="padding:0;margin:0;">
        <!-- admin-builder.js reads these on load and keeps them updated as the source of
             truth for the form/palette state; ajaxSave() below receives that state back -->
        <div id="forge-editor"
             data-form='<?php echo esc_attr($data_form); ?>'
             data-palette='<?php echo esc_attr($data_palette); ?>'
             data-nonce="<?php echo \esc_attr($nonce); ?>"
             data-ajax-url="<?php echo esc_attr($ajax_url); ?>">

            <div id="forge-canvas">
                <div id="forge-canvas-header">
                    <div id="forge-header-brand">
                        <i class="fa-solid fa-table-list"></i>
                        <span>FormForge</span>
                    </div>
                    <div id="forge-header-divider"></div>
                    <input id="forge-form-name" type="text" value="" />
                    <span id="forge-save-status"></span>
                    <?php if ($perf_mode) : ?>
                    <button id="forge-perf-btn" type="button" title="<?php echo esc_attr__('Performance Overlay', 'form-forge'); ?>">
                        <i class="fa-solid fa-gauge-high"></i>
                    </button>
                    <?php endif; ?>
                    <button id="forge-preview-btn" type="button" title="<?php echo esc_attr__('Preview', 'form-forge'); ?>">
                        <i class="fa-solid fa-eye"></i> <?php echo esc_html__('Preview', 'form-forge'); ?>
                    </button>
                    <button id="forge-save-btn" type="button"><?php esc_html_e('Save', 'form-forge'); ?></button>
                </div>

                <div id="forge-canvas-tabs">
                    <button class="forge-tab-btn forge-tab-active" data-tab="forge-fields-panel"><?php esc_html_e('Fields', 'form-forge'); ?></button>
                    <button class="forge-tab-btn" data-tab="forge-notifications-panel"><?php esc_html_e('Notifications', 'form-forge'); ?></button>
                </div>

                <div id="forge-fields-panel" class="forge-tab-panel forge-panel-active">
                    <div id="forge-field-list"></div>
                    <div id="forge-submit-preview-bar"></div>
                    <div id="forge-add-field-bar">
                        <button id="forge-add-field-btn" type="button">
                            <i class="fa-solid fa-plus"></i> <?php esc_html_e('Add Field', 'form-forge'); ?>
                        </button>
                    </div>
                </div>

                <div id="forge-notifications-panel" class="forge-tab-panel"></div>
            </div>

        </div><!-- #forge-editor -->
        </div>
        <script>
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
        </script>
        <?php
    }

    /**
     * AJAX handler that returns a complete HTML preview of the form.
     *
     * @return void
     */
    public static function ajaxPreview(): void
    {
        if (!\ForgeForms\Plugin::userCan('edit_forms')) {
            \wp_send_json_error(['message' => 'Forbidden'], 403);
        }
        \check_ajax_referer('forge_forms_admin_nonce', 'nonce');

        $form_id = isset($_POST['form_id']) ? absint(wp_unslash($_POST['form_id'])) : 0;

        $settings_override = [];
        if (!empty($_POST['settings'])) {
            $raw_s = json_decode(\ForgeForms\Utils\Sanitize::str(sanitize_textarea_field(\wp_unslash($_POST['settings']))), true);
            if (is_array($raw_s)) {
                $settings_override = self::sanitizeSettings($raw_s);
            }
        }

        /* Use live editor fields when posted; fall back to saved DB state. */
        $fields_override = null;
        if (!empty($_POST['fields'])) {
            $raw_f = json_decode(\ForgeForms\Utils\Sanitize::str(sanitize_textarea_field(\wp_unslash($_POST['fields']))), true);
            if (is_array($raw_f)) {
                $fields_override = self::sanitizeFields($raw_f);
            }
        }

        $html = \ForgeForms\Form\FormRenderer::render($form_id, $settings_override, $fields_override);
        if (!$html) {
            \wp_send_json_error(['message' => __('Form not found.', 'form-forge')], 404);
        }

        /* Collect all field-specific CSS (mirrors Assets::enqueueFront). */
        $field_css = [];
        foreach (\ForgeForms\Fields\FieldRegistry::all() as $class) {
            $css = trim((new $class())->getStyles());
            if ($css !== '') {
                $field_css[] = $css;
            }
        }

        /* Collect ForgeFieldInits. */
        $inits = [];
        foreach (\ForgeForms\Fields\FieldRegistry::all() as $type => $class) {
            $fn = (new $class())->getClientInit();
            if ($fn !== '') {
                $inits[] = \wp_json_encode($type) . ':' . trim($fn);
            }
        }

        /* Collect ForgeValidators. */
        $pairs = [];
        $seen  = [];
        foreach (\ForgeForms\Fields\FieldRegistry::all() as $class) {
            foreach ((new $class())->getClientValidation() as $entry) {
                $rule = $entry['rule'] ?? '';
                $fn   = $entry['fn']   ?? '';
                if ($rule === '' || $fn === '' || isset($seen[$rule])) {
                    continue;
                }
                $seen[$rule] = true;
                $pairs[]     = \wp_json_encode($rule) . ':' . trim($fn);
            }
        }

        /* Collect ForgeEmptyChecks. */
        $empty_checks = [];
        foreach (\ForgeForms\Fields\FieldRegistry::all() as $type => $class) {
            $entry = (new $class())->getClientEmptyCheck();
            if (!empty($entry['fn'])) {
                $empty_checks[] = \wp_json_encode($type) . ':' . trim($entry['fn']);
            }
        }

        /* Collect ForgeSkipValidation. */
        $skip = [];
        foreach (\ForgeForms\Fields\FieldRegistry::all() as $type => $class) {
            if ((new $class())->skipValidation()) {
                $skip[] = \wp_json_encode($type);
            }
        }

        // phpcs:ignore PHPCS_SecurityAudit.BadFunctions.FilesystemFunctions.WarnFilesystem -- hardcoded plugin-relative path, not attacker- or request-influenced.
        $front_js = (string)file_get_contents(FORGE_FORMS_PATH . 'assets/js/front.js');
        $css_url  = \FORGE_FORMS_URL . 'assets/css/front.css';

        $globals = 'window.ForgeForms={'
            . 'ajaxUrl:"",ibanBicUrl:"",'
            . 'i18n:{submitting:' . \wp_json_encode(__('Sending…', 'form-forge')) . ',error_server:' . \wp_json_encode(__('Server error.', 'form-forge')) . '}'
            . '};';
        if (!empty($inits)) {
            $globals .= 'window.ForgeFieldInits={' . implode(',', $inits) . '};';
        }
        if (!empty($pairs)) {
            $globals .= 'window.ForgeValidators={' . implode(',', $pairs) . '};';
        }
        if (!empty($empty_checks)) {
            $globals .= 'window.ForgeEmptyChecks={' . implode(',', $empty_checks) . '};';
        }
        if (!empty($skip)) {
            $globals .= 'window.ForgeSkipValidation=[' . implode(',', $skip) . '];';
        }

        $toolbar_css = '
#forge-preview-toolbar{
    position:sticky;top:16px;flex-shrink:0;
    background:#fff;border:1px solid #dcdcde;border-radius:10px;
    box-shadow:0 4px 16px rgba(0,0,0,.12);
    padding:12px 14px;display:flex;flex-direction:column;gap:10px;
    font-family:system-ui,sans-serif;font-size:12px;
    width:190px;align-self:flex-start;
}
.fpt-row{display:flex;align-items:flex-start;gap:10px;}
.fpt-toggle{position:relative;flex-shrink:0;width:34px;height:20px;margin-top:1px;}
.fpt-toggle input{opacity:0;width:0;height:0;position:absolute;}
.fpt-slider{
    position:absolute;inset:0;border-radius:20px;
    background:#c3c4c7;cursor:pointer;transition:background .2s;
}
.fpt-slider::before{
    content:"";position:absolute;left:3px;top:3px;
    width:14px;height:14px;border-radius:50%;background:#fff;
    transition:transform .2s;
}
.fpt-toggle input:checked+.fpt-slider{background:#2271b1;}
.fpt-toggle input:checked+.fpt-slider::before{transform:translateX(14px);}
.fpt-label{display:flex;flex-direction:column;gap:2px;cursor:pointer;}
.fpt-label strong{font-size:12px;font-weight:600;color:#1d2327;}
.fpt-label span{font-size:11px;color:#787c82;line-height:1.4;}
.fpt-badge{
    display:inline-block;padding:2px 7px;border-radius:20px;font-size:10px;
    font-weight:700;text-transform:uppercase;letter-spacing:.4px;
    background:#f0f6fc;color:#2271b1;border:1px solid #c2d9f0;
}
@media(prefers-color-scheme:dark){
    #forge-preview-toolbar{background:#2c2c2c;border-color:#3c3c3c;box-shadow:0 4px 16px rgba(0,0,0,.4);}
    .fpt-label strong{color:#e0e0e0;}
}';

        $toolbar_html = '<div id="forge-preview-toolbar">'
            . '<div class="fpt-row">'
            . '<label class="fpt-toggle">'
            . '<input type="checkbox" id="fpt-skip-required">'
            . '<span class="fpt-slider"></span>'
            . '</label>'
            . '<label class="fpt-label" for="fpt-skip-required">'
            . '<strong>' . esc_html__('Ignore required fields', 'form-forge') . '</strong>'
            . '</label>'
            . '</div>'
            . '</div>';

        $toolbar_js = '(function(){

/* ── Skip-required toggle ── */
var cb = document.getElementById("fpt-skip-required");
if (cb) {
    cb.addEventListener("change", function () {
        window.ForgeIgnoreRequired = this.checked;
    });
}

/* ── Fake fetch so preview submissions don\'t fire real AJAX ── */
var _origFetch = window.fetch;
window.fetch = function (url, opts) {
    var isSubmit = (url === "" || url === location.href)
        && opts && opts.body instanceof FormData
        && typeof opts.body.get === "function"
        && opts.body.get("action") === "forge_forms_submit";
    if (isSubmit) {
        return new Promise(function (resolve) {
            setTimeout(function () {
                resolve(new Response(
                    JSON.stringify({success:true,data:{message:""}}),
                    {status:200,headers:{"Content-Type":"application/json"}}
                ));
            }, 700);
        });
    }
    return _origFetch.apply(this, arguments);
};

}());';

        $page = '<!DOCTYPE html><html lang="' . esc_attr(str_replace('_', '-', get_locale())) . '"><head>'
            . '<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>' . esc_html__('Preview', 'form-forge') . '</title>'
            // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- standalone preview HTML document returned via wp_send_json_success(), not rendered through the WP page pipeline (no wp_head/wp_footer to enqueue into); Font Awesome is this plugin's own vendored local asset (FORGE_FORMS_URL . 'assets/vendor/fontawesome/css/all.min.css'), not an external/offloaded resource.
            . '<link rel="stylesheet" href="'
            . \esc_url(FORGE_FORMS_URL . 'assets/vendor/fontawesome/css/all.min.css')
            . '">'
            // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- standalone preview HTML document returned via wp_send_json_success(), not rendered through the WP page pipeline (no wp_head/wp_footer to enqueue into); $css_url is this plugin's own local asset (FORGE_FORMS_URL . 'assets/css/front.css'), not an external/offloaded resource.
            . '<link rel="stylesheet" href="' . \esc_url($css_url) . '">'
            . '<style>' . implode("\n", $field_css) . '</style>'
            . '<style>'
            . 'body{font-family:system-ui,sans-serif;background:#f6f7f7;'
            . 'margin:0;padding:40px 24px;display:flex;gap:20px;'
            . 'justify-content:center;align-items:flex-start;}'
            . '#forge-preview-content{flex:1;max-width:760px;min-width:0;}'
            . '.forge-form-wrap{background:#fff;border-radius:8px;padding:32px;'
            . 'box-shadow:0 2px 12px rgba(0,0,0,.08);box-sizing:border-box;}'
            . '@media(prefers-color-scheme:dark){'
            . 'body{background:#1a1a1a;}'
            . '.forge-form-wrap{box-shadow:0 2px 16px rgba(0,0,0,.5);}'
            . '}'
            . $toolbar_css
            . '</style>'
            . '</head><body>'
            . '<div id="forge-preview-content">' . $html . '</div>'
            . $toolbar_html
            . '<script>' . $globals . $front_js . '</script>'
            . '<script>' . $toolbar_js . '</script>'
            . '</body></html>';

        \wp_send_json_success(['html' => $page]);
    }

    /**
     * AJAX handler that saves form data.
     *
     * @return void
     */
    public static function ajaxSave(): void
    {
        if (!\ForgeForms\Plugin::userCan('edit_forms')) {
            \wp_send_json_error(['message' => 'Forbidden'], 403);
        }
        \check_ajax_referer('forge_forms_admin_nonce', 'nonce');

        // base64-wrapped so admin-builder.js can send the raw JSON as a plain string field
        // without WP's magic-quotes slashing corrupting embedded quotes before we get here
        $encoded = \ForgeForms\Utils\Sanitize::str(sanitize_text_field(\wp_unslash($_POST['form_data'] ?? '')));
        $json    = base64_decode($encoded, true);
        $raw     = ($json !== false) ? json_decode($json, true) : null;
        if (!is_array($raw)) {
            \wp_send_json_error(['message' => 'Invalid form data'], 400);
        }

        $form_id       = (int)($raw['id'] ?? 0);
        $raw_title     = $raw['title'] ?? '';
        $sanitized_notifications = self::sanitizeNotifications(is_array($raw['notifications'] ?? null) ? $raw['notifications'] : []);
        $result    = FormModel::save(
            [
            'title'         => \sanitize_text_field(is_string($raw_title) ? $raw_title : ''),
            'fields'        => self::sanitizeFields(is_array($raw['fields'] ?? null) ? $raw['fields'] : []),
            'notifications' => $sanitized_notifications,
            'settings'      => self::sanitizeSettings(is_array($raw['settings'] ?? null) ? $raw['settings'] : []),
            ],
            $form_id,
            true
        );

        if (\is_wp_error($result)) {
            \wp_send_json_error(['message' => $result->get_error_message()], 500);
        }

        /* Save PDF attachment settings from notifications */
        $pdf_settings = \get_option('forge_forms_pdf_settings', []);
        foreach ($sanitized_notifications as $notif) {
            $slug = $notif['slug'] ?? '';
            if ($slug) {
                $pdf_settings[$result . '|' . $slug] = !empty($notif['attach_pdf']) ? 1 : 0;
            }
        }
        \update_option('forge_forms_pdf_settings', $pdf_settings);

        \wp_send_json_success(
            [
            'message' => __('Form saved.', 'form-forge'),
            'form_id' => $result,
            ]
        );
    }

    /**
     * Sanitizes the fields array from the builder.
     *
     * @param array $fields Raw fields array from the builder.
     * @return array Sanitized fields array.
     */
    public static function sanitizeFields(array $fields): array
    {
        $clean = [];
        foreach ($fields as $field) {
            if (!is_array($field) || empty($field['id']) || empty($field['type'])) {
                continue;
            }
            // These structural/label keys bypass each field type's sanitizeConfigValue()
            // below — they're plain identifiers/labels, not rich config values, and every
            // field type needs them handled the same way regardless of its own rules
            $plaintext_keys = ['id', 'type', 'label', 'placeholder', 'description', 'hint', 'name'];
            $handler = \ForgeForms\Fields\FieldRegistry::get((string)($field['type'] ?? ''));
            $f = [];
            foreach ($field as $k => $v) {
                $sk = \sanitize_key($k);
                if (is_string($v)) {
                    $f[$sk] = in_array($k, $plaintext_keys, true)
                        ? \sanitize_text_field($v)
                        : ($handler ? $handler->sanitizeConfigValue($k, $v) : \wp_kses_post($v));
                } elseif (is_bool($v) || is_int($v) || is_float($v)) {
                    $f[$sk] = $v;
                } elseif (is_array($v)) {
                    $f[$sk] = self::sanitizeArrayValue($v);
                }
            }
            if (isset($f['options']) && is_array($f['options'])) {
                $clean_opts = [];
                foreach ($f['options'] as $opt) {
                    if (is_array($opt)) {
                        $clean_opts[] = [
                            'value'   => \sanitize_text_field($opt['value'] ?? ''),
                            'label'   => \sanitize_text_field($opt['label'] ?? ''),
                            'default' => !empty($opt['default']),
                        ];
                    } else {
                        $clean_opts[] = \sanitize_text_field((string)$opt);
                    }
                }
                $f['options'] = $clean_opts;
            }
            $clean[] = $f;
        }
        return $clean;
    }

    /**
     * Recursively sanitizes a nested array-valued field config entry. sanitizeFields() used to copy any
     * array-typed field value through verbatim (only the well-known 'options' key got dedicated handling
     * afterward). Since sanitizeFields() is the sanitization boundary for untrusted pasted import strings
     * (see FormList.php::ajaxImport()), any OTHER array-valued key let an attacker-crafted import smuggle raw
     * HTML/script strings straight into stored field config, bypassing the whole
     * sanitizeConfigValue()/wp_kses_post() pipeline (CWE-79). No current field type stores config under a
     * second array key, but this generic loop must not silently trust one if a field type adds one later —
     * so every array is now walked and its string leaves are sanitized the same way an unrecognized scalar
     * config value is above. Depth is capped to bound recursion against a deeply nested payload (CWE-674).
     *
     * @param array $value Raw nested array value.
     * @param int   $depth Current recursion depth (internal use).
     * @return array Recursively sanitized array.
     */
    private static function sanitizeArrayValue(array $value, int $depth = 0): array
    {
        if ($depth > 10) {
            return [];
        }
        $clean = [];
        foreach ($value as $k => $v) {
            $sk = is_string($k) ? \sanitize_key($k) : $k;
            if (is_string($v)) {
                $clean[$sk] = \wp_kses_post($v);
            } elseif (is_bool($v) || is_int($v) || is_float($v)) {
                $clean[$sk] = $v;
            } elseif (is_array($v)) {
                $clean[$sk] = self::sanitizeArrayValue($v, $depth + 1);
            }
        }
        return $clean;
    }

    /**
     * Sanitizes the notifications array.
     *
     * @param array $notifications Raw notifications array.
     * @return array Sanitized notifications array.
     */
    public static function sanitizeNotifications(array $notifications): array
    {
        $clean = [];
        foreach ($notifications as $n) {
            if (!is_array($n)) {
                continue;
            }
            $routing_rules = [];
            foreach ((array)($n['routing_rules'] ?? []) as $rule) {
                if (!is_array($rule)) {
                    continue;
                }
                $routing_rules[] = [
                    'field_id' => \sanitize_key(\ForgeForms\Utils\Sanitize::str($rule['field_id'] ?? '')),
                    'operator' => \sanitize_key(\ForgeForms\Utils\Sanitize::str($rule['operator'] ?? 'equals', 'equals')),
                    'value'    => \sanitize_text_field(\ForgeForms\Utils\Sanitize::str($rule['value'] ?? '')),
                    /* May be a literal address or a {field_id}/{admin_email}
                       placeholder resolved at send time — sanitize_email()
                       would strip the braces, so keep it as plain text. */
                    'email'    => \sanitize_text_field(\ForgeForms\Utils\Sanitize::str($rule['email'] ?? '')),
                ];
            }
            $clean[] = [
                'slug'             => \sanitize_key(\ForgeForms\Utils\Sanitize::str($n['slug'] ?? '', 'notification-' . \wp_generate_uuid4())),
                'name'             => \sanitize_text_field(\ForgeForms\Utils\Sanitize::str($n['name']       ?? '')),
                'recipient_mode'   => in_array($n['recipient_mode'] ?? '', ['single', 'routing'], true)
                    ? $n['recipient_mode'] : 'single',
                'to'               => \sanitize_text_field(\ForgeForms\Utils\Sanitize::str($n['to']         ?? '')),
                'routing_rules'    => $routing_rules,
                'routing_fallback' =>
                    \sanitize_text_field(\ForgeForms\Utils\Sanitize::str($n['routing_fallback'] ?? '')),
                'reply_to'         => \sanitize_text_field(\ForgeForms\Utils\Sanitize::str($n['reply_to']   ?? '')),
                'subject'          => \sanitize_text_field(\ForgeForms\Utils\Sanitize::str($n['subject']    ?? '')),
                /* Body is always HTML, authored via the Visual or Code view. */
                'body'             => self::sanitizeEmailBody(\ForgeForms\Utils\Sanitize::str($n['body'] ?? '')),
                'from_name'        => \sanitize_text_field(\ForgeForms\Utils\Sanitize::str($n['from_name']  ?? '')),
                'from_email'       => \sanitize_email(\ForgeForms\Utils\Sanitize::str($n['from_email']      ?? '')),
                'cc'               => \sanitize_text_field(\ForgeForms\Utils\Sanitize::str($n['cc']         ?? '')),
                'bcc'              => \sanitize_text_field(\ForgeForms\Utils\Sanitize::str($n['bcc']        ?? '')),
                'attach_pdf'       => !empty($n['attach_pdf']),
                'attach_uploads'   => !empty($n['attach_uploads']),
                'enabled'          => !isset($n['enabled']) || !empty($n['enabled']),
            ];
        }
        return $clean;
    }

    /**
     * Sanitizes the form settings array.
     *
     * @param array $settings Raw settings array.
     * @return array Sanitized settings array.
     */
    public static function sanitizeSettings(array $settings): array
    {
        $rules = [];
        foreach ((array)($settings['submit_conditions']['rules'] ?? []) as $rule) {
            if (!is_array($rule)) {
                continue;
            }
            $rules[] = [
                'field_id'   => \sanitize_key(\ForgeForms\Utils\Sanitize::str($rule['field_id']   ?? '')),
                'operator'   => \sanitize_key(\ForgeForms\Utils\Sanitize::str($rule['operator']   ?? 'equals', 'equals')),
                'value'      => \sanitize_text_field(\ForgeForms\Utils\Sanitize::str($rule['value'] ?? '')),
                'use_option' => !empty($rule['use_option']),
            ];
        }

        return [
            'submit_label'      => \sanitize_text_field(\ForgeForms\Utils\Sanitize::str($settings['submit_label']    ?? '', __('Submit', 'form-forge'))),
            'submit_working'    => \sanitize_text_field(\ForgeForms\Utils\Sanitize::str($settings['submit_working']   ?? '', __('Sending…', 'form-forge'))),
            'success_message'   => \wp_kses_post(\ForgeForms\Utils\Sanitize::str($settings['success_message']         ?? '', __('Thank you!', 'form-forge'))),
            'submit_conditions' => [
                'enabled' => !empty($settings['submit_conditions']['enabled']),
                'match'   => in_array($settings['submit_conditions']['match'] ?? '', ['all', 'any'], true)
                    ? $settings['submit_conditions']['match'] : 'all',
                'rules'   => $rules,
            ],
        ];
    }

    /**
     * Sanitizes an HTML email body for admin-authored notification templates. Uses targeted regex instead of
     * wp_kses because wp_kses passes all style attributes through safecss_filter_attr, which drops valid
     * email CSS properties (overflow, border-radius, display:inline-block, etc.). Only genuinely dangerous
     * constructs are removed: script elements, inline event-handler attributes, and javascript:/vbscript:
     * URIs.
     *
     * @param string $html Raw HTML body from the notification editor.
     * @return string Sanitized HTML.
     */
    private static function sanitizeEmailBody(string $html): string
    {
        $before = $html;

        $passes = [
            'script-open'   => '/<script\b[^>]*>/i',
            'script-close'  => '/<\/script\s*>/i',
            'script-tags'   => '/<script\b[^<]*(?:(?!<\/script>)<[^<]*)*<\/script>/is',
            // Active/embeddable content tags have no legitimate use in an email
            // body and some webmail/desktop clients do render them, so strip
            // the whole element rather than relying on attribute-level passes.
            'iframe-tags'   => '/<iframe\b[^<]*(?:(?!<\/iframe>)<[^<]*)*<\/iframe>|<iframe\b[^>]*\/?>/is',
            'object-tags'   => '/<object\b[^<]*(?:(?!<\/object>)<[^<]*)*<\/object>/is',
            'embed-tags'    => '/<embed\b[^>]*\/?>/i',
            // Meta refresh is a classic open-redirect/auto-navigate vector in
            // rendered HTML mail and has no legitimate use in a notification body.
            'meta-refresh'  => '/<meta\b[^>]*http-equiv\s*=\s*["\']?\s*refresh[^>]*>/i',
            // HTML5 allows "/" as an attribute boundary (e.g. <img/onerror=...>),
            // not just whitespace — match both so this can't be sidestepped.
            'event-handlers' =>
                '/[\s\/]+on[a-z]+\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+)/i',
            'js-uris'
                => '/\b(href|src|action)\s*=\s*(["\'])\s*'
                . '(?:javascript|vbscript)\s*:[^"\']*\2/i',
            // Unquoted attribute values are valid HTML (<a href=javascript:...>)
            // and aren't matched by the quoted pattern above at all.
            'js-uris-unquoted'
                => '/\b(href|src|action)\s*=\s*(?!["\'])'
                . '(?:javascript|vbscript)\s*:[^\s>]*/i',
            // style="...url('javascript:...')..." is a CSS-vector XSS the
            // href/src/action pass above doesn't cover.
            'css-js-uris'
                => '/(\bstyle\s*=\s*)(["\'])((?:(?!\2).)*(?:javascript|vbscript)\s*:(?:(?!\2).)*)\2/i',
        ];

        $replacements = [
            'script-open'       => '',
            'script-close'      => '',
            'script-tags'       => '',
            'iframe-tags'       => '',
            'object-tags'       => '',
            'embed-tags'        => '',
            'meta-refresh'      => '',
            'event-handlers'    => '',
            'js-uris-unquoted'  => '$1=""',
            'css-js-uris'       => '$1$2$2',
        ];
        $replacements['js-uris'] = '$1=$2#$2';

        foreach ($passes as $label => $pattern) {
            // phpcs:ignore PHPCS_SecurityAudit.BadFunctions.PregReplace.PregReplaceDyn -- $pattern/$replacements are drawn from the hardcoded $passes/$replacements arrays above, not attacker input; no /e modifier is used anywhere in this codebase.
            $after = preg_replace($pattern, $replacements[$label], $html);
            if ($after !== $html) {
                // Count-only, no stripped content — safe to log unconditionally
                // (not gated behind WP_DEBUG) so production sites keep an audit
                // trail when a notification body actually triggers a strip.
                preg_match_all($pattern, $html, $m);
                \ForgeForms\forge_log(
                    'ForgeForms sanitizeEmailBody [' . $label . '] removed '
                    . count($m[0] ?? []) . ' match(es)'
                );
            }
            $html = $after;
        }

        // The js-uris/css-js-uris passes above match the raw attribute string.
        // A payload can dodge that by HTML-entity-encoding the "javascript:"
        // keyword (a browser/mail client decodes entities before evaluating a
        // URI) or by splitting it with a CSS comment (url(java/**/script:...),
        // which CSS strips before the URL is evaluated). Decode + strip
        // comments in a working copy of each href/src/action/style value and
        // drop the whole value if the decoded form is still dangerous.
        // phpcs:ignore PHPCS_SecurityAudit.BadFunctions.CallbackFunctions.WarnCallbackFunctions -- callback is a static closure defined inline, not attacker-controlled/dynamic dispatch.
        $html = preg_replace_callback(
            '/\b(href|src|action|style)\s*=\s*(?:(["\'])((?:(?!\2).)*)\2|([^\s>]+))/is',
            static function (array $m): string {
                $attr    = $m[1];
                $quoted  = $m[2] !== '';
                $q       = $quoted ? $m[2] : '';
                $val     = $quoted ? $m[3] : ($m[4] ?? '');
                $decoded = html_entity_decode($val, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                // phpcs:ignore PHPCS_SecurityAudit.BadFunctions.PregReplace.PregReplaceWeird -- hardcoded literal pattern/replacement stripping CSS comments from the decoded attribute value, not attacker-influenced pattern selection; no /e modifier.
                $decoded = preg_replace('#/\*.*?\*/#s', '', $decoded);
                // Browsers strip ASCII tab/CR/LF from a URL before parsing its
                // scheme, so "jav\tascript:" still executes as javascript: even
                // though the keyword itself isn't contiguous — strip these
                // before the keyword check, not just via \s* around the colon.
                $decoded = str_replace(["\t", "\n", "\r"], '', (string) $decoded);
                if (preg_match('/javascript\s*:|vbscript\s*:/i', $decoded)) {
                    \ForgeForms\forge_log(
                        'ForgeForms sanitizeEmailBody [encoded-js-uri] stripped '
                        . $attr . ' value: ' . substr($val, 0, 200)
                    );
                    return $quoted ? ($attr . '=' . $q . $q) : ($attr . '=""');
                }
                return $m[0];
            },
            $html
        ) ?? $html;

        // If the body is a full HTML document and the user appended content
        // after </html> (e.g. {all_fields} tacked on at the end), that content
        // ends up outside the document structure and breaks email clients.
        // Move any such orphaned content to just before </body> instead.
        $close_pos = strripos($html, '</html>');
        if ($close_pos !== false) {
            $orphan = trim(substr($html, $close_pos + 7));
            if ($orphan !== '') {
                $html = substr($html, 0, $close_pos + 7);
                $body_close = strripos($html, '</body>');
                if ($body_close !== false) {
                    $html = substr($html, 0, $body_close)
                        . "\n" . $orphan . "\n"
                        . substr($html, $body_close);
                    \ForgeForms\forge_log(
                        'ForgeForms sanitizeEmailBody: moved orphaned content'
                        . ' from after </html> to before </body>: '
                        . substr($orphan, 0, 100)
                    );
                }
            }
        }

        // ── Layer 2: wp_kses() allow-list, defense-in-depth on top of the
        // regex passes above — NOT a replacement for them; it runs on their
        // already-cleaned output. The regex passes above are a targeted
        // deny-list (strip only what's known dangerous); a parser-based
        // allow-list catches HTML-parsing quirks the regexes don't anticipate
        // (malformed/overlapping tags, mutation XSS, etc. — CWE-79) that a
        // regex operating on the raw token stream can't see.
        //
        // The rich-text ("Visuell") notification editor
        // (assets/js/admin-builder.js, spRichTextEditor()/sanitizeRichDoc())
        // deliberately saves a *complete* HTML document — <!DOCTYPE html>
        // <html><head><style>...</style></head><body>...</body></html>, not a
        // fragment — so the design-mode iframe keeps its own <html>/<head>/
        // <body> wrapper (a plain contenteditable div would strip it and is
        // an extension target). The allow-list below therefore has to permit
        // that wrapper too: if <style> were stripped as a *tag* while its
        // text content survived (wp_kses removes disallowed tags but leaves
        // their inner text alone), the CSS ruleset from <head> would spill
        // out as visible plaintext at the top of the rendered email — a
        // content-corruption bug, not just a style loss. The DOCTYPE is
        // preserved explicitly since wp_kses doesn't recognize it as a tag
        // and would silently drop it.
        $doctype = '';
        if (preg_match('/^\s*<!DOCTYPE[^>]*>/i', $html, $dm)) {
            $doctype = $dm[0];
            $html    = substr($html, strlen($dm[0]));
        }

        $allowed_email_tags = [
            // Document wrapper — see comment above for why this must stay.
            'html'  => ['lang' => true],
            'head'  => [],
            'body'  => ['style' => true, 'bgcolor' => true],
            'title' => [],
            'meta'  => ['charset' => true, 'http-equiv' => true, 'content' => true, 'name' => true],
            'style' => ['type' => true, 'media' => true],
            'link'  => ['rel' => true, 'href' => true, 'type' => true, 'media' => true],

            // Layout & content — HTML-email-safe subset.
            'table'  => ['style' => true, 'width' => true, 'height' => true, 'border' => true,
                         'cellpadding' => true, 'cellspacing' => true, 'align' => true,
                         'bgcolor' => true, 'role' => true],
            'thead'  => [],
            'tbody'  => [],
            'tfoot'  => [],
            'tr'     => ['style' => true, 'align' => true, 'valign' => true, 'bgcolor' => true],
            'td'     => ['style' => true, 'align' => true, 'valign' => true, 'width' => true,
                         'height' => true, 'colspan' => true, 'rowspan' => true, 'bgcolor' => true],
            'th'     => ['style' => true, 'align' => true, 'valign' => true, 'width' => true,
                         'height' => true, 'colspan' => true, 'rowspan' => true, 'bgcolor' => true],
            'div'    => ['style' => true, 'align' => true],
            'span'   => ['style' => true],
            'p'      => ['style' => true, 'align' => true],
            'a'      => ['style' => true, 'href' => true, 'title' => true, 'target' => true, 'rel' => true],
            'img'    => ['style' => true, 'src' => true, 'alt' => true, 'title' => true,
                         'width' => true, 'height' => true, 'align' => true, 'border' => true],
            'br'     => [],
            'hr'     => ['style' => true],
            'strong' => [],
            'em'     => [],
            'b'      => [],
            'i'      => [],
            'u'      => [],
            'ul'     => ['style' => true],
            'ol'     => ['style' => true],
            'li'     => ['style' => true],
            'h1'     => ['style' => true, 'align' => true],
            'h2'     => ['style' => true, 'align' => true],
            'h3'     => ['style' => true, 'align' => true],
            'h4'     => ['style' => true, 'align' => true],
            'h5'     => ['style' => true, 'align' => true],
            'h6'     => ['style' => true, 'align' => true],
        ];

        // Email layouts commonly need CSS properties that WP core's default
        // safecss_filter_attr() allow-list doesn't cover; add them rather
        // than letting safecss_filter_attr silently drop the declaration.
        $allow_email_css = static function (array $attrs): array {
            return array_unique(
                array_merge(
                    $attrs,
                    [
                        'overflow', 'border-radius', 'display', 'background-color',
                        'padding', 'margin', 'font-family', 'font-size', 'color',
                        'text-align', 'width', 'max-width', 'border', 'border-collapse',
                        'vertical-align', 'line-height', 'background', 'box-sizing',
                        'white-space', 'text-decoration', 'font-weight', 'letter-spacing',
                        'border-top', 'border-right', 'border-bottom', 'border-left',
                        'border-spacing', 'padding-top', 'padding-right', 'padding-bottom',
                        'padding-left', 'margin-top', 'margin-right', 'margin-bottom',
                        'margin-left',
                    ]
                )
            );
        };
        \add_filter('safecss_filter_attr_allow_css', $allow_email_css);
        $html = \wp_kses($html, $allowed_email_tags);
        \remove_filter('safecss_filter_attr_allow_css', $allow_email_css);

        $html = $doctype . $html;

        // Force rel="noopener noreferrer" on any target="_blank" link. Without
        // it, the linked page gets window.opener access to this document
        // (reverse tabnabbing) and the browser sends a Referer header leaking
        // this admin page's URL — both avoidable via `rel`. The email body is
        // authored by trusted edit_forms-capability admins, not attacker
        // input, so this is defense-in-depth rather than an XSS fix.
        // phpcs:ignore PHPCS_SecurityAudit.BadFunctions.CallbackFunctions.WarnCallbackFunctions -- callback is a static closure defined inline, not attacker-controlled/dynamic dispatch.
        $html = preg_replace_callback(
            '/<a\b([^>]*\btarget=["\']_blank["\'][^>]*)>/i',
            static function (array $m): string {
                if (preg_match('/\brel=["\']([^"\']*)["\']/i', $m[1], $rel_match)) {
                    $rel     = $rel_match[1];
                    $needed  = array_diff(['noopener', 'noreferrer'], explode(' ', $rel));
                    if (empty($needed)) {
                        return $m[0];
                    }
                    $new_rel = trim($rel . ' ' . implode(' ', $needed));
                    return '<a' . preg_replace('/\brel=["\'][^"\']*["\']/i', 'rel="' . esc_attr($new_rel) . '"', $m[1]) . '>';
                }
                return '<a' . $m[1] . ' rel="noopener noreferrer">';
            },
            $html
        ) ?? $html;

        // Lengths only, no content — log unconditionally when something was
        // actually stripped so production keeps an audit trail; the "nothing
        // stripped" case is debug-only noise, not a security-relevant event.
        if ($html !== $before) {
            \ForgeForms\forge_log(
                'ForgeForms sanitizeEmailBody: input length '
                . strlen($before) . ' → output length ' . strlen($html)
            );
        } elseif (defined('WP_DEBUG') && WP_DEBUG) {
            \ForgeForms\forge_log(
                'ForgeForms sanitizeEmailBody: nothing stripped '
                . '(input length ' . strlen($html) . ')'
            );
        }

        return $html;
    }

    /**
     * Returns the default notification configuration array.
     *
     * @return array Default notification configuration.
     */
    private static function defaultNotification(): array
    {
        return [
            'slug'             => 'notification-1',
            'name'             => __('Notification 1', 'form-forge'),
            'recipient_mode'   => 'single',
            'to'               => \get_option('admin_email', ''),
            'routing_rules'    => [],
            'routing_fallback' => '',
            'subject'          => __('New Entry: {form_title}', 'form-forge'),
            'body'             =>
                __('A new entry has been received.<br><br>{all_fields}', 'form-forge'),
            'from_name'        => '{site_name}',
            'from_email'       => '{admin_email}',
            'reply_to'         => '{email}',
            'cc'               => '',
            'bcc'              => '',
            'attach_pdf'       => true,
            'enabled'          => true,
        ];
    }

    /**
     * Builds the localized string catalog consumed by assets/js/admin-builder.js
     * (the drag-and-drop builder UI). Mirrors the pattern used for
     * ForgeForms.i18n (Assets::enqueueFront()) and hbi18n
     * (PDFLayoutEditor.php) — a single object of English-source strings that
     * the JS falls back to its own English literal for if this ever fails to
     * load (see the `_i18n.key || 'English fallback'` pattern in the JS).
     *
     * @return array{i18n: array<string, string>, countryNames: array<string, string>, phoneCodes: array<string, string>}
     */
    private static function builderI18n(): array
    {
        return [
            'i18n' => [
                /* Defaults seeded into a brand-new form (state.formName / state.settings) */
                'defaultFormName'  => __('New Form', 'form-forge'),
                'submitLabel'      => __('Submit', 'form-forge'),
                'submitWorking'    => __('Sending…', 'form-forge'),
                'successMessage'   => __('Thank you for your submission!', 'form-forge'),

                /* Unsaved-changes guard modal */
                'unsavedTitle' => __('Unsaved changes', 'form-forge'),
                'unsavedBody'  => __('This form has unsaved changes. Leave anyway?', 'form-forge'),
                'stay'         => __('Stay', 'form-forge'),
                'leave'        => __('Leave', 'form-forge'),

                /* Number-field validation rule dropdown */
                'validationNone'             => __('None', 'form-forge'),
                'validationIntegersOnly'     => __('Integers only', 'form-forge'),
                'validationPositiveNumbers'  => __('Positive numbers', 'form-forge'),
                'validationPositiveIntegers' => __('Positive integers', 'form-forge'),

                /* Condition-rule operator dropdown (field/submit-button conditions) */
                'opEquals'      => __('is equal to', 'form-forge'),
                'opNotEquals'   => __('is not equal to', 'form-forge'),
                'opContains'    => __('contains', 'form-forge'),
                'opNotContains' => __('does not contain', 'form-forge'),
                'opEmpty'       => __('is empty', 'form-forge'),
                'opNotEmpty'    => __('is not empty', 'form-forge'),
                'opGreater'     => __('is greater than', 'form-forge'),
                'opLess'        => __('is less than', 'form-forge'),

                /* Field list / rows */
                'noFieldsFound'       => __('No fields found.', 'form-forge'),
                'noFieldsYetTitle'    => __('No fields yet', 'form-forge'),
                'addFirstFieldHtml'   => __('Add your first field via', 'form-forge'),
                'noLabel'             => __('(no label)', 'form-forge'),
                'edit'                => __('Edit', 'form-forge'),
                'duplicate'           => __('Duplicate', 'form-forge'),
                'remove'              => __('Remove', 'form-forge'),
                'copied'              => __('copied', 'form-forge'),
                'fieldGroupType'      => __('Field group', 'form-forge'),
                'addFieldsToGroup'    => __('Add fields to group', 'form-forge'),
                'settingsLabel'       => __('Settings', 'form-forge'),
                'conditionsActive'    => __('Conditions active', 'form-forge'),

                /* Field picker modal */
                'addFieldTitle'    => __('Add field', 'form-forge'),
                'searchFieldType'  => __('Search field type…', 'form-forge'),

                /* Field settings modal */
                'fieldSettingsTitle' => __('Field settings', 'form-forge'),
                'copyFieldId'        => __('Copy field ID', 'form-forge'),
                'tabGeneral'         => __('General', 'form-forge'),
                'tabAdvanced'        => __('Advanced', 'form-forge'),
                'tabConditions'      => __('Conditions', 'form-forge'),
                'done'               => __('Done', 'form-forge'),
                'editFieldSuffix'    => __('Edit', 'form-forge'),
                'labelField'         => __('Label', 'form-forge'),
                'hideLabel'          => __('Hide label', 'form-forge'),
                'requiredField'      => __('Required field', 'form-forge'),
                'noOtherFields'      => __('(no other fields)', 'form-forge'),
                'valueWord'          => __('Value', 'form-forge'),
                'urlPlaceholder'     => __('https://…', 'form-forge'),
                'mediaLibrary'       => __('Media library', 'form-forge'),
                'useButtonLabel'     => __('Use', 'form-forge'),
                'imageUrlPrompt'     => __('Image URL:', 'form-forge'),
                'linkUrlPrompt'      => __('Enter link URL:', 'form-forge'),

                /* Rich-text toolbar (spRichTextEditor) command tooltips */
                'rtBold'          => __('Bold', 'form-forge'),
                'rtItalic'        => __('Italic', 'form-forge'),
                'rtUnderline'     => __('Underline', 'form-forge'),
                'rtBulletList'    => __('List', 'form-forge'),
                'rtNumberedList'  => __('Numbered list', 'form-forge'),
                'rtLink'          => __('Link', 'form-forge'),

                /* Country/calling-code tag remove buttons, rating icon pill */
                'removeAriaLabel' => __('Remove', 'form-forge'),
                'wholeValues'     => __('Whole values', 'form-forge'),
                'halfValues'      => __('Half values', 'form-forge'),
                'pagePrefix'         => __('Page ', 'form-forge'),
                'useEmailsHint'      => __('Use in emails:', 'form-forge'),
                'fieldIdLabel'       => __('Field ID', 'form-forge'),

                /* Advanced tab */
                'validationSection'      => __('Validation', 'form-forge'),
                'validationRule'         => __('Validation rule', 'form-forge'),
                'autocompleteSection'    => __('Browser autocomplete', 'form-forge'),
                'enableBrowserFill'      => __('Enable browser autofill', 'form-forge'),
                'autocompleteValue'      => __('Autocomplete value', 'form-forge'),
                'autocompleteValueHint'  => __('E.g. "name", "email", "tel". Empty = browser default.', 'form-forge'),
                'appearanceSection'      => __('Appearance', 'form-forge'),
                'cssClasses'             => __('CSS class(es)', 'form-forge'),
                'separateClassesHint'    => __('Separate multiple classes with spaces.', 'form-forge'),

                /* SEPA / phone advanced blocks */
                'countryFilter'      => __('Country filter', 'form-forge'),
                'countryList'        => __('Country list', 'form-forge'),
                'off'                => __('Off', 'form-forge'),
                'allowed'            => __('Allowed', 'form-forge'),
                'disallowed'         => __('Disallowed', 'form-forge'),
                'formatValidation'   => __('Format validation', 'form-forge'),
                'phoneAnyFormatHint' => __('Any valid format: min. 7 digits, optionally with + and country code.', 'form-forge'),
                'any'                => __('Any', 'form-forge'),
                'countriesMode'      => __('Countries', 'form-forge'),
                'dialCodes'          => __('Dial codes', 'form-forge'),

                /* Conditions tab (per-field and submit-button) */
                'condShow'          => __('Show', 'form-forge'),
                'condHide'          => __('Hide', 'form-forge'),
                'condSentenceField' => __('this field when', 'form-forge'),
                'condAll'           => __('all', 'form-forge'),
                'condAny'           => __('any', 'form-forge'),
                'condSentenceTail'  => __('of the following conditions match:', 'form-forge'),
                'addCondition'      => __('Add condition', 'form-forge'),
                'removeCondition'   => __('Remove condition', 'form-forge'),
                'removeRule'        => __('Remove rule', 'form-forge'),
                'addRule'           => __('Add rule', 'form-forge'),
                'chooseOption'      => __('Choose option', 'form-forge'),
                'modeOption'        => __('Option', 'form-forge'),
                'modeValue'         => __('Value', 'form-forge'),
                'noFields'          => __('(no fields)', 'form-forge'),

                /* Country / calling-code tag pickers */
                'searchCountry'  => __('Search and add country…', 'form-forge'),
                'searchDialCode' => __('Search dial code (+49)…', 'form-forge'),

                /* Select/radio/checkbox option editor */
                'preselected'            => __('Preselected', 'form-forge'),
                'optionLabelPlaceholder' => __('Label', 'form-forge'),
                'optionValuePlaceholder' => __('value', 'form-forge'),
                'addOption'              => __('Add option', 'form-forge'),

                /* Textarea/text character-limit unit toggle */
                'unitChars' => __('Characters', 'form-forge'),
                'unitWords' => __('Words', 'form-forge'),

                /* Rich text / HTML editors */
                'modeCode'    => __('Code', 'form-forge'),
                'modePreview' => __('Preview', 'form-forge'),
                'modeVisual'  => __('Visual', 'form-forge'),

                /* Time field */
                'formatLabel' => __('Format', 'form-forge'),
                'format24h'   => __('24h', 'form-forge'),
                'format12h'   => __('12h (AM/PM)', 'form-forge'),
                'prefillNow'  => __('Prefill now', 'form-forge'),

                /* Sub-fields accordion (Name, Address, SEPA, …) */
                'subfieldsSection' => __('Subfields', 'form-forge'),
                'enableSubfield'   => __('Enable subfield', 'form-forge'),
                'placeholderLabel' => __('Placeholder', 'form-forge'),

                /* Notifications list + modal */
                'noNotificationsHtml' => __('No notifications yet. Click', 'form-forge'),
                'addNotification'     => __('Add notification', 'form-forge'),
                'notificationPrefix'  => __('Notification ', 'form-forge'),
                'routingActive'       => __('Routing active', 'form-forge'),
                'noName'              => __('(no name)', 'form-forge'),
                'notificationPlaceholder' => __('Notification', 'form-forge'),
                'tabRecipient'        => __('Recipient', 'form-forge'),
                'tabContent'          => __('Content', 'form-forge'),
                'tabSender'           => __('Sender', 'form-forge'),
                'recipientMode'       => __('Recipient mode', 'form-forge'),
                'modeDirect'          => __('Direct', 'form-forge'),
                'modeRouting'         => __('Routing', 'form-forge'),
                'activeLabel'         => __('Active', 'form-forge'),
                'singleModeHint'      => __('All entries are sent to a fixed address.', 'form-forge'),
                'routingModeHint'     => __('The email address is chosen based on field conditions.', 'form-forge'),
                'toEmail'             => __('To (email)', 'form-forge'),
                'arrowTo'             => __('→ To:', 'form-forge'),
                'emailPlaceholder'    => __('Email', 'form-forge'),
                'fallbackEmail'       => __('Fallback email', 'form-forge'),
                'fallbackEmailHint'   => __('Used when no rule matches', 'form-forge'),
                'subject'             => __('Subject', 'form-forge'),
                'message'             => __('Message', 'form-forge'),
                'attachments'         => __('Attachments', 'form-forge'),
                'attachPdf'           => __('Attach generated PDF', 'form-forge'),
                'attachPdfHint'       => __('The completed form is attached as a PDF document.', 'form-forge'),
                'attachUploads'       => __('Attach uploaded files', 'form-forge'),
                'attachUploadsHint'   => __('All file uploads from the form are forwarded as attachments.', 'form-forge'),
                'fromName'            => __('Sender name', 'form-forge'),
                'defaultSiteNameHint' => __('{site_name} for default value', 'form-forge'),
                'fromEmail'           => __('Sender email address', 'form-forge'),
                'defaultAdminEmailHint' => __('{admin_email} for default value', 'form-forge'),
                'replyTo'             => __('Reply-to email', 'form-forge'),
                'emptyMeansSenderEmail' => __('Empty = sender email', 'form-forge'),
                'ccEmails'            => __('CC emails', 'form-forge'),
                'bccEmails'           => __('BCC emails', 'form-forge'),
                'separateWithSemicolon' => __('Separate multiple with semicolons', 'form-forge'),

                /* Submit button preview + settings modal */
                'submitButtonTitle'       => __('Submit button', 'form-forge'),
                'tabLabels'               => __('Labeling', 'form-forge'),
                'buttonLabel'             => __('Label', 'form-forge'),
                'buttonLabelHint'         => __('Visible text of the button', 'form-forge'),
                'workingLabel'            => __('Label while sending', 'form-forge'),
                'workingLabelHint'        => __('Shown while the submission is in progress', 'form-forge'),
                'successMessageLabel'     => __('Success message', 'form-forge'),
                'successMessageHint'      => __('Message shown after a successful submission', 'form-forge'),
                'showButtonWhen'          => __('Show button when', 'form-forge'),
                'conditionsMatchSuffix'   => __('of the conditions match:', 'form-forge'),
                'configureButton'         => __('Configure button', 'form-forge'),
                'visibilityConditionsActive' => __('Visibility: conditions active', 'form-forge'),
                'conditionalBadge'        => __('conditional', 'form-forge'),

                /* Save / preview status messages */
                'saving'         => __('Saving…', 'form-forge'),
                'saved'          => __('Saved', 'form-forge'),
                'errorGeneric'   => __('Error', 'form-forge'),
                'unknownError'   => __('Unknown error', 'form-forge'),
                'serverError'    => __('Server error', 'form-forge'),
                'previewLabel'   => __('Preview', 'form-forge'),
                'previewFailed'  => __('Preview failed.', 'form-forge'),
                'networkError'   => __('Network error.', 'form-forge'),
            ],

            /* SEPA "Länderfilter" country-tag picker (IBAN-capable countries). Reuses the
               exact same __() msgids as SepaField::ibanCountryOptions() — same list, same
               English source text — except 'SC' (Seychelles), which that list doesn't
               contain and is new here. Reusing msgids means these already have German
               translations in languages/form-forge-de_DE.po. */
            'countryNames' => [
                'AD' => __('Andorra', 'form-forge'),
                'AE' => __('United Arab Emirates', 'form-forge'),
                'AL' => __('Albania', 'form-forge'),
                'AT' => __('Austria', 'form-forge'),
                'AZ' => __('Azerbaijan', 'form-forge'),
                'BA' => __('Bosnia and Herzegovina', 'form-forge'),
                'BE' => __('Belgium', 'form-forge'),
                'BG' => __('Bulgaria', 'form-forge'),
                'BH' => __('Bahrain', 'form-forge'),
                'BR' => __('Brazil', 'form-forge'),
                'CH' => __('Switzerland', 'form-forge'),
                'CR' => __('Costa Rica', 'form-forge'),
                'CY' => __('Cyprus', 'form-forge'),
                'CZ' => __('Czechia', 'form-forge'),
                'DE' => __('Germany', 'form-forge'),
                'DJ' => __('Djibouti', 'form-forge'),
                'DK' => __('Denmark', 'form-forge'),
                'DO' => __('Dominican Republic', 'form-forge'),
                'EE' => __('Estonia', 'form-forge'),
                'EG' => __('Egypt', 'form-forge'),
                'ES' => __('Spain', 'form-forge'),
                'FI' => __('Finland', 'form-forge'),
                'FR' => __('France', 'form-forge'),
                'GB' => __('United Kingdom', 'form-forge'),
                'GE' => __('Georgia', 'form-forge'),
                'GI' => __('Gibraltar', 'form-forge'),
                'GL' => __('Greenland', 'form-forge'),
                'GR' => __('Greece', 'form-forge'),
                'GT' => __('Guatemala', 'form-forge'),
                'HR' => __('Croatia', 'form-forge'),
                'HU' => __('Hungary', 'form-forge'),
                'IE' => __('Ireland', 'form-forge'),
                'IL' => __('Israel', 'form-forge'),
                'IQ' => __('Iraq', 'form-forge'),
                'IS' => __('Iceland', 'form-forge'),
                'IT' => __('Italy', 'form-forge'),
                'JO' => __('Jordan', 'form-forge'),
                'KW' => __('Kuwait', 'form-forge'),
                'KZ' => __('Kazakhstan', 'form-forge'),
                'LB' => __('Lebanon', 'form-forge'),
                'LC' => __('St. Lucia', 'form-forge'),
                'LI' => __('Liechtenstein', 'form-forge'),
                'LT' => __('Lithuania', 'form-forge'),
                'LU' => __('Luxembourg', 'form-forge'),
                'LV' => __('Latvia', 'form-forge'),
                'LY' => __('Libya', 'form-forge'),
                'MA' => __('Morocco', 'form-forge'),
                'MC' => __('Monaco', 'form-forge'),
                'MD' => __('Moldova', 'form-forge'),
                'ME' => __('Montenegro', 'form-forge'),
                'MK' => __('North Macedonia', 'form-forge'),
                'MR' => __('Mauritania', 'form-forge'),
                'MT' => __('Malta', 'form-forge'),
                'MU' => __('Mauritius', 'form-forge'),
                'NI' => __('Nicaragua', 'form-forge'),
                'NL' => __('Netherlands', 'form-forge'),
                'NO' => __('Norway', 'form-forge'),
                'PK' => __('Pakistan', 'form-forge'),
                'PL' => __('Poland', 'form-forge'),
                'PT' => __('Portugal', 'form-forge'),
                'QA' => __('Qatar', 'form-forge'),
                'RO' => __('Romania', 'form-forge'),
                'RS' => __('Serbia', 'form-forge'),
                'SA' => __('Saudi Arabia', 'form-forge'),
                'SC' => __('Seychelles', 'form-forge'),
                'SE' => __('Sweden', 'form-forge'),
                'SI' => __('Slovenia', 'form-forge'),
                'SK' => __('Slovakia', 'form-forge'),
                'SM' => __('San Marino', 'form-forge'),
                'SV' => __('El Salvador', 'form-forge'),
                'TN' => __('Tunisia', 'form-forge'),
                'TR' => __('Turkey', 'form-forge'),
                'UA' => __('Ukraine', 'form-forge'),
                'VA' => __('Vatican City', 'form-forge'),
                'VG' => __('British Virgin Islands', 'form-forge'),
                'XK' => __('Kosovo', 'form-forge'),
            ],

            /* Phone-field "Vorwahlen" (calling code) tag picker. These are dial-code
               region labels, not ISO country names, so they're a distinct set of new
               msgids from countryNames above (some cover multiple countries, e.g. +1). */
            'phoneCodes' => [
                '+1'   => __('USA / Canada', 'form-forge'),
                '+7'   => __('Russia', 'form-forge'),
                '+20'  => __('Egypt', 'form-forge'),
                '+27'  => __('South Africa', 'form-forge'),
                '+30'  => __('Greece', 'form-forge'),
                '+31'  => __('Netherlands', 'form-forge'),
                '+32'  => __('Belgium', 'form-forge'),
                '+33'  => __('France', 'form-forge'),
                '+34'  => __('Spain', 'form-forge'),
                '+36'  => __('Hungary', 'form-forge'),
                '+39'  => __('Italy', 'form-forge'),
                '+40'  => __('Romania', 'form-forge'),
                '+41'  => __('Switzerland', 'form-forge'),
                '+43'  => __('Austria', 'form-forge'),
                '+44'  => __('United Kingdom', 'form-forge'),
                '+45'  => __('Denmark', 'form-forge'),
                '+46'  => __('Sweden', 'form-forge'),
                '+47'  => __('Norway', 'form-forge'),
                '+48'  => __('Poland', 'form-forge'),
                '+49'  => __('Germany', 'form-forge'),
                '+51'  => __('Peru', 'form-forge'),
                '+52'  => __('Mexico', 'form-forge'),
                '+54'  => __('Argentina', 'form-forge'),
                '+55'  => __('Brazil', 'form-forge'),
                '+56'  => __('Chile', 'form-forge'),
                '+57'  => __('Colombia', 'form-forge'),
                '+61'  => __('Australia', 'form-forge'),
                '+62'  => __('Indonesia', 'form-forge'),
                '+63'  => __('Philippines', 'form-forge'),
                '+64'  => __('New Zealand', 'form-forge'),
                '+65'  => __('Singapore', 'form-forge'),
                '+66'  => __('Thailand', 'form-forge'),
                '+81'  => __('Japan', 'form-forge'),
                '+82'  => __('South Korea', 'form-forge'),
                '+84'  => __('Vietnam', 'form-forge'),
                '+86'  => __('China', 'form-forge'),
                '+90'  => __('Turkey', 'form-forge'),
                '+91'  => __('India', 'form-forge'),
                '+92'  => __('Pakistan', 'form-forge'),
                '+94'  => __('Sri Lanka', 'form-forge'),
                '+98'  => __('Iran', 'form-forge'),
                '+212' => __('Morocco', 'form-forge'),
                '+213' => __('Algeria', 'form-forge'),
                '+216' => __('Tunisia', 'form-forge'),
                '+220' => __('Gambia', 'form-forge'),
                '+234' => __('Nigeria', 'form-forge'),
                '+254' => __('Kenya', 'form-forge'),
                '+351' => __('Portugal', 'form-forge'),
                '+352' => __('Luxembourg', 'form-forge'),
                '+353' => __('Ireland', 'form-forge'),
                '+354' => __('Iceland', 'form-forge'),
                '+356' => __('Malta', 'form-forge'),
                '+357' => __('Cyprus', 'form-forge'),
                '+358' => __('Finland', 'form-forge'),
                '+359' => __('Bulgaria', 'form-forge'),
                '+370' => __('Lithuania', 'form-forge'),
                '+371' => __('Latvia', 'form-forge'),
                '+372' => __('Estonia', 'form-forge'),
                '+380' => __('Ukraine', 'form-forge'),
                '+385' => __('Croatia', 'form-forge'),
                '+386' => __('Slovenia', 'form-forge'),
                '+420' => __('Czechia', 'form-forge'),
                '+421' => __('Slovakia', 'form-forge'),
                '+423' => __('Liechtenstein', 'form-forge'),
            ],
        ];
    }
}
