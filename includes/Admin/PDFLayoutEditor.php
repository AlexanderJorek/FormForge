<?php

/**
 * Admin settings page for configuring PDF layout and branding.
 *
 * PHP Version 8.1
 *
 * @category  FormForge
 * @package   FormForge
 * @author    Alexander Jorek
 * @copyright 2026 Alexander Jorek
 * @license   https://www.gnu.org/licenses/gpl-2.0.html GPL-2.0-or-later
 * @version   1.0.0
 * @link      https://github.com/AlexanderJorek/form-forge
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2
 * of the License, or (at your option) any later version.
 */

namespace ForgeForms\Admin;

defined('ABSPATH') || exit;

/**
 * Admin editor for customizing the PDF layout (header, footer, fonts, colors).
 */
class PDFLayoutEditor
{
    private static array $section_labels = [
        'header'     => 'Kopfzeile (Logo & Titel)',
        'fields'     => 'Formularfelder',
        'signatures' => 'Unterschriften & Uploads',
        'metadata'   => 'Metadaten & Zeitstempel',
        'legal'      => 'Rechtlicher Hinweis',
        'footer'     => 'Fußzeile',
    ];

    /**
     * Returns the default PDF layout options array.
     *
     * @return array Default PDF layout options.
     */
    public static function defaults(): array
    {
        return [
            'logo_url'        => '',
            'logo_width'      => 180,
            'accent_color'    => '#f59e0b',
            'separator_color' => '#c9cdd4',
            'font_family'     => 'dejavusans',
            'font_size_body'  => 11,
            'title_size'      => 18,
            'footer_text'     => '',
            'margin_top'      => 15,
            'margin_bottom'   => 15,
            'margin_left'     => 15,
            'margin_right'    => 15,
            'section_order'   => ['header', 'fields', 'signatures', 'metadata', 'legal', 'footer'],
            'section_hidden'  => [],
            'header_layout'   => ['rows' => 8, 'elements' => []],
        ];
    }

    /**
     * Registers admin hooks for the PDF layout editor.
     *
     * @return void
     */
    public static function init(): void
    {
        add_action('admin_menu', [self::class, 'addPage']);
        add_action('admin_body_class', [self::class, 'bodyClass']);
        add_action('wp_ajax_forge_forms_pdf_preview', [self::class, 'ajaxPreview']);
        add_action('wp_ajax_forge_save_pdf_layout', [self::class, 'handleSave']);
    }

    /**
     * AJAX handler that generates and returns a PDF preview as base64.
     *
     * @return void
     */
    public static function ajaxPreview(): void
    {
        if (!\ForgeForms\Plugin::userCan('edit_pdf_layout')) {
            wp_send_json_error(['message' => 'Forbidden'], 403);
        }
        check_ajax_referer('forge_forms_admin_nonce', 'nonce');

        /* Use live settings from the request so the user doesn't have to save first */
        if (!empty($_POST['settings'])) {
            /* wp_unslash is required — WordPress's wp_magic_quotes() slashes all $_POST values */
            $raw = json_decode(wp_unslash($_POST['settings']), true);
            if (!is_array($raw)) {
                \ForgeForms\forge_log('ajaxPreview: settings JSON decode failed — ' . json_last_error_msg());
            }
            if (is_array($raw)) {
                $defs = self::defaults();
                $sanitized_hl = self::sanitizeHeaderLayout((array) ($raw['header_layout'] ?? []));
                $preview_opts = [
                    'logo_url'        => esc_url_raw($raw['logo_url'] ?? ''),
                    'logo_width'      => min(400, max(40, (int) ($raw['logo_width']     ?? 180))),
                    'accent_color'    => sanitize_hex_color($raw['accent_color']    ?? '') ?: $defs['accent_color'],
                    'separator_color' => sanitize_hex_color($raw['separator_color'] ?? '') ?: $defs['separator_color'],
                    'font_family'     => sanitize_key($raw['font_family'] ?? 'dejavusans'),
                    'font_size_body'  => min(20, max(6, (int) ($raw['font_size_body'] ?? 11))),
                    'title_size'      => min(36, max(10, (int) ($raw['title_size']     ?? 18))),
                    'footer_text'     => sanitize_textarea_field($raw['footer_text'] ?? ''),
                    'margin_top'      => min(50, max(0, (int) ($raw['margin_top']    ?? 15))),
                    'margin_bottom'   => min(50, max(0, (int) ($raw['margin_bottom'] ?? 15))),
                    'margin_left'     => min(50, max(0, (int) ($raw['margin_left']   ?? 15))),
                    'margin_right'    => min(50, max(0, (int) ($raw['margin_right']  ?? 15))),
                    'section_order'   => array_values(
                        array_filter(
                            array_map('sanitize_key', (array) ($raw['section_order'] ?? [])),
                            fn($s) => isset(self::$section_labels[$s])
                        )
                    ),
                    'section_hidden'  => array_values(
                        array_filter(
                            array_map('sanitize_key', (array) ($raw['section_hidden'] ?? [])),
                            fn($s) => isset(self::$section_labels[$s])
                        )
                    ),
                    'header_layout'   => $sanitized_hl,
                ];
                add_filter(
                    'pre_option_forge_forms_pdf_layout', static function () use ($preview_opts): array {
                        return $preview_opts;
                    }, PHP_INT_MAX
                );
            }
        }

        $dummy = self::dummyFields();

        $path = \ForgeForms\PDF\Generator::generate($dummy, 0, 'Layout-Vorschau');

        if (!$path || !file_exists($path)) {
            wp_send_json_error(['message' => 'PDF-Generierung fehlgeschlagen.'], 500);
        }

        $data = file_get_contents($path);
        @unlink($path);

        if ($data === false) {
            wp_send_json_error(['message' => 'PDF konnte nicht gelesen werden.'], 500);
        }

        wp_send_json_success(['pdf_b64' => base64_encode($data)]);
    }

    /**
     * Appends a CSS class on the PDF layout editor page.
     *
     * @param string $classes Existing admin body classes.
     *
     * @return string Modified body class string.
     */
    public static function bodyClass(string $classes): string
    {
        if (isset($_GET['page']) && $_GET['page'] === 'forge-forms-pdf-layout') {
            $classes .= ' forge-list-page';
        }
        return $classes;
    }

    /**
     * Registers the PDF-Layout submenu page and removes admin notices on it.
     *
     * @return void
     */
    public static function addPage(): void
    {
        if (!\ForgeForms\Plugin::userCan('edit_pdf_layout')) {
            return;
        }
        $hook = add_submenu_page(
            'forge-forms',
            'FormForge PDF-Layout',
            'PDF-Layout',
            'read',
            'forge-forms-pdf-layout',
            [self::class, 'render']
        );
        add_action(
            'load-' . $hook, static function (): void {
                remove_all_actions('admin_notices');
                remove_all_actions('all_admin_notices');
                remove_all_actions('user_admin_notices');
                remove_all_actions('network_admin_notices');
            }
        );
    }

