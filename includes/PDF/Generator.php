<?php

/**
 * Generates PDF documents from form submissions using mPDF.
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

namespace ForgeForms\PDF;

use Mpdf\Mpdf;
use Mpdf\MpdfException;
use Mpdf\HTMLParserMode;

defined('ABSPATH') || exit;

/**
 * Generates PDF documents from form submission data and embeds an HMAC seal.
 */
class Generator
{
    /**
     * Generates a PDF from normalized submission data and returns its path.
     *
     * @param array  $mapped     Normalized field data from SubmissionMapper::map().
     * @param int    $form_id    The form identifier.
     * @param string $form_title Human-readable form title used in the PDF header.
     *
     * @return string|false Absolute path to the generated PDF, or false on failure.
     */
    public static function generate(array $mapped, int $form_id, string $form_title = ''): string|false
    {
        if (empty($mapped)) {
            \ForgeForms\forge_log('ForgeForms Generator: No data provided');
            return false;
        }

        $layout = include FORGE_FORMS_PATH . 'pdf-templates/layout.php';

        $embedded_pdfs  = [];
        $image_vars     = [];
        $sealed_uploads = [];

        $title = $form_title !== '' ? $form_title : 'Formulareinsendung';

        $metadata = [
            'generated' => current_time('mysql'),
            'nonce'     => bin2hex(random_bytes(16)),
            'form_id'   => $form_id,
            'form_name' => $title,
        ];

        $full_data      = ['metadata' => $metadata, 'fields' => $mapped];
        $default_order  = ['header', 'fields', 'signatures', 'metadata', 'legal', 'footer'];
        $section_order  = $layout['section_order']  ?? $default_order;
        $section_hidden = $layout['section_hidden'] ?? [];

        $fields_html = '';  // regular form fields
        $sigs_html   = '';  // upload + signature fields only

        foreach ($mapped as $key => $field) {
            $field_id  = 'field_' . $key;
            $field_type = $field['type'] ?? '';
            $is_media   = in_array($field_type, ['upload', 'signature'], true);

            if ($field_type === 'html') {
                $start = '<span style="font-size:0.1px;line-height:0.1px;color:#000;position:absolute;">'
                    . '[FORGE_PDF_FIELD_' . $field_id . ']</span>';
                $end   = '<span style="font-size:0.1px;line-height:0.1px;color:#000;position:absolute;">'
                    . '[FORGE_PDF_FIELD_END]</span>';
                if (!empty($field['label'])) {
                    $fields_html .= $layout['field']($field['label'], $start . $field['value'] . $end);
                } else {
                    $fields_html .= '<div class="field-block">' . $start . $field['value'] . $end . '</div>';
                }
                continue;
            }

            if (!isset($field['label'], $field['value'])) {
                continue;
            }

            // Signature image is self-explanatory; suppress redundant text label.
            $cell_html = $field_type === 'signature'
                ? ''
                : esc_html($field['value']);

            if ($is_media && !empty($field['materialized_files'])) {
                foreach ($field['materialized_files'] as $file) {
                    $mime = $file['mime'] ?? '';
                    $name = $file['name'] ?? 'file';

                    if ($mime === 'application/pdf' && !empty($file['base64'])) {
                        $binary = base64_decode($file['base64'], true);
                        if ($binary === false) {
                            continue;
                        }

                        $embed_dir  = self::getEmbedStorageDir();
                        $safe_name  = preg_replace('/[^a-zA-Z0-9._-]/', '_', $name);
                        $temp_path  = $embed_dir . '/' . bin2hex(random_bytes(8)) . '_' . $safe_name;

                        if (file_put_contents($temp_path, $binary) === false) {
                            continue;
                        }

                        $sealed_uploads[] = [
                            'name'   => $safe_name,
                            'mime'   => 'application/pdf',
                            'sha256' => hash('sha256', $binary),
                        ];
                        $embedded_pdfs[$safe_name] = [
                            'path' => $temp_path,
                            'name' => $safe_name,
                            'mime' => 'application/pdf',
                        ];
                        continue;
                    }

                    if (str_starts_with($mime, 'image/') && !empty($file['base64'])) {
                        $binary = base64_decode($file['base64'], true);
                        if ($binary === false) {
                            continue;
                        }

                        $sealed_uploads[] = [
                            'name'   => (string)$name,
                            'mime'   => (string)$mime,
                            'sha256' => self::thumbnailHash($binary) ?? hash('sha256', $binary),
                        ];

                        $image_var = 'img' . bin2hex(random_bytes(8));
                        $image_vars[$image_var] = $binary;
                        $cell_html .= $layout['image']($image_var);
                    }
                }
            }

            $start     = '<span style="font-size:0.1px;line-height:0.1px;color:#000;position:absolute;">'
                . '[FORGE_PDF_FIELD_' . $field_id . ']</span>';
            $end       = '<span style="font-size:0.1px;line-height:0.1px;color:#000;position:absolute;">'
                . '[FORGE_PDF_FIELD_END]</span>';
            $cell_html = $start . $cell_html . $end;
            $block = $layout['field']($field['label'], $cell_html);

            if ($is_media) {
                $sigs_html .= $block;
            } else {
                $fields_html .= $block;
            }
        }

        /* ---- Assemble HTML in section order ---- */
        $html        = '<base href="">';
        $html       .= $layout['base_css']();
        $header_html = '';

        foreach ($section_order as $slug) {
            if (in_array($slug, $section_hidden, true)) {
                continue;
            }
            if ($slug === 'header') {
                $header_html = $layout['header']($title);
                $html       .= $header_html;
            } elseif ($slug === 'fields') {
                $html .= $fields_html;
            } elseif ($slug === 'signatures') {
                $html .= $sigs_html;
            } elseif ($slug === 'metadata') {
                $html .= $layout['document_metadata']($full_data);
            } elseif ($slug === 'legal' && isset($layout['legal_notice'])) {
                $html .= $layout['legal_notice']();
            }
            /* footer moved to SetHTMLFooter — rendered per-page, not inline */
        }

        /* ---- Template image fingerprints ---- */
        $template = self::buildTemplateFingerprints();

        /* ---- mPDF setup ---- */
        try {
            $prev_backtrack = (int)ini_get('pcre.backtrack_limit');
            ini_set('pcre.backtrack_limit', (string)max($prev_backtrack, 16 * 1024 * 1024));

            $upload_dir = wp_upload_dir();
            $safe_dir   = $upload_dir['basedir'] . '/forge-secure-pdf';

            foreach (['', '/pdf', '/embed', '/mpdf'] as $sub) {
                $dir = $safe_dir . $sub;
                if (!is_dir($dir)) {
                    wp_mkdir_p($dir);
                    chmod($dir, 0750);
                    file_put_contents($dir . '/index.php', '<?php // Silence is golden ?>');
                    chmod($dir . '/index.php', 0640);
                }
            }

            $htaccess = $safe_dir . '/.htaccess';
            if (!file_exists($htaccess)) {
                file_put_contents($htaccess, "Options -Indexes\nDeny from all\n");
                chmod($htaccess, 0640);
            }

            $pdf_dir   = $safe_dir . '/pdf';
            $mpdf_temp = $safe_dir . '/mpdf';
            $grid_svg  = FORGE_FORMS_PATH . 'pdf-templates/construction-grid.svg';

            $form_name_clean = preg_replace('/[^a-zA-Z0-9_-]/', '_', $title);
            $date_time       = wp_date('D_d_m_Y_T_H_i');

            $margin_top    = (int) ($layout['margin_top_mm']    ?? 30);
            $margin_left   = (int) ($layout['margin_left_mm']   ?? 15);
            $margin_right  = (int) ($layout['margin_right_mm']  ?? 15);
            $margin_bottom = (int) ($layout['margin_bottom_mm'] ?? 15);

            /* Strip any HTML wrapper that older cached versions of layout.php
               may have returned (e.g. a <div style="border-top:...">). */
            $user_footer_text = isset($layout['footer'])
                ? wp_strip_all_tags(trim((string) $layout['footer']()))
                : '';
            $pdf_opts  = (array) get_option('forge_forms_pdf_layout', []);
            $sep_color = sanitize_hex_color($pdf_opts['separator_color'] ?? '')
                ?: '#c9cdd4';
            /* Widen the footer zone when user text is present. */
            $footer_margin = $user_footer_text !== '' ? 10 : 5;

            $mpdf_config = [
                'tempDir'       => $mpdf_temp,
                'margin_top'    => $margin_top,
                'margin_left'   => $margin_left,
                'margin_right'  => $margin_right,
                'margin_bottom' => $margin_bottom,
                'margin_header' => 3,
                'margin_footer' => $footer_margin,
            ];

            /* ---- PASS 1: font discovery ---- */
            $mpdf = new Mpdf($mpdf_config);
            $mpdf->SetDefaultBodyCSS('background', "url('{$grid_svg}')");
            $mpdf->SetDefaultBodyCSS('background-repeat', 'repeat');
            $mpdf->SetDefaultBodyCSS('background-position', 'center center');
            $mpdf->SetHTMLFooter(self::footerHtml($user_footer_text, $sep_color));
            if (!empty($image_vars)) {
                $mpdf->imageVars = $image_vars;
            }

            self::writeHtmlChunked($mpdf, $html);

            $sl_path = $mpdf_temp . "/SL_{$form_name_clean}_{$date_time}.pdf";
            $mpdf->Output($sl_path, \Mpdf\Output\Destination::FILE);
            unset($mpdf);

            $fonts           = [];
            $expected_pages  = 0;
            $content_hashes  = [];
            $pdf_raw         = file_get_contents($sl_path);

            $image_hashes = [];
            if ($pdf_raw !== false) {
                if (preg_match_all('/\/BaseFont\s*\/([A-Za-z0-9\+\-_]+)/', $pdf_raw, $m)) {
                    foreach ($m[1] as $font) {
                        $fonts[preg_replace('/^[A-Z]{6}\+/', '', $font)] = true;
                    }
                }
                preg_match_all('/\/Type\s*\/Page\b/', $pdf_raw, $pm);
                $expected_pages  = count($pm[0]);
                $content_hashes   = self::hashPageContentStreams($pdf_raw);
                $image_hashes     = self::hashImageXObjects($pdf_raw);
                $font_prog_hashes = self::hashFontProgramStreams($pdf_raw);
                $all_stream_hashes = self::hashAllCompressedStreams($pdf_raw);
            }
            $fonts             = array_keys($fonts);
            $font_prog_hashes  = $font_prog_hashes  ?? [];
            $all_stream_hashes = $all_stream_hashes ?? [];
            if (!@unlink($sl_path)) {
                \ForgeForms\forge_log('ForgeForms Generator: failed to delete temp PDF: ' . $sl_path);
            }

            // All seal inputs come from PASS 1 — compute it now so the seal div
            // can be appended to $html and written in a single writeHtmlChunked call.
            $pdf_meta = [
                'title'   => self::normalizeFieldValue($title),
                'author'  => self::normalizeFieldValue((string) get_bloginfo('name')),
                'creator' => 'FormForge',
            ];

            $seal_data = [
                'generated'       => trim((string)$metadata['generated']),
                'key_id'          => HashSeal::getCurrentKeyId(),
                'nonce'           => (string)$metadata['nonce'],
                'form_id'         => (int)$metadata['form_id'],
                'form_name'       => self::normalizeFieldValue($metadata['form_name']),
                'fields'          => self::buildSealFields($mapped),
                'uploads'         => $sealed_uploads,
                'template'        => $template,
                'fonts'           => $fonts,
                'expected_pages'  => $expected_pages,
                'content_streams' => $content_hashes,
                'image_hashes'      => $image_hashes,
                'font_prog_hashes'  => $font_prog_hashes,
                'all_stream_hashes' => $all_stream_hashes,
                'pdf_meta'          => $pdf_meta,
            ];

            $hash = HashSeal::generate($seal_data);
            $seal_data['seal'] = $hash;

            $seal_json = json_encode($seal_data, JSON_UNESCAPED_SLASHES);
            if ($seal_json === false) {
                throw new \RuntimeException(
                    'ForgeForms Generator: JSON encode failed — ' . json_last_error_msg()
                );
            }
            $seal_base64 = base64_encode($seal_json);

            $seal_div = '<div style="font-size:0.1px;line-height:0.1px;color:#000;">'
                . '---BEGIN-SEAL---' . $seal_base64 . '---END-SEAL---'
                . '</div>';

            $html .= $seal_div;

            /* ---- PASS 2: final PDF with seal ---- */
            $mpdf = new Mpdf($mpdf_config);
            $mpdf->SetTitle($pdf_meta['title']);
            $mpdf->SetAuthor($pdf_meta['author']);
            $mpdf->SetCreator($pdf_meta['creator']);
            $mpdf->SetDefaultBodyCSS('background', "url('{$grid_svg}')");
            $mpdf->SetDefaultBodyCSS('background-repeat', 'repeat');
            $mpdf->SetDefaultBodyCSS('background-position', 'center center');
            $mpdf->SetHTMLFooter(self::footerHtml($user_footer_text, $sep_color));
            if (!empty($image_vars)) {
                $mpdf->imageVars = $image_vars;
            }

            self::writeHtmlChunked($mpdf, $html);

            $final_path = $pdf_dir . "/Entry_{$form_name_clean}_{$date_time}.pdf";
            $mpdf->Output($final_path, \Mpdf\Output\Destination::FILE);

            foreach ($embedded_pdfs as $pdf) {
                if (!empty($pdf['path']) && file_exists($pdf['path'])) {
                    @unlink($pdf['path']);
                }
            }

            ini_set('pcre.backtrack_limit', (string)$prev_backtrack);
            return $final_path;
        } catch (MpdfException $e) {
            if (isset($prev_backtrack)) {
                ini_set('pcre.backtrack_limit', (string)$prev_backtrack);
            }
            error_log('ForgeForms Generator error: ' . $e->getMessage());
            return false;
        }
    }

