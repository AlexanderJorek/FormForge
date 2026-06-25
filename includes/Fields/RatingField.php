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

namespace ForgeForms\Fields;

defined('ABSPATH') || exit;

class RatingField extends BaseField
{
    public function getStyles(): string
    {
        return <<<'CSS'
.forge-rating-group { display: inline-flex; gap: 2px; }
.forge-rating-star {
    position: relative;
    display: inline-block;
    line-height: 1;
    cursor: pointer;
}
.forge-rating-bg {
    display: block;
    font-size: 30px;
    color: var(--forge-border);
    user-select: none;
    transition: color .1s;
}
.forge-rating-fill {
    position: absolute;
    top: 0; left: 0;
    display: block;
    font-size: 30px;
    color: #f0a500;
    pointer-events: none;
    user-select: none;
    white-space: nowrap;
    clip-path: inset(0 100% 0 0);
    transition: clip-path .1s;
}
.forge-rating-star--full .forge-rating-fill { clip-path: inset(0 0% 0 0); }
.forge-rating-star--half .forge-rating-fill { clip-path: inset(0 50% 0 0); }
.forge-rating-zone {
    position: absolute;
    top: 0;
    height: 100%;
    display: block;
    cursor: pointer;
}
.forge-rating-zone input { display: none; }
.forge-rating-zone-half { left: 0; width: 50%; }
.forge-rating-zone-full { right: 0; width: 50%; }
.forge-rating-zone-full--only { left: 0; right: 0; width: 100%; }
CSS;
    }

    public function getLabel(): string
    {
        return 'Bewertung';
    }
    public function getIcon(): string
    {
        return 'fa-solid fa-star';
    }

    public function getClientEmptyCheck(): array
    {
        return ['fn' => "function(f){return !f.querySelector('.forge-rating-group input:checked');}"];
    }

    public function getClientInit(): string
    {
        return <<<'JS'
        function (root) {
            root.querySelectorAll('.forge-rating-group').forEach(function (group) {
                var stars = Array.from(group.querySelectorAll('.forge-rating-star'));
                function highlight(val) {
                    stars.forEach(function (star) {
                        var n    = parseInt(star.dataset.star, 10);
                        var full = n <= Math.floor(val);
                        var half = !full && val % 1 !== 0 && n === Math.ceil(val);
                        star.classList.toggle('forge-rating-star--full', full);
                        star.classList.toggle('forge-rating-star--half', half);
                    });
                }
                function restoreChecked() {
                    var checked = group.querySelector('input:checked');
                    highlight(checked ? parseFloat(checked.value) : 0);
                }
                group.addEventListener('mouseover', function (e) {
                    var zone = e.target.closest('.forge-rating-zone');
                    if (!zone) return;
                    var radio = zone.querySelector('input[type="radio"]');
                    if (radio) highlight(parseFloat(radio.value));
                });
                group.addEventListener('mouseleave', restoreChecked);
                group.querySelectorAll('.forge-rating-zone').forEach(function (zone) {
                    var radio = zone.querySelector('input[type="radio"]');
                    if (!radio) return;
                    zone.addEventListener('click', function () {
                        radio.checked = true;
                        radio.dispatchEvent(new Event('change', { bubbles: true }));
                        restoreChecked();
                    });
                });
                restoreChecked();
            });
        }
        JS;
    }

    private const ICONS = [
        'star'    => ['filled' => '★', 'empty' => '☆'],
        'heart'   => ['filled' => '♥', 'empty' => '♡'],
        'circle'  => ['filled' => '●', 'empty' => '○'],
        'diamond' => ['filled' => '◆', 'empty' => '◇'],
    ];

