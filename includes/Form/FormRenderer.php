<?php

/**
 * Renders form HTML for front-end display.
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

namespace ForgeForms\Form;

defined('ABSPATH') || exit;

use ForgeForms\Fields\FieldRegistry;

/**
 * Renders forge_form shortcode output and form HTML strings.
 */
class FormRenderer
{
    /**
     * Handles the [forge_form] shortcode and returns the rendered form HTML.
     *
     * @param array $atts Shortcode attributes (expects 'id' key).
     *
     * @return string Rendered form HTML or empty string.
     */
    public static function shortcode(array $atts): string
    {
        $form_id = (int)($atts['id'] ?? 0);
        if (!$form_id) {
            return '';
        }
        return self::render($form_id);
    }

    /**
     * Renders a form as an HTML string.
     *
     * @param int   $form_id           Post ID of the form to render.
     * @param array $settings_override Optional settings to override form defaults.
     *
     * @return string Rendered HTML string.
     */
    public static function render(int $form_id, array $settings_override = []): string
    {
        $form = FormModel::get($form_id);
        if (!$form) {
            return '';
        }
        if (!empty($settings_override)) {
            $form->settings = array_merge($form->settings, $settings_override);
        }

        $nonce        = wp_create_nonce('forge_forms_submit_' . $form_id);
        $ajax_url     = esc_url(admin_url('admin-ajax.php'));
        $submit_label   = esc_html($form->settings['submit_label']   ?? 'Absenden');
        $submit_working = esc_attr($form->settings['submit_working'] ?? 'Wird gesendet…');
        $success_msg    = esc_attr($form->settings['success_message'] ?? 'Vielen Dank!');
        $has_upload   = self::hasFieldType($form->fields, 'upload');
        $has_captcha  = self::hasFieldType($form->fields, 'captcha');

        if ($has_captcha) {
            wp_enqueue_script('google-recaptcha', 'https://www.google.com/recaptcha/api.js', [], null, true);
        }

        ob_start();
        ?>
        <div class="forge-form-wrap" id="forge-form-<?php echo esc_attr($form_id); ?>">
            <div class="forge-form-messages" role="alert" aria-live="polite" style="display:none;"></div>
            <form
                class="forge-form"
                id="forge-form-inner-<?php echo esc_attr($form_id); ?>"
                method="post"
                action="<?php echo $ajax_url; ?>"
                <?php echo $has_upload ? 'enctype="multipart/form-data"' : ''; ?>
                novalidate
                data-form-id="<?php echo esc_attr($form_id); ?>"
                <?php echo self::hasFieldType($form->fields, 'pagebreak') ? 'data-has-pages="true"' : ''; ?>
            >
                <input type="hidden" name="action"     value="forge_forms_submit">
                <input type="hidden" name="form_id"    value="<?php echo $form_id; ?>">
                <input type="hidden" name="forge_nonce" value="<?php echo esc_attr($nonce); ?>">
                <!-- Honeypot -->
                <input type="text" name="forge_hp_field"
                       style="display:none!important" tabindex="-1" autocomplete="off">

                <?php echo self::renderFields($form->fields); ?>

                <div class="forge-form-footer">
                    <button type="submit" class="forge-submit-btn"
                            data-working="<?php echo $submit_working; ?>"
                            data-success="<?php echo $success_msg; ?>">
                        <span class="forge-submit-label"><?php echo $submit_label; ?></span>
                        <span class="forge-submit-spinner" aria-hidden="true" style="display:none;"></span>
                    </button>
                </div>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Renders a flat list of fields (used for group children).
     *
     * No page-break handling; fields use their own plain IDs.
     *
     * @param array $fields Array of field config arrays.
     *
     * @return string
     */
    private static function renderChildFields(array $fields): string
    {
        return self::renderFields($fields, insideGroup: true);
    }

    /**
     * Renders form fields as HTML, handling page-break divs and column layout.
     *
     * @param array $fields      Array of field configuration arrays.
     * @param bool  $insideGroup Whether the fields are inside a group field.
     *
     * @return string HTML string of rendered fields.
     */
    private static function renderFields(array $fields, bool $insideGroup = false): string
    {
        $html    = '';
        $page    = 0;
        $in_page = false;
        $count   = count($fields);
        $i       = 0;

        /* If the form has any pagebreaks, wrap everything in page divs.
         * Open page 0 immediately so fields before the first pagebreak are included. */
        $has_pagebreaks = !$insideGroup && !empty(
            array_filter(
                $fields,
                static fn($f) => ($f['type'] ?? '') === 'pagebreak'
            )
        );
        if ($has_pagebreaks) {
            $html   .= '<div class="forge-form-page forge-page-active" data-page="0">';
            $in_page = true;
            $page    = 1;
        }

        while ($i < $count) {
            $field_cfg  = $fields[$i];
            $field_id   = $field_cfg['id']   ?? '';
            $field_type = $field_cfg['type'] ?? '';
            $cols       = (int)($field_cfg['cols'] ?? 12);

            if (!$field_id || !$field_type) {
                $i++;
                continue;
            }

            if ($field_type === 'group') {
                $handler = FieldRegistry::get('group');
                if ($handler instanceof \ForgeForms\Fields\GroupField) {
                    $children_html = self::renderChildFields($field_cfg['children'] ?? []);
                    $html .= '<div class="forge-row"><div class="forge-col forge-col-12">'
                        . $handler->openTag($field_cfg, $field_id)
                        . $children_html
                        . $handler->closeTag()
                        . '</div></div>';
                }
                $i++;
                continue;
            }

            if ($field_type === 'pagebreak' && !$insideGroup) {
                $prev_label = esc_html($field_cfg['prev_btn'] ?? '← Zurück');
                $next_label = esc_html($field_cfg['next_btn'] ?? 'Weiter →');
                $prev_btn = $page > 1
                    ? '<button type="button" class="forge-btn forge-btn-prev">' . $prev_label . '</button>'
                    : '<span></span>';
                $next_btn = '<button type="button" class="forge-btn forge-btn-next">' . $next_label . '</button>';
                $html .= '<div class="forge-page-nav">' . $prev_btn . $next_btn . '</div>';
                $html .= '</div>'; /* close current page */
                $html .= '<div class="forge-form-page" data-page="' . $page . '">';
                /* Prev button at the top of every non-first page so the last page
                 * (which has no closing pagebreak) always has a way back. */
                $html .= '<div class="forge-page-nav forge-page-nav--top">'
                    . '<button type="button" class="forge-btn forge-btn-prev">' . $prev_label . '</button>'
                    . '</div>';
                $in_page = true;
                $page++;
                $i++;
                continue;
            }

            $handler = FieldRegistry::get($field_type);
            if (!$handler) {
                $i++;
                continue;
            }

            $cond_attr = !empty($field_cfg['conditions']['rules'])
                ? ' data-conditions="' . esc_attr(wp_json_encode($field_cfg['conditions'])) . '"'
                : '';

            if ($cols === 6) {
                $next      = $fields[$i + 1] ?? null;
                $next_cols = (int)($next['cols'] ?? 12);
                $next_type = $next['type']    ?? '';
                $next_id   = $next['id']      ?? '';

                $next_handler = ($next && $next_cols === 6 && $next_type !== 'pagebreak' && $next_id)
                    ? FieldRegistry::get($next_type) : null;

                if ($next_handler) {
                    $next_cond = !empty($next['conditions']['rules'])
                        ? ' data-conditions="' . esc_attr(wp_json_encode($next['conditions'])) . '"'
                        : '';
                    $col_a  = '<div class="forge-col forge-col-6"' . $cond_attr . '>';
                    $col_a .= $handler->render($field_cfg, $field_id) . '</div>';
                    $col_b  = '<div class="forge-col forge-col-6"' . $next_cond . '>';
                    $col_b .= $next_handler->render($next, $next_id) . '</div>';
                    $html  .= '<div class="forge-row forge-row--pair">' . $col_a . $col_b . '</div>';
                    $i += 2;
                    continue;
                }

                $html .= '<div class="forge-row"' . $cond_attr . '><div class="forge-col forge-col-6">'
                    . $handler->render($field_cfg, $field_id)
                    . '</div></div>';
            } else {
                $html .= '<div class="forge-row"' . $cond_attr . '><div class="forge-col forge-col-12">'
                    . $handler->render($field_cfg, $field_id)
                    . '</div></div>';
            }

            $i++;
        }

        if ($in_page) {
            $html .= '</div>';
        }

        return $html;
    }

    /**
     * Checks whether the fields array contains at least one field of the given type.
     *
     * @param array  $fields Array of field configuration arrays.
     * @param string $type   The field type slug to look for.
     *
     * @return bool True if at least one field of that type exists.
     */
    private static function hasFieldType(array $fields, string $type): bool
    {
        foreach ($fields as $f) {
            if (($f['type'] ?? '') === $type) {
                return true;
            }
        }
        return false;
    }
}
