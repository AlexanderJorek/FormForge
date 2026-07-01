<?php

/**
 * File upload field.
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
 * File upload field with MIME type and size validation.
 */
class UploadField extends BaseField
{
    private const BLOCKED_TYPES = [
        'htm','html','shtml','phtml','jse','jar','xml','css','asp','aspx',
        'jsp','sql','hta','dll','bat','com','sh','bash','py','pl','js',
        'php','svg','swf','dfxp','rar','exe',
    ];

    private const TYPE_GROUPS = [
        'images'    => ['jpg','jpeg','png','gif','bmp','tiff','webp'],
        'documents' => ['pdf','doc','docx','xls','xlsx','odt','ods','ppt','pptx','txt','rtf'],
        'audio'     => ['mp3','ogg','wav','m4a','flac'],
        'video'     => ['mp4','mov','avi','wmv','mkv'],
        'archives'  => ['zip','tar','gz','7z'],
    ];

    /**
     * Returns field-specific CSS styles.
     *
     * @return string
     */
    public function getStyles(): string
    {
        return <<<'CSS'
.forge-upload-zone {
    position: relative;
    border: 2px dashed var(--forge-border-input);
    border-radius: var(--forge-radius);
    background: var(--forge-bg);
    transition: border-color .15s, background .15s;
    cursor: pointer;
}
.forge-upload-zone:hover,
.forge-upload-zone.forge-upload-zone--drag {
    border-color: var(--forge-accent);
    background: var(--forge-accent-light);
}
.forge-upload-input {
    position: absolute;
    inset: 0;
    opacity: 0;
    cursor: pointer;
    width: 100%; height: 100%;
}
.forge-upload-zone-body {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 6px;
    padding: 28px 16px;
    pointer-events: none;
    text-align: center;
}
.forge-upload-icon { font-size: 28px; color: var(--forge-text-subtle); line-height: 1; }
.forge-upload-prompt { font-size: 14px; color: var(--forge-text-muted); }
.forge-upload-link { color: var(--forge-accent); text-decoration: underline; }
.forge-upload-filename { font-size: 13px; font-weight: 600; color: var(--forge-text); }
CSS;
    }

    /**
     * Returns the field type label.
     *
     * @return string
     */
    public function getLabel(): string
    {
        return 'Datei-Upload';
    }

