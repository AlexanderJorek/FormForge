<?php

/**
 * Static HTML content field for layout and display purposes.
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

namespace ForgeForms\Fields;

defined('ABSPATH') || exit;

/**
 * Static HTML content field (non-interactive).
 */
class HtmlField extends BaseField
{
    /**
     * Returns field-specific CSS styles.
     *
     * @return string
     */
    public function getStyles(): string
    {
        return <<<'CSS'
.forge-field--html { font-size: 14px; line-height: 1.7; color: var(--forge-text); }
.forge-field--html a { color: var(--forge-accent); }
CSS;
    }

    /**
     * Returns the field type label.
     *
     * @return string
     */
    public function getLabel(): string
    {
        return __('HTML Block', 'form-forge');
    }

    /**
     * Returns the Font Awesome icon class.
     *
     * @return string
     */
    public function getIcon(): string
    {
        return 'fa-solid fa-code';
    }

    /**
     * Returns false because HTML fields have no required-toggle in the editor.
     *
     * @return bool
     */
    public function hasRequired(): bool
    {
        return false;
    }

    /**
     * Returns true if validation should be skipped for this field type.
     *
     * @return bool
     */
    public function skipValidation(): bool
    {
        return true;
    }

    /**
     * HTML blocks are excluded from the {all_fields} email summary.
     *
     * @return bool
     */
    public function includeInEmailSummary(): bool
    {
        return false;
    }

    /**
     * Renders the field HTML.
     *
     * @param array  $config   Field configuration.
     * @param string $field_id Unique field identifier.
     * @param mixed  $value    Current field value.
     *
     * @return string Rendered HTML.
     */
    public function sanitizeConfigValue(string $key, string $value): string
    {
        return $key === 'html_content' ? self::kses($value) : \wp_kses_post($value);
    }

    /**
     * Strips only <script> tags; allows all other HTML elements and attributes.
     * Applied on both save and render so the stored value round-trips cleanly.
     */
    private static function kses(string $html): string
    {
        $html = preg_replace('#<script\b[^>]*+>[\s\S]*?</script>#i', '', $html);

        $base = \wp_kses_allowed_html('post');
        $extra = [
            'input'    => ['type'=>true,'name'=>true,'id'=>true,'value'=>true,
                           'placeholder'=>true,'checked'=>true,'disabled'=>true,
                           'readonly'=>true,'required'=>true,'min'=>true,'max'=>true,
                           'step'=>true,'pattern'=>true,'autocomplete'=>true,
                           'accept'=>true,'multiple'=>true,'class'=>true],
            'select'   => ['name'=>true,'id'=>true,'multiple'=>true,'disabled'=>true,
                           'required'=>true,'class'=>true],
            'option'   => ['value'=>true,'selected'=>true,'disabled'=>true],
            'optgroup' => ['label'=>true,'disabled'=>true],
            'source'   => ['src'=>true,'type'=>true,'media'=>true,'srcset'=>true,'sizes'=>true],
            'track'    => ['kind'=>true,'src'=>true,'srclang'=>true,'label'=>true,'default'=>true],
            'canvas'   => ['id'=>true,'width'=>true,'height'=>true,'class'=>true],
            'svg'      => ['xmlns'=>true,'width'=>true,'height'=>true,'viewbox'=>true,
                           'class'=>true,'fill'=>true,'stroke'=>true,
                           'stroke-width'=>true,'aria-hidden'=>true],
            'circle'   => ['cx'=>true,'cy'=>true,'r'=>true,'fill'=>true,'stroke'=>true,
                           'stroke-width'=>true,'class'=>true],
            'rect'     => ['x'=>true,'y'=>true,'width'=>true,'height'=>true,'rx'=>true,'ry'=>true,
                           'fill'=>true,'stroke'=>true,'stroke-width'=>true,'class'=>true],
            'path'     => ['d'=>true,'fill'=>true,'stroke'=>true,'stroke-width'=>true,
                           'fill-rule'=>true,'clip-rule'=>true,'class'=>true],
            'line'     => ['x1'=>true,'y1'=>true,'x2'=>true,'y2'=>true,'stroke'=>true,
                           'stroke-width'=>true,'class'=>true],
            'polyline' => ['points'=>true,'fill'=>true,'stroke'=>true,'stroke-width'=>true,'class'=>true],
            'polygon'  => ['points'=>true,'fill'=>true,'stroke'=>true,'stroke-width'=>true,'class'=>true],
            'ellipse'  => ['cx'=>true,'cy'=>true,'rx'=>true,'ry'=>true,'fill'=>true,
                           'stroke'=>true,'class'=>true],
            'g'        => ['fill'=>true,'stroke'=>true,'transform'=>true,'class'=>true,
                           'opacity'=>true,'fill-opacity'=>true,'stroke-opacity'=>true],
            'defs'     => [],
            'use'      => ['href'=>true,'xlink:href'=>true,'x'=>true,'y'=>true,'width'=>true,'height'=>true],
            'text'     => ['x'=>true,'y'=>true,'fill'=>true,'font-size'=>true,'text-anchor'=>true,
                           'class'=>true,'transform'=>true],
            'animate'  => ['attributeName'=>true,'from'=>true,'to'=>true,'dur'=>true,
                           'repeatCount'=>true,'values'=>true,'calcMode'=>true],
            'animateTransform' => ['attributeName'=>true,'type'=>true,'from'=>true,'to'=>true,
                                   'dur'=>true,'repeatCount'=>true],
        ];
        $html = \wp_kses($html, array_merge($base, $extra));
        // <use href>/<use xlink:href> may only reference an in-document fragment;
        // anything else (javascript:, data:, external URLs) is stripped.
        $html = preg_replace_callback(
            '/<use\b[^>]*>/i',
            static function ($m) {
                return preg_replace('/\s(?:xlink:)?href\s*=\s*(["\'])(?!#)[^"\']*\1/i', '', $m[0]);
            },
            $html
        );
        return $html;
    }

