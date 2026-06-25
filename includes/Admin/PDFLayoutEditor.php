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

    public static function defaults(): array
    {
        return [
            'logo_url'        => '',
            'logo_width'      => 180,
            'primary_color'   => '#1a3a5c',
            'accent_color'    => '#4a7fc1',
            'separator_color' => '#aaaaaa',
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

    public static function init(): void
    {
        add_action('admin_menu', [self::class, 'addPage']);
        add_action('admin_body_class', [self::class, 'bodyClass']);
        add_action('wp_ajax_forge_forms_pdf_preview', [self::class, 'ajaxPreview']);
    }

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
                    'primary_color'   => sanitize_hex_color($raw['primary_color']   ?? '') ?: $defs['primary_color'],
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
                    'section_order'   => array_values(array_filter(
                        array_map('sanitize_key', (array) ($raw['section_order'] ?? [])),
                        fn($s) => isset(self::$section_labels[$s])
                    )),
                    'section_hidden'  => array_values(array_filter(
                        array_map('sanitize_key', (array) ($raw['section_hidden'] ?? [])),
                        fn($s) => isset(self::$section_labels[$s])
                    )),
                    'header_layout'   => $sanitized_hl,
                ];
                add_filter('pre_option_forge_forms_pdf_layout', static function () use ($preview_opts): array {
                    return $preview_opts;
                }, PHP_INT_MAX);
            }
        }

        $dummy = [
            ['type' => 'text',     'label' => 'Vorname',   'value' => 'Max'],
            ['type' => 'text',     'label' => 'Nachname',  'value' => 'Mustermann'],
            ['type' => 'email',    'label' => 'E-Mail',    'value' => 'max.mustermann@example.de'],
            ['type' => 'text',     'label' => 'Anliegen',  'value' => 'Mitgliedsantrag'],
            ['type' => 'textarea', 'label' => 'Nachricht', 'value' => 'Beispieltext für die PDF-Vorschau.'],
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

    public static function bodyClass(string $classes): string
    {
        if (isset($_GET['page']) && $_GET['page'] === 'forge-forms-pdf-layout') {
            $classes .= ' forge-list-page';
        }
        return $classes;
    }

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
        add_action('load-' . $hook, static function (): void {
            remove_all_actions('admin_notices');
            remove_all_actions('all_admin_notices');
            remove_all_actions('user_admin_notices');
            remove_all_actions('network_admin_notices');
        });
    }

    public static function render(): void
    {
        if (!\ForgeForms\Plugin::userCan('edit_pdf_layout')) {
            wp_die('Keine Berechtigung.');
        }

        wp_enqueue_media();

        $saved = false;
        if (
            isset($_POST['forge_pdf_layout_nonce']) &&
            wp_verify_nonce(sanitize_key($_POST['forge_pdf_layout_nonce']), 'forge_pdf_layout')
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

        $js_opts = wp_json_encode([
            'primary_color'   => $opts['primary_color'],
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
        ]);

        $site_name = esc_js(get_bloginfo('name'));
        $site_url  = esc_js(get_bloginfo('url'));
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

                    <?php foreach (
                    [
                        ['accent_color',    'Akzentfarbe (dicke Trennlinien)'],
                        ['separator_color', 'Trennlinienfarbe (dünne Linien)'],
                    ] as [$id, $lbl]
) : ?>
                    <div class="forge-settings-field forge-color-row">
                        <label><?php echo esc_html($lbl); ?></label>
                        <div class="forge-color-input-wrap">
                            <input type="color" id="<?php echo $id; ?>" name="<?php echo $id; ?>"
                                value="<?php echo esc_attr($opts[$id]); ?>">
                            <input type="text" id="<?php echo $id; ?>_hex" class="forge-hex-input"
                                value="<?php echo esc_attr($opts[$id]); ?>" maxlength="7">
                        </div>
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
                        <p class="forge-settings-hint">Platzhalter:
                            <code>{site_name}</code> <code>{site_url}</code>
                            <code>{date}</code> <code>{page}</code>
                        </p>
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

    var sampleFields = [
        {label:'Vorname',    value:'Max'},
        {label:'Nachname',   value:'Mustermann'},
        {label:'E-Mail',     value:'max.mustermann@example.de'},
        {label:'Anliegen',   value:'Mitgliedsantrag'},
        {label:'Nachricht',  value:'Dies ist ein Beispieltext der Vorschau.'}
    ];

    function mm(n){ return (n*3.7795).toFixed(1)+'px'; }

    function collectSettings(){
        var order=[], hidden=[];
        document.querySelectorAll('#forge-sections-sortable .forge-section-item').forEach(function(li){
            order.push(li.dataset.slug);
            if(li.classList.contains('forge-section-hidden')) hidden.push(li.dataset.slug);
        });
        return {
            primary_color:   val('primary_color')||'#1a3a5c',
            accent_color:    val('accent_color')||'#4a7fc1',
            separator_color: val('separator_color')||'#aaaaaa',
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
        var fs  = s.font_size_body+'pt';
        var pad = mm(s.margin_top)+' '+mm(s.margin_right)+' '+mm(s.margin_bottom)+' '+mm(s.margin_left);
        var out = '<div style="font-family:'+ff+';font-size:'+fs+';color:#222;padding:'+pad+';box-sizing:border-box;">';

        s.section_order.forEach(function(slug){
            if(s.section_hidden.indexOf(slug)!==-1) return;

            if(slug==='header'){
                out+='<table style="width:100%;border-collapse:collapse;margin-bottom:10px;"><tr>';
                out+='<td style="text-align:right;vertical-align:middle;font-size:'+s.title_size+'pt;'
                    +'font-weight:bold;color:#1a3a5c;">Beispielformular</td>';
                out+='</tr></table>';
            }

            if(slug==='fields'){
                sampleFields.forEach(function(f){
                    out+='<div style="margin-bottom:10px;">';
                    out+='<div style="font-weight:700;font-size:'+s.font_size_body+'pt;border-bottom:1px solid '+s.separator_color+';padding-bottom:2px;">'+f.label+'</div>';
                    out+='<div style="font-size:'+s.font_size_body+'pt;color:#444;padding:3px 0 4px;border-bottom:3px solid '+s.accent_color+';">'+f.value+'</div>';
                    out+='</div>';
                });
            }

            if(slug==='signatures'){
                out+='<div style="margin-bottom:12px;">';
                out+='<div style="font-weight:700;margin-bottom:5px;">Unterschrift</div>';
                out+='<div style="border:1px solid '+s.separator_color+';height:65px;border-radius:4px;background:#fafafa;display:flex;align-items:center;justify-content:center;color:#bbb;font-size:8pt;">[Signatur]</div>';
                out+='</div>';
            }

            if(slug==='metadata'){
                out+='<div style="margin:12px 0;padding:8px 10px;background:#f9f9f9;border:1px solid #e0e0e0;border-radius:4px;font-size:8pt;color:#555;">';
                out+='<strong>Metadaten</strong><br>';
                out+='Erstellt: '+new Date().toLocaleString('de-DE')+'<br>';
                out+='Formular-ID: 42 &nbsp;·&nbsp; Formular: Beispielformular';
                out+='</div>';
            }

            if(slug==='legal'){
                out+='<p style="font-size:7.5pt;color:#666;margin-top:6px;line-height:1.4;">';
                out+='<strong>Rechtlicher Hinweis:</strong> Dieses Dokument stellt das Original dar. '
                    +'Jede Änderung, Manipulation oder Modifikation macht dieses Dokument ungültig.';
                out+='</p>';
            }

            if(slug==='footer'){
                var ft = (s.footer_text||'')
                    .replace(/\{site_name\}/g,'<?php echo $site_name; ?>')
                    .replace(/\{site_url\}/g, '<?php echo $site_url; ?>')
                    .replace(/\{date\}/g, new Date().toLocaleDateString('de-DE'))
                    .replace(/\{page\}/g, '1');
                if(ft){
                    out+='<div style="margin-top:18px;padding-top:7px;border-top:1px solid '+s.separator_color+';font-size:7.5pt;color:#888;text-align:center;word-wrap:break-word;overflow-wrap:break-word;white-space:pre-wrap;">'+ft+'</div>';
                }
            }
        });

        out+='</div>';
        return out;
    }

    function updatePreview(){
        var s = collectSettings();
        var oi = $('forge-section-order-input');
        var hi = $('forge-section-hidden-input');
        if(oi) oi.value = s.section_order.join(',');
        if(hi) hi.value = s.section_hidden.join(',');
        if(paper) paper.innerHTML = buildPreview(s);
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

    /* color pickers */
    ['primary_color','accent_color','separator_color'].forEach(function(id){
        var picker = $(id), hex = $(id+'_hex');
        if(!picker||!hex) return;
        picker.addEventListener('input', function(){ hex.value=picker.value; updatePreview(); });
        hex.addEventListener('input', function(){
            if(/^#[0-9a-fA-F]{6}$/.test(hex.value)){ picker.value=hex.value; updatePreview(); }
        });
    });

    /* font & textarea */
    var fontSel = $('font_family'), footerTxt = $('footer_text');
    if(fontSel)  fontSel.addEventListener('change', updatePreview);
    if(footerTxt) footerTxt.addEventListener('input', updatePreview);



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
            inner.innerHTML = '<img src="'+hbEsc(el.src)+'" style="width:100%;height:100%;display:block;">';
        } else if(el.type==='title'){
            var fs = Math.round((el.size||18)*0.75);
            inner.innerHTML = '<div style="width:100%;height:100%;display:flex;align-items:center;padding:2px 4px;box-sizing:border-box;">'
                +'<span style="width:100%;font-size:'+fs+'px;font-weight:'+(el.bold?'bold':'normal')+';color:'+hbEsc(el.color||'#1a3a5c')+';text-align:'+hbEsc(el.align||'left')+';">'
                +hbEsc((el.text||'{form_title}').replace('{form_title}','Beispielformular'))+'</span></div>';
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
    });

    document.addEventListener('mouseup', function(){ if(hbDrag){ hbDrag=null; hbRender(); } });

    /* click canvas background → deselect */
    if(hbCanvas){
        hbCanvas.addEventListener('mousedown', function(e){
            if(e.target===hbCanvas){ hbSel=null; hbRender(); hbRenderProps(); }
        });
    }

    /* Delete key */
    document.addEventListener('keydown', function(e){
        if(!hbModal||hbModal.hidden) return;
        if(e.key!=='Delete'&&e.key!=='Backspace') return;
        var t=document.activeElement;
        if(t&&(t.tagName==='INPUT'||t.tagName==='TEXTAREA'||t.tagName==='SELECT')) return;
        if(!hbSel) return;
        hbLayout.elements = hbLayout.elements.filter(function(el){ return el.id!==hbSel; });
        hbSel=null; hbRender(); hbRenderProps();
        e.preventDefault();
    });

    function hbAdd(type){
        var rows = Math.max(2, hbLayout.rows||8);
        var el = { id:'e'+(hbNextId++), type:type, x:0, y:0,
            w: HB_COLS, h: rows };
        if(type==='title'){ el.text='{form_title}'; el.size=18; el.bold=true; el.align='right'; el.color='#1a3a5c'; }
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

        var h='<div class="forge-hb-prop-row2">'
            +'<div class="forge-hb-prop-group"><span>X</span><input type="number" data-p="x" value="'+el.x+'" min="0" max="41"></div>'
            +'<div class="forge-hb-prop-group"><span>Y</span><input type="number" data-p="y" value="'+el.y+'" min="0"></div>'
            +'<div class="forge-hb-prop-group"><span>Breite</span><input type="number" data-p="w" value="'+el.w+'" min="1" max="42"></div>'
            +'<div class="forge-hb-prop-group"><span>Höhe</span><input type="number" data-p="h" value="'+el.h+'" min="1"></div>'
            +'</div>';

        if(el.type==='image'){
            /* Image: picker first, then position/size, then fit */
            h=''; /* reset — image skips the shared X/Y/W/H block above */
            h+='<div class="forge-hb-prop-group">'
                +'<span>Bild</span>'
                +'<div class="forge-hb-img-preview">'
                +(el.src ? '<img src="'+hbEsc(el.src)+'" style="max-width:100%;max-height:80px;display:block;border-radius:3px;">' : '<span style="color:#aaa;font-size:11px;">Kein Bild gewählt</span>')
                +'</div>'
                +'<button type="button" class="button" id="hb-media-pick" style="width:100%;margin-top:6px">'
                +'<i class="fa-solid fa-upload"></i> '+(el.src?'Bild ändern':'Aus Mediathek wählen')+'</button>'
                +'<input type="text" data-p="src" value="'+hbEsc(el.src||'')+'" placeholder="oder URL eingeben …" style="margin-top:4px">'
                +'</div>';
            h+='<div class="forge-hb-prop-row2">'
                +'<div class="forge-hb-prop-group"><span>X</span><input type="number" data-p="x" value="'+el.x+'" min="0" max="41"></div>'
                +'<div class="forge-hb-prop-group"><span>Y</span><input type="number" data-p="y" value="'+el.y+'" min="0"></div>'
                +'<div class="forge-hb-prop-group"><span>Breite</span><input type="number" data-p="w" value="'+el.w+'" min="1" max="42"></div>'
                +'<div class="forge-hb-prop-group"><span>Höhe</span><input type="number" data-p="h" value="'+el.h+'" min="1"></div>'
                +'</div>';
            h+='<div class="forge-hb-prop-group"><span>Anpassung</span><select data-p="fit">'
                +'<option value="contain"'+(el.fit==='contain'?' selected':'')+'>Einpassen</option>'
                +'<option value="cover"'+(el.fit==='cover'?' selected':'')+'>Füllen</option>'
                +'<option value="fill"'+(el.fit==='fill'?' selected':'')+'>Strecken</option>'
                +'</select></div>';
        } else if(el.type==='title'){
            h+='<div class="forge-hb-prop-group"><span>Text</span><input type="text" data-p="text" value="'+hbEsc(el.text||'')+'" placeholder="{form_title}"></div>';
            h+='<div class="forge-hb-prop-row2">'
                +'<div class="forge-hb-prop-group"><span>Größe (pt)</span><input type="number" data-p="size" value="'+(el.size||18)+'" min="6" max="72"></div>'
                +'<div class="forge-hb-prop-group"><span>Farbe</span><input type="color" data-p="color" value="'+hbEsc(el.color||'#1a3a5c')+'"></div>'
                +'</div>';
            h+='<div class="forge-hb-prop-group"><span>Ausrichtung</span><select data-p="align">'
                +'<option value="left"'+(el.align==='left'?' selected':'')+'>Links</option>'
                +'<option value="center"'+(el.align==='center'?' selected':'')+'>Mitte</option>'
                +'<option value="right"'+(el.align==='right'?' selected':'')+'>Rechts</option>'
                +'</select></div>';
            h+='<div class="forge-hb-prop-inline"><input type="checkbox" data-p="bold"'+(el.bold?' checked':'')+' id="hb-bold"><label for="hb-bold">Fett</label></div>';
        } else if(el.type==='html'){
            h+='<div class="forge-hb-prop-group"><span>HTML-Code</span><textarea data-p="html"></textarea></div>';
            h+='<p style="font-size:11px;color:#888;margin:0">HTML wird direkt gerendert. Kein Script-Tag.</p>';
        }

        h+='<div style="margin-top:auto;padding-top:10px;border-top:1px solid #e2e4e7;">'
            +'<button type="button" class="button" id="hb-delete-btn" style="color:#d63638;width:100%"><i class="fa-solid fa-trash"></i> Element löschen</button>'
            +'</div>';

        hbProps.innerHTML = h;

        /* Set textarea value safely (avoids HTML injection via innerHTML) */
        if(el.type==='html'){
            var ta = hbProps.querySelector('textarea[data-p="html"]');
            if(ta) ta.value = el.html||'';
        }

        /* Bind prop inputs */
        hbProps.querySelectorAll('[data-p]').forEach(function(inp){
            var ev = (inp.tagName==='SELECT'||inp.type==='checkbox'||inp.type==='color') ? 'change' : 'input';
            inp.addEventListener(ev, function(){
                var p=inp.dataset.p, v;
                if(inp.type==='checkbox') v=inp.checked;
                else if(inp.type==='number') v=parseInt(inp.value)||0;
                else v=inp.value;
                el[p]=v;
                /* clamp grid coords */
                el.x=Math.max(0,Math.min(HB_COLS-el.w,el.x));
                el.y=Math.max(0,el.y);
                el.w=Math.max(1,Math.min(HB_COLS-el.x,el.w));
                el.h=Math.max(1,el.h);
                hbRender();
            });
        });

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
            var fs = s.font_size_body+'pt';
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
                            result += '<img src="'+hbEsc(el.src)+'" style="width:100%;height:auto;display:block;">';
                        } else if(el.type==='title'){
                            var fspx=Math.round((el.size||18)*0.75);
                            var txt=hbEsc((el.text||'{form_title}').replace('{form_title}','Beispielformular'));
                            result += '<div style="width:100%;height:100%;display:flex;align-items:center;">'
                                +'<span style="width:100%;font-size:'+fspx+'px;font-weight:'+(el.bold?'bold':'normal')+';color:'+hbEsc(el.color||s.primary_color)+';text-align:'+hbEsc(el.align||'left')+';">'+txt+'</span></div>';
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

    updatePreview();
}());
</script>
        <?php
    }

    private static function save(): void
    {
        $defs = self::defaults();
        $labels = self::$section_labels;

        $order = array_values(array_filter(
            array_map('sanitize_key', explode(',', wp_unslash($_POST['section_order'] ?? ''))),
            fn($s) => isset($labels[$s])
        ));
        $hidden = array_values(array_filter(
            array_map('sanitize_key', explode(',', wp_unslash($_POST['section_hidden'] ?? ''))),
            fn($s) => isset($labels[$s])
        ));

        if (empty($order)) {
            $order = $defs['section_order'];
        }

        update_option('forge_forms_pdf_layout', [
            'logo_url'        => esc_url_raw(wp_unslash($_POST['logo_url'] ?? '')),
            'logo_width'      => min(400, max(40, (int) ($_POST['logo_width'] ?? 180))),
            'primary_color'   => sanitize_hex_color($_POST['primary_color']   ?? '') ?: $defs['primary_color'],
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
        ]);
    }

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
                $item['color'] = sanitize_hex_color($el['color'] ?? '') ?: '#1a3a5c';
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
     * Generate a 300×80 PNG showing a handwriting-style squiggle (signature placeholder).
     * Falls back to a solid-colour rectangle if GD is unavailable.
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

    /** Minimal valid 1×1 white PNG for environments without GD. */
    private static function dummyFallbackPng(): string
    {
        return 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwADhQGAWjR9awAAAABJRU5ErkJggg==';
    }
}