    /* ------------------------------------------------------------------ */

    /**
     * Returns the mPDF HTML footer string with page number tokens.
     *
     * @return string HTML footer markup.
     */
    private static function footerHtml(string $user_text = '', string $sep_color = '#c9cdd4'): string
    {
        $pageno = '<span style="font-size:0.1px;line-height:0.1px;color:#fff;">'
            . '[FORGE_PDF_PAGENO_START]</span>'
            . 'Seite {PAGENO} von {nbpg}'
            . '<span style="font-size:0.1px;line-height:0.1px;color:#fff;">'
            . '[FORGE_PDF_PAGENO_END]</span>';

        $border = '';

        if ($user_text === '') {
            return '<div style="text-align:right;font-size:10pt;' . $border . '">'
                . $pageno . '</div>';
        }

        return '<table style="width:100%;border-collapse:collapse;' . $border . 'font-size:8pt;">'
            . '<tr>'
            . '<td style="text-align:left;color:#888;">' . $user_text . '</td>'
            . '<td style="text-align:right;white-space:nowrap;font-size:10pt;">' . $pageno . '</td>'
            . '</tr>'
            . '</table>';
    }

    /**
     * Returns the absolute path to the embed storage directory, creating it if needed.
     *
     * @return string Absolute filesystem path to the embed directory.
     */
    private static function getEmbedStorageDir(): string
    {
        $dir = wp_upload_dir()['basedir'] . '/forge-secure-pdf/embed';
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
            file_put_contents($dir . '/index.php', '<?php // Silence is golden ?>');
        }
        return $dir;
    }

    /**
     * Builds SHA-256 fingerprint records for all PDF template asset files.
     *
     * @return array Array of fingerprint entries, each with name, mime, and sha256 keys.
     */
    private static function buildTemplateFingerprints(): array
    {
        $finfo    = new \finfo(FILEINFO_MIME_TYPE);
        $seen     = [];
        $template = [];

        $fingerprint = function (string $path, string $name) use ($finfo, &$seen, &$template): void {
            $real = realpath($path);
            if ($real === false || !is_readable($real) || isset($seen[$real])) {
                return;
            }
            $seen[$real] = true;
            $data = file_get_contents($real);
            if ($data === false) {
                return;
            }
            $mime = $finfo->file($real) ?: 'application/octet-stream';
            $th   = str_starts_with($mime, 'image/') ? self::thumbnailHash($data) : null;
            $template[] = ['name' => $name, 'mime' => $mime, 'sha256' => $th ?? hash('sha256', $data)];
        };

        $fingerprint(FORGE_FORMS_PATH . 'pdf-templates/construction-grid.svg', 'construction-grid.svg');

        $raw = (array) \get_option('forge_forms_pdf_layout', []);

        // Custom logo only — no fallback
        if (!empty($raw['logo_url'])) {
            $post_id   = \attachment_url_to_postid($raw['logo_url']);
            $logo_path = $post_id ? (\get_attached_file($post_id) ?: '') : '';
            if ($logo_path !== '') {
                $fingerprint($logo_path, basename($logo_path));
            }
        }

        // Header-builder image elements
        $elements = $raw['header_layout']['elements'] ?? [];
        foreach ($elements as $el) {
            if (($el['type'] ?? '') !== 'image' || empty($el['src'])) {
                continue;
            }
            $post_id  = \attachment_url_to_postid($el['src']);
            $img_path = $post_id ? (\get_attached_file($post_id) ?: '') : '';
            if ($img_path !== '') {
                $fingerprint($img_path, basename($img_path));
            }
        }

        return $template;
    }

    /**
     * Hashes every decoded page content stream found in the raw PDF bytes.
     *
     * @param string $pdf_raw Raw PDF binary string.
     *
     * @return array Sorted SHA-256 hashes of decoded page content streams.
     */
    private static function hashPageContentStreams(string $pdf_raw): array
    {
        $hashes = [];
        $offset = 0;
        while (true) {
            $pos  = strpos($pdf_raw, "stream\r\n", $offset);
            $pos2 = strpos($pdf_raw, "stream\n", $offset);
            if ($pos === false && $pos2 === false) {
                break;
            }
            if ($pos === false) {
                $pos = $pos2;
            } elseif ($pos2 !== false && $pos2 < $pos) {
                $pos = $pos2;
            }

            $eol = (substr($pdf_raw, $pos + 6, 2) === "\r\n") ? 2 : 1;
            $bs  = $pos + 6 + $eol;
            $be  = strpos($pdf_raw, 'endstream', $bs);
            if ($be === false) {
                $offset = $pos + 7;
                continue;
            }

            $body = substr($pdf_raw, $bs, $be - $bs);
            $dec  = @gzuncompress($body) ?: @gzinflate(substr($body, 2));

            if ($dec !== false && self::isPageContentStream($dec)) {
                $hashes[] = hash('sha256', $dec);
            }
            $offset = $be + 9;
        }
        return $hashes;
    }

    /**
     * Hashes every image XObject stream in the PDF, skipping SMask alpha channels.
     *
     * Uses the same decoding pipeline as Verificationpage so the hashes match
     * exactly. Called on PASS-1 output; PASS-2 produces byte-identical XObjects.
     *
     * @param string $pdf_raw Raw PDF binary string.
     *
     * @return array SHA-256 hashes of raw compressed image XObject streams.
     */
    private static function hashImageXObjects(string $pdf_raw): array
    {
        $hashes = [];

        // Collect SMask object numbers so alpha-channel XObjects are skipped.
        $smask_nums = [];
        if (preg_match_all('/\/SMask\s+(\d+)\s+\d+\s+R/', $pdf_raw, $sm)) {
            $smask_nums = array_flip($sm[1]);
        }

        $offset = 0;
        while (($pos = strpos($pdf_raw, '/XObject', $offset)) !== false) {
            $line_start = strrpos(substr($pdf_raw, 0, $pos), "\n") ?: 0;
            $obj_start  = $line_start + 1;
            $obj_end    = strpos($pdf_raw, 'endobj', $obj_start);
            if ($obj_end === false) {
                $offset = $pos + 10;
                continue;
            }

            $full_obj = substr($pdf_raw, $obj_start, $obj_end + 6 - $obj_start);

            if (!str_contains($full_obj, '/Subtype /Image')) {
                $offset = $obj_end + 6;
                continue;
            }

            // Skip SMask XObjects (alpha channels).
            $look_back = substr($pdf_raw, max(0, $obj_start - 100), 100);
            if (preg_match('/(\d+)\s+\d+\s+obj\s*$/', $look_back, $nm) && isset($smask_nums[$nm[1]])) {
                $offset = $obj_end + 6;
                continue;
            }

            // Extract stream — use same boundary logic as Verificationpage.
            $sp  = strpos($pdf_raw, 'stream', $obj_start);
            $esp = $sp !== false ? strpos($pdf_raw, 'endstream', $sp) : false;
            if ($sp === false || $esp === false) {
                $offset = $obj_end + 6;
                continue;
            }

            $raw = ltrim(substr($pdf_raw, $sp + 6, $esp - ($sp + 6)), "\r\n");

            // Hash raw compressed bytes — identical between PASS 1 and PASS 2.
            $hashes[] = hash('sha256', $raw);
            $offset = $obj_end + 6;
        }

        return $hashes;
    }

    /**
     * Computes a perceptual thumbnail hash of an image for stable cross-pass comparison.
     *
     * Downscales the image to an 8x8 pixel grid and hashes the quantised RGB
     * values, producing a hash that is stable across PDF rendering passes.
     * Returns null when the GD extension is unavailable or the image cannot be decoded.
     *
     * @param string $binary Raw binary image data.
     *
     * @return string|null SHA-256 hash of the thumbnail pixels, or null on failure.
     */
    private static function thumbnailHash(string $binary): ?string
    {
        if (!function_exists('imagecreatefromstring')) {
            \ForgeForms\forge_log(
                'ForgeForms Generator: GD extension unavailable — '
                . 'image verification uses raw hash (degraded mode).'
            );
            return null;
        }
        $gd = @imagecreatefromstring($binary);
        if ($gd === false) {
            return null;
        }
        $thumb = imagecreatetruecolor(8, 8);
        imagealphablending($thumb, false); // raw RGB area-average — matches DeviceRGB XObject (no alpha)
        imagecopyresampled($thumb, $gd, 0, 0, 0, 0, 8, 8, imagesx($gd), imagesy($gd));
        imagedestroy($gd);
        $pixels = '';
        for ($ty = 0; $ty < 8; $ty++) {
            for ($tx = 0; $tx < 8; $tx++) {
                $c       = imagecolorat($thumb, $tx, $ty);
                $pixels .= chr((($c >> 16) & 0xFF) & ~7)
                         . chr((($c >> 8)  & 0xFF) & ~7)
                         . chr(($c         & 0xFF) & ~7);
            }
        }
        imagedestroy($thumb);
        return hash('sha256', $pixels);
    }

    /**
     * Hashes every non-content compressed stream in the PDF.
     *
     * Content streams are excluded because they differ between PASS 1 and PASS 2
     * (seal embedding changes them) and are already covered by content_streams.
     * Catches auxiliary streams (Form XObjects, ICC profiles, CMaps, etc.)
     * that the type-specific checks do not cover; stable across both passes.
     *
     * @param string $pdf_raw Raw PDF binary string.
     *
     * @return array Sorted SHA-256 hashes of all non-content compressed streams.
     */
    private static function hashAllCompressedStreams(string $pdf_raw): array
    {
        $hashes = [];
        $offset = 0;
        while (true) {
            $pos  = strpos($pdf_raw, "stream\r\n", $offset);
            $pos2 = strpos($pdf_raw, "stream\n", $offset);
            if ($pos === false && $pos2 === false) {
                break;
            }
            if ($pos === false) {
                $pos = $pos2;
            } elseif ($pos2 !== false && $pos2 < $pos) {
                $pos = $pos2;
            }
            $eol = (substr($pdf_raw, $pos + 6, 2) === "\r\n") ? 2 : 1;
            $bs  = $pos + 6 + $eol;
            $be  = strpos($pdf_raw, 'endstream', $bs);
            if ($be === false) {
                $offset = $bs;
                continue;
            }
            $body = substr($pdf_raw, $bs, $be - $bs);
            if (strlen($body) > 67108864) {
                $offset = $be + 9;
                continue;
            }
            $dec = @gzuncompress($body) ?: @gzinflate($body);
            if ($dec === false || strlen($dec) > 67108864) {
                $offset = $be + 9;
                continue;
            }
            // Skip page content streams — those are handled by content_streams
            // and change between PASS 1 and PASS 2 due to seal embedding.
            if (!self::isPageContentStream($dec)) {
                $hashes[] = hash('sha256', $dec);
            }
            $offset = $be + 9;
        }
        sort($hashes);
        return $hashes;
    }

    /**
     * Hashes every embedded font program stream found in FontDescriptor objects.
     *
     * Covers TrueType, CIDFontType2, and Type1 font files referenced via
     * /FontFile, /FontFile2, or /FontFile3. Returns sorted SHA-256 hashes so
     * the result is order-independent and matches the Verificationpage output.
     *
     * @param string $pdf_raw Raw PDF binary string.
     *
     * @return array Sorted SHA-256 hashes of decoded font program streams.
     */
    private static function hashFontProgramStreams(string $pdf_raw): array
    {
        $hashes = [];
        // mPDF writes FontDescriptors as independent objects ("<N> 0 obj << /Type /FontDescriptor ... >>").
        // Find every such object body, then follow its /FontFile, /FontFile2, /FontFile3 reference.
        $pat_desc = '/\d+\s+\d+\s+obj\s*<<([\s\S]*?\/Type\s*\/FontDescriptor[\s\S]*?)>>\s*endobj/m';
        if (!preg_match_all($pat_desc, $pdf_raw, $descs, PREG_SET_ORDER)) {
            return $hashes;
        }

        $seen = [];
        foreach ($descs as $desc) {
            if (!preg_match('/\/FontFile[23]?\s+(\d+)\s+\d+\s+R/', $desc[1], $ref)) {
                continue;
            }
            $obj_num = (int) $ref[1];
            if (isset($seen[$obj_num])) {
                continue;
            }
            $seen[$obj_num] = true;

            $pat_obj = '/' . $obj_num . '\s+\d+\s+obj[\s\S]*?stream\r?\n([\s\S]*?)\r?\nendstream/m';
            if (!preg_match($pat_obj, $pdf_raw, $so)) {
                continue;
            }
            $body = $so[1];
            if (strlen($body) > 67108864) {
                continue;
            }
            $dec = @gzuncompress($body) ?: @gzinflate($body);
            if ($dec === false || strlen($dec) > 67108864) {
                $dec = $body;
            }
            $hashes[] = hash('sha256', $dec);
        }

        sort($hashes);
        return $hashes;
    }

    /**
     * Returns true when the decoded stream data looks like a PDF page content stream.
     *
     * Checks for printable leading bytes and the presence of common PDF graphics
     * or text operators (BT, q, Q, cm, Tf, Tj, Td).
     *
     * @param string $decoded Decompressed stream data.
     *
     * @return bool True if the stream appears to be a page content stream.
     */
    private static function isPageContentStream(string $decoded): bool
    {
        $head = substr($decoded, 0, 16);
        for ($i = 0; $i < strlen($head); $i++) {
            $b = ord($head[$i]);
            if ($b < 9 || ($b > 13 && $b < 32 && $b !== 27)) {
                return false;
            }
        }
        return (bool)preg_match('/\bBT\b|\bq\b|\bQ\b|\bcm\b|\bTf\b|\bTj\b|\bTd\b/', $decoded);
    }

    /**
     * Writes an HTML string to mPDF in chunks to avoid PCRE backtrack limit errors.
     *
     * @param Mpdf   $mpdf      The mPDF instance to write into.
     * @param string $html      Full HTML string to render.
     * @param int    $chunkSize Maximum byte size of each chunk.
     *
     * @return void
     */
    private static function writeHtmlChunked(Mpdf $mpdf, string $html, int $chunkSize = 1500000): void
    {
        $html  = str_replace(["\r\n", "\r"], "\n", $html);
        $parts = preg_split('/(<\/[^>]+>)/i', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
        $buf   = '';
        $first = true;
        foreach ($parts as $part) {
            $buf .= $part;
            if (strlen($buf) >= $chunkSize) {
                $mpdf->WriteHTML($buf, $first ? HTMLParserMode::DEFAULT_MODE : HTMLParserMode::HTML_BODY);
                $buf = '';
                $first = false;
            }
        }
        if ($buf !== '') {
            $mpdf->WriteHTML($buf, $first ? HTMLParserMode::DEFAULT_MODE : HTMLParserMode::HTML_BODY);
        }
    }

    /**
     * Builds the normalized fields array used as seal input from mapped submission data.
     *
     * @param array $mapped Normalized field data from SubmissionMapper::map().
     *
     * @return array Array of label/value pairs with normalized string values.
     */
    private static function buildSealFields(array $mapped): array
    {
        $fields = [];
        foreach ($mapped as $field) {
            $is_signature = ($field['type'] ?? '') === 'signature';
            $fields[] = [
                'label' => (string)($field['label'] ?? ''),
                'value' => (!$is_signature && isset($field['value']) && is_string($field['value']))
                    ? self::normalizeFieldValue($field['value'])
                    : '',
            ];
        }
        return $fields;
    }

    /**
     * Normalises a field value by decoding HTML entities, stripping tags,
     * and collapsing whitespace.
     *
     * @param string $value Raw field value string.
     *
     * @return string Normalised plain-text value.
     */
    private static function normalizeFieldValue(string $value): string
    {
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = wp_strip_all_tags($value);
        return trim(preg_replace('/\s+/u', ' ', $value));
    }
}
