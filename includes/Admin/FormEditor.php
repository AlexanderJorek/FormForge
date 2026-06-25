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

namespace ForgeForms\Admin;

defined('ABSPATH') || exit;

use ForgeForms\Form\FormModel;
use ForgeForms\Fields\FieldRegistry;

class FormEditor
{
    public static function init(): void
    {
        \add_action('admin_menu', [self::class, 'menu']);
        \add_action('wp_ajax_forge_forms_save_form', [self::class, 'ajaxSave']);
        \add_action('wp_ajax_forge_forms_preview', [self::class, 'ajaxPreview']);
        \add_filter('admin_body_class', [self::class, 'bodyClass']);
    }

    public static function bodyClass(string $classes): string
    {
        if (isset($_GET['page']) && $_GET['page'] === 'forge-forms-editor') {
            $classes .= ' forge-editor-page';
        }
        return $classes;
    }

    public static function menu(): void
    {
        if (\ForgeForms\Plugin::userCan('edit_forms')) {
            \add_submenu_page(
                'forge-forms',
                'FormForge Bearbeitung',
                'Neues Formular',
                'read',
                'forge-forms-editor',
                [self::class, 'render']
            );
        }
    }

    public static function render(): void
    {
        if (!\ForgeForms\Plugin::userCan('edit_forms')) {
            \wp_die('Keine Berechtigung.');
        }

        $form_id = (int)($_GET['form_id'] ?? 0);
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
                'title'         => 'Neues Formular',
                'fields'        => [],
                'notifications' => [self::defaultNotification()],
                'settings'      => [
                    'submit_label'    => 'Absenden',
                    'success_message' => 'Vielen Dank für Ihren Eintrag!',
                ],
            ];
        }

        $nonce    = \wp_create_nonce('forge_forms_admin_nonce');
        $data_form    = \wp_json_encode($form_data, JSON_HEX_APOS | JSON_HEX_QUOT);
        $data_palette = \wp_json_encode($palette, JSON_HEX_APOS | JSON_HEX_QUOT);
        $ajax_url     = \esc_attr(\admin_url('admin-ajax.php'));
        ?>
        <canvas id="forge-particle-canvas"></canvas>
        <div class="wrap forge-editor-wrap" style="padding:0;margin:0;">
        <div id="forge-editor"
             data-form='<?php echo $data_form; ?>'
             data-palette='<?php echo $data_palette; ?>'
             data-nonce="<?php echo \esc_attr($nonce); ?>"
             data-ajax-url="<?php echo $ajax_url; ?>">

            <div id="forge-canvas">
                <div id="forge-canvas-header">
                    <div id="forge-header-brand">
                        <i class="fa-solid fa-table-list"></i>
                        <span>FormForge</span>
                    </div>
                    <div id="forge-header-divider"></div>
                    <input id="forge-form-name" type="text" value="" />
                    <span id="forge-save-status"></span>
                    <?php if ($form_id) : ?>
                    <button id="forge-preview-btn" type="button" title="Vorschau">
                        <i class="fa-solid fa-eye"></i> Vorschau
                    </button>
                    <?php endif; ?>
                    <button id="forge-save-btn" type="button">Speichern</button>
                </div>

                <div id="forge-canvas-tabs">
                    <button class="forge-tab-btn forge-tab-active" data-tab="forge-fields-panel">Felder</button>
                    <button class="forge-tab-btn" data-tab="forge-notifications-panel">Benachrichtigungen</button>
                </div>

                <div id="forge-fields-panel" class="forge-tab-panel forge-panel-active">
                    <div id="forge-field-list"></div>
                    <div id="forge-submit-preview-bar"></div>
                    <div id="forge-add-field-bar">
                        <button id="forge-add-field-btn" type="button">
                            <i class="fa-solid fa-plus"></i> Feld hinzuf&uuml;gen
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
            var DOTS = 80, LINK = 150, SPEED = 0.4, COLOR = '99, 132, 180';
            var particles = [];
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
                ctx.clearRect(0, 0, canvas.width, canvas.height);
                particles.forEach(function(p) {
                    p.x += p.vx; p.y += p.vy;
                    if (p.x < 0 || p.x > canvas.width)  p.vx *= -1;
                    if (p.y < 0 || p.y > canvas.height) p.vy *= -1;
                });
                for (var i = 0; i < particles.length; i++) {
                    for (var j = i + 1; j < particles.length; j++) {
                        var dx = particles[i].x - particles[j].x, dy = particles[i].y - particles[j].y;
                        var d = Math.sqrt(dx*dx + dy*dy);
                        if (d < LINK) {
                            ctx.beginPath(); ctx.moveTo(particles[i].x, particles[i].y);
                            ctx.lineTo(particles[j].x, particles[j].y);
                            ctx.strokeStyle = 'rgba(' + COLOR + ',' + (1 - d/LINK) * 0.3 + ')';
                            ctx.lineWidth = 1; ctx.stroke();
                        }
                    }
                    var mdx = particles[i].x - mouse.x, mdy = particles[i].y - mouse.y;
                    var md = Math.sqrt(mdx*mdx + mdy*mdy);
                    if (md < LINK) {
                        ctx.beginPath(); ctx.moveTo(particles[i].x, particles[i].y);
                        ctx.lineTo(mouse.x, mouse.y);
                        ctx.strokeStyle = 'rgba(' + COLOR + ',' + (1 - md/LINK) * 0.55 + ')';
                        ctx.lineWidth = 1; ctx.stroke();
                    }
                }
                particles.forEach(function(p) {
                    ctx.beginPath(); ctx.arc(p.x, p.y, p.r, 0, Math.PI*2);
                    ctx.fillStyle = 'rgba(' + COLOR + ', 0.5)'; ctx.fill();
                });
                requestAnimationFrame(draw);
            }
            document.addEventListener('mousemove', function(e) { mouse.x = e.clientX; mouse.y = e.clientY; });
            window.addEventListener('resize', function() { resize(); init(); });
            resize(); init(); draw();
        }());
        </script>
        <?php
    }

    public static function ajaxPreview(): void
    {
        if (!\ForgeForms\Plugin::userCan('edit_forms')) {
            \wp_send_json_error(['message' => 'Forbidden'], 403);
        }
        \check_ajax_referer('forge_forms_admin_nonce', 'nonce');

        $form_id = (int)($_POST['form_id'] ?? 0);
        if (!$form_id) {
            \wp_send_json_error(['message' => 'No form ID'], 400);
        }

        /* Merge any unsaved settings from the builder into the form before rendering. */
        $settings_override = [];
        if (!empty($_POST['settings'])) {
            $raw_s = json_decode(\wp_unslash($_POST['settings']), true);
            if (is_array($raw_s)) {
                $settings_override = self::sanitizeSettings($raw_s);
            }
        }

        $html = \ForgeForms\Form\FormRenderer::render($form_id, $settings_override);
        if (!$html) {
            \wp_send_json_error(['message' => 'Formular nicht gefunden.'], 404);
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
            . 'i18n:{submitting:"Wird gesendet…",error_server:"Serverfehler."}'
            . '};';
        if (!empty($inits))        { $globals .= 'window.ForgeFieldInits={'   . implode(',', $inits)        . '};'; }
        if (!empty($pairs))        { $globals .= 'window.ForgeValidators={'    . implode(',', $pairs)        . '};'; }
        if (!empty($empty_checks)) { $globals .= 'window.ForgeEmptyChecks={'  . implode(',', $empty_checks) . '};'; }
        if (!empty($skip))         { $globals .= 'window.ForgeSkipValidation=[' . implode(',', $skip)       . '];'; }

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
            . '<strong>Pflichtfelder ignorieren</strong>'
            . '</label>'
            . '</div>'
            . '</div>';

        $toolbar_js = '(function(){

/* ── Skip-required toggle ── */
var cb = document.getElementById("fpt-skip-required");
if (cb) {
    var origSkip = (window.ForgeSkipValidation || []).slice();
    cb.addEventListener("change", function () {
        if (this.checked) {
            var types = [];
            document.querySelectorAll(".forge-field").forEach(function (f) {
                var m = f.className.match(/forge-field--(\S+)/);
                if (m && types.indexOf(m[1]) === -1) types.push(m[1]);
            });
            window.ForgeSkipValidation = types;
        } else {
            window.ForgeSkipValidation = origSkip;
        }
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
            . '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">'
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

    public static function ajaxSave(): void
    {
        if (!\ForgeForms\Plugin::userCan('edit_forms')) {
            \wp_send_json_error(['message' => 'Forbidden'], 403);
        }
        \check_ajax_referer('forge_forms_admin_nonce', 'nonce');

        $raw = json_decode(\wp_unslash($_POST['form_data'] ?? ''), true);
        if (!is_array($raw)) {
            \wp_send_json_error(['message' => 'Invalid form data'], 400);
        }

        $form_id = (int)($raw['id'] ?? 0);
        $result  = FormModel::save([
            'title'         => \sanitize_text_field($raw['title']              ?? ''),
            'fields'        => self::sanitizeFields($raw['fields']             ?? []),
            'notifications' => self::sanitizeNotifications($raw['notifications'] ?? []),
            'settings'      => self::sanitizeSettings($raw['settings']         ?? []),
        ], $form_id);

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

        \wp_send_json_success([
            'message' => 'Formular gespeichert.',
            'form_id' => $result,
        ]);
    }

    private static function sanitizeFields(array $fields): array
    {
        $clean = [];
        foreach ($fields as $field) {
            if (!is_array($field) || empty($field['id']) || empty($field['type'])) {
                continue;
            }
            $plaintext_keys = ['id', 'type', 'label', 'placeholder', 'description', 'hint', 'name'];
            $f = [];
            foreach ($field as $k => $v) {
                $sk = \sanitize_key($k);
                if (is_string($v)) {
                    $f[$sk] = in_array($k, $plaintext_keys, true)
                        ? \sanitize_text_field($v)
                        : \wp_kses_post($v);
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

    private static function sanitizeNotifications(array $notifications): array
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
                    'field_id' => \sanitize_key($rule['field_id'] ?? ''),
                    'operator' => \sanitize_key($rule['operator'] ?? 'equals'),
                    'value'    => \sanitize_text_field($rule['value'] ?? ''),
                    'email'    => \sanitize_email($rule['email'] ?? ''),
                ];
            }
            $clean[] = [
                'slug'             => \sanitize_key($n['slug'] ?? ('notification-' . \wp_generate_uuid4())),
                'name'             => \sanitize_text_field($n['name']       ?? ''),
                'recipient_mode'   => in_array($n['recipient_mode'] ?? '', ['single', 'routing'], true)
                    ? $n['recipient_mode'] : 'single',
                'to'               => \sanitize_text_field($n['to']         ?? ''),
                'routing_rules'    => $routing_rules,
                'routing_fallback' => \sanitize_email($n['routing_fallback'] ?? ''),
                'reply_to'         => \sanitize_text_field($n['reply_to']   ?? ''),
                'subject'          => \sanitize_text_field($n['subject']    ?? ''),
                'body'             => \wp_kses_post($n['body']              ?? ''),
                'body_html'        => !empty($n['body_html']),
                'from_name'        => \sanitize_text_field($n['from_name']  ?? ''),
                'from_email'       => \sanitize_email($n['from_email']      ?? ''),
                'cc'               => \sanitize_text_field($n['cc']         ?? ''),
                'bcc'              => \sanitize_text_field($n['bcc']        ?? ''),
                'attach_pdf'       => !empty($n['attach_pdf']),
                'attach_uploads'   => !empty($n['attach_uploads']),
                'enabled'          => !isset($n['enabled']) || !empty($n['enabled']),
            ];
        }
        return $clean;
    }

    private static function sanitizeSettings(array $settings): array
    {
        $rules = [];
        foreach ((array)($settings['submit_conditions']['rules'] ?? []) as $rule) {
            if (!is_array($rule)) {
                continue;
            }
            $rules[] = [
                'field_id'   => \sanitize_key($rule['field_id']   ?? ''),
                'operator'   => \sanitize_key($rule['operator']   ?? 'equals'),
                'value'      => \sanitize_text_field($rule['value'] ?? ''),
                'use_option' => !empty($rule['use_option']),
            ];
        }

        return [
            'submit_label'      => \sanitize_text_field($settings['submit_label']    ?? 'Absenden'),
            'submit_working'    => \sanitize_text_field($settings['submit_working']   ?? 'Wird gesendet…'),
            'success_message'   => \wp_kses_post($settings['success_message']         ?? 'Vielen Dank!'),
            'submit_conditions' => [
                'enabled' => !empty($settings['submit_conditions']['enabled']),
                'match'   => in_array($settings['submit_conditions']['match'] ?? '', ['all', 'any'], true)
                    ? $settings['submit_conditions']['match'] : 'all',
                'rules'   => $rules,
            ],
        ];
    }

    private static function defaultNotification(): array
    {
        return [
            'slug'             => 'notification-1',
            'name'             => 'Benachrichtigung 1',
            'recipient_mode'   => 'single',
            'to'               => \get_option('admin_email', ''),
            'routing_rules'    => [],
            'routing_fallback' => '',
            'subject'          => 'Neuer Eintrag: {form_title}',
            'body'             => "Ein neuer Eintrag ist eingegangen.\n\n{all_fields}",
            'body_html'        => false,
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
