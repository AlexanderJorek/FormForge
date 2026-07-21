<?php

/**
 * MPDF HTML layout template for form submission PDF output.
 *
 * NOTE: This is rendered by mPDF, not the browser — it is not subject to WordPress's
 * output-escaping/KSES filters or CSP. Field values echoed here ($value in the loop
 * below) arrive already pre-escaped by each field type's own pdfData()/map() handler
 * (see includes/PDF/Generator.php), so they are intentionally NOT re-escaped in this file.
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

defined('ABSPATH') || exit;

$_defaults     = \ForgeForms\Admin\PDFLayoutEditor::defaults();
$_raw          = (array) get_option('forge_forms_pdf_layout', []);
$o             = array_merge($_defaults, $_raw);
$_field_layout = get_option('forge_forms_field_layout', 'block');

$_accent    = esc_attr($o['accent_color']);
$_sep       = esc_attr($o['separator_color']);
$_fs        = (int) $o['font_size_body'];
$_title_fs  = (int) $o['title_size'];
$_logo_w    = (int) $o['logo_width'];
$_font      = match ($o['font_family']) {
    'dejavuserif'    => 'dejavuserif',
    'dejavusansmono' => 'dejavusansmono',
    'freemono'       => 'freemono',
    default          => 'dejavusans',
};

// Fail closed, not open: if the stored logo_url doesn't resolve to a local
// media-library attachment (stale/deleted attachment, or an option value
// written by anything other than PDFLayoutEditor::save()'s sideload-on-save
// path), never fall back to fetching the raw URL — mPDF has no SSRF guard of
// its own and would fetch an external URL directly.
$_logo_post_id = !empty($o['logo_url']) ? attachment_url_to_postid($o['logo_url']) : 0;
$_logo_path    = $_logo_post_id ? (get_attached_file($_logo_post_id) ?: '') : '';

$_section_order  = is_array($o['section_order'])  ? $o['section_order']  : $_defaults['section_order'];
$_section_hidden = is_array($o['section_hidden']) ? $o['section_hidden'] : [];

$_margin_top_mm = (int) ($o['margin_top'] ?? 15);

return [
    'margin_top_mm'    => $_margin_top_mm,
    'margin_left_mm'   => (int) ($o['margin_left']   ?? 15),
    'margin_right_mm'  => (int) ($o['margin_right']  ?? 15),
    'margin_bottom_mm' => (int) ($o['margin_bottom'] ?? 15),

    'section_order'  => $_section_order,
    'section_hidden' => $_section_hidden,

    'base_css' => function () use ($_accent, $_sep, $_fs, $_title_fs, $_font): string {
        return '
        <style>
            body        { font-family:' . $_font . '; font-size:' . $_fs . 'pt; }
            .field-block { margin-bottom:14px; }
            .field-label { font-weight:bold; font-size:' . $_title_fs . 'pt; margin-bottom:4px; color:#222; }
            .field-separator-thin  { border-bottom:1px solid ' . $_sep . '; margin-bottom:4px; }
            .field-value           { font-size:' . $_fs . 'pt; margin-bottom:5px; color:#333; }
            .field-separator-thick { border-bottom:3px solid ' . $_accent . '; margin-top:2px; }
            .pdf-link   { font-size:' . ($_fs - 1) . 'pt; margin-top:4px; display:block; }
            .section-metadata { background:#f9f9f9; border:1px solid #e0e0e0;'
            . ' padding:8px 10px; font-size:' . ($_fs - 2) . 'pt; margin-bottom:12px; }
            .section-legal    { font-size:' . ($_fs - 3) . 'pt; color:#666; margin-top:8px; line-height:1.4; }
        </style>';
    },

    'header' => function (string $title) use (
        $_logo_path,
        $_logo_w,
        $_title_fs,
        $o
    ): string {
        $hb = $o['header_layout'] ?? [];
        $elements = $hb['elements'] ?? [];

        /* ── Grid-based header (header builder was used) ── */
        if (!empty($elements)) {
            $cols     = 42;
            $margin_l = (int) ($o['margin_left']  ?? 15);
            $margin_r = (int) ($o['margin_right'] ?? 15);
            $margin_t = (int) ($o['margin_top']   ?? 15);
            $w_mm     = 210 - $margin_l - $margin_r;
            $cell_mm  = round($w_mm / $cols, 4); // square cells: same unit for x and y

            /* Header height = lowest element's bottom edge in cells × cell_mm */
            $max_bottom = 0;
            foreach ($elements as $el) {
                $b = (int) ($el['y'] ?? 0) + max(1, (int) ($el['h'] ?? 1));
                if ($b > $max_bottom) {
                    $max_bottom = $b;
                }
            }
            $header_h_mm = round($max_bottom * $cell_mm, 2);

            /*
             * Spacer div occupies the header area in the content flow so that
             * the fields below start after the header. The header elements themselves
             * are position:absolute (page-relative in mPDF), placed at their exact
             * page coordinates derived from the grid.
             */
            $out = '<div style="height:' . $header_h_mm . 'mm;">&nbsp;</div>';

            foreach ($elements as $el) {
                $ex      = max(0, (int) ($el['x'] ?? 0));
                $ey      = max(0, (int) ($el['y'] ?? 0));
                $ew      = max(1, (int) ($el['w'] ?? $cols));
                $eh      = max(1, (int) ($el['h'] ?? 1));
                $type    = $el['type'] ?? '';
                $el_w_mm = round($ew * $cell_mm, 2);
                $el_h_mm = round($eh * $cell_mm, 2);
                $abs_l   = round($margin_l + $ex * $cell_mm, 2); // from page left edge
                $abs_t   = round($margin_t + $ey * $cell_mm, 2); // from page top edge

                $out .= '<div style="position:absolute;left:' . $abs_l . 'mm;top:' . $abs_t . 'mm;'
                      . 'width:' . $el_w_mm . 'mm;height:' . $el_h_mm . 'mm;overflow:hidden;">';

                if ($type === 'image' && !empty($el['src'])) {
                    // Fail closed, not open: PDFLayoutEditor::save() only ever persists a
                    // 'src' that already resolved to a local media-library attachment (via
                    // resolveImageSrc()/media_sideload_image()'s SSRF-safe fetch). If this
                    // value doesn't resolve to a real local file — a stale/deleted
                    // attachment, or a header_layout option written by any other path —
                    // never fall back to the raw value, since mPDF has no SSRF guard of
                    // its own and would fetch an external URL directly.
                    $post_id  = attachment_url_to_postid($el['src']);
                    $img_path = $post_id ? (get_attached_file($post_id) ?: '') : '';
                    if ($img_path !== '') {
                        $out .= '<img src="' . esc_attr($img_path) . '" style="width:' . $el_w_mm . 'mm;height:auto;" />';
                    }
                } elseif ($type === 'title') {
                    $fs    = max(6, (int) ($el['size'] ?? 18));
                    $color = esc_attr($el['color'] ?? '#1d2327');
                    $align = in_array($el['align'] ?? '', ['left','center','right'], true)
                           ? $el['align'] : 'left';
                    $raw   = $el['content'] ?? $el['text'] ?? '{form_title}';
                    $raw   = str_replace('{form_title}', $title, $raw);
                    $allowed = [
                        'b'      => [],
                        'strong' => [],
                        'i'      => [],
                        'em'     => [],
                        'u'      => ['style' => []],
                        's'      => [],
                        'del'    => [],
                        'sup'    => [],
                        'sub'    => [],
                        'span'   => ['style' => []],
                        'br'     => [],
                    ];
                    $safe = wp_kses($raw, $allowed);
                    $out .= '<div style="font-size:' . $fs . 'pt;color:' . $color
                          . ';text-align:' . $align . ';line-height:' . $el_h_mm . 'mm;">'
                          . $safe . '</div>';
                }

                $out .= '</div>';
            }

            return $out;
        }

        /* ── Default header (no builder layout set) ── */
        $has_logo = file_exists($_logo_path) && is_readable($_logo_path);
        if (!$has_logo && !empty($o['logo_url'])) {
            \ForgeForms\forge_log("PDF header: logo missing at {$_logo_path}");
        }

        if ($has_logo) {
            $logo_cell  = '<td style="width:' . $_logo_w . 'px;vertical-align:middle;">'
                . '<img src="' . esc_attr($_logo_path) . '" style="width:' . $_logo_w
                . 'px;height:auto;" /></td>';
            $title_cell = '<td style="text-align:right;vertical-align:middle;'
                . 'font-size:' . $_title_fs . 'pt;font-weight:bold;'
                . 'padding-left:10px;">' . esc_html($title) . '</td>';
        } else {
            $logo_cell  = '';
            $title_cell = '<td style="text-align:left;vertical-align:middle;'
                . 'font-size:' . $_title_fs . 'pt;font-weight:bold;">'
                . esc_html($title) . '</td>';
        }

        return '
        <table style="width:100%;border-collapse:collapse;margin-bottom:6px;">
            <tr>' . $logo_cell . $title_cell . '</tr>
        </table>';
    },

    'field' => function (string $label, string $value) use ($_field_layout, $_title_fs, $_fs): string {
        $lbl_style = 'font-weight:bold;font-size:' . $_title_fs . 'pt;color:#222;';
        $val_style = 'font-size:' . $_fs . 'pt;color:#333;';
        if ($_field_layout === 'inline') {
            return '
        <div class="field-block">
            <span style="' . $lbl_style . '">' . esc_html($label) . ':</span>'
            . ' <span style="' . $val_style . '">' . $value . '</span>'
            . '<div class="field-separator-thick"></div>
        </div>';
        }
        return '
        <div class="field-block">
            <div style="' . $lbl_style . 'margin-bottom:4px;">' . esc_html($label) . '</div>
            <div class="field-separator-thin"></div>
            <div style="' . $val_style . 'margin-bottom:5px;">' . $value . '</div>
            <div class="field-separator-thick"></div>
        </div>';
    },

    'image' => function (string $varname): string {
        return '<div style="margin:8px 0;">'
            . '<img src="var:' . esc_attr($varname)
            . '" style="max-width:100%;max-height:300px;border:1px solid #ccc;padding:4px;" />'
            . '</div>';
    },

    'document_metadata' => function (array $data) use ($_fs): string {
        $metadata = $data['metadata'] ?? [];
        return '
        <div class="section-metadata">
            <strong>Metadaten</strong><br>
            Erstellt: ' . esc_html($metadata['generated'] ?? '') . '<br>
            Formular: ' . esc_html($metadata['form_name'] ?? '')
            . ' (ID: ' . esc_html((string) ($metadata['form_id'] ?? '')) . ')
        </div>';
    },

    'legal_notice' => function (): string {
        return '
        <p class="section-legal">
            <strong>Rechtlicher Hinweis:</strong>
            Dieses Dokument stellt das Original dar. Jede Änderung, Manipulation oder Modifikation
            macht dieses Dokument ungültig. Dieses Dokument wurde in elektronischer Form ausgestellt
            und ist ausschließlich in elektronischer Form aufzubewahren. Jeder Ausdruck ist lediglich
            eine Kopie und hat keine Rechtsgültigkeit.
        </p>';
    },

    'footer' => function () use ($o, $_sep): string {
        $text = $o['footer_text'] ?? '';
        if (!$text) {
            return '';
        }
        $text = str_replace(
            ['{site_name}', '{site_url}', '{date}'],
            [get_bloginfo('name'), get_bloginfo('url'), current_time('d.m.Y')],
            $text
        );
        return $text;
    },
];