    /**
     * Returns the Font Awesome icon class.
     *
     * @return string
     */
    public function getIcon(): string
    {
        return 'fa-solid fa-file-arrow-up';
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
            root.querySelectorAll('.forge-upload-zone').forEach(function (zone) {
                var input    = zone.querySelector('.forge-upload-input');
                var nameEl   = zone.querySelector('.forge-upload-filename');
                var multiple = zone.dataset.multiple === '1';
                function showNames(files) {
                    if (!nameEl || !files || !files.length) return;
                    nameEl.textContent = Array.from(files).map(function (f) { return f.name; }).join(', ');
                }
                if (input) {
                    input.addEventListener('change', function () { showNames(this.files); });
                    var form = input.closest('form');
                    if (form) {
                        form.addEventListener('reset', function () {
                            if (nameEl) { nameEl.textContent = ''; }
                        });
                    }
                }
                zone.addEventListener('dragover', function (e) {
                    e.preventDefault();
                    zone.classList.add('forge-upload-zone--drag');
                });
                zone.addEventListener('dragleave', function () {
                    zone.classList.remove('forge-upload-zone--drag');
                });
                zone.addEventListener('drop', function (e) {
                    e.preventDefault();
                    zone.classList.remove('forge-upload-zone--drag');
                    if (!input) return;
                    var files = e.dataTransfer.files;
                    if (!files || !files.length) return;
                    if (!multiple && files.length > 1) {
                        var single = new DataTransfer();
                        single.items.add(files[0]);
                        files = single.files;
                    }
                    try {
                        var transfer = new DataTransfer();
                        Array.from(files).forEach(function (f) { transfer.items.add(f); });
                        input.files = transfer.files;
                        showNames(input.files);
                    } catch (err) { /* DataTransfer not supported */ }
                });
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
        $req      = !empty($config['required']) ? ' required aria-required="true"' : '';
        $multiple = !empty($config['multiple']) ? ' multiple' : '';
        $accept   = $this->buildAccept($config);
        $acc_attr = $accept !== '' ? ' accept="' . esc_attr($accept) . '"' : '';

        $max    = (int)($config['max_size_mb'] ?? 10);
        $inner  = '<div class="forge-upload-zone" data-multiple="' . ($multiple ? '1' : '0') . '">';
        $inner .= '<input type="file" id="' . esc_attr($field_id) . '" name="'
            . esc_attr($field_id) . ($multiple ? '[]' : '') . '"'
            . ' class="forge-upload-input"' . $acc_attr . $multiple . $req . '>';
        $inner .= '<div class="forge-upload-zone-body" aria-hidden="true">'
            . '<span class="forge-upload-icon">↑</span>'
            . '<span class="forge-upload-prompt">'
            . 'Datei hier ablegen oder <span class="forge-upload-link">klicken zum Auswählen</span>'
            . '</span>'
            . '<span class="forge-upload-filename"></span>'
            . '</div>';
        $inner .= '</div>';

        if ($accept !== '') {
            $inner .= '<p class="forge-field-hint">Erlaubte Dateitypen: ' . esc_html($accept) . '</p>';
        }
        $inner .= '<p class="forge-field-hint">Maximale Dateigröße: ' . $max . ' MB</p>';

        return $this->wrap($field_id, $config, $inner);
    }

    /**
     * Builds the accept attribute value from allowed file type groups and custom types.
     *
     * @param array $config Field configuration.
     *
     * @return string Comma-separated list of allowed file extensions.
     */
    private function buildAccept(array $config): string
    {
        $exts = [];
        foreach (array_keys(self::TYPE_GROUPS) as $group) {
            if (!empty($config['allow_' . $group])) {
                foreach (self::TYPE_GROUPS[$group] as $ext) {
                    $exts[] = '.' . $ext;
                }
            }
        }
        $custom = trim($config['allowed_types'] ?? '');
        if ($custom !== '') {
            foreach (preg_split('/[\s,]+/', $custom) as $e) {
                $e = trim($e);
                if ($e !== '') {
                    $exts[] = strpos($e, '.') === 0 ? $e : '.' . $e;
                }
            }
        }
        $blocked = self::BLOCKED_TYPES;
        $exts    = array_unique(
            array_filter(
                $exts, static function (string $e) use ($blocked): bool {
                    return !in_array(ltrim($e, '.'), $blocked, true);
                }
            )
        );
        return implode(',', $exts);
    }

    /**
     * Validates the submitted value.
     *
     * @param mixed $value  Submitted value.
     * @param array $config Field configuration.
     *
     * @return bool|string True on valid, error message string on invalid.
     */
    public function validate(mixed $value, array $config): bool|string
    {
        $field_id = $config['field_id'] ?? '';
        $file     = isset($_FILES[$field_id]) ? $_FILES[$field_id] : null;

        if (!empty($config['required'])) {
            if (!$file || empty($file['name'])) {
                return ($config['label'] ?? 'Datei') . ': Bitte laden Sie eine Datei hoch.';
            }
        }

        if ($file && !empty($file['name'])) {
            $names = is_array($file['name']) ? $file['name'] : [$file['name']];
            foreach ($names as $name) {
                $ext = strtolower(pathinfo((string)$name, PATHINFO_EXTENSION));
                if (in_array($ext, self::BLOCKED_TYPES, true)) {
                    return 'Dateityp ".' . esc_html($ext) . '" ist aus Sicherheitsgründen nicht erlaubt.';
                }
            }
        }

        return true;
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
        if (is_string($value) && $value !== '') {
            return $value;
        }
        return '[Kein Eintrag]';
    }

    /**
     * Returns the default field configuration.
     *
     * @return array
     */
    public function getDefaultConfig(): array
    {
        return array_merge(
            parent::getDefaultConfig(), [
            'allow_images'    => true,
            'allow_documents' => true,
            'allow_audio'     => false,
            'allow_video'     => false,
            'allow_archives'  => false,
            'allowed_types'   => '',
            'max_size_mb'     => 10,
            'multiple'        => false,
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
            ['key' => 'max_size_mb', 'type' => 'number',   'label' => 'Max. Dateigröße (MB)'],
            ['key' => 'multiple',    'type' => 'checkbox', 'label' => 'Mehrere Dateien erlauben'],
        ];
    }

    /**
     * Returns the advanced settings schema for the field editor.
     *
     * @return array
     */
    public function getAdvancedSchema(): array
    {
        $blocked = implode(', .', self::BLOCKED_TYPES);
        $notice  = 'Aus Sicherheitsgründen gesperrt: .' . $blocked
            . '. Diese Typen können nicht freigegeben werden.';
        return [
            ['type' => 'notice', 'level' => 'warning', 'text' => $notice],
            ['key' => 'allow_images',
             'type' => 'checkbox', 'label' => 'Bilder (jpg, png, gif, bmp, tiff, webp)'],
            ['key' => 'allow_documents',
             'type' => 'checkbox', 'label' => 'Dokumente (pdf, doc, docx, xls, xlsx, odt, ppt, pptx, txt)'],
            ['key' => 'allow_audio',
             'type' => 'checkbox', 'label' => 'Audio (mp3, ogg, wav, m4a, flac)'],
            ['key' => 'allow_video',
             'type' => 'checkbox', 'label' => 'Video (mp4, mov, avi, wmv, mkv)'],
            ['key' => 'allow_archives',
             'type' => 'checkbox', 'label' => 'Archive (zip, tar, gz, 7z)'],
            ['key' => 'allowed_types', 'type' => 'text',
             'label' => 'Zusätzliche Typen', 'hint' => 'z.B. .pdf,.docx'],
        ];
    }
}
