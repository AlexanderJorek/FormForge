<?php

/**
 * Section group field used to visually group other fields.
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
 * Section/group container field that wraps child fields.
 */
class GroupField extends BaseField
{
    /**
     * Returns field-specific CSS styles.
     *
     * @return string
     */
    public function getStyles(): string
    {
        return <<<'CSS'
.forge-field-group { margin: 8px 0 var(--forge-gap); }
.forge-group-header {
    padding: 10px 14px;
    border-left: 3px solid var(--forge-accent);
    background: var(--forge-accent-light);
    border-radius: 0 var(--forge-radius) var(--forge-radius) 0;
    margin-bottom: 14px;
}
.forge-group-title {
    font-size: 14px;
    font-weight: 700;
    color: var(--forge-text);
    line-height: 1.3;
    margin: 0 0 2px;
}
.forge-group-desc {
    font-size: 13px;
    color: var(--forge-text-muted);
    line-height: 1.5;
    margin: 0;
}
.forge-group-copies { display: flex; flex-direction: column; gap: 12px; }
.forge-group-copy {
    border: 1px solid var(--forge-border);
    border-radius: var(--forge-radius);
    padding: 16px 16px 4px;
}
.forge-group-copy-hdr {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 12px;
}
.forge-group-copy-num {
    font-size: 11px;
    font-weight: 700;
    color: var(--forge-text-subtle);
    text-transform: uppercase;
    letter-spacing: .06em;
}
.forge-group-copy-remove {
    background: none;
    border: 1px solid var(--forge-error);
    color: var(--forge-error);
    border-radius: var(--forge-radius-sm);
    padding: 3px 10px;
    font-size: 12px;
    cursor: pointer;
    transition: background .1s;
}
.forge-group-copy-remove:hover { background: var(--forge-error-bg); }
.forge-group-add-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    margin-top: 10px;
    padding: 7px 16px;
    background: var(--forge-bg);
    border: 1px dashed var(--forge-accent);
    color: var(--forge-accent);
    border-radius: var(--forge-radius);
    font-size: 13px;
    font-family: var(--forge-font);
    cursor: pointer;
    transition: background .1s;
}
.forge-group-add-btn:hover { background: var(--forge-accent-light); }
CSS;
    }

    /**
     * Returns the field type label.
     *
     * @return string
     */
    public function getLabel(): string
    {
        return 'Feldgruppe';
    }

    /**
     * Returns the Font Awesome icon class.
     *
     * @return string
     */
    public function getIcon(): string
    {
        return 'fa-solid fa-layer-group';
    }

    /**
     * Returns false because group fields have no settings panel in the builder.
     *
     * @return bool
     */
    public function hasSettingsPanel(): bool
    {
        return false;
    }

    /**
     * Returns false because group fields have no required-toggle in the editor.
     *
     * @return bool
     */
    public function hasRequired(): bool
    {
        return false;
    }

    /**
     * Returns the opening wrapper tag for this group.
     *
     * Children are injected by FormRenderer — this just provides the container.
     *
     * @param array  $config   Field configuration.
     * @param string $field_id Unique field identifier.
     *
     * @return string Opening HTML tag.
     */
    public function openTag(array $config, string $field_id): string
    {
        $cond_attr = '';
        if (!empty($config['conditions']['rules'])) {
            $cond_attr = ' data-conditions="' . esc_attr(wp_json_encode($config['conditions'])) . '"';
        }
        $desc = $config['description'] ?? '';
        $desc_html = $desc !== ''
            ? '<p class="forge-field-description">' . esc_html($desc) . '</p>'
            : '';
        return '<div class="forge-field-group" data-field-id="' . esc_attr($field_id) . '"' . $cond_attr . '>'
            . $desc_html;
    }

    /**
     * Returns the closing wrapper tag for this group.
     *
     * @return string Closing HTML tag.
     */
    public function closeTag(): string
    {
        return '</div>';
    }

    /**
     * Renders the field HTML.
     *
     * Fallback only — not called during normal rendering; FormRenderer uses
     * openTag() and closeTag() directly to inject child fields.
     *
     * @param array  $config   Field configuration.
     * @param string $field_id Unique field identifier.
     * @param mixed  $value    Current field value.
     *
     * @return string Rendered HTML.
     */
    public function render(array $config, string $field_id, mixed $value = null): string
    {
        return $this->openTag($config, $field_id) . $this->closeTag();
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
        return '';
    }

    /**
     * Returns the default field configuration.
     *
     * @return array
     */
    public function getDefaultConfig(): array
    {
        return [
            'label'       => 'Feldgruppe',
            'description' => '',
            'required'    => false,
            'children'    => [],
            'conditions'  => ['action' => 'show', 'match' => 'all', 'rules' => []],
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
            ['key' => 'description', 'type' => 'text', 'label' => 'Beschreibung'],
        ];
    }
}