    /**
     * Renders the PDF layout editor page.
     *
     * @return void
     */
    public static function render(): void
    {
        if (!\ForgeForms\Plugin::userCan('edit_pdf_layout')) {
            wp_die('Keine Berechtigung.');
        }

        wp_enqueue_media();

        $saved = false;
        if (isset($_POST['forge_pdf_layout_nonce']) 
            && wp_verify_nonce(sanitize_key($_POST['forge_pdf_layout_nonce']), 'forge_pdf_layout')
        ) {
            self::save();
            $saved = true;
        }

        $defs = self::defaults();
        $opts = array_merge($defs, (array) get_option('forge_forms_pdf_layout', []));

        if (!is_array($opts['section_order'])) {
            $opts['section_order']  = $defs['section_order'];
        }
        if (!is_array($opts['section_hidden'])) {
            $opts['section_hidden'] = $defs['section_hidden'];
        }

        foreach (array_keys(self::$section_labels) as $slug) {
            if (!in_array($slug, $opts['section_order'], true)) {
                $opts['section_order'][] = $slug;
            }
        }

        $fonts = [
            'dejavusans'     => 'DejaVu Sans (Standard, serifenlos)',
            'dejavuserif'    => 'DejaVu Serif (mit Serifen)',
            'dejavusansmono' => 'DejaVu Sans Mono (Festbreit)',
            'freemono'       => 'FreeMono (Schreibmaschine)',
        ];

        $js_opts = wp_json_encode(
            [
            'accent_color'    => $opts['accent_color'],
            'separator_color' => $opts['separator_color'],
            'font_family'     => $opts['font_family'],
            'font_size_body'  => (int) $opts['font_size_body'],
            'title_size'      => (int) $opts['title_size'],
            'footer_text'     => $opts['footer_text'],
            'margin_top'      => (int) $opts['margin_top'],
            'margin_bottom'   => (int) $opts['margin_bottom'],
            'margin_left'     => (int) $opts['margin_left'],
            'margin_right'    => (int) $opts['margin_right'],
            'section_order'   => $opts['section_order'],
            'section_hidden'  => $opts['section_hidden'],
            ]
        );

        $site_name         = esc_js(get_bloginfo('name'));
        $site_url          = esc_js(get_bloginfo('url'));
        $field_layout_mode = get_option('forge_forms_field_layout', 'block');

        /* Same sample data as the server-rendered PDF preview, so both
           previews show identical text and images. */
        $dummy_fields    = self::dummyFields();
        $dummy_text      = array_values(
            array_filter(
                $dummy_fields,
                fn($f) => in_array($f['type'], ['text', 'email', 'textarea'], true)
            )
        );
        $dummy_signature = self::dummySignaturePng();
        $dummy_upload    = self::dummyUploadPng();
        ?>
<canvas id="forge-particle-canvas"></canvas>
<div class="wrap forge-list-wrap">
    <div class="forge-title-pill"><i class="fa-solid fa-file-pdf"></i> PDF-Layout</div>
    <hr class="wp-header-end" style="display:none">

        <?php if ($saved) : ?>
        <div class="forge-settings-notice forge-settings-notice--success">
            <i class="fa-solid fa-circle-check"></i> Layout gespeichert.
        </div>
        <?php endif; ?>

    <form method="post" id="forge-pdf-layout-form">
        <?php wp_nonce_field('forge_pdf_layout', 'forge_pdf_layout_nonce'); ?>
        <input type="hidden" name="section_order" id="forge-section-order-input"
            value="<?php echo esc_attr(implode(',', $opts['section_order'])); ?>">
        <input type="hidden" name="section_hidden" id="forge-section-hidden-input"
            value="<?php echo esc_attr(implode(',', $opts['section_hidden'])); ?>">
        <input type="hidden" name="header_layout_json" id="forge-header-layout-input"
            value="<?php echo esc_attr(wp_json_encode($opts['header_layout'] ?? ['rows' => 8, 'elements' => []])); ?>">

        <div class="forge-pdf-editor-wrap">

            <!-- ── Settings Panel ── -->
            <div class="forge-pdf-settings-panel">

                <div class="forge-settings-card">
                    <h2 class="forge-settings-card-title"><i class="fa-solid fa-table-columns"></i> Kopfzeile</h2>
                    <div class="forge-settings-field">
                        <p class="forge-card-hint">
                            Titel, Logos und weitere Inhalte per Drag&nbsp;&amp;&nbsp;Drop positionieren.
                        </p>
                        <button type="button" class="button button-primary forge-hb-open-btn"
                            id="forge-open-header-builder-card">
                            <i class="fa-solid fa-pen-to-square"></i> Kopfzeile bearbeiten
                        </button>
                    </div>
                </div>

                <div class="forge-settings-card">
                    <h2 class="forge-settings-card-title"><i class="fa-solid fa-palette"></i> Farben</h2>

                    <?php
                    $color_fields = [
                        ['accent_color',    'Akzentfarbe (dicke Trennlinien)', '#f59e0b'],
                        ['separator_color', 'Trennlinienfarbe (dünne Linien)', '#c9cdd4'],
                    ];
                    foreach ($color_fields as [$id, $lbl, $default]) :
                        $eid = esc_attr($id);
                        ?>
                    <div class="forge-settings-field">
                        <label for="<?php echo $eid; ?>"><?php echo esc_html($lbl); ?></label>
                        <input type="text" id="<?php echo $eid; ?>"
                               name="<?php echo $eid; ?>"
                               value="<?php echo esc_attr($opts[$id]); ?>"
                               class="forge-iris-input"
                               data-default-color="<?php echo esc_attr($default); ?>"
                               autocomplete="off" data-lpignore="true"
                               data-1p-ignore data-bwignore spellcheck="false">
                    </div>
                    <?php endforeach; ?>
                </div>

                <div class="forge-settings-card">
                    <h2 class="forge-settings-card-title"><i class="fa-solid fa-font"></i> Typografie</h2>

                    <div class="forge-settings-field">
                        <label for="font_family">Schriftart</label>
                        <select id="font_family" name="font_family">
                            <?php foreach ($fonts as $val => $lbl) : ?>
                                <option value="<?php echo esc_attr($val); ?>"
                                    <?php selected($opts['font_family'], $val); ?>
                                ><?php echo esc_html($lbl); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="forge-settings-field">
                        <label for="font_size_body">Grundschriftgröße:
                            <span id="font-size-body-val"><?php echo (int) $opts['font_size_body']; ?></span> pt
                        </label>
                        <input type="range" id="font_size_body" name="font_size_body"
                            min="8" max="14" step="1" value="<?php echo (int) $opts['font_size_body']; ?>">
                    </div>

                    <div class="forge-settings-field">
                        <label for="title_size">Titelgröße:
                            <span id="title-size-val"><?php echo (int) $opts['title_size']; ?></span> pt
                        </label>
                        <input type="range" id="title_size" name="title_size"
                            min="12" max="28" step="1" value="<?php echo (int) $opts['title_size']; ?>">
                    </div>
                </div>

                <div class="forge-settings-card">
                    <h2 class="forge-settings-card-title">
                        <i class="fa-solid fa-arrows-left-right-to-line"></i> Seitenränder (mm)
                    </h2>
                    <div class="forge-margins-grid">
                        <?php
                        $margin_sides = [
                            'top'    => 'Oben',
                            'right'  => 'Rechts',
                            'bottom' => 'Unten',
                            'left'   => 'Links',
                        ];
                        foreach ($margin_sides as $side => $lbl) :
                            ?>
                        <div class="forge-settings-field">
                            <label for="margin_<?php echo $side; ?>"><?php echo $lbl; ?>:
                                <span id="margin-<?php echo $side; ?>-val">
                                    <?php echo (int) $opts['margin_' . $side]; ?>
                                </span> mm
                            </label>
                            <input type="range" id="margin_<?php echo $side; ?>"
                                name="margin_<?php echo $side; ?>" min="5" max="40" step="1"
                                value="<?php echo (int) $opts['margin_' . $side]; ?>">
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="forge-settings-card">
                    <h2 class="forge-settings-card-title">
                        <i class="fa-solid fa-table-list"></i> Abschnitte &amp; Reihenfolge
                    </h2>
                    <p class="forge-settings-hint" style="margin-top:0">
                        Drag &amp; Drop zum Sortieren. Augensymbol zum Ein-/Ausblenden.
                    </p>
                    <ul id="forge-sections-sortable" class="forge-sections-list">
                        <?php foreach ($opts['section_order'] as $slug) :
                            if (!isset(self::$section_labels[$slug])) {
                                continue;
                            }
                            $is_hidden = in_array($slug, $opts['section_hidden'], true);
                            ?>
                        <li class="forge-section-item<?php echo $is_hidden ? ' forge-section-hidden' : ''; ?>"
                            data-slug="<?php echo esc_attr($slug); ?>" draggable="true">
                            <i class="fa-solid fa-grip-vertical forge-drag-handle"></i>
                            <span><?php echo esc_html(self::$section_labels[$slug]); ?></span>
                            <?php if ($slug === 'header') : ?>
                            <button type="button" class="forge-section-edit-btn"
                                id="forge-open-header-builder" title="Kopfzeile bearbeiten">
                                <i class="fa-solid fa-pen-to-square"></i>
                            </button>
                            <?php endif; ?>
                            <button type="button" class="forge-section-toggle" title="Ein-/Ausblenden">
                                <i class="fa-solid <?php echo $is_hidden ? 'fa-eye-slash' : 'fa-eye'; ?>"></i>
                            </button>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </div>

                <div class="forge-settings-card">
                    <h2 class="forge-settings-card-title"><i class="fa-solid fa-shoe-prints"></i> Fußzeile</h2>
                    <div class="forge-settings-field">
                        <label for="footer_text">Fußzeilentext</label>
                        <textarea id="footer_text" name="footer_text" rows="3"
                            placeholder="z. B. Firmenname · Adresse · Telefon"
                        ><?php echo esc_textarea($opts['footer_text']); ?></textarea>
                        <div class="forge-placeholder-chips">
                            <?php foreach (['{site_name}','{site_url}','{date}'] as $token): ?>
                            <button type="button" class="forge-placeholder-chip"
                                data-insert="<?php echo esc_attr($token); ?>"><?php echo esc_html($token); ?></button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>


            </div><!-- /.forge-pdf-settings-panel -->

            <!-- ── Preview Panel ── -->
            <div class="forge-pdf-preview-panel">
                <div class="forge-preview-toolbar">
                    <span><i class="fa-solid fa-eye"></i> Vorschau (A4)</span>
                    <div style="display:flex;gap:8px;">
                        <button type="submit" class="button button-primary" form="forge-pdf-layout-form">
                            <i class="fa-solid fa-floppy-disk"></i> Speichern
                        </button>
                        <button type="button" class="button" id="forge-pdf-preview-btn">
                            <i class="fa-solid fa-file-pdf"></i> PDF öffnen
                        </button>
                    </div>
                </div>
                <div class="forge-preview-stage">
                    <div class="forge-a4-paper" id="forge-a4-paper"></div>
                </div>
            </div>

        </div><!-- /.forge-pdf-editor-wrap -->
    </form>
</div>

<!-- ── Header Builder Modal ── -->
<div id="forge-hb-modal" class="forge-hb-modal" hidden>
    <div class="forge-hb-overlay" id="forge-hb-overlay"></div>
    <div class="forge-hb-dialog">

        <div class="forge-hb-dialog-head">
            <span><i class="fa-solid fa-table-cells-large"></i> Kopfzeile bearbeiten</span>
            <button type="button" class="forge-hb-dialog-head-close"
                id="forge-hb-close" title="Schließen">&#x2715;</button>
        </div>

        <div class="forge-hb-toolbar">
            <button type="button" class="button" id="forge-hb-add-title">
                <i class="fa-solid fa-heading"></i> Titel
            </button>
            <button type="button" class="button" id="forge-hb-add-image"><i class="fa-solid fa-image"></i> Bild</button>
            <div style="width:1px;height:24px;background:#c3c4c7;margin:0 4px;"></div>
            <label>Höhe (Zeilen à 5&thinsp;mm):
                <input type="number" id="forge-hb-rows" min="2" max="30" value="8" style="width:52px">
            </label>
            <span style="font-size:11px;color:#888;margin-left:4px;">
                ← Ziehen zum Positionieren · Ecken zum Skalieren · Entf zum Löschen
            </span>
        </div>

        <div class="forge-hb-body">
            <div class="forge-hb-canvas-wrap">
                <div id="forge-hb-canvas" class="forge-hb-canvas"></div>
            </div>
            <div class="forge-hb-props" id="forge-hb-props">
                <p class="forge-hb-empty">Element auswählen<br>zum Bearbeiten</p>
            </div>
        </div>

        <div class="forge-hb-dialog-footer">
            <button type="button" class="button" id="forge-hb-cancel">Abbrechen</button>
            <button type="button" class="button button-primary" id="forge-hb-apply">
                <i class="fa-solid fa-check"></i> Übernehmen
            </button>
        </div>

    </div>
</div>

<script>
(function () {
    /* Mirrors the Settings → Feldausgabe option so the browser preview
       matches what the actual mPDF template (layout.php) renders. */
    var fieldLayoutMode = <?php echo wp_json_encode($field_layout_mode); ?>;

    /* Same sample content (text + images) as the server-rendered PDF
       preview (PDFLayoutEditor::dummyFields/dummySignaturePng/dummyUploadPng)
       so the browser preview matches the real PDF exactly. */
    var dummyTextFields = <?php echo wp_json_encode($dummy_text); ?>;
    var dummySignatureSrc = 'data:image/png;base64,'
        + <?php echo wp_json_encode($dummy_signature); ?>;
    var dummyUploadSrc = 'data:image/png;base64,'
        + <?php echo wp_json_encode($dummy_upload); ?>;

    /* Auto-dismiss save notice: wait 5 s, then fade out over 2 s */
    var notice = document.querySelector('.forge-settings-notice');
    if (notice) {
        setTimeout(function () {
            notice.style.opacity = '0';
            setTimeout(function () { notice.style.display = 'none'; }, 2000);
        }, 5000);
    }

    /* particle canvas */
    var canvas = document.getElementById('forge-particle-canvas');
    if (canvas) {
        var ctx = canvas.getContext('2d'), mouse = {x:-9999,y:-9999};
        var DOTS=80,LINK=150,SPEED=0.4,COLOR='99,132,180',particles=[];
        function resize(){ canvas.width=innerWidth; canvas.height=innerHeight; }
        function rand(a,b){ return a+Math.random()*(b-a); }
        function initP(){
            particles=[];
            for(var i=0;i<DOTS;i++) particles.push({x:rand(0,canvas.width),y:rand(0,canvas.height),vx:rand(-SPEED,SPEED),vy:rand(-SPEED,SPEED),r:rand(2,3.5)});
        }
        function draw(){
            ctx.clearRect(0,0,canvas.width,canvas.height);
            particles.forEach(function(p){p.x+=p.vx;p.y+=p.vy;if(p.x<0||p.x>canvas.width)p.vx*=-1;if(p.y<0||p.y>canvas.height)p.vy*=-1;});
            for(var i=0;i<particles.length;i++){
                for(var j=i+1;j<particles.length;j++){
                    var dx=particles[i].x-particles[j].x,dy=particles[i].y-particles[j].y,d=Math.sqrt(dx*dx+dy*dy);
                    if(d<LINK){ctx.beginPath();ctx.moveTo(particles[i].x,particles[i].y);ctx.lineTo(particles[j].x,particles[j].y);ctx.strokeStyle='rgba('+COLOR+','+(1-d/LINK)*0.3+')';ctx.lineWidth=1;ctx.stroke();}
                }
                var mdx=particles[i].x-mouse.x,mdy=particles[i].y-mouse.y,md=Math.sqrt(mdx*mdx+mdy*mdy);
                if(md<LINK){ctx.beginPath();ctx.moveTo(particles[i].x,particles[i].y);ctx.lineTo(mouse.x,mouse.y);ctx.strokeStyle='rgba('+COLOR+','+(1-md/LINK)*0.55+')';ctx.lineWidth=1;ctx.stroke();}
            }
            particles.forEach(function(p){ctx.beginPath();ctx.arc(p.x,p.y,p.r,0,Math.PI*2);ctx.fillStyle='rgba('+COLOR+',0.5)';ctx.fill();});
            requestAnimationFrame(draw);
        }
        document.addEventListener('mousemove',function(e){mouse.x=e.clientX;mouse.y=e.clientY;});
        window.addEventListener('resize',function(){resize();initP();});
        resize();initP();draw();
    }

    /* helpers */
    function $(id){ return document.getElementById(id); }
    function val(id){ var e=$(id); return e?e.value:''; }

    /* font family → CSS font-stack (approximates the mPDF font in browser preview) */
    /* Single quotes inside font names — these go into style="" attributes, so double quotes would break parsing */
    var fontMap = {
        'dejavusans':     'Arial, Helvetica, sans-serif',
        'dejavuserif':    "'Times New Roman', Times, serif",
        'dejavusansmono': "'DejaVu Sans Mono', 'Courier New', monospace",
        'freemono':       "'Courier New', Courier, monospace"
    };

    /* dummyTextFields comes from PHP (PDFLayoutEditor::dummyFields) — same
       content as the server-rendered PDF preview. */
    var sampleFields = dummyTextFields.map(function (f) {
        return {label: f.label, value: f.value};
    });

    /* mPDF renders A4 at 120 DPI: 120/25.4 = 4.7244 px/mm, 120/72 = 1.6667 px/pt */
    function mm(n){ return (n*4.7244).toFixed(1)+'px'; }
    function pt(n){ return (n*120/72).toFixed(1)+'px'; }

    function collectSettings(){
        var order=[], hidden=[];
        document.querySelectorAll('#forge-sections-sortable .forge-section-item').forEach(function(li){
            order.push(li.dataset.slug);
            if(li.classList.contains('forge-section-hidden')) hidden.push(li.dataset.slug);
        });
        return {
            accent_color:    val('accent_color')||'#f59e0b',
            separator_color: val('separator_color')||'#c9cdd4',
            font_family:     val('font_family')||'dejavusans',
            font_size_body:  parseInt(val('font_size_body'))||11,
            title_size:      parseInt(val('title_size'))||18,
            footer_text:     val('footer_text'),
            margin_top:      parseInt(val('margin_top'))||15,
            margin_bottom:   parseInt(val('margin_bottom'))||15,
            margin_left:     parseInt(val('margin_left'))||15,
            margin_right:    parseInt(val('margin_right'))||15,
            section_order:   order,
            section_hidden:  hidden
        };
    }

    var paper = $('forge-a4-paper');

    function buildPreview(s){
        var ff  = fontMap[s.font_family]||'Arial,sans-serif';
        var fs  = pt(s.font_size_body);
        var pad = mm(s.margin_top)+' '+mm(s.margin_right)+' '+mm(s.margin_bottom)+' '+mm(s.margin_left);
        /* line-height:1.7 matches mPDF's default — browsers default to ~1.2
           which makes all block heights ~22px shorter than the real PDF. */
        var out = '<div style="font-family:'+ff+';font-size:'+fs+';line-height:1.6;color:#222;padding:'+pad+';box-sizing:border-box;">';

        s.section_order.forEach(function(slug){
            if(s.section_hidden.indexOf(slug)!==-1) return;

            if(slug==='header'){
                out+='<table style="width:100%;border-collapse:collapse;margin-bottom:10px;"><tr>';
                out+='<td style="text-align:right;vertical-align:middle;font-size:'+pt(s.title_size)+';'
                    +'font-weight:bold;color:#1d2327;">Beispielformular</td>';
                out+='</tr></table>';
            }

            if(slug==='fields'){
                sampleFields.forEach(function(f){
                    /* Mirror layout.php .field-block / .field-label / .field-separator-thin
                       / .field-value / .field-separator-thick CSS exactly */
                    if(fieldLayoutMode==='inline'){
                        out+='<div style="margin-bottom:14px;">';
                        out+='<span style="font-weight:700;font-size:'+pt(s.font_size_body)+';color:#222;">'+f.label+':</span> ';
                        out+='<span style="font-size:'+pt(s.font_size_body)+';color:#333;margin-bottom:5px;">'+f.value+'</span>';
                        out+='<div style="border-bottom:3px solid '+s.accent_color+';margin-top:2px;"></div>';
                        out+='</div>';
                    } else {
                        out+='<div style="margin-bottom:14px;">';
                        out+='<div style="font-weight:700;font-size:'+pt(s.font_size_body)+';margin-bottom:4px;color:#222;">'+f.label+'</div>';
                        out+='<div style="border-bottom:1px solid '+s.separator_color+';margin-bottom:4px;"></div>';
                        out+='<div style="font-size:'+pt(s.font_size_body)+';margin-bottom:5px;color:#333;">'+f.value+'</div>';
                        out+='<div style="border-bottom:3px solid '+s.accent_color+';margin-top:2px;"></div>';
                        out+='</div>';
                    }
                });
            }

            if(slug==='signatures'){
                /* Mirror Generator.php: image rendered via layout['image'] at
                   max-width:100%;max-height:300px inside a field-block.
                   Signature suppresses the text label; upload shows filename. */
                /* width="300" height="80" = natural PNG dimensions; explicit attrs
                   ensure sandbox measurement is correct before async decode. */
                var imgStyle = 'max-width:100%;max-height:300px;border:1px solid #ccc;padding:4px;display:block;';
                /* Signature */
                out+='<div style="margin-bottom:14px;">';
                out+='<div style="font-weight:700;font-size:'+pt(s.font_size_body)+';margin-bottom:4px;color:#222;">Unterschrift</div>';
                out+='<div style="border-bottom:1px solid '+s.separator_color+';margin-bottom:4px;"></div>';
                out+='<div style="margin:8px 0;"><img src="'+dummySignatureSrc+'" width="300" height="80" style="'+imgStyle+'" alt="Unterschrift"></div>';
                out+='<div style="border-bottom:3px solid '+s.accent_color+';margin-top:2px;"></div>';
                out+='</div>';
                /* Upload / Anhang */
                out+='<div style="margin-bottom:14px;">';
                out+='<div style="font-weight:700;font-size:'+pt(s.font_size_body)+';margin-bottom:4px;color:#222;">Anhang</div>';
                out+='<div style="border-bottom:1px solid '+s.separator_color+';margin-bottom:4px;"></div>';
                out+='<div style="font-size:'+pt(s.font_size_body)+';margin-bottom:5px;color:#333;">beispiel-dokument.png</div>';
                out+='<div style="margin:8px 0;"><img src="'+dummyUploadSrc+'" width="300" height="80" style="'+imgStyle+'" alt="Anhang"></div>';
                out+='<div style="border-bottom:3px solid '+s.accent_color+';margin-top:2px;"></div>';
                out+='</div>';
            }

            if(slug==='metadata'){
                out+='<div style="margin:12px 0;padding:8px 10px;background:#f9f9f9;border:1px solid #e0e0e0;border-radius:4px;font-size:'+pt(8)+';color:#555;">';
                out+='<strong>Metadaten</strong><br>';
                out+='Erstellt: '+new Date().toLocaleString('de-DE')+'<br>';
                out+='Formular-ID: 42 &nbsp;·&nbsp; Formular: Beispielformular';
                out+='</div>';
            }

            if(slug==='legal'){
                out+='<p style="font-size:'+pt(7.5)+';color:#666;margin-top:6px;line-height:1.4;">';
                out+='<strong>Rechtlicher Hinweis:</strong> Dieses Dokument stellt das Original dar. '
                    +'Jede Änderung, Manipulation oder Modifikation macht dieses Dokument ungültig. '
                    +'Dieses Dokument wurde in elektronischer Form ausgestellt '
                    +'und ist ausschließlich in elektronischer Form aufzubewahren. '
                    +'Jeder Ausdruck ist lediglich eine Kopie und hat keine Rechtsgültigkeit.';
                out+='</p>';
            }

            /* footer is rendered as a per-page overlay in updatePreview,
               not as inline content — skip here so it doesn't paginate. */
        });

        out+='</div>';
        return out;
    }

    var PAGE_HEIGHT_PX = 1402; /* A4 at 120dpi (mPDF), matches .forge-a4-paper */

    /* Splits buildPreview()'s single (unbounded) HTML output into multiple
       A4-sized pages by measuring top-level blocks in an offscreen sandbox —
       mirrors real pagination instead of one ever-growing sheet. */
    function paginate(s, fullHtml){
        var inner = fullHtml.replace(/^<div[^>]*>/, '').replace(/<\/div>\s*$/, '');
        var ff  = fontMap[s.font_family]||'Arial,sans-serif';
        var fs  = pt(s.font_size_body);
        var pad = mm(s.margin_top)+' '+mm(s.margin_right)+' '+mm(s.margin_bottom)+' '+mm(s.margin_left);

        var parser = document.createElement('div');
        parser.innerHTML = inner;
        var blocks = Array.prototype.slice.call(parser.children).map(function(el){
            return el.outerHTML;
        });

        var sandbox = document.createElement('div');
        sandbox.style.cssText = 'position:absolute;visibility:hidden;left:-99999px;top:0;'
            +'width:992px;box-sizing:border-box;font-family:'+ff+';font-size:'+fs
            +';line-height:1.6;padding:'+pad+';';
        document.body.appendChild(sandbox);

        var pages = [];
        var current = '';
        blocks.forEach(function(blockHtml){
            var trial = current + blockHtml;
            sandbox.innerHTML = trial;
            if(sandbox.offsetHeight > PAGE_HEIGHT_PX && current !== ''){
                pages.push(current);
                current = blockHtml;
            } else {
                current = trial;
            }
        });
        if(current || pages.length===0) pages.push(current);
        document.body.removeChild(sandbox);

        return {pages: pages, ff: ff, fs: fs, pad: pad, footerText: s.footer_text||''};
    }

    function updatePreview(){
        var s = collectSettings();
        var oi = $('forge-section-order-input');
        var hi = $('forge-section-hidden-input');
        if(oi) oi.value = s.section_order.join(',');
        if(hi) hi.value = s.section_hidden.join(',');

        var stage = paper ? paper.parentElement : null;
        if(!stage) return;

        var result = paginate(s, buildPreview(s));
        var total  = result.pages.length;

        /* Pre-process footer template once — page number substituted per-page */
        var footerBase = (result.footerText||'')
            .replace(/\{site_name\}/g, '<?php echo $site_name; ?>')
            .replace(/\{site_url\}/g,  '<?php echo $site_url; ?>')
            .replace(/\{date\}/g, new Date().toLocaleDateString('de-DE'))
            .replace(/\{nbpg\}/g, total);

        stage.innerHTML = '';
        result.pages.forEach(function(pageContent, idx){
            var pageEl = document.createElement('div');
            pageEl.className = 'forge-a4-paper';
            if(idx===0) pageEl.id = 'forge-a4-paper';

            var padParts = result.pad.split(' ');
            var pRight = padParts[1]||padParts[0];
            var pBottom = padParts[2]||padParts[0];
            var pLeft  = padParts[3]||padParts[1]||padParts[0];

            /* Mirror PHP footerHtml(): user text left, page numbers right,
               always present (page numbers shown even with no user text). */
            var pageNum = idx + 1;
            var userFt  = footerBase || '';
            var pageNumHtml = 'Seite ' + pageNum + ' von ' + total;
            var footerHtml;
            if(userFt){
                footerHtml = '<table style="width:100%;border-collapse:collapse;font-size:'+pt(8)+';"><tr>'
                    +'<td style="text-align:left;color:#888;">'+userFt+'</td>'
                    +'<td style="text-align:right;white-space:nowrap;font-size:'+pt(10)+';">'
                    +pageNumHtml+'</td></tr></table>';
            } else {
                footerHtml = '<div style="text-align:right;font-size:'+pt(10)+';">'
                    +pageNumHtml+'</div>';
            }
            footerHtml = '<div style="position:absolute;bottom:0;left:0;right:0;'
                +'padding:0 '+pRight+' '+pBottom+' '+pLeft+';'
                +'background:#fff;box-sizing:border-box;">'
                +footerHtml+'</div>';

            pageEl.innerHTML = '<div style="font-family:'+result.ff+';font-size:'+result.fs+';'
                +'line-height:1.6;color:#222;padding:'+result.pad+';box-sizing:border-box;height:100%;overflow:hidden;">'
                +pageContent+'</div>'+footerHtml;
            stage.appendChild(pageEl);
        });
        paper = document.getElementById('forge-a4-paper');
    }

    /* range sliders */
    [
        ['font_size_body', 'font-size-body-val'],
        ['title_size',     'title-size-val'],
        ['margin_top',     'margin-top-val'],
        ['margin_right',   'margin-right-val'],
        ['margin_bottom',  'margin-bottom-val'],
        ['margin_left',    'margin-left-val'],
    ].forEach(function(pair){
        var inp = $(pair[0]), lbl = $(pair[1]);
        if(!inp||!lbl) return;
        inp.addEventListener('input', function(){ lbl.textContent=inp.value; updatePreview(); });
    });


    /* font & textarea */
    var fontSel = $('font_family'), footerTxt = $('footer_text');
    if(fontSel)  fontSel.addEventListener('change', updatePreview);
    if(footerTxt) footerTxt.addEventListener('input', updatePreview);

    /* Placeholder chips — insert token at cursor position */
    document.querySelectorAll('.forge-placeholder-chip').forEach(function(btn){
        btn.addEventListener('click', function(){
            var token = btn.dataset.insert;
            if(!footerTxt) return;
            footerTxt.focus();
            var start = footerTxt.selectionStart, end = footerTxt.selectionEnd;
            var val   = footerTxt.value;
            footerTxt.value = val.slice(0,start) + token + val.slice(end);
            footerTxt.selectionStart = footerTxt.selectionEnd = start + token.length;
            updatePreview();
        });
    });



    /* drag-and-drop section sorting */
    var sortable = $('forge-sections-sortable');
    var dragSrc  = null;

    if(sortable){
        sortable.addEventListener('dragstart', function(e){
            dragSrc = e.target.closest('.forge-section-item');
            if(dragSrc){ dragSrc.classList.add('forge-dragging'); e.dataTransfer.effectAllowed='move'; }
        });
        sortable.addEventListener('dragend', function(){
            document.querySelectorAll('.forge-section-item').forEach(function(li){
                li.classList.remove('forge-dragging','forge-drag-over');
            });
            dragSrc=null;
            updatePreview();
        });
        sortable.addEventListener('dragover', function(e){
            e.preventDefault();
            e.dataTransfer.dropEffect='move';
            var target = e.target.closest('.forge-section-item');
            if(!target||target===dragSrc) return;
            document.querySelectorAll('.forge-section-item').forEach(function(li){ li.classList.remove('forge-drag-over'); });
            target.classList.add('forge-drag-over');
            var rect = target.getBoundingClientRect();
            if(e.clientY < rect.top+rect.height/2){
                sortable.insertBefore(dragSrc,target);
            } else {
                sortable.insertBefore(dragSrc,target.nextSibling);
            }
        });

        sortable.addEventListener('click', function(e){
            var btn = e.target.closest('.forge-section-toggle');
            if(!btn) return;
            var li  = btn.closest('.forge-section-item');
            var ico = btn.querySelector('i');
            if(li.classList.toggle('forge-section-hidden')){
                ico.className='fa-solid fa-eye-slash';
            } else {
                ico.className='fa-solid fa-eye';
            }
            updatePreview();
        });
    }

    /* ── PDF preview button ── */
    var pdfBtn = $('forge-pdf-preview-btn');
    if(pdfBtn){
        pdfBtn.addEventListener('click', function(){
            pdfBtn.disabled = true;
            pdfBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Generiere…';
            var fd = new FormData();
            fd.append('action', 'forge_forms_pdf_preview');
            fd.append('nonce',  '<?php echo esc_js(wp_create_nonce("forge_forms_admin_nonce")); ?>');
            fd.append('settings', JSON.stringify(collectSettings()));
            fetch('<?php echo esc_js(admin_url("admin-ajax.php")); ?>', {method:'POST', body:fd})
                .then(function(r){ return r.json(); })
                .then(function(resp){
                    if(resp.success && resp.data.pdf_b64){
                        var bin  = atob(resp.data.pdf_b64);
                        var buf  = new Uint8Array(bin.length);
                        for(var i=0;i<bin.length;i++) buf[i]=bin.charCodeAt(i);
                        var blob = new Blob([buf],{type:'application/pdf'});
                        var url  = URL.createObjectURL(blob);
                        window.open(url,'_blank');
                        setTimeout(function(){ URL.revokeObjectURL(url); }, 30000);
                    } else {
                        alert((resp.data && resp.data.message) || 'Fehler beim Generieren.');
                    }
                })
                .catch(function(){ alert('Netzwerkfehler.'); })
                .finally(function(){
                    pdfBtn.disabled = false;
                    pdfBtn.innerHTML = '<i class="fa-solid fa-file-pdf"></i> PDF öffnen';
                });
        });
    }

    /* ================================================================
     * Header Builder
     * ================================================================ */
    var HB_COLS = 42;   /* A4 at 5 mm/cell */
    var HB_CELL = 15;   /* px per cell */
    var hbLayout   = { rows: 8, elements: [] };
    /* Initialize from saved DB value immediately so preview works without opening the modal */
    (function(){
        var inp = document.getElementById('forge-header-layout-input');
        if(inp && inp.value){ try{ hbLayout = JSON.parse(inp.value); }catch(e){} }
        if(!hbLayout || !Array.isArray(hbLayout.elements)) hbLayout = { rows:8, elements:[] };
    })();
    var hbSnapshot = null; /* for cancel */
    var hbSel      = null; /* selected element id */
    var hbNextId   = 1;
    var hbDrag     = null;
    /* hbDrag = { type:'move'|'resize', dir, elId, mx0, my0, ex0, ey0, ew0, eh0 } */

    var hbModal  = document.getElementById('forge-hb-modal');
    var hbCanvas = document.getElementById('forge-hb-canvas');
    var hbProps  = document.getElementById('forge-hb-props');

    function hbEsc(s){ return String(s||'').replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
    function hbGetEl(id){ return hbLayout.elements.find(function(e){ return e.id===id; }); }

    function hbOpen(){
        var inp = document.getElementById('forge-header-layout-input');
        if(inp && inp.value){ try{ hbLayout = JSON.parse(inp.value); }catch(e){} }
        if(!hbLayout || !Array.isArray(hbLayout.elements)) hbLayout = { rows:8, elements:[] };
        hbSnapshot = JSON.parse(JSON.stringify(hbLayout));
        hbSel = null;
        hbNextId = hbLayout.elements.reduce(function(m,e){ return Math.max(m, parseInt((e.id||'0').replace(/\D/g,''))||0); }, 0) + 1;
        var ri = document.getElementById('forge-hb-rows');
        if(ri) ri.value = hbLayout.rows || 8;
        hbRender();
        hbRenderProps();
        if(hbModal) hbModal.hidden = false;
    }

    function hbClose(){ if(hbModal) hbModal.hidden = true; hbDrag = null; }

    function hbCancel(){
        if(hbSnapshot) hbLayout = JSON.parse(JSON.stringify(hbSnapshot));
        hbClose();
    }

    function hbApply(){
        var inp = document.getElementById('forge-header-layout-input');
        if(inp) inp.value = JSON.stringify(hbLayout);
        hbClose();
        updatePreview();
    }

    function hbRender(){
        if(!hbCanvas) return;
        var rows = Math.max(2, hbLayout.rows||8);
        hbCanvas.style.width  = (HB_COLS * HB_CELL) + 'px';
        hbCanvas.style.height = (rows    * HB_CELL) + 'px';
        hbCanvas.innerHTML = '';
        hbLayout.elements.forEach(function(el){ hbCanvas.appendChild(hbMakeNode(el)); });
    }

    function hbMakeNode(el){
        var node = document.createElement('div');
        node.className = 'forge-hb-el' + (hbSel===el.id ? ' forge-hb-el--selected' : '');
        node.dataset.id = el.id;
        node.style.cssText = 'left:'+(el.x*HB_CELL)+'px;top:'+(el.y*HB_CELL)+'px;width:'+(el.w*HB_CELL)+'px;height:'+(el.h*HB_CELL)+'px;';

        /* label */
        var lbl = document.createElement('div');
        lbl.className = 'forge-hb-el-label';
        lbl.textContent = el.type === 'title' ? 'Titel' : el.type === 'image' ? 'Bild' : 'HTML';
        node.appendChild(lbl);

        /* inner content */
        var inner = document.createElement('div');
        inner.className = 'forge-hb-el-inner';
        if(el.type==='image' && el.src){
            inner.innerHTML = '<img src="'+hbEsc(el.src)+'" style="width:100%;height:auto;max-height:100%;display:block;">';
        } else if(el.type==='title'){
            var tcnt = (el.content||el.text||'{form_title}').replace(/\{form_title\}/g,'Beispielformular');
            inner.innerHTML = '<div style="width:100%;height:100%;display:flex;align-items:center;padding:2px 4px;box-sizing:border-box;">'
                +'<span style="width:100%;font-size:'+(el.size||18)+'pt;color:'+hbEsc(el.color||'#1d2327')+';text-align:'+hbEsc(el.align||'left')+';">'
                +tcnt+'</span></div>';
        } else if(el.type==='html'){
            inner.innerHTML = el.html || '<span style="color:#aaa;font-size:10px;padding:4px">[HTML]</span>';
        } else {
            inner.innerHTML = '<span style="color:#aaa;font-size:10px;padding:4px">['+el.type+']</span>';
        }
        node.appendChild(inner);

        /* resize handles (only when selected) */
        if(hbSel===el.id){
            ['nw','n','ne','w','e','sw','s','se'].forEach(function(dir){
                var h = document.createElement('div');
                h.className = 'forge-hb-handle forge-hb-handle--'+dir;
                h.dataset.dir = dir;
                node.appendChild(h);
            });
        }

        node.addEventListener('mousedown', function(e){
            e.preventDefault(); e.stopPropagation();
            hbSel = el.id;
            if(e.target.dataset && e.target.dataset.dir){
                hbDrag = { type:'resize', dir:e.target.dataset.dir, elId:el.id,
                    mx0:e.clientX, my0:e.clientY, ex0:el.x, ey0:el.y, ew0:el.w, eh0:el.h };
            } else {
                hbDrag = { type:'move', elId:el.id,
                    mx0:e.clientX, my0:e.clientY, ex0:el.x, ey0:el.y };
            }
            hbRender(); hbRenderProps();
        });
        return node;
    }

    function hbSyncPosInputs(el){
        if(!el||!hbProps) return;
        hbProps.querySelectorAll('input[data-p]').forEach(function(inp){
            var p=inp.dataset.p;
            if(p==='x'||p==='y'||p==='w'||p==='h') inp.value=el[p];
        });
    }

    document.addEventListener('mousemove', function(e){
        if(!hbDrag) return;
        var dx = Math.round((e.clientX - hbDrag.mx0) / HB_CELL);
        var dy = Math.round((e.clientY - hbDrag.my0) / HB_CELL);
        var el = hbGetEl(hbDrag.elId);
        if(!el) return;

        var maxRows = Math.max(2, hbLayout.rows||8);
        if(hbDrag.type==='move'){
            el.x = Math.max(0, Math.min(HB_COLS - el.w, hbDrag.ex0 + dx));
            el.y = Math.max(0, Math.min(maxRows - el.h, hbDrag.ey0 + dy));
        } else {
            var dir=hbDrag.dir, nx=hbDrag.ex0, ny=hbDrag.ey0, nw=hbDrag.ew0, nh=hbDrag.eh0;
            if(dir.indexOf('e')>=0) nw = Math.max(1, hbDrag.ew0 + dx);
            if(dir.indexOf('s')>=0) nh = Math.max(1, Math.min(maxRows - hbDrag.ey0, hbDrag.eh0 + dy));
            if(dir.indexOf('w')>=0){ nx = hbDrag.ex0+dx; nw = Math.max(1, hbDrag.ew0-dx); }
            if(dir.indexOf('n')>=0){ ny = hbDrag.ey0+dy; nh = Math.max(1, hbDrag.eh0-dy); }
            el.x = Math.max(0, Math.min(HB_COLS-1, nx));
            el.y = Math.max(0, Math.min(maxRows-1, ny));
            el.w = Math.min(HB_COLS-el.x, Math.max(1, nw));
            el.h = Math.min(maxRows-el.y, Math.max(1, nh));
        }
        hbRender();
        hbSyncPosInputs(el);
    });

    document.addEventListener('mouseup', function(){ if(hbDrag){ hbDrag=null; hbRender(); } });

    /* click canvas background → deselect */
    if(hbCanvas){
        hbCanvas.addEventListener('mousedown', function(e){
            if(e.target===hbCanvas){ hbSel=null; hbRender(); hbRenderProps(); }
        });
    }

    /* Delete key (ENTF) — removes selected element */
    document.addEventListener('keydown', function(e){
        if(!hbModal||hbModal.hidden) return;
        if(e.key!=='Delete') return;
        var t=document.activeElement;
        if(t&&(t.tagName==='INPUT'||t.tagName==='TEXTAREA'||t.tagName==='SELECT'||t.isContentEditable)) return;
        if(!hbSel) return;
        hbLayout.elements = hbLayout.elements.filter(function(el){ return el.id!==hbSel; });
        hbSel=null; hbRender(); hbRenderProps();
        e.preventDefault();
    });

    function hbAdd(type){
        var rows = Math.max(2, hbLayout.rows||8);
        var el = { id:'e'+(hbNextId++), type:type, x:0, y:0,
            w: HB_COLS, h: rows };
        if(type==='title'){ el.content='{form_title}'; el.size=18; el.align='right'; el.color='#1d2327'; }
        else if(type==='html'){ el.html=''; }
        hbLayout.elements.push(el);
        hbSel=el.id; hbRender(); hbRenderProps();
    }

    function hbAddImageWithSrc(src, natW, natH){
        var rows = Math.max(2, hbLayout.rows||8);
        var w = 8;
        var h = (natW && natH) ? Math.max(1, Math.min(rows, Math.round(natH / natW * w))) : Math.min(4, rows);
        var el = { id:'e'+(hbNextId++), type:'image', x:0, y:0, w:w, h:h, src:src, fit:'contain' };
        hbLayout.elements.push(el);
        hbSel=el.id; hbRender(); hbRenderProps();
    }

    function hbOpenMediaPicker(onSelect){
        if(!window.wp || !wp.media){ var u=prompt('Bild-URL:'); if(u) onSelect(u,0,0); return; }
        var fr=wp.media({title:'Bild wählen',button:{text:'Einfügen'},multiple:false});
        fr.on('open',function(){
            var wrap=document.querySelector('.media-modal'), over=document.querySelector('.media-modal-backdrop');
            if(wrap) wrap.style.zIndex='200000';
            if(over) over.style.zIndex='199999';
        });
        fr.on('select',function(){
            var att=fr.state().get('selection').first().toJSON();
            onSelect(att.url, att.width||0, att.height||0);
        });
        fr.open();
    }

    function hbPickImageAndAdd(){
        /* Show inline picker in props panel — user chooses URL or media library */
        if(!hbProps) return;
        hbSel = null;
        hbProps.innerHTML = '<div class="forge-hb-img-picker">'
            +'<p class="forge-hb-img-picker-title"><i class="fa-solid fa-image"></i> Bild hinzufügen</p>'
            +'<button type="button" class="button button-primary" id="hb-pick-media" style="width:100%">'
            +'<i class="fa-solid fa-photo-film"></i> Aus Mediathek wählen</button>'
            +'<div class="forge-hb-img-picker-sep"><span>oder</span></div>'
            +'<div class="forge-hb-prop-group"><span>Externe URL</span>'
            +'<input type="text" id="hb-pick-url" placeholder="https://…" style="margin-bottom:4px">'
            +'<button type="button" class="button" id="hb-pick-url-confirm" style="width:100%">Einfügen</button>'
            +'</div>'
            +'<button type="button" class="button" id="hb-pick-cancel" style="width:100%;margin-top:8px;color:#646970">Abbrechen</button>'
            +'</div>';

        document.getElementById('hb-pick-media').addEventListener('click', function(){
            hbOpenMediaPicker(function(url,w,h){ hbAddImageWithSrc(url,w,h); });
        });
        document.getElementById('hb-pick-url-confirm').addEventListener('click', function(){
            var u = document.getElementById('hb-pick-url').value.trim();
            if(u) hbAddImageWithSrc(u,0,0);
            else document.getElementById('hb-pick-url').focus();
        });
        document.getElementById('hb-pick-url').addEventListener('keydown', function(e){
            if(e.key==='Enter'){ var u=this.value.trim(); if(u) hbAddImageWithSrc(u,0,0); }
        });
        document.getElementById('hb-pick-cancel').addEventListener('click', function(){
            hbRenderProps();
        });
    }

    function hbRenderProps(){
        if(!hbProps) return;
        var el = hbSel ? hbGetEl(hbSel) : null;
        if(!el){ hbProps.innerHTML='<p class="forge-hb-empty">Element auswählen<br>zum Bearbeiten</p>'; return; }

        var h='<div class="forge-hb-card"><div class="forge-hb-prop-row2">'
            +'<div class="forge-hb-prop-group"><span>X</span><input type="number" data-p="x" value="'+el.x+'" min="0" max="41"></div>'
            +'<div class="forge-hb-prop-group"><span>Y</span><input type="number" data-p="y" value="'+el.y+'" min="0"></div>'
            +'<div class="forge-hb-prop-group"><span>Breite</span><input type="number" data-p="w" value="'+el.w+'" min="1" max="42"></div>'
            +'<div class="forge-hb-prop-group"><span>Höhe</span><input type="number" data-p="h" value="'+el.h+'" min="1"></div>'
            +'</div></div>';

        if(el.type==='image'){
            /* Image: picker first, then position/size, then fit */
            h=''; /* reset — image skips the shared X/Y/W/H block above */
            h+='<div class="forge-hb-card"><div class="forge-hb-prop-row2">'
                +'<div class="forge-hb-prop-group"><span>X</span><input type="number" data-p="x" value="'+el.x+'" min="0" max="41"></div>'
                +'<div class="forge-hb-prop-group"><span>Y</span><input type="number" data-p="y" value="'+el.y+'" min="0"></div>'
                +'<div class="forge-hb-prop-group"><span>Breite</span><input type="number" data-p="w" value="'+el.w+'" min="1" max="42"></div>'
                +'<div class="forge-hb-prop-group"><span>Höhe</span><input type="number" data-p="h" value="'+el.h+'" min="1"></div>'
                +'</div></div>';
            h+='<div class="forge-hb-card forge-hb-card--image">'
                +'<div class="forge-hb-img-preview">'
                +(el.src ? '<img src="'+hbEsc(el.src)+'" style="max-width:100%;max-height:80px;display:block;border-radius:3px;">' : '<span style="color:#aaa;font-size:11px;">Kein Bild gewählt</span>')
                +'</div>'
                +'<button type="button" class="button" id="hb-media-pick" style="width:100%">'
                +'<i class="fa-solid fa-upload"></i> '+(el.src?'Bild ändern':'Aus Mediathek wählen')+'</button>'
                +'<input type="text" data-p="src" value="'+hbEsc(el.src||'')+'" placeholder="oder URL eingeben …">'
                +'</div>';
            h+='<div class="forge-hb-card">'
                +'<div class="forge-hb-fit-btns">'
                +'<button type="button" class="forge-hb-fit-btn'+((!el.fit||el.fit==='contain')?' forge-hb-fit-btn--active':'')+'" data-fit="contain">Einpassen</button>'
                +'<button type="button" class="forge-hb-fit-btn'+(el.fit==='cover'?' forge-hb-fit-btn--active':'')+'" data-fit="cover">Füllen</button>'
                +'<button type="button" class="forge-hb-fit-btn'+(el.fit==='fill'?' forge-hb-fit-btn--active':'')+'" data-fit="fill">Strecken</button>'
                +'</div>'
                +'</div>';
        } else if(el.type==='title'){
            var _al = (!el.align||el.align==='left') ? ' forge-hb-tb-btn--active' : '';
            var _ac = el.align==='center' ? ' forge-hb-tb-btn--active' : '';
            var _ar = el.align==='right'  ? ' forge-hb-tb-btn--active' : '';
            h+='<div class="forge-hb-card"><div class="forge-hb-fmt-toolbar">'
                /* Rich-text format buttons — execCommand, use data-cmd */
                +'<button type="button" class="forge-hb-tb-btn" data-cmd="bold" title="Fett"><i class="fa-solid fa-bold"></i></button>'
                +'<button type="button" class="forge-hb-tb-btn" data-cmd="italic" title="Kursiv"><i class="fa-solid fa-italic"></i></button>'
                /* Underline split button */
                +'<div class="forge-hb-tb-split">'
                +'<button type="button" class="forge-hb-tb-btn" data-cmd="underline" title="Unterstreichen"><i class="fa-solid fa-underline"></i></button>'
                +'<button type="button" class="forge-hb-tb-btn forge-hb-tb-chevron" data-action="ul-menu" title="Unterstreichungsart"><i class="fa-solid fa-chevron-down"></i></button>'
                +'<div class="forge-hb-ul-menu" hidden>'
                +'<button type="button" data-ul-style="solid"><span class="forge-hb-ul-prev forge-hb-ul-solid"></span>Durchgehend</button>'
                +'<button type="button" data-ul-style="double"><span class="forge-hb-ul-prev forge-hb-ul-double"></span>Doppelt</button>'
                +'<button type="button" data-ul-style="dotted"><span class="forge-hb-ul-prev forge-hb-ul-dotted"></span>Gepunktet</button>'
                +'<button type="button" data-ul-style="dashed"><span class="forge-hb-ul-prev forge-hb-ul-dashed"></span>Gestrichelt</button>'
                +'</div></div>'
                +'<button type="button" class="forge-hb-tb-btn" data-cmd="strikeThrough" title="Durchgestrichen"><i class="fa-solid fa-strikethrough"></i></button>'
                +'<div class="forge-hb-tb-sep"></div>'
                +'<button type="button" class="forge-hb-tb-btn" data-valign="super" title="Hochgestellt"><i class="fa-solid fa-superscript"></i></button>'
                +'<button type="button" class="forge-hb-tb-btn" data-valign="sub" title="Tiefgestellt"><i class="fa-solid fa-subscript"></i></button>'
                +'<div class="forge-hb-tb-sep"></div>'
                /* Alignment — element-level, use data-p */
                +'<button type="button" class="forge-hb-tb-btn'+_al+'" data-p="align" data-val="left" title="Links"><i class="fa-solid fa-align-left"></i></button>'
                +'<button type="button" class="forge-hb-tb-btn'+_ac+'" data-p="align" data-val="center" title="Mitte"><i class="fa-solid fa-align-center"></i></button>'
                +'<button type="button" class="forge-hb-tb-btn'+_ar+'" data-p="align" data-val="right" title="Rechts"><i class="fa-solid fa-align-right"></i></button>'
                +'<div class="forge-hb-tb-sep"></div>'
                /* Size + colour — element-level */
                +(function(){
                    var sizes=[8,9,10,11,12,14,16,18,20,24,28,32,36,48,72];
                    var cur=el.size||18;
                    var s='<select class="forge-hb-tb-size-sel" data-sz title="Schriftgröße">';
                    sizes.forEach(function(n){
                        s+='<option value="'+n+'"'+(n===cur?' selected':'')+'>'+n+' pt</option>';
                    });
                    return s+'</select>';
                }())
                +'<input type="color" data-p="color" value="'+hbEsc(el.color||'#1d2327')+'" class="forge-hb-tb-color" title="Farbe">'
                +'</div>';
            h+='<div class="forge-hb-prop-group forge-hb-prop-group--editor"><span>Text</span>'
                +'<div class="forge-hb-title-editor" contenteditable="true" spellcheck="false" '
                +'style="font-size:'+(el.size||18)+'pt;color:'+hbEsc(el.color||'#1d2327')+';text-align:'+hbEsc(el.align||'left')+';">'
                +(el.content||el.text||'{form_title}')
                +'</div></div>'
                +'</div>';
        } else if(el.type==='html'){
            h+='<div class="forge-hb-prop-group"><span>HTML-Code</span><textarea data-p="html"></textarea></div>';
            h+='<p style="font-size:11px;color:#888;margin:0">HTML wird direkt gerendert. Kein Script-Tag.</p>';
        }

        h+='<div style="padding-top:10px;border-top:1px solid #e2e4e7;">'
            +'<button type="button" class="button" id="hb-delete-btn" style="color:#d63638;width:100%"><i class="fa-solid fa-trash"></i> Element löschen</button>'
            +'</div>';

        hbProps.innerHTML = h;

        /* Set textarea value safely (avoids HTML injection via innerHTML) */
        if(el.type==='html'){
            var ta = hbProps.querySelector('textarea[data-p="html"]');
            if(ta) ta.value = el.html||'';
        }

        /* Rich-text editor (title element) */
        var titleEd   = hbProps.querySelector('.forge-hb-title-editor');
        var savedRange = null;

        /* Save selection whenever focus might leave the editor */
        if(titleEd){
            hbProps.addEventListener('mousedown', function(e){
                if(titleEd.contains(e.target)) return;
                var sel=window.getSelection();
                if(sel && sel.rangeCount && titleEd.contains(sel.anchorNode)){
                    savedRange = sel.getRangeAt(0).cloneRange();
                }
            }, true);
        }

        function hbRestoreRange(){
            if(!savedRange || !titleEd) return false;
            titleEd.focus();
            var sel=window.getSelection();
            sel.removeAllRanges();
            sel.addRange(savedRange);
            return !savedRange.collapsed;
        }

        function hbApplySpan(props){
            if(!titleEd) return;
            var hasRange=hbRestoreRange();
            if(!hasRange){ return; }
            var sel=window.getSelection();
            var range=sel.getRangeAt(0);
            var span=document.createElement('span');
            Object.keys(props).forEach(function(k){
                if(k.indexOf('data-')===0) span.setAttribute(k, props[k]);
                else span.style[k]=props[k];
            });
            try{ range.surroundContents(span); }
            catch(ex){
                var frag=range.extractContents();
                span.appendChild(frag);
                range.insertNode(span);
            }
            el.content=titleEd.innerHTML; hbRender();
        }

        /* execCommand buttons — mousedown+preventDefault keeps editor focus intact */
        hbProps.querySelectorAll('button[data-cmd]').forEach(function(btn){
            btn.addEventListener('mousedown', function(e){
                e.preventDefault();
                if(titleEd) titleEd.focus();
                document.execCommand(btn.dataset.cmd, false, null);
                setTimeout(function(){
                    if(titleEd){ el.content=titleEd.innerHTML; hbRender(); hbSyncToolbarState(); }
                }, 0);
            });
        });

        /* Superscript / Subscript — use spans so line-height is unaffected */
        hbProps.querySelectorAll('button[data-valign]').forEach(function(btn){
            btn.addEventListener('mousedown', function(e){
                e.preventDefault();
                var va = btn.dataset.valign;
                hbRestoreRange();
                var sel = window.getSelection();
                if(!sel || !sel.rangeCount || !titleEd) return;

                /* Find all existing [data-va=va] spans that overlap the selection */
                var existing = Array.prototype.slice.call(
                    titleEd.querySelectorAll('span[data-va="'+va+'"]')
                ).filter(function(s){
                    return sel.containsNode(s, true);
                });

                if(existing.length){
                    /* Unwrap every matched span */
                    existing.forEach(function(s){
                        var frag=document.createDocumentFragment();
                        while(s.firstChild) frag.appendChild(s.firstChild);
                        s.parentNode.replaceChild(frag, s);
                    });
                } else if(!sel.getRangeAt(0).collapsed){
                    hbApplySpan({verticalAlign:va, fontSize:'75%', 'data-va':va});
                }
                el.content=titleEd.innerHTML; hbRender(); hbSyncToolbarState();
            });
        });

        /* Underline-style split button */
        var ulChevron = hbProps.querySelector('[data-action="ul-menu"]');
        var ulMenu    = hbProps.querySelector('.forge-hb-ul-menu');
        if(ulChevron && ulMenu){
            ulChevron.addEventListener('mousedown', function(e){
                e.preventDefault(); ulMenu.hidden=!ulMenu.hidden;
            });
            ulMenu.querySelectorAll('[data-ul-style]').forEach(function(opt){
                opt.addEventListener('mousedown', function(e){
                    e.preventDefault();
                    ulMenu.hidden=true;
                    if(!titleEd) return;
                    titleEd.focus();
                    var sel=window.getSelection();
                    if(sel && sel.rangeCount && !sel.getRangeAt(0).collapsed){
                        var range=sel.getRangeAt(0);
                        var span=document.createElement('span');
                        span.style.cssText='text-decoration:underline;text-decoration-style:'+opt.dataset.ulStyle;
                        try{ range.surroundContents(span); }
                        catch(ex){ document.execCommand('underline',false,null); }
                    } else {
                        document.execCommand('underline',false,null);
                    }
                    el.content=titleEd.innerHTML; hbRender();
                });
            });
            /* Close menu on outside click */
            document.addEventListener('mousedown', function hbUlClose(e){
                if(!ulMenu.contains(e.target) && e.target!==ulChevron){
                    ulMenu.hidden=true;
                    document.removeEventListener('mousedown', hbUlClose);
                }
            });
        }

        /* Alignment & other element-level toolbar buttons (data-p on <button>) */
        hbProps.querySelectorAll('button[data-p]').forEach(function(btn){
            btn.addEventListener('mousedown', function(e){
                e.preventDefault();
                el[btn.dataset.p]=btn.dataset.val;
                if(titleEd) titleEd.style.textAlign=el.align||'left';
                hbRender(); hbRenderProps();
            });
        });

        /* Font-size select: per-selection if text is selected, else element-level */
        var szSel=hbProps.querySelector('[data-sz]');
        if(szSel){
            szSel.addEventListener('change', function(){
                var sz=parseInt(szSel.value)||18;
                var hadRange=hbRestoreRange();
                if(hadRange){
                    hbApplySpan({fontSize:sz+'pt'});
                } else {
                    el.size=sz;
                    if(titleEd) titleEd.style.fontSize=sz+'pt';
                    hbRender();
                }
            });
        }

        /* Live toolbar state (bold/italic/underline active when cursor is inside) */
        function hbSyncToolbarState(){
            if(!titleEd) return;
            ['bold','italic','underline','strikeThrough'].forEach(function(cmd){
                var btn=hbProps.querySelector('button[data-cmd="'+cmd+'"]');
                if(!btn) return;
                try{ btn.classList.toggle('forge-hb-tb-btn--active',
                    document.queryCommandState(cmd)); }
                catch(ex){}
            });
            /* valign: active if cursor is inside OR selection contains a [data-va] span */
            ['super','sub'].forEach(function(va){
                var btn=hbProps.querySelector('button[data-valign="'+va+'"]');
                if(!btn) return;
                var sel=window.getSelection();
                var active=false;
                if(sel&&sel.rangeCount){
                    /* cursor inside a span? */
                    var node=sel.anchorNode;
                    if(node&&node.nodeType===3) node=node.parentNode;
                    if(node&&node.closest) active=!!node.closest('span[data-va="'+va+'"]');
                    /* selection contains a span? */
                    if(!active&&titleEd){
                        active=Array.prototype.some.call(
                            titleEd.querySelectorAll('span[data-va="'+va+'"]'),
                            function(s){ return sel.containsNode(s,true); }
                        );
                    }
                }
                btn.classList.toggle('forge-hb-tb-btn--active', active);
            });
        }
        document.addEventListener('selectionchange', function hbSC(){
            if(!titleEd || !document.body.contains(titleEd)){
                document.removeEventListener('selectionchange', hbSC); return;
            }
            var sel=window.getSelection();
            if(sel&&sel.rangeCount&&titleEd.contains(sel.anchorNode)){
                hbSyncToolbarState();
            }
        });

        /* Color input: per-selection when text is selected, element-level otherwise */
        hbProps.querySelectorAll('input[data-p="color"]').forEach(function(inp){
            inp.addEventListener('change', function(){
                var v=inp.value;
                var hadRange=hbRestoreRange();
                if(hadRange){
                    hbApplySpan({color:v});
                } else {
                    el.color=v;
                    if(titleEd) titleEd.style.color=v;
                    hbRender();
                }
            });
        });

        /* Other element-level inputs (grid coords etc.) */
        hbProps.querySelectorAll('input[data-p]:not([data-p="color"])').forEach(function(inp){
            var ev = inp.type==='color' ? 'change' : 'input';
            inp.addEventListener(ev, function(){
                var p=inp.dataset.p, v;
                if(inp.type==='number') v=parseInt(inp.value)||0;
                else v=inp.value;
                el[p]=v;
                if(titleEd){
                    if(p==='size') titleEd.style.fontSize=v+'pt';
                }
                /* clamp grid coords */
                el.x=Math.max(0,Math.min(HB_COLS-el.w,el.x));
                el.y=Math.max(0,el.y);
                el.w=Math.max(1,Math.min(HB_COLS-el.x,el.w));
                el.h=Math.max(1,el.h);
                hbRender();
            });
        });

        /* Image fit buttons */
        hbProps.querySelectorAll('button[data-fit]').forEach(function(btn){
            btn.addEventListener('click', function(){
                el.fit=btn.dataset.fit;
                hbProps.querySelectorAll('button[data-fit]').forEach(function(b){
                    b.classList.toggle('forge-hb-fit-btn--active', b===btn);
                });
                hbRender();
            });
        });

        /* Select inputs (remaining selects) */
        hbProps.querySelectorAll('select[data-p]').forEach(function(sel){
            sel.addEventListener('change', function(){
                el[sel.dataset.p]=sel.value; hbRender();
            });
        });

        /* Contenteditable live update */
        if(titleEd){
            titleEd.addEventListener('input', function(){
                el.content=titleEd.innerHTML; hbRender();
            });
        }

        /* Media picker (change image button inside props) */
        var mpBtn=document.getElementById('hb-media-pick');
        if(mpBtn) mpBtn.addEventListener('click', function(){
            hbOpenMediaPicker(function(url){ el.src=url; hbRender(); hbRenderProps(); });
        });

        /* Delete */
        var delBtn=document.getElementById('hb-delete-btn');
        if(delBtn) delBtn.addEventListener('click',function(){
            hbLayout.elements=hbLayout.elements.filter(function(e){ return e.id!==hbSel; });
            hbSel=null; hbRender(); hbRenderProps();
        });
    }

    /* Wire up toolbar / dialog buttons */
    ['forge-open-header-builder','forge-open-header-builder-card'].forEach(function(id){
        var btn=document.getElementById(id);
        if(btn) btn.addEventListener('click', hbOpen);
    });

    ['forge-hb-close','forge-hb-cancel'].forEach(function(id){
        var btn=document.getElementById(id);
        if(btn) btn.addEventListener('click', hbCancel);
    });

    var hbOverlay=document.getElementById('forge-hb-overlay');
    if(hbOverlay) hbOverlay.addEventListener('click', hbCancel);

    var hbApplyBtn=document.getElementById('forge-hb-apply');
    if(hbApplyBtn) hbApplyBtn.addEventListener('click', hbApply);

    var hbAddTitle=document.getElementById('forge-hb-add-title');
    if(hbAddTitle) hbAddTitle.addEventListener('click',function(){ hbAdd('title'); });
    var hbAddImage=document.getElementById('forge-hb-add-image');
    if(hbAddImage) hbAddImage.addEventListener('click', hbPickImageAndAdd);

    var hbRowsInp=document.getElementById('forge-hb-rows');
    if(hbRowsInp) hbRowsInp.addEventListener('input',function(){
        hbLayout.rows=Math.max(2,Math.min(30,parseInt(this.value)||8));
        hbRender();
    });

    /* ── Update collectSettings to include header_layout ── */
    /* Always read from the live hbLayout variable so preview reflects unsaved canvas changes */
    var _origCollect = collectSettings;
    collectSettings = function(){
        var s = _origCollect();
        s.header_layout = JSON.parse(JSON.stringify(hbLayout));
        return s;
    };

    /* ── Update buildPreview to render header from layout ── */
    var _origBuild = buildPreview;
    buildPreview = function(s){
        var hl = s.header_layout;
        if(hl && Array.isArray(hl.elements) && hl.elements.length > 0){
            /* patch: replace the header section with grid-based render */
            var _origS = s;
            /* Temporarily wrap to intercept header slug */
            var patched = false;
            var result = '';
            var order = s.section_order || [];
            var hidden = s.section_hidden || [];
            var ff = (function(){ var fm={'dejavusans':'Arial,Helvetica,sans-serif','dejavuserif':"'Times New Roman',Times,serif",'dejavusansmono':"'DejaVu Sans Mono','Courier New',monospace",'freemono':"'Courier New',Courier,monospace"}; return fm[s.font_family]||'Arial,sans-serif'; })();
            var fs = pt(s.font_size_body);
            var pad = mm(s.margin_top)+' '+mm(s.margin_right)+' '+mm(s.margin_bottom)+' '+mm(s.margin_left);
            result += '<div style="font-family:'+ff+';font-size:'+fs+';color:#222;padding:'+pad+';box-sizing:border-box;">';
            order.forEach(function(slug){
                if(hidden.indexOf(slug)!==-1) return;
                if(slug==='header'){
                    /*
                     * Render header at builder canvas scale (HB_COLS*HB_CELL px wide) then
                     * CSS-scale it to fit the preview paper width. This means image aspect
                     * ratios match the builder exactly — no empty space from object-fit:contain
                     * at a different viewport width.
                     */
                    var canvasW  = HB_COLS * HB_CELL;
                    var marginPx = parseFloat(mm(s.margin_left)) + parseFloat(mm(s.margin_right));
                    var paperW   = paper ? Math.max(1, paper.offsetWidth - marginPx) : canvasW;
                    var scale    = paperW / canvasW; /* always fill content width; height scales proportionally */
                    var hpx = 0;
                    hl.elements.forEach(function(el){ var b=(el.y+el.h)*HB_CELL; if(b>hpx) hpx=b; });
                    hpx = Math.max(HB_CELL, hpx);
                    var scaledH = Math.ceil(hpx * scale);
                    result += '<div style="overflow:hidden;margin-bottom:4px;height:'+scaledH+'px;">';
                    result += '<div style="position:relative;width:'+canvasW+'px;height:'+hpx+'px;transform-origin:left top;transform:scale('+scale.toFixed(6)+')">';
                    hl.elements.forEach(function(el){
                        var lp  = (el.x * HB_CELL)+'px';
                        var tp  = (el.y * HB_CELL)+'px';
                        var wp2 = (el.w * HB_CELL)+'px';
                        var hp2 = (el.h * HB_CELL)+'px';
                        result += '<div style="position:absolute;left:'+lp+';top:'+tp+';width:'+wp2+';height:'+hp2+';overflow:hidden;">';
                        if(el.type==='image' && el.src){
                            result += '<img src="'+hbEsc(el.src)
                                +'" style="width:100%;height:auto;max-height:100%;display:block;">';
                        } else if(el.type==='title'){
                            var pcnt = (el.content||el.text||'{form_title}').replace(/\{form_title\}/g,'Beispielformular');
                            result += '<div style="width:100%;height:100%;display:flex;align-items:center;">'
                                +'<span style="width:100%;font-size:'+(el.size||18)+'pt;color:'+hbEsc(el.color||'#1d2327')+';text-align:'+hbEsc(el.align||'left')+';">'+pcnt+'</span></div>';
                        }
                        result += '</div>';
                    });
                    result += '</div></div>';
                    patched = true;
                    return;
                }
                /* all other sections: delegate to original build */
                var fakeS = Object.assign({}, s, { section_order:[slug], section_hidden:[] });
                /* extract just this section's HTML by calling original with single-section order */
                var chunk = _origBuild(fakeS);
                /* strip the outer wrapper div that _origBuild adds */
                chunk = chunk.replace(/^<div[^>]*>/, '').replace(/<\/div>\s*$/, '');
                result += chunk;
            });
            result += '</div>';
            return result;
        }
        return _origBuild(s);
    };

    window.forgePdfUpdatePreview = updatePreview;
    updatePreview();
}());

/* ---- AJAX save for #forge-pdf-layout-form (no page reload) ---- */
(function(){
    var form = document.getElementById('forge-pdf-layout-form');
    if (!form) return;
    function showNotice(msg, isError) {
        var existing = document.querySelector('.forge-settings-notice');
        if (existing) existing.remove();
        var n = document.createElement('div');
        n.className = 'forge-settings-notice forge-settings-notice--'
            + (isError ? 'error' : 'success');
        n.innerHTML = '<i class="fa-solid fa-'
            + (isError ? 'circle-xmark' : 'circle-check') + '"></i> ' + msg;
        var topbar = document.querySelector('.forge-settings-topbar');
        var ref = topbar || form;
        ref.parentNode.insertBefore(n, ref);
        n.scrollIntoView({behavior:'smooth', block:'nearest'});
        if (!isError) {
            setTimeout(function() {
                n.style.transition = 'opacity .4s';
                n.style.opacity = '0';
                setTimeout(function() { n.remove(); }, 420);
            }, 3000);
        }
    }
    form.addEventListener('submit', function(e){
        e.preventDefault();
        var btn = document.querySelector('[form="forge-pdf-layout-form"][type="submit"]')
            || form.querySelector('button[type="submit"]');
        var origHtml = btn ? btn.innerHTML : '';
        if (btn) { btn.disabled = true; btn.innerHTML = '<span class="forge-spinner"></span> Speichern…'; }
        var fd = new FormData(form);
        fd.set('action', 'forge_save_pdf_layout');
        requestAnimationFrame(function(){ requestAnimationFrame(function(){
        fetch('<?php echo esc_js(admin_url('admin-ajax.php')); ?>', {method:'POST', body:fd})
            .then(function(r){ return r.json(); })
            .then(function(data){
                if (btn) { btn.disabled = false; btn.innerHTML = origHtml; }
                if (data.success) {
                    showNotice(data.data.message, false);
                } else {
                    showNotice((data.data && data.data.message) || 'Fehler beim Speichern.', true);
                }
            })
            .catch(function(){
                if (btn) { btn.disabled = false; btn.innerHTML = origHtml; }
                showNotice('Netzwerkfehler.', true);
            });
        }); }); // requestAnimationFrame double-frame
    });
}());
</script>
        <?php
    }

    /**
     * AJAX handler that saves PDF layout settings.
     *
     * @return void
     */
    public static function handleSave(): void
    {
        if (!\ForgeForms\Plugin::userCan('edit_pdf_layout')) {
            wp_send_json_error(['message' => 'Forbidden'], 403);
        }
        check_ajax_referer('forge_pdf_layout', 'forge_pdf_layout_nonce');
        self::save();
        wp_send_json_success(['message' => 'Layout gespeichert.']);
    }

    /**
     * Saves PDF layout settings from POST data.
     *
     * @return void
     */
    private static function save(): void
    {
        $defs = self::defaults();
        $labels = self::$section_labels;

        $order = array_values(
            array_filter(
                array_map('sanitize_key', explode(',', wp_unslash($_POST['section_order'] ?? ''))),
                fn($s) => isset($labels[$s])
            )
        );
        $hidden = array_values(
            array_filter(
                array_map('sanitize_key', explode(',', wp_unslash($_POST['section_hidden'] ?? ''))),
                fn($s) => isset($labels[$s])
            )
        );

        if (empty($order)) {
            $order = $defs['section_order'];
        }

        update_option(
            'forge_forms_pdf_layout', [
            'logo_url'        => esc_url_raw(wp_unslash($_POST['logo_url'] ?? '')),
            'logo_width'      => min(400, max(40, (int) ($_POST['logo_width'] ?? 180))),
            'accent_color'    => sanitize_hex_color($_POST['accent_color']    ?? '') ?: $defs['accent_color'],
            'separator_color' => sanitize_hex_color($_POST['separator_color'] ?? '') ?: $defs['separator_color'],
            'font_family'     => sanitize_key($_POST['font_family'] ?? 'dejavusans'),
            'font_size_body'  => min(20, max(6, (int) ($_POST['font_size_body'] ?? 11))),
            'title_size'      => min(36, max(10, (int) ($_POST['title_size']     ?? 18))),
            'footer_text'     => sanitize_textarea_field(wp_unslash($_POST['footer_text'] ?? '')),
            'margin_top'      => min(50, max(0, (int) ($_POST['margin_top']    ?? 15))),
            'margin_bottom'   => min(50, max(0, (int) ($_POST['margin_bottom'] ?? 15))),
            'margin_left'     => min(50, max(0, (int) ($_POST['margin_left']   ?? 15))),
            'margin_right'    => min(50, max(0, (int) ($_POST['margin_right']  ?? 15))),
            'section_order'   => $order,
            'section_hidden'  => $hidden,
            'header_layout'   => self::sanitizeHeaderLayout(
                json_decode(wp_unslash($_POST['header_layout_json'] ?? '{}'), true) ?: []
            ),
            ]
        );
    }

    /**
     * Sanitizes the header layout grid configuration.
     *
     * @param array $raw Raw header layout data from POST.
     *
     * @return array Sanitized header layout array.
     */
    private static function sanitizeHeaderLayout(array $raw): array
    {
        $rows = min(30, max(2, (int) ($raw['rows'] ?? 8)));
        $elements = [];
        foreach ((array) ($raw['elements'] ?? []) as $el) {
            $type = sanitize_key($el['type'] ?? '');
            if (!in_array($type, ['title', 'image', 'html'], true)) {
                continue;
            }
            $item = [
                'id'   => sanitize_key($el['id'] ?? 'e1'),
                'type' => $type,
                'x'    => max(0, min(41, (int) ($el['x'] ?? 0))),
                'y'    => max(0, (int) ($el['y'] ?? 0)),
                'w'    => max(1, min(42, (int) ($el['w'] ?? 10))),
                'h'    => max(1, (int) ($el['h'] ?? 4)),
            ];
            if ($type === 'title') {
                $item['text']  = sanitize_text_field($el['text']  ?? '{form_title}');
                $item['size']  = min(72, max(6, (int) ($el['size'] ?? 18)));
                $item['bold']  = !empty($el['bold']);
                $item['align'] = in_array($el['align'] ?? '', ['left', 'center', 'right'], true) ? $el['align'] : 'left';
                $item['color'] = sanitize_hex_color($el['color'] ?? '') ?: '#1d2327';
            } elseif ($type === 'image') {
                $item['src'] = esc_url_raw($el['src'] ?? '');
                $item['fit'] = in_array($el['fit'] ?? '', ['contain', 'cover', 'fill'], true) ? $el['fit'] : 'contain';
            } elseif ($type === 'html') {
                $item['html'] = wp_kses_post($el['html'] ?? '');
            }
            $elements[] = $item;
        }
        return ['rows' => $rows, 'elements' => $elements];
    }

    /**
     * Sample submission data shared by the server-rendered PDF preview and
     * the browser-side HTML preview, so both show identical content. Sized
     * to run roughly 1.5–2 A4 pages at the default layout settings.
     *
     * @return array Dummy mapped-field entries.
     */
    public static function dummyFields(): array
    {
        $nachricht = 'Beispieltext für die PDF-Vorschau. Dieser Absatz '
            . 'demonstriert, wie längere Freitext-Eingaben im fertigen '
            . 'Dokument umbrechen und wie viel Platz sie einnehmen. Er '
            . 'enthält mehrere Sätze, damit sich die Vorschau über eine '
            . 'realistische Textmenge erstreckt, wie sie auch bei echten '
            . 'Formular-Einsendungen vorkommt.';
        $anmerkungen = 'Hier folgt ein weiterer Beispielabsatz mit '
            . 'zusätzlichen Anmerkungen, damit die Vorschau insgesamt '
            . 'mehrere Seiten umfasst und Layout-Einstellungen wie Ränder, '
            . 'Schriftgröße und Abschnittsreihenfolge realistisch '
            . 'beurteilt werden können.';

        return [
            ['type' => 'text', 'label' => 'Vorname', 'value' => 'Max'],
            ['type' => 'text', 'label' => 'Nachname', 'value' => 'Mustermann'],
            [
                'type'  => 'email',
                'label' => 'E-Mail',
                'value' => 'max.mustermann@example.de',
            ],
            ['type' => 'text', 'label' => 'Telefon', 'value' => '+49 151 23456789'],
            [
                'type'  => 'text',
                'label' => 'Anschrift',
                'value' => 'Musterstraße 12, 10115 Berlin',
            ],
            ['type' => 'text', 'label' => 'Geburtsdatum', 'value' => '14.03.1990'],
            ['type' => 'text', 'label' => 'Anliegen', 'value' => 'Mitgliedsantrag'],
            [
                'type'  => 'text',
                'label' => 'Mitgliedschaftsart',
                'value' => 'Fördermitglied',
            ],
            ['type' => 'textarea', 'label' => 'Nachricht', 'value' => $nachricht],
            [
                'type'  => 'textarea',
                'label' => 'Zusätzliche Anmerkungen',
                'value' => $anmerkungen,
            ],
            [
                'type'  => 'signature',
                'label' => 'Unterschrift',
                'value' => '[Beispielunterschrift]',
                'materialized_files' => [[
                    'name'   => 'unterschrift.png',
                    'mime'   => 'image/png',
                    'base64' => self::dummySignaturePng(),
                ]],
            ],
            [
                'type'  => 'upload',
                'label' => 'Anhang',
                'value' => 'beispiel-dokument.png',
                'materialized_files' => [[
                    'name'   => 'beispiel-dokument.png',
                    'mime'   => 'image/png',
                    'base64' => self::dummyUploadPng(),
                ]],
            ],
        ];
    }

    /**
     * Generate a 300×80 PNG showing a handwriting-style squiggle (signature placeholder).
     * Falls back to a solid-colour rectangle if GD is unavailable.
     *
     * @return string Base64-encoded dummy signature PNG.
     */
    private static function dummySignaturePng(): string
    {
        if (!function_exists('imagecreatetruecolor')) {
            return self::dummyFallbackPng();
        }
        $img   = imagecreatetruecolor(300, 80);
        $white = imagecolorallocate($img, 255, 255, 255);
        $gray  = imagecolorallocate($img, 210, 210, 210);
        $ink   = imagecolorallocate($img, 30, 30, 30);
        imagefill($img, 0, 0, $white);
        imagerectangle($img, 0, 0, 299, 79, $gray);
        /* Simple squiggle path */
        $pts = [20,55, 45,25, 70,50, 95,30, 120,55, 150,20, 180,50, 210,35, 240,55, 270,30, 290,45];
        for ($i = 0; $i < count($pts) - 2; $i += 2) {
            imageline($img, $pts[$i], $pts[$i + 1], $pts[$i + 2], $pts[$i + 3], $ink);
        }
        ob_start();
        imagepng($img);
        $raw = ob_get_clean();
        imagedestroy($img);
        return base64_encode((string) $raw);
    }

    /**
     * Generate a 300×80 PNG showing a document icon (upload placeholder).
     *
     * @return string Base64-encoded dummy upload PNG.
     */
    private static function dummyUploadPng(): string
    {
        if (!function_exists('imagecreatetruecolor')) {
            return self::dummyFallbackPng();
        }
        $img   = imagecreatetruecolor(300, 80);
        $white = imagecolorallocate($img, 255, 255, 255);
        $gray  = imagecolorallocate($img, 210, 210, 210);
        $blue  = imagecolorallocate($img, 80, 120, 200);
        $dark  = imagecolorallocate($img, 60, 60, 60);
        imagefill($img, 0, 0, $white);
        imagerectangle($img, 0, 0, 299, 79, $gray);
        /* Document shape */
        imagerectangle($img, 110, 10, 160, 70, $blue);
        /* Folded corner */
        imageline($img, 148, 10, 160, 22, $blue);
        imageline($img, 148, 10, 148, 22, $blue);
        imageline($img, 148, 22, 160, 22, $blue);
        /* Lines representing text */
        imagefilledrectangle($img, 117, 30, 153, 32, $dark);
        imagefilledrectangle($img, 117, 38, 153, 40, $dark);
        imagefilledrectangle($img, 117, 46, 140, 48, $dark);
        /* Label */
        imagestring($img, 2, 170, 32, 'Anhang', $dark);
        ob_start();
        imagepng($img);
        $raw = ob_get_clean();
        imagedestroy($img);
        return base64_encode((string) $raw);
    }

    /**
     * Minimal valid 1×1 white PNG for environments without GD.
     *
     * @return string Base64-encoded 1×1 white PNG fallback.
     */
    private static function dummyFallbackPng(): string
    {
        return 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwADhQGAWjR9awAAAABJRU5ErkJggg==';
    }
}