    public function render(array $config, string $field_id, mixed $value = null): string
    {
        $html = self::kses($config['html_content'] ?? '');
        return '<div class="forge-field forge-field--html" data-field-id="'
            . esc_attr($field_id) . '">'
            . $html
            . '</div>';
    }

    /**
     * Returns the sanitized HTML content as a single labeled entry.
     *
     * @param string $field_id Field identifier.
     * @param string $label    Field label.
     * @param mixed  $value    Raw submitted value (unused).
     * @param array  $config   Field configuration.
     * @param array  $context  Submission context (unused).
     *
     * @return array<string, array>
     */
    public function mapNormalized(
        string $field_id,
        string $label,
        mixed $value,
        array $config,
        array $context
    ): array {
        $html = self::kses($config['html_content'] ?? '');
        if ($html === '') {
            return [];
        }
        return [$field_id => [
            'label' => $label ?: null,
            'type'  => 'html',
            'value' => $html,
        ]];
    }

    /**
     * Maps the field value to a human-readable string for email and PDF output.
     *
     * @param mixed $value  Submitted value.
     * @param array $config Field configuration.
     *
     * @return string Human-readable representation.
     */
    public function map(mixed $value, array $config): string
    {
        return wp_strip_all_tags($config['html_content'] ?? '');
    }

    /**
     * Override: the stored value is already HTML, so it must not be escaped.
     * If the form author left the label blank, skip the label row entirely.
     *
     * @param array $field Normalized entry from FieldRegistry::mapSubmission().
     *
     * @return array PDF render descriptor.
     */
    public function pdfData(array $field): array
    {
        $desc = $this->pdf($field)->rawHtml((string)($field['value'] ?? ''));
        if (empty($field['label'])) {
            $desc->unlabeled();
        }
        return $desc->build();
    }

    /**
     * Returns the default field configuration.
     *
     * @return array
     */
    public function getDefaultConfig(): array
    {
        return [
            'label'        => __('HTML Block', 'form-forge'),
            'html_content' => '<p>Text hier</p>',
            'required'     => false,
            'description'  => '',
        ];
    }

    /**
     * Returns the general settings schema for the field editor.
     *
     * @return array
     */
    public function getGeneralSchema(): array
    {
        return [
            [
                'key'   => 'html_content',
                'type'  => 'html_editor',
                'label' => __('HTML content', 'form-forge'),
            ],
        ];
    }
}