    public function render(array $config, string $field_id, mixed $value = null): string
    {
        $max       = (int)($config['max'] ?? 5);
        $val       = (float)($value ?? 0);
        $half      = !empty($config['allow_half']);
        $custom    = !empty($config['icon_source']) && !empty($config['custom_icon_url']);
        $icon_key  = $config['icon_type'] ?? 'star';
        $icons     = self::ICONS[$icon_key] ?? self::ICONS['star'];
        $req       = !empty($config['required']) ? ' data-required="true"' : '';
        $custom_url = $custom ? esc_url($config['custom_icon_url']) : '';

        $inner = '<div class="forge-rating-group" role="group"'
            . ' aria-label="' . esc_attr($config['label'] ?? 'Bewertung') . '"'
            . ' data-half="' . ($half ? '1' : '0') . '"'
            . $req . '>';

        /* One .forge-rating-star container per visible star.
         * Each container holds two invisible click zones (left=half, right=full)
         * and a visual background + filled overlay controlled by JS classes. */
        for ($i = 1; $i <= $max; $i++) {
            $v_full         = $i;
            $v_half         = $i - 0.5;
            $checked_full   = ((float)$val === (float)$v_full) ? ' checked' : '';
            $checked_half   = ((float)$val === (float)$v_half) ? ' checked' : '';
            $glyph          = esc_html($icons['filled']);

            $inner .= '<span class="forge-rating-star" data-star="' . $i . '">';

            if ($custom_url) {
                /* Custom image: background image tile, filled overlay clips left half */
                $base_style = 'display:block;width:30px;height:30px;'
                    . 'background:url(' . esc_url($custom_url) . ') center/contain no-repeat;opacity:0.2;';
                $fill_style = 'position:absolute;top:0;left:0;width:30px;height:30px;'
                    . 'background:url(' . esc_url($custom_url) . ') center/contain no-repeat;'
                    . 'pointer-events:none;';
                $inner .= '<span class="forge-rating-bg" style="' . $base_style . '"></span>';
                $inner .= '<span class="forge-rating-fill" style="' . $fill_style . '"></span>';
            } else {
                $inner .= '<span class="forge-rating-bg">' . $glyph . '</span>';
                $inner .= '<span class="forge-rating-fill">' . $glyph . '</span>';
            }

            if ($half) {
                $inner .= '<label class="forge-rating-zone forge-rating-zone-half"'
                    . ' title="' . $v_half . '">'
                    . '<input type="radio" name="' . esc_attr($field_id)
                    . '" value="' . $v_half . '"' . $checked_half . '>'
                    . '</label>';
                $inner .= '<label class="forge-rating-zone forge-rating-zone-full"'
                    . ' title="' . $v_full . '">'
                    . '<input type="radio" name="' . esc_attr($field_id)
                    . '" value="' . $v_full . '"' . $checked_full . '>'
                    . '</label>';
            } else {
                $inner .= '<label class="forge-rating-zone forge-rating-zone-full forge-rating-zone-full--only"'
                    . ' title="' . $v_full . '">'
                    . '<input type="radio" name="' . esc_attr($field_id)
                    . '" value="' . $v_full . '"' . $checked_full . '>'
                    . '</label>';
            }

            $inner .= '</span>'; /* .forge-rating-star */
        }
        $inner .= '</div>';

        return $this->wrap($field_id, $config, $inner);
    }

    public function map(mixed $value, array $config): string
    {
        if (empty($value)) {
            return '[Kein Eintrag]';
        }
        return $value . ' / ' . (int)($config['max'] ?? 5);
    }

    public function getDefaultConfig(): array
    {
        return array_merge(parent::getDefaultConfig(), [
            'max'             => 5,
            'icon_type'       => 'star',
            'allow_half'      => false,
            'icon_source'     => false,
            'custom_icon_url' => '',
        ]);
    }

    public function getGeneralSchema(): array
    {
        return [
            ['key' => 'max', 'type' => 'number', 'label' => 'Anzahl Symbole', 'rebuild' => true],
            ['key' => 'icon_type', 'type' => 'icon_row', 'label' => 'Symbol & Halbe Werte',
             'half_key' => 'allow_half', 'rebuild' => true,
             'options' => [
                 ['value' => 'star',    'label' => '★ Stern'],
                 ['value' => 'heart',   'label' => '♥ Herz'],
                 ['value' => 'circle',  'label' => '● Kreis'],
                 ['value' => 'diamond', 'label' => '◆ Raute'],
             ]],
            ['key' => 'icon_source', 'type' => 'bool_seg', 'label' => 'Icon-Quelle',
             'false_label' => 'Voreingestellt', 'true_label' => 'Eigenes Bild', 'rebuild' => true],
            ['key' => 'custom_icon_url', 'type' => 'media_upload', 'label' => 'Bild', 'rebuild' => true,
             'hint' => 'Quadratisches Bild. Bei halben Werten wird die linke Hälfte verwendet.',
             'depends_on' => ['icon_source' => true]],
            ['type' => 'rating_preview'],
        ];
    }
}
