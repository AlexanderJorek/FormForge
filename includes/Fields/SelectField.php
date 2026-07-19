<?php

/**
 * Dropdown select field.
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
 * Dropdown select input field.
 */
class SelectField extends BaseField
{
    /**
     * Returns field-specific CSS styles.
     *
     * @return string
     */
    public function getStyles(): string
    {
        return <<<'CSS'
.forge-select { cursor: pointer; }
.forge-select-wrap { position: relative; }
.forge-select-custom {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    height: var(--forge-input-height);
    padding: 0 12px;
    border: 1px solid var(--forge-border-input);
    border-radius: var(--forge-radius);
    background: var(--forge-bg);
    color: var(--forge-text);
    font-size: var(--forge-font-size);
    font-family: var(--forge-font);
    cursor: pointer;
    user-select: none;
    outline: none;
    transition: border-color .15s, box-shadow .15s;
}
.forge-select-custom:focus,
.forge-select-custom[aria-expanded="true"] {
    border-color: var(--forge-accent);
    box-shadow: 0 0 0 3px
        color-mix(in srgb, var(--forge-accent) 15%, transparent);
}
.forge-select-display {
    flex: 1;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.forge-select-display--placeholder { color: var(--forge-text-muted); }
.forge-select-arrow {
    font-size: 24px;
    color: var(--forge-text-muted);
    transition: transform .15s;
    flex-shrink: 0;
}
.forge-select-custom[aria-expanded="true"] .forge-select-arrow {
    transform: rotate(180deg);
}
.forge-select-panel {
    display: none;
    position: absolute;
    top: calc(100% + 4px);
    left: 0;
    right: 0;
    background: var(--forge-bg);
    border: 1px solid var(--forge-border-input);
    border-radius: var(--forge-radius);
    box-shadow: 0 4px 12px rgba(0,0,0,.1);
    z-index: 999;
    max-height: 260px;
    overflow-y: auto;
}
.forge-select-custom[aria-expanded="true"] .forge-select-panel { display: block; }
.forge-select-option {
    padding: 9px 12px;
    cursor: pointer;
    font-size: var(--forge-font-size);
    color: var(--forge-text);
    border-bottom: 1px solid var(--forge-border);
    transition: background .1s;
}
.forge-select-option:last-child { border-bottom: none; }
.forge-select-option:hover,
.forge-select-option--focused { background: var(--forge-accent-light); }
.forge-select-option--selected { font-weight: 600; color: var(--forge-accent); }
.forge-select-option--placeholder { color: var(--forge-text-muted); }
CSS;
    }

    /**
     * Returns the field type label.
     *
     * @return string
     */
    public function getLabel(): string
    {
        return __('Dropdown', 'form-forge');
    }

    /**
     * Returns the Font Awesome icon class.
     *
     * @return string
     */
    public function getIcon(): string
    {
        return 'fa-solid fa-chevron-down';
    }

    /**
     * Returns client-side initialization JavaScript function body.
     *
     * @return string
     */
    public function getClientInit(): string
    {
        return <<<'JS'
        function (root) {
            root.querySelectorAll('select.forge-select').forEach(function (native) {
                if (native.dataset.forgeSelectInit) return;
                native.dataset.forgeSelectInit = '1';
                native.classList.add('forge-select-native');
                var wrap = document.createElement('div');
                wrap.className = 'forge-select-wrap';
                native.parentNode.insertBefore(wrap, native);
                wrap.appendChild(native);
                var custom = document.createElement('div');
                custom.className = 'forge-select-custom';
                custom.tabIndex  = 0;
                custom.setAttribute('role', 'combobox');
                custom.setAttribute('aria-expanded', 'false');
                custom.setAttribute('aria-haspopup', 'listbox');
                var display = document.createElement('span');
                display.className = 'forge-select-display';
                var arrow = document.createElement('span');
                arrow.className   = 'forge-select-arrow';
                arrow.textContent = '▾';
                arrow.setAttribute('aria-hidden', 'true');
                var panel = document.createElement('div');
                panel.className = 'forge-select-panel';
                panel.setAttribute('role', 'listbox');
                custom.appendChild(display); custom.appendChild(arrow); custom.appendChild(panel);
                wrap.appendChild(custom);
                function buildOptions() {
                    panel.innerHTML = '';
                    Array.from(native.options).forEach(function (opt) {
                        var item = document.createElement('div');
                        item.className = 'forge-select-option';
                        item.textContent = opt.text;
                        item.dataset.value = opt.value;
                        item.setAttribute('role', 'option');
                        if (!opt.value) item.classList.add('forge-select-option--placeholder');
                        if (opt.selected) item.classList.add('forge-select-option--selected');
                        item.addEventListener('click', function (e) {
                            e.stopPropagation();
                            native.value = opt.value;
                            native.dispatchEvent(new Event('change', { bubbles: true }));
                            close();
                        });
                        panel.appendChild(item);
                    });
                    syncDisplay();
                }
                function syncDisplay() {
                    var sel = native.options[native.selectedIndex];
                    panel.querySelectorAll('.forge-select-option').forEach(function (el) {
                        el.classList.toggle('forge-select-option--selected', el.dataset.value === native.value);
                    });
                    if (sel && sel.value) {
                        display.textContent = sel.text;
                        display.classList.remove('forge-select-display--placeholder');
                    } else {
                        display.textContent = sel ? sel.text : '';
                        display.classList.add('forge-select-display--placeholder');
                    }
                }
                function open() {
                    custom.setAttribute('aria-expanded', 'true');
                    document.querySelectorAll('.forge-select-custom[aria-expanded="true"]').forEach(function (o) {
                        if (o !== custom) o.setAttribute('aria-expanded', 'false');
                    });
                }
                function close() { custom.setAttribute('aria-expanded', 'false'); }
                custom.addEventListener('click', function () {
                    custom.getAttribute('aria-expanded') === 'true' ? close() : open();
                });
                custom.addEventListener('keydown', function (e) {
                    var isOpen = custom.getAttribute('aria-expanded') === 'true';
                    if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); isOpen ? close() : open(); }
                    else if (e.key === 'Escape') { close(); }
                    else if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                        e.preventDefault();
                        var dir = e.key === 'ArrowDown' ? 1 : -1;
                        var idx = native.selectedIndex + dir;
                        if (idx >= 0 && idx < native.options.length) {
                            native.selectedIndex = idx;
                            native.dispatchEvent(new Event('change', { bubbles: true }));
                            syncDisplay();
                        }
                    }
                });
                document.addEventListener('click', function (e) { if (!wrap.contains(e.target)) close(); });
                native.addEventListener('change', syncDisplay);
                buildOptions();
            });
        }
        JS;
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
    public function render(array $config, string $field_id, mixed $value = null): string
    {
        $req     = !empty($config['required']) ? ' required aria-required="true"' : '';
        $options = $config['options'] ?? [];

        /* When no submitted value, fall back to the option marked as default */
        if ($value === null) {
            foreach ($options as $opt) {
                if (is_array($opt) && !empty($opt['default'])) {
                    $value = $opt['value'] ?? '';
                    break;
                }
            }
        }

        $inner = '<select id="' . esc_attr($field_id) . '" name="' . esc_attr($field_id)
            . '" class="forge-input forge-select" autocomplete="off"' . $req . '>';
        $inner .= '<option value="">' . esc_html__('— Please select —', 'form-forge') . '</option>';
        foreach ($options as $opt) {
            $opt_val   = is_array($opt) ? ($opt['value'] ?? '') : $opt;
            $opt_label = is_array($opt) ? ($opt['label'] ?? $opt_val) : $opt;
            $selected  = selected((string)($value ?? ''), (string)$opt_val, false);
            $inner .= '<option value="' . esc_attr((string)$opt_val) . '"'
                . $selected . '>'
                . esc_html((string)$opt_label)
                . '</option>';
        }
        if (!empty($config['other_option'])) {
            $other_val = is_array($value) ? '' : (string)($value ?? '');
            $inner .= '<option value="__other__"' . selected($other_val, '__other__', false) . '>Other…</option>';
        }
        $inner .= '</select>';
        if (!empty($config['other_option'])) {
            $show  = (string)($value ?? '') === '__other__' ? '' : ' style="display:none"';
            $inner .= '<input type="text" name="' . esc_attr($field_id) . '_other"'
                . ' class="forge-input forge-other-input" placeholder="Bitte angeben"' . $show . '>';
        }

        return $this->wrap($field_id, $config, $inner);
    }

    /**
     * Maps the field value to the normalized submission entry.
     *
     * @param mixed $value  Submitted value.
     * @param array $config Field configuration.
     *
     * @return string Normalized field entry.
     */
    public function map(mixed $value, array $config): string
    {
        if (empty($value)) {
            return __('[No entry]', 'form-forge');
        }
        if ((string)$value === '__other__') {
            return __('[Other]', 'form-forge');
        }
        foreach ($config['options'] ?? [] as $opt) {
            $opt_val = is_array($opt) ? ($opt['value'] ?? '') : $opt;
            if ((string)$opt_val === (string)$value) {
                return is_array($opt) ? ($opt['label'] ?? (string)$opt_val) : (string)$opt;
            }
        }
        return (string)$value;
    }

    /**
     * Returns the default field configuration.
     *
     * @return array
     */
    public function getDefaultConfig(): array
    {
        return array_merge(
            parent::getDefaultConfig(),
            [
            'multiple'     => false,
            'other_option' => false,
            'options'      => [
                ['value' => 'option-1', 'label' => 'Option 1', 'default' => false],
                ['value' => 'option-2', 'label' => 'Option 2', 'default' => false],
            ],
            ]
        );
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
                'key'   => 'description',
                'type'  => 'text',
                'label' => __('Beschreibung', 'form-forge'),
            ],
            [
                'key'   => 'other_option',
                'type'  => 'checkbox',
                'label' => __('Show "Other" option', 'form-forge'),
            ],
            [
                'key'   => 'options',
                'type'  => 'options_list',
                'label' => __('Optionen', 'form-forge'),
            ],
        ];
    }
}
