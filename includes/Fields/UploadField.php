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
.forge-upload-error {
    font-size: 13px;
    color: var(--forge-error, #cc1818);
    min-height: 1.2em;
    margin-top: 4px;
}
.forge-upload-filelist {
    list-style: none;
    margin: 6px 0 0;
    padding: 0;
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
}
.forge-upload-chip {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 3px 8px 3px 10px;
    background: var(--forge-accent-light, #e8f0fe);
    border: 1px solid var(--forge-accent, #2271b1);
    border-radius: 20px;
    font-size: 12px;
    color: var(--forge-text, #1d2327);
    max-width: 240px;
}
.forge-upload-chip-name {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.forge-upload-chip-remove {
    flex-shrink: 0;
    background: none;
    border: none;
    cursor: pointer;
    padding: 0 2px;
    line-height: 1;
    font-size: 16px;
    color: var(--forge-text-muted, #646970);
}
.forge-upload-chip-remove:hover { color: var(--forge-error, #cc1818); }
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
                var errEl    = zone.parentNode
                    ? zone.parentNode.querySelector('.forge-upload-error') : null;
                var listEl   = zone.parentNode
                    ? zone.parentNode.querySelector('.forge-upload-filelist') : null;
                var multiple = zone.dataset.multiple === '1';
                var maxFiles = parseInt(zone.dataset.maxFiles || '0', 10);

                function showError(msg) {
                    if (!errEl) return;
                    errEl.textContent = msg;
                    errEl.style.color = '';
                }
                function showNotice(msg) {
                    if (!errEl) return;
                    errEl.textContent = msg;
                    errEl.style.color = 'var(--forge-warning, #996600)';
                }
                function clearError() {
                    if (!errEl) return;
                    errEl.textContent = '';
                    errEl.style.color = '';
                }
                function publishCount(n) {
                    zone.dataset.forgeFileCount = String(n);
                }
                function renderChips(files) {
                    if (!listEl) return;
                    listEl.innerHTML = '';
                    clearError();
                    if (!files || !files.length) { publishCount(0); return; }
                    publishCount(files.length);
                    Array.from(files).forEach(function (file, idx) {
                        var li   = document.createElement('li');
                        li.className = 'forge-upload-chip';
                        var nm  = document.createElement('span');
                        nm.className   = 'forge-upload-chip-name';
                        nm.textContent = file.name;
                        nm.title       = file.name;
                        var btn = document.createElement('button');
                        btn.type      = 'button';
                        btn.className = 'forge-upload-chip-remove';
                        btn.setAttribute(
                            'aria-label', 'Entfernen: ' + file.name
                        );
                        btn.textContent = '×';
                        btn.addEventListener('click', function () {
                            try {
                                var dt = new DataTransfer();
                                Array.from(input.files).forEach(function (f, i) {
                                    if (i !== idx) dt.items.add(f);
                                });
                                input.files = dt.files;
                                renderChips(input.files);
                            } catch (e) { /* DataTransfer not supported */ }
                        });
                        li.appendChild(nm);
                        li.appendChild(btn);
                        listEl.appendChild(li);
                    });
                }
                function filterAllowed(fileList) {
                    var accept = (input ? input.accept : '') || '';
                    if (!accept) return Array.from(fileList);
                    var parts = accept.split(',').map(function (s) {
                        return s.trim().toLowerCase();
                    });
                    return Array.from(fileList).filter(function (f) {
                        var ext  = '.' + f.name.split('.').pop().toLowerCase();
                        var mime = (f.type || '').toLowerCase();
                        return parts.some(function (p) {
                            if (p.charAt(0) === '.') return p === ext;
                            if (p.slice(-2) === '/*') {
                                return mime.indexOf(p.slice(0, -1)) === 0;
                            }
                            return p === mime;
                        });
                    });
                }
                function checkLimit(files) {
                    if (maxFiles > 0 && files.length > maxFiles) {
                        showError(
                            'Zu viele Dateien. Maximal ' + maxFiles + ' erlaubt.'
                        );
                        return false;
                    }
                    return true;
                }
                function applyFiles(fileList) {
                    var all     = Array.from(fileList);
                    var allowed = filterAllowed(fileList);
                    if (!multiple && allowed.length > 1) {
                        allowed = [allowed[0]];
                    }
                    if (!allowed.length) {
                        showError('Keine erlaubten Dateitypen in der Auswahl.');
                        return;
                    }
                    if (!checkLimit(allowed)) return;
                    try {
                        var dt = new DataTransfer();
                        allowed.forEach(function (f) { dt.items.add(f); });
                        input.files = dt.files;
                        renderChips(input.files);
                        var skipped = all.length - allowed.length;
                        if (skipped > 0) {
                            showNotice(
                                skipped + ' Datei'
                                + (skipped === 1 ? ' wurde' : 'en wurden')
                                + ' aufgrund des Dateityps übersprungen.'
                            );
                        }
                    } catch (e) { /* DataTransfer not supported */ }
                }
                publishCount(0);
                if (input) {
                    input.addEventListener('change', function () {
                        applyFiles(this.files);
                    });
                    var form = input.closest('form');
                    if (form) {
                        form.addEventListener('reset', function () {
                            renderChips(null);
                        });
                        if (!form.dataset.forgeOverflowBound) {
                            form.dataset.forgeOverflowBound = '1';
                            form.addEventListener('forge:upload-overflow', function (ev) {
                                var firstField = null;
                                form.querySelectorAll('.forge-upload-zone').forEach(
                                    function (z) {
                                        var zi = z.querySelector('.forge-upload-input');
                                        var ze = z.parentNode
                                            ? z.parentNode.querySelector(
                                                '.forge-upload-error'
                                            ) : null;
                                        if (!ze || !zi || !zi.files || !zi.files.length) {
                                            return;
                                        }
                                        ze.textContent = 'Zu viele Dateien insgesamt ('
                                            + ev.detail.total + '). Max. '
                                            + ev.detail.max + ' pro Einsendung.';
                                        if (!firstField) {
                                            firstField = z.closest('.forge-field') || z;
                                        }
                                    }
                                );
                                if (firstField) {
                                    var top = firstField.getBoundingClientRect().top
                                        + window.pageYOffset - 80;
                                    window.scrollTo(0, Math.max(0, top));
                                    firstField.setAttribute('tabindex', '-1');
                                    firstField.focus({ preventScroll: true });
                                }
                            });
                        }
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
                    if (!input || !e.dataTransfer.files.length) return;
                    applyFiles(e.dataTransfer.files);
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

        $max      = (int)($config['max_size_mb'] ?? 10);
        $max_files = $multiple ? max(1, (int)(ini_get('max_file_uploads') ?: 20)) : 1;
        $inner  = '<div class="forge-upload-zone"'
            . ' data-multiple="' . ($multiple ? '1' : '0') . '"'
            . ' data-max-files="' . $max_files . '"'
            . '>';
        $inner .= '<input type="file" id="' . esc_attr($field_id) . '" name="'
            . esc_attr($field_id) . ($multiple ? '[]' : '') . '"'
            . ' class="forge-upload-input"' . $acc_attr . $multiple . $req . '>';
        $inner .= '<div class="forge-upload-zone-body" aria-hidden="true">'
            . '<span class="forge-upload-icon">↑</span>'
            . '<span class="forge-upload-prompt">Datei hier ablegen oder '
            . '<span class="forge-upload-link">klicken zum Auswählen</span>'
            . '</span>'
            . '</div>';
        $inner .= '</div>';
        $inner .= '<div class="forge-upload-error" role="alert"></div>';
        $inner .= '<ul class="forge-upload-filelist" aria-live="polite"></ul>';

        if ($accept !== '') {
            $inner .= '<p class="forge-field-hint">'
                . 'Erlaubte Dateitypen: ' . esc_html($accept) . '</p>';
        }
        $inner .= '<p class="forge-field-hint">'
            . 'Maximale Dateigröße: ' . $max . ' MB</p>';

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
                $exts,
                static function (string $e) use ($blocked): bool {
                    return !in_array(ltrim($e, '.'), $blocked, true);
                }
            )
        );
        return implode(',', $exts);
    }

    /**
     * Returns true: upload fields require multipart/form-data on the form element.
     *
     * @return bool
     */
    public function needsMultipartEncoding(): bool
    {
        return true;
    }

    /**
     * Returns the raw $_FILES entry for this upload field.
     *
     * @param string $field_id The field element ID.
     *
     * @return mixed
     */
    public function extractValue(string $field_id): mixed
    {
        return $_FILES[$field_id] ?? null;
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
                    return 'Dateityp ".' . esc_html($ext)
                        . '" ist aus Sicherheitsgründen nicht erlaubt.';
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
     * Returns a normalized entry with materialized uploaded files.
     *
     * @param string $field_id Field identifier.
     * @param string $label    Field label.
     * @param mixed  $value    Raw submitted value.
     * @param array  $config   Field configuration.
     * @param array  $context  Submission context (carries 'files').
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
        $file_data = ($context['files'] ?? [])[$field_id] ?? null;

        if (!$file_data || (is_array($file_data) && empty($file_data['name']))) {
            return [$field_id => [
                'label'              => $label,
                'type'               => 'upload',
                'value'              => '[Kein Eintrag]',
                'materialized_files' => [],
            ]];
        }

        $files_list = [];
        if (isset($file_data['name']) && is_array($file_data['name'])) {
            foreach ($file_data['name'] as $i => $name) {
                if ($file_data['error'][$i] === UPLOAD_ERR_OK) {
                    $files_list[] = [
                        'name'     => $name,
                        'tmp_name' => $file_data['tmp_name'][$i],
                        'type'     => $file_data['type'][$i],
                        'size'     => $file_data['size'][$i],
                        'error'    => UPLOAD_ERR_OK,
                    ];
                }
            }
        } elseif (
            isset($file_data['tmp_name'])
            && $file_data['error'] === UPLOAD_ERR_OK
        ) {
            $files_list[] = $file_data;
        }

        $info_parts   = [];
        $materialized = [];

        foreach ($files_list as $file) {
            $tmp  = $file['tmp_name'] ?? '';
            $name = sanitize_file_name($file['name'] ?? 'unknown');
            $mime = sanitize_mime_type(
                $file['type'] ?? 'application/octet-stream'
            );
            $size = (int)($file['size'] ?? 0);

            $info_parts[] = sprintf(
                '%s (%s, %s KB)',
                $name,
                $mime,
                round($size / 1024, 1)
            );

            if (!$tmp || !is_readable($tmp) || !is_uploaded_file($tmp)) {
                \ForgeForms\forge_log(
                    "ForgeForms: Upload file not readable: {$name}"
                );
                continue;
            }

            $binary = file_get_contents($tmp);
            if ($binary === false) {
                continue;
            }

            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $mime  = sanitize_mime_type(
                $finfo->file($tmp) ?: 'application/octet-stream'
            );

            $materialized[] = [
                'name'   => $name,
                'mime'   => $mime,
                'size'   => strlen($binary),
                'sha256' => hash('sha256', $binary),
                'base64' => base64_encode($binary),
            ];
        }

        return [$field_id => [
            'label'              => $label,
            'type'               => 'upload',
            'value'              => $info_parts
                ? implode('; ', $info_parts) : '[Kein Eintrag]',
            'materialized_files' => $materialized,
        ]];
    }

    /**
     * Override: show filename as text; embed images inline.
     * Non-image files (PDF, Word, audio, video, archives) show filename only.
     *
     * @param array $field Normalized entry from FieldRegistry::mapSubmission().
     *
     * @return array PDF render descriptor.
     */
    public function pdfData(array $field): array
    {
        $desc = $this->pdf($field);

        foreach ($field['materialized_files'] ?? [] as $file) {
            $mime   = $file['mime'] ?? '';
            $binary = !empty($file['base64']) ? base64_decode($file['base64'], true) : false;
            if ($binary === false || !str_starts_with($mime, 'image/')) {
                continue;
            }
            $desc->attachImage($binary, (string)($file['name'] ?? 'upload'), $mime);
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
        return array_merge(
            parent::getDefaultConfig(),
            [
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
            [
                'key'   => 'max_size_mb',
                'type'  => 'number',
                'label' => 'Max. Dateigröße (MB)',
            ],
            [
                'key'   => 'multiple',
                'type'  => 'checkbox',
                'label' => 'Mehrere Dateien erlauben',
            ],
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
        $nd = 'Diese Dateien werden im PDF nur als Dateiname angezeigt '
            . 'und können nicht kryptografisch verifiziert werden.';
        return [
            [
                'type'  => 'notice',
                'level' => 'warning',
                'text'  => $notice,
            ],
            [
                'key'   => 'allow_images',
                'type'  => 'checkbox',
                'label' => 'Bilder (jpg, png, gif, bmp, tiff, webp)',
            ],
            [
                'key'        => 'allow_documents',
                'type'       => 'checkbox',
                'label'      => 'Dokumente (pdf, doc, docx, xls, xlsx, odt, ppt, pptx, txt)',
                'disclaimer' => $nd,
            ],
            [
                'key'        => 'allow_audio',
                'type'       => 'checkbox',
                'label'      => 'Audio (mp3, ogg, wav, m4a, flac)',
                'disclaimer' => $nd,
            ],
            [
                'key'        => 'allow_video',
                'type'       => 'checkbox',
                'label'      => 'Video (mp4, mov, avi, wmv, mkv)',
                'disclaimer' => $nd,
            ],
            [
                'key'        => 'allow_archives',
                'type'       => 'checkbox',
                'label'      => 'Archive (zip, tar, gz, 7z)',
                'disclaimer' => $nd,
            ],
            [
                'key'   => 'allowed_types',
                'type'  => 'text',
                'label' => 'Zusätzliche Typen',
                'hint'  => 'z.B. .pdf,.docx',
            ],
        ];
    }
}
