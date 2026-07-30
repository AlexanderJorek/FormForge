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
     *
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
                    <?php if ($form_id) : ?>
                    <button id="forge-preview-btn" type="button" title="<?php echo esc_attr__('Preview', 'form-forge'); ?>">
                        <i class="fa-solid fa-eye"></i> <?php echo esc_html__('Preview', 'form-forge'); ?>
                    </button>
                    <?php endif; ?>
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
        if (!$form_id) {
            \wp_send_json_error(['message' => 'No form ID'], 400);
        }

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

        $page = '<!DOCTYPE html><html lang="de"><head>'
            . '<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>Vorschau</title>'
            . '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/'
            . \ForgeForms\Utils\Assets::FONT_AWESOME_VERSION . '/css/all.min.css"'
            . ' integrity="' . \ForgeForms\Utils\Assets::FONT_AWESOME_SRI . '"'
            . ' crossorigin="anonymous" referrerpolicy="no-referrer">'
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

        $form_id   = (int)($raw['id'] ?? 0);
        $raw_title = $raw['title'] ?? '';
        $result    = FormModel::save(
            [
            'title'         => \sanitize_text_field(is_string($raw_title) ? $raw_title : ''),
            'fields'        => self::sanitizeFields($raw['fields']             ?? []),
            'notifications' => self::sanitizeNotifications($raw['notifications'] ?? []),
            'settings'      => self::sanitizeSettings($raw['settings']         ?? []),
            ],
            $form_id
        );

        if (\is_wp_error($result)) {
            \wp_send_json_error(['message' => $result->get_error_message()], 500);
        }

        /* Save PDF attachment settings from notifications */
        $pdf_settings = \get_option('forge_forms_pdf_settings', []);
        foreach ($raw['notifications'] ?? [] as $notif) {
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
     *
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
                    $f[$sk] = $v;
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
     * Sanitizes the notifications array.
     *
     * @param array $notifications Raw notifications array.
     *
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
     *
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
     * Sanitizes an HTML email body for admin-authored notification templates.
     *
     * Uses targeted regex instead of wp_kses because wp_kses passes all style
     * attributes through safecss_filter_attr, which drops valid email CSS
     * properties (overflow, border-radius, display:inline-block, etc.).
     * Only genuinely dangerous constructs are removed: script elements,
     * inline event-handler attributes, and javascript:/vbscript: URIs.
     *
     * @param string $html Raw HTML body from the notification editor.
     *
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
            $after = preg_replace($pattern, $replacements[$label], $html);
            if ($after !== $html) {
                preg_match_all($pattern, $html, $m);
                \ForgeForms\forge_log(
                    'ForgeForms sanitizeEmailBody [' . $label . '] removed '
                    . count($m[0]) . ' match(es): '
                    . substr(implode(' | ', $m[0]), 0, 300)
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
        $html = preg_replace_callback(
            '/\b(href|src|action|style)\s*=\s*(?:(["\'])((?:(?!\2).)*)\2|([^\s>]+))/is',
            static function (array $m): string {
                $attr    = $m[1];
                $quoted  = $m[2] !== '';
                $q       = $quoted ? $m[2] : '';
                $val     = $quoted ? $m[3] : ($m[4] ?? '');
                $decoded = html_entity_decode($val, ENT_QUOTES | ENT_HTML5, 'UTF-8');
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

        if ($html !== $before) {
            \ForgeForms\forge_log(
                'ForgeForms sanitizeEmailBody: input length '
                . strlen($before) . ' → output length ' . strlen($html)
            );
        } else {
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
}
