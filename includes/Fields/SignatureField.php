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

class SignatureField extends BaseField
{
    private const ICON_RESET = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512"'
        . ' aria-hidden="true" focusable="false">'
        . '<path d="M125.7 160H176a16 16 0 0 1 0 32H48a16 16 0 0 1-16-16V48a16 16 0 0 1 32 0v68.7'
        . 'C115.3 45.1 191.6 0 278 0c141.4 0 256 114.6 256 256S419.4 512 278 512'
        . 'C167.7 512 74.4 443.5 38 346a16 16 0 1 1 30-11c31.4 83.7 111.5 141 210 141'
        . ' 123.7 0 224-100.3 224-224S401.7 32 278 32c-78.1 0-145.8 39.4-185.3 99.3z"/>'
        . '</svg>';

    public function getStyles(): string
    {
        return <<<'CSS'
.forge-signature-wrap {
    border: 1px solid var(--forge-border-input);
    border-radius: var(--forge-radius);
    overflow: hidden;
    background: #ffffff;
}
.forge-signature-wrap:has(.forge-signature-canvas:focus) {
    border-color: var(--forge-accent);
    box-shadow: 0 0 0 3px
        color-mix(in srgb, var(--forge-accent) 15%, transparent);
}
.forge-signature-canvas {
    display: block;
    width: 100%;
    cursor: crosshair;
    touch-action: none;
    background: #ffffff !important;
    color-scheme: light;
}
.forge-signature-toolbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 6px 10px;
    background: var(--forge-bg-subtle);
    border-top: 1px solid var(--forge-border);
}
.forge-signature-clear {
    -webkit-appearance: none;
    appearance: none;
    background: transparent !important;
    border: 1px solid var(--forge-border-input);
    border-radius: var(--forge-radius-sm);
    padding: 5px 6px;
    cursor: pointer;
    color: var(--forge-text-muted);
    line-height: 1;
    transition: border-color .1s;
}
.forge-signature-clear:hover,
.forge-signature-clear:active,
.forge-signature-clear:focus {
    background: transparent !important;
    outline: none !important;
    box-shadow: none !important;
}
.forge-signature-clear:hover {
    border-color: var(--forge-text-muted);
    color: var(--forge-text);
}
.forge-signature-clear svg {
    display: block;
    width: 14px; height: 14px;
    fill: currentColor;
    overflow: visible;
}
.forge-signature-hint { font-size: 12px; color: var(--forge-text-subtle); }
CSS;
    }

    public function getLabel(): string
    {
        return 'Unterschrift';
    }
    public function getIcon(): string
    {
        return 'fa-solid fa-signature';
    }

    public function getClientInit(): string
    {
        return <<<'JS'
        function (root) {
            function initSignature(wrap) {
                var canvas   = wrap.querySelector('.forge-signature-canvas');
                var input    = wrap.querySelector('input[type="hidden"]');
                var clearBtn = wrap.querySelector('.forge-signature-clear');
                if (!canvas || !input) return;
                var ctx     = canvas.getContext('2d');
                var stroke  = parseFloat(wrap.dataset.stroke || '2');
                var fmt     = wrap.dataset.format || 'png';
                var drawing  = false;
                var leftArea = false;
                function resize() {
                    var rect  = canvas.getBoundingClientRect();
                    var ratio = window.devicePixelRatio || 1;
                    var snap  = canvas.toDataURL();
                    var cssH  = rect.height || (parseFloat(canvas.style.height) || canvas.getAttribute('height') || 160);
                    var cssW  = rect.width  || canvas.offsetWidth || 400;
                    canvas.width  = Math.round(cssW * ratio);
                    canvas.height = Math.round(cssH * ratio);
                    ctx.scale(ratio, ratio);
                    ctx.fillStyle = '#ffffff';
                    ctx.fillRect(0, 0, cssW, cssH);
                    ctx.strokeStyle = '#1d2327';
                    ctx.lineWidth   = stroke;
                    ctx.lineCap     = 'round';
                    ctx.lineJoin    = 'round';
                    if (snap && snap !== 'data:,') {
                        var img = new Image();
                        img.onload = function () { ctx.drawImage(img, 0, 0, cssW, cssH); };
                        img.src = snap;
                    }
                }
                function pos(e) {
                    var rect = canvas.getBoundingClientRect();
                    var src  = e.touches ? e.touches[0] : e;
                    return { x: src.clientX - rect.left, y: src.clientY - rect.top };
                }
                function start(e) {
                    e.preventDefault();
                    drawing = true;
                    var p = pos(e);
                    ctx.beginPath();
                    ctx.moveTo(p.x, p.y);
                }
                function move(e) {
                    if (!drawing) return;
                    e.preventDefault();
                    var p = pos(e);
                    if (leftArea) {
                        ctx.beginPath(); ctx.moveTo(p.x, p.y); leftArea = false;
                    } else {
                        ctx.lineTo(p.x, p.y); ctx.stroke();
                    }
                }
                function end() {
                    if (!drawing) return;
                    drawing = false;
                    input.value = canvas.toDataURL(fmt === 'jpeg' ? 'image/jpeg' : 'image/png');
                }
                canvas.addEventListener('mousedown',  start, { passive: false });
                canvas.addEventListener('mousemove',  move,  { passive: false });
                canvas.addEventListener('mouseleave', function () { leftArea = true; });
                document.addEventListener('mouseup',  end);
                canvas.addEventListener('touchstart', start, { passive: false });
                canvas.addEventListener('touchmove',  move,  { passive: false });
                canvas.addEventListener('touchend',   end);
                if (clearBtn) {
                    clearBtn.addEventListener('click', function () {
                        ctx.fillStyle = '#ffffff';
                        ctx.fillRect(0, 0, canvas.width, canvas.height);
                        input.value = '';
                    });
                }
                resize();
                window.addEventListener('resize', resize);
            }
            (root || document).querySelectorAll('.forge-signature-wrap').forEach(initSignature);
        }
        JS;
    }

    public function render(array $config, string $field_id, mixed $value = null): string
    {
        $req       = !empty($config['required']) ? ' data-required="true"' : '';
        $canvas_id = $field_id . '-canvas';
        $height    = (int)($config['canvas_height'] ?? 160);
        $stroke    = (float)($config['stroke_width'] ?? 2);
        $fmt       = $config['export_format'] ?? 'png';

        $inner = '<div class="forge-signature-wrap"' . $req
            . ' data-field-id="' . esc_attr($field_id) . '"'
            . ' data-stroke="' . esc_attr((string)$stroke) . '"'
            . ' data-format="' . esc_attr($fmt) . '">'
            . '<canvas id="' . esc_attr($canvas_id) . '" class="forge-signature-canvas"'
            . ' width="500" height="' . $height . '"'
            . ' style="height:' . $height . 'px"'
            . ' tabindex="0"'
            . ' aria-label="' . esc_attr($config['label'] ?? 'Unterschrift') . '"></canvas>'
            . '<div class="forge-signature-toolbar">'
            . '<button type="button" class="forge-signature-clear"'
            . ' data-canvas="' . esc_attr($canvas_id) . '" title="Löschen" aria-label="Unterschrift löschen">'
            . self::ICON_RESET . '</button>'
            . '<span class="forge-signature-hint">Hier unterschreiben</span>'
            . '</div>'
            . '<input type="hidden" name="' . esc_attr($field_id) . '" id="' . esc_attr($field_id) . '-data"'
            . ' value="' . esc_attr((string)($value ?? '')) . '">'
            . '</div>';

        return $this->wrap($field_id, $config, $inner);
    }

    public function validate(mixed $value, array $config): bool|string
    {
        if (!empty($config['required'])) {
            $prefix = ($config['export_format'] ?? 'png') === 'jpeg'
                ? 'data:image/jpeg;base64,'
                : 'data:image/png;base64,';
            if (empty($value) || !str_starts_with((string)$value, $prefix)) {
                return ($config['label'] ?? 'Unterschrift') . ' ist ein Pflichtfeld.';
            }
        }
        return true;
    }

    public function map(mixed $value, array $config): string
    {
        return empty($value) ? '[Kein Eintrag]' : 'Erfasste Unterschrift';
    }

    public function getDefaultConfig(): array
    {
        return array_merge(parent::getDefaultConfig(), [
            'canvas_height' => 200,
            'stroke_width'  => 2,
            'export_format' => 'png',
        ]);
    }

    public function getGeneralSchema(): array
    {
        return [];
    }

    public function getAdvancedSchema(): array
    {
        return [
            ['key' => 'canvas_height', 'type' => 'number', 'label' => 'Höhe (px)'],
            ['key' => 'stroke_width',  'type' => 'number', 'label' => 'Strichstärke'],
            ['key'    => 'export_format',
             'type'   => 'pill3',
             'label'  => 'Dateiformat',
             'values' => ['png', 'jpeg'],
             'labels' => ['PNG', 'JPEG']],
        ];
    }
}
