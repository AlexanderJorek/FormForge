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

namespace ForgeForms\PDF;

use ForgeForms\Form\FormModel;
use ForgeForms\Admin\FormSettings;

defined('ABSPATH') || exit;

class MailSender
{
    /**
     * Hooked into forge_forms_submission.
     * Generates PDF once, sends one email per enabled notification,
     * attaches PDF to those with attach_pdf = true.
     */
    public static function onSubmission(int $form_id, array $mapped, FormModel $form): void
    {
        if (empty($form->notifications)) {
            return;
        }

        /* ---- Generate PDF once ---- */
        $pdf_path = Generator::generate($mapped, $form_id, $form->title);

        if ($pdf_path === false) {
            \ForgeForms\forge_log("ForgeForms MailSender: PDF generation failed for form {$form_id}");
        }

        $from_email = get_option('forge_forms_from_email', get_option('admin_email'));
        $from_name  = get_option('forge_forms_from_name', get_bloginfo('name'));

        foreach ($form->notifications as $notif) {
            if (empty($notif['enabled'])) {
                continue;
            }

            $to = self::resolveRecipient($notif['to'] ?? '', $mapped, $form);
            if (empty($to)) {
                continue;
            }

            $should_attach = !empty($notif['attach_pdf'])
                || FormSettings::shouldAttachPdf($form_id, $notif['slug'] ?? '');

            $subject = self::replacePlaceholders($notif['subject'] ?? 'Neue Einsendung', $mapped, $form);
            $body    = self::buildEmailBody($notif['body'] ?? '', $mapped, $form);

            $headers = [
                'Content-Type: text/html; charset=UTF-8',
                'From: ' . $from_name . ' <' . $from_email . '>',
            ];

            $reply_to = self::resolveRecipient($notif['reply_to'] ?? '', $mapped, $form);
            if ($reply_to) {
                $headers[] = 'Reply-To: ' . $reply_to;
            }

            $attachments = [];
            if ($should_attach && $pdf_path && file_exists($pdf_path)) {
                $attachments[] = $pdf_path;
            }

            wp_mail(
                $to,
                $subject,
                $body,
                $headers,
                $attachments
            );
        }

        /* ---- Clean up PDF after all emails sent ---- */
        register_shutdown_function(static function () use ($pdf_path): void {
            if ($pdf_path && file_exists($pdf_path)) {
                @unlink($pdf_path);
            }
        });
    }

    private static function resolveRecipient(string $to, array $mapped, FormModel $form): string
    {
        /* Support {admin_email}, {field_id} */
        $to = self::replacePlaceholders($to, $mapped, $form);
        $to = sanitize_email(trim($to));
        return is_email($to) ? $to : '';
    }

    private static function replacePlaceholders(string $text, array $mapped, FormModel $form): string
    {
        /* {admin_email} */
        $text = str_replace('{admin_email}', get_option('admin_email'), $text);
        /* {form_title} */
        $text = str_replace('{form_title}', $form->title, $text);
        /* {site_name} */
        $text = str_replace('{site_name}', get_bloginfo('name'), $text);

        /* {all_fields} — formatted list */
        if (str_contains($text, '{all_fields}')) {
            $all = '';
            foreach ($mapped as $entry) {
                $label = $entry['label'] ?? '';
                $value = $entry['value'] ?? '';
                if (in_array($entry['type'] ?? '', ['html', 'pagebreak'], true)) {
                    continue;
                }
                if ($label !== '') {
                    $all .= '<strong>' . esc_html((string)$label) . '</strong><br>'
                        . nl2br(esc_html((string)$value)) . '<br><br>';
                }
            }
            $text = str_replace('{all_fields}', $all, $text);
        }

        /* {field_id} placeholders — match any field by its ID; escape for HTML contexts */
        foreach ($mapped as $key => $entry) {
            $safe = esc_html((string)($entry['value'] ?? ''));
            $text = str_replace('{' . $key . '}', $safe, $text);
        }

        return $text;
    }

    private static function buildEmailBody(string $body_template, array $mapped, FormModel $form): string
    {
        $body = self::replacePlaceholders($body_template, $mapped, $form);

        return '<!DOCTYPE html><html><body style="font-family:Arial,sans-serif;font-size:14px;color:#333;">'
            . '<p>' . nl2br(wp_kses_post($body)) . '</p>'
            . '</body></html>';
    }
}
