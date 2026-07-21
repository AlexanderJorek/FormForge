<?php

/**
 * Admin list table displaying all saved forms.
 *
 * PHP Version 8.1
 *
 * @category  FormForge
 * @package   FormForge
 * @author    Alexander Jorek
 * @copyright 2026 Alexander Jorek
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GPL-3.0-or-later
 * @version   1.0.0
 * @link      https://github.com/AlexanderJorek/FormForge
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 3
 * of the License, or (at your option) any later version.
 */

namespace ForgeForms\Admin;

defined('ABSPATH') || exit;

use ForgeForms\Form\FormModel;

/**
 * Admin list table displaying all saved forms.
 */
class FormList
{
    /**
     * Registers admin hooks for the form list page.
     *
     * @return void
     */
    public static function init(): void
    {
        add_action('admin_menu', [self::class, 'menu']);
        add_action('wp_ajax_forge_forms_delete', [self::class, 'ajaxDelete']);
        add_action('wp_ajax_forge_forms_duplicate', [self::class, 'ajaxDuplicate']);
        add_action('wp_ajax_forge_forms_bulk_delete', [self::class, 'ajaxBulkDelete']);
        add_action('wp_ajax_forge_forms_bulk_duplicate', [self::class, 'ajaxBulkDuplicate']);
        add_action('wp_ajax_forge_forms_export', [self::class, 'ajaxExport']);
        add_action('wp_ajax_forge_forms_import', [self::class, 'ajaxImport']);
        add_filter('admin_body_class', [self::class, 'bodyClass']);
    }

    /**
     * Appends a CSS class on the list page.
     *
     * @param string $classes Existing admin body classes.
     *
     * @return string Modified body class string.
     */
    public static function bodyClass(string $classes): string
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only admin body-class check, no data written.
        if (isset($_GET['page']) && $_GET['page'] === 'forge-forms') {
            $classes .= ' forge-list-page';
        }
        return $classes;
    }

    /**
     * Registers the main menu page and list submenu.
     *
     * @return void
     */
    public static function menu(): void
    {
        if (\ForgeForms\Plugin::userCan('view_forms')) {
            $menuIconSvg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">'
                . '<path fill="#fff" transform="rotate(-45 10 10)" d="'
                . 'M11.9.39l1.4 1.4c1.61.19 3.5-.74 4.61.37s.18 3 .37 4.61l1.4 1.4c'
                . '.39.39.39 1.02 0 1.41l-9.19 9.2c-.4.39-1.03.39-1.42 0L1.29 11c'
                . '-.39-.39-.39-1.02 0-1.42l9.2-9.19c.39-.39 1.02-.39 1.41 0z'
                . 'm.58 2.25l-.58.58 4.95 4.95.58-.58c-.19-.6-.2-1.22-.15-1.82'
                . '.02-.31.05-.62.09-.92.12-1 .18-1.63-.17-1.98s-.98-.29-1.98-.17'
                . 'c-.3.04-.61.07-.92.09-.6.05-1.22.04-1.82-.15z'
                . 'm4.02.93c.39.39.39 1.03 0 1.42s-1.03.39-1.42 0-.39-1.03 0-1.42 1.03-.39 1.42 0z'
                . 'm-6.72.36l-.71.7L15.44 11l.7-.71z'
                . 'M8.36 5.34l-.7.71 6.36 6.36.71-.7z'
                . 'M6.95 6.76l-.71.7 6.37 6.37.7-.71z'
                . 'M5.54 8.17l-.71.71 6.36 6.36.71-.71z'
                . 'M4.12 9.58l-.71.71 6.37 6.37.71-.71z'
                . '"/></svg>';
            add_menu_page(
                __('FormForge Form List', 'form-forge'),
                __('FormForge', 'form-forge'),
                'read',
                'forge-forms',
                [self::class, 'render'],
                'data:image/svg+xml;base64,' . base64_encode($menuIconSvg),
                30
            );

            // Rename the auto-generated first submenu entry from "FormForge" to "Formular Liste"
            add_submenu_page(
                'forge-forms',
                __('FormForge Form List', 'form-forge'),
                __('Form List', 'form-forge'),
                'read',
                'forge-forms',
                [self::class, 'render']
            );
        }
    }

    /**
     * Renders the admin form list page.
     *
     * @return void
     */
    public static function render(): void
    {
        if (!\ForgeForms\Plugin::userCan('view_forms')) {
            wp_die(esc_html__('Permission denied.', 'form-forge'));
        }

        $forms   = FormModel::getAll();
        $new_url = admin_url('admin.php?page=forge-forms-editor');
        ?>
        <canvas id="forge-particle-canvas"></canvas>

        <div class="wrap forge-list-wrap">

            <div class="forge-title-pill"><?php esc_html_e('Forms', 'form-forge'); ?></div>
            <hr class="wp-header-end" style="display:none">

            <div class="forge-list-toolbar" id="forge-list-toolbar">
                    <!-- Left: select-all + bulk actions -->
                    <?php $noForms = empty($forms) ? ' hidden' : ''; ?>
                    <div class="forge-toolbar-left" id="forge-toolbar-left"<?php echo esc_attr($noForms); ?>>
                        <label class="forge-select-all-wrap">
                            <input type="checkbox" id="forge-select-all" title="<?php echo esc_attr__('Select all', 'form-forge'); ?>">
                        </label>
                        <div class="forge-bulk-bar" id="forge-bulk-bar" hidden>
                            <span class="forge-bulk-count" id="forge-bulk-count"></span>
                            <div class="forge-bulk-action-wrap">
                                <button class="button forge-list-btn" id="forge-bulk-action-btn">
                                    <span id="forge-bulk-action-label"><?php esc_html_e('Choose action', 'form-forge'); ?></span> &#9660;
                                </button>
                                <div class="forge-row-dropdown" id="forge-bulk-action-dd" hidden>
                                    <button class="forge-dd-item" data-action="duplicate">
                                        <i class="fa-solid fa-copy"></i> <?php esc_html_e('Duplicate', 'form-forge'); ?>
                                    </button>
                                    <div class="forge-dd-sep"></div>
                                    <button class="forge-dd-item forge-dd-item--danger" data-action="delete">
                                        <i class="fa-solid fa-trash"></i> <?php esc_html_e('Delete', 'form-forge'); ?>
                                    </button>
                                </div>
                            </div>
                            <button class="button forge-list-btn button-primary" id="forge-bulk-apply"><?php esc_html_e('Apply', 'form-forge'); ?></button>
                        </div>
                    </div>
                    <!-- Center: search -->
                    <div class="forge-toolbar-center" id="forge-toolbar-center"<?php echo esc_attr($noForms); ?>>
                        <input type="search" id="forge-form-search"
                               placeholder="<?php echo esc_attr__('Search forms…', 'form-forge'); ?>" autocomplete="off">
                    </div>
                    <!-- Right: import input + new form -->
                    <div class="forge-toolbar-right">
                        <div class="forge-import-wrap">
                            <input type="text" id="forge-import-input"
                                   placeholder="<?php echo esc_attr__('Paste export string…', 'form-forge'); ?>" autocomplete="off">
                            <button class="button forge-list-btn" id="forge-import-submit">
                                <i class="fa-solid fa-file-import"></i>
                            </button>
                        </div>
                        <a href="<?php echo esc_url($new_url); ?>"
                           class="button button-primary forge-list-btn">
                            <?php esc_html_e('+ New Form', 'form-forge'); ?>
                        </a>
                    </div>
                </div>

            <div class="forge-list-empty" id="forge-list-empty"<?php echo !empty($forms) ? ' hidden' : ''; ?>>
                <h2><?php esc_html_e('No forms yet', 'form-forge'); ?></h2>
                <p><?php esc_html_e('Create your first form and embed it via shortcode on any page.', 'form-forge'); ?></p>
                <a href="<?php echo esc_url($new_url); ?>" class="button button-primary">
                    <?php esc_html_e('+ Create First Form', 'form-forge'); ?>
                </a>
            </div>

            <?php if (!empty($forms)) : ?>
                <div class="forge-form-list" id="forge-form-list">
                    <?php foreach ($forms as $form) : ?>
                        <?php
                        $edit_url  = admin_url('admin.php?page=forge-forms-editor&form_id=' . $form->id);
                        $shortcode = '[forge_form id="' . $form->id . '"]';
                        $count     = count($form->fields);
                        $del_nonce = wp_create_nonce('forge_forms_delete_' . $form->id);
                        $dup_nonce = wp_create_nonce('forge_forms_duplicate_' . $form->id);
                        $exp_nonce = wp_create_nonce('forge_forms_export_' . $form->id);
                        ?>
                        <div class="forge-form-row" data-title="<?php echo esc_attr(strtolower($form->title)); ?>">
                            <label class="forge-row-check-wrap">
                                <input type="checkbox" class="forge-row-check"
                                       value="<?php echo esc_attr($form->id); ?>"
                                       data-del-nonce="<?php echo esc_attr($del_nonce); ?>"
                                       data-dup-nonce="<?php echo esc_attr($dup_nonce); ?>">
                            </label>
                            <div class="forge-form-row-icon">
                                <i class="fa-solid fa-table-list"></i>
                            </div>
                            <div class="forge-form-row-main">
                                <a href="<?php echo esc_url($edit_url); ?>"
                                   class="forge-form-row-title">
                                    <?php echo esc_html($form->title); ?>
                                </a>
                                <div class="forge-form-row-meta">
                                    <?php // translators: %d: number of fields in the form. ?>
                                    <span><?php echo esc_html(sprintf(_n('%d Field', '%d Fields', $count, 'form-forge'), $count)); ?></span>
                                    <span class="forge-meta-sep">&middot;</span>
                                    <code class="forge-form-row-code"><?php echo esc_html($shortcode); ?></code>
                                </div>
                            </div>
                            <div class="forge-form-row-actions">
                                <a href="<?php echo esc_url($edit_url); ?>"
                                   class="button forge-btn-edit">
                                    <?php esc_html_e('Edit', 'form-forge'); ?>
                                </a>
                                <div class="forge-row-menu-wrap">
                                    <button class="button forge-row-menu-btn" title="<?php echo esc_attr__('More actions', 'form-forge'); ?>">&#8942;</button>
                                    <div class="forge-row-dropdown" hidden>
                                        <button class="forge-dd-item forge-copy-shortcode"
                                                data-code="<?php echo esc_attr($shortcode); ?>">
                                            <i class="fa-solid fa-clipboard"></i> <?php esc_html_e('Copy shortcode', 'form-forge'); ?>
                                        </button>
                                        <button class="forge-dd-item forge-duplicate-form"
                                                data-id="<?php echo esc_attr($form->id); ?>"
                                                data-nonce="<?php echo esc_attr($dup_nonce); ?>">
                                            <i class="fa-solid fa-copy"></i> <?php esc_html_e('Duplicate', 'form-forge'); ?>
                                        </button>
                                        <div class="forge-dd-sep"></div>
                                        <button class="forge-dd-item forge-export-form"
                                                data-id="<?php echo esc_attr($form->id); ?>"
                                                data-nonce="<?php echo esc_attr($exp_nonce); ?>">
                                            <i class="fa-solid fa-file-export"></i> <?php esc_html_e('Export', 'form-forge'); ?>
                                        </button>
                                        <div class="forge-dd-sep"></div>
                                        <button class="forge-dd-item forge-dd-item--danger forge-delete-form"
                                                data-id="<?php echo esc_attr($form->id); ?>"
                                                data-nonce="<?php echo esc_attr($del_nonce); ?>">
                                            <i class="fa-solid fa-trash"></i> <?php esc_html_e('Delete', 'form-forge'); ?>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    <div class="forge-no-results" id="forge-no-results" hidden>
                        <?php esc_html_e('No forms found.', 'form-forge'); ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- Export modal -->
        <div id="forge-export-modal" class="forge-modal-backdrop" hidden>
            <div class="forge-modal forge-modal--wide">
                <h3 class="forge-modal-title"><?php esc_html_e('Export form', 'form-forge'); ?></h3>

                <!-- Loading state -->
                <div id="forge-export-loading">
                    <p class="forge-export-loading-label"><?php esc_html_e('Exporting…', 'form-forge'); ?></p>
                    <div class="forge-export-bar-track">
                        <div class="forge-export-bar-fill" id="forge-export-bar"></div>
                    </div>
                </div>

                <!-- Result state -->
                <div id="forge-export-result" hidden>
                    <p class="forge-modal-hint">
                        Kopieren Sie diesen String. Er enthält alle Felder, Benachrichtigungen und Einstellungen.
                    </p>
                    <textarea id="forge-export-string" class="forge-modal-textarea" readonly rows="6"></textarea>
                    <div class="forge-modal-actions">
                        <button class="button forge-list-btn" id="forge-export-copy">
                            <i class="fa-solid fa-copy"></i> <?php esc_html_e('Copy', 'form-forge'); ?>
                        </button>
                        <button class="button forge-list-btn" id="forge-export-close"><?php esc_html_e('Close', 'form-forge'); ?></button>
                    </div>
                </div>
            </div>
        </div>

<!-- Delete confirmation modal -->
        <div id="forge-delete-modal" class="forge-modal-backdrop" hidden>
            <div class="forge-modal">
                <p class="forge-modal-msg" id="forge-modal-msg"><?php esc_html_e('Really delete form?', 'form-forge'); ?></p>
                <div class="forge-modal-actions">
                    <button class="button forge-list-btn" id="forge-modal-cancel"><?php esc_html_e('Cancel', 'form-forge'); ?></button>
                    <button class="button forge-list-btn forge-btn-danger" id="forge-modal-confirm"><?php esc_html_e('Delete', 'form-forge'); ?></button>
                </div>
            </div>
        </div>

        <script>
        (function() {

            /* ── Dropdown: hoist to body, smart flip up/down ── */
            var openDd = null;

            function closeAllDropdowns() {
                if (openDd) { openDd.hidden = true; openDd = null; }
            }

            document.addEventListener('click', closeAllDropdowns);
            document.addEventListener('scroll', closeAllDropdowns, true);

            /* ── Live search ── */
            var searchInput = document.getElementById('forge-form-search');
            var noResults   = document.getElementById('forge-no-results');

            if (searchInput) {
                searchInput.addEventListener('input', function() {
                    var q = this.value.toLowerCase().trim();
                    var visible = 0;
                    document.querySelectorAll('.forge-form-row').forEach(function(row) {
                        var title = row.dataset.title || '';
                        var show  = !q || title.indexOf(q) !== -1;
                        row.hidden = !show;
                        if (show) visible++;
                    });
                    if (noResults) noResults.hidden = visible > 0;
                });
            }

            /* ── Multi-select ── */
            var selectAll   = document.getElementById('forge-select-all');
            var bulkBar       = document.getElementById('forge-bulk-bar');
            var bulkCount     = document.getElementById('forge-bulk-count');
            var bulkApply     = document.getElementById('forge-bulk-apply');
            var bulkActionBtn = document.getElementById('forge-bulk-action-btn');
            var bulkActionDd  = document.getElementById('forge-bulk-action-dd');
            var bulkActionLbl = document.getElementById('forge-bulk-action-label');
            var selectedBulkAction = '';

            /* ── Bulk action custom dropdown ── */
            if (bulkActionBtn && bulkActionDd) {
                document.body.appendChild(bulkActionDd);
                bulkActionBtn.addEventListener('click', function(e) {
                    e.stopPropagation();
                    var open = !bulkActionDd.hidden;
                    closeAllDropdowns();
                    if (open) return;
                    var rect = bulkActionBtn.getBoundingClientRect();
                    bulkActionDd.style.top   = (rect.bottom + 4) + 'px';
                    bulkActionDd.style.left  = rect.left + 'px';
                    bulkActionDd.style.right = 'auto';
                    bulkActionDd.hidden = false;
                    openDd = bulkActionDd;
                });
                bulkActionDd.querySelectorAll('.forge-dd-item[data-action]').forEach(function(item) {
                    item.addEventListener('click', function() {
                        selectedBulkAction = this.dataset.action;
                        bulkActionLbl.textContent = this.textContent.trim();
                        closeAllDropdowns();
                    });
                });
            }

            function getChecked() {
                return document.querySelectorAll('.forge-row-check:checked');
            }

            var emptyState   = document.getElementById('forge-list-empty');
            var listToolbar  = document.getElementById('forge-list-toolbar');
            var deleteModal  = document.getElementById('forge-delete-modal');
            var modalMsg     = document.getElementById('forge-modal-msg');
            var modalConfirm = document.getElementById('forge-modal-confirm');
            var modalCancel  = document.getElementById('forge-modal-cancel');
            var modalResolve = null;

            function showDeleteModal(msg) {
                return new Promise(function(resolve) {
                    modalMsg.textContent = msg;
                    deleteModal.hidden = false;
                    modalResolve = resolve;
                });
            }

            if (modalConfirm) {
                modalConfirm.addEventListener('click', function() {
                    deleteModal.hidden = true;
                    if (modalResolve) { modalResolve(true); modalResolve = null; }
                });
            }
            if (modalCancel) {
                modalCancel.addEventListener('click', function() {
                    deleteModal.hidden = true;
                    if (modalResolve) { modalResolve(false); modalResolve = null; }
                });
            }
            if (deleteModal) {
                deleteModal.addEventListener('click', function(e) {
                    if (e.target === deleteModal) {
                        deleteModal.hidden = true;
                        if (modalResolve) { modalResolve(false); modalResolve = null; }
                    }
                });
            }

            function checkEmpty() {
                if (document.querySelectorAll('.forge-form-row').length === 0) {
                    if (emptyState) emptyState.hidden = false;
                    var tl = document.getElementById('forge-toolbar-left');
                    var tc = document.getElementById('forge-toolbar-center');
                    if (tl) tl.hidden = true;
                    if (tc) tc.hidden = true;
                }
            }

            function fadeRemoveRow(row, cb) {
                row.style.transition = 'opacity .2s';
                row.style.opacity = '0';
                setTimeout(function() { row.remove(); checkEmpty(); if (cb) cb(); }, 220);
            }

            function updateBulkBar() {
                var checked = getChecked();
                if (bulkBar)   bulkBar.hidden = checked.length === 0;
                if (bulkCount) bulkCount.textContent = checked.length + ' ausgewählt';
                if (selectAll) {
                    var all = document.querySelectorAll('.forge-row-check');
                    selectAll.indeterminate = checked.length > 0 && checked.length < all.length;
                    selectAll.checked = all.length > 0 && checked.length === all.length;
                }
            }

            /* ── Per-row event binding (also called after dynamic injection) ── */
            function bindRow(row) {
                /* Grab all references before dd is hoisted to body */
                var menuBtn = row.querySelector('.forge-row-menu-btn');
                var copyBtn = row.querySelector('.forge-copy-shortcode');
                var dupBtn  = row.querySelector('.forge-duplicate-form');
                var delBtn  = row.querySelector('.forge-delete-form');
                var expBtn  = row.querySelector('.forge-export-form');
                var cb      = row.querySelector('.forge-row-check');

                if (menuBtn) {
                    var dd = menuBtn.nextElementSibling;
                    document.body.appendChild(dd);
                    menuBtn.addEventListener('click', function(e) {
                        e.stopPropagation();
                        var alreadyOpen = (openDd === dd && !dd.hidden);
                        closeAllDropdowns();
                        if (alreadyOpen) return;
                        var rect = menuBtn.getBoundingClientRect();
                        dd.style.bottom = 'auto';
                        dd.style.top    = (rect.bottom + 4) + 'px';
                        dd.style.left   = 'auto';
                        dd.style.right  = (window.innerWidth - rect.right) + 'px';
                        dd.hidden = false;
                        openDd = dd;
                        var ddH = dd.getBoundingClientRect().height;
                        if (rect.bottom + 4 + ddH > window.innerHeight - 8) {
                            dd.style.top    = 'auto';
                            dd.style.bottom = (window.innerHeight - rect.top + 4) + 'px';
                        }
                    });
                }

                if (copyBtn) {
                    copyBtn.addEventListener('click', function() {
                        navigator.clipboard.writeText(copyBtn.dataset.code).then(function() {
                            copyBtn.textContent = '✓ Kopiert!';
                            setTimeout(function() { copyBtn.textContent = '📋 Shortcode kopieren'; }, 1500);
                        });
                        closeAllDropdowns();
                    });
                }

                if (dupBtn) {
                    dupBtn.addEventListener('click', function() {
                        closeAllDropdowns();
                        var formId = dupBtn.dataset.id;
                        var nonce  = dupBtn.dataset.nonce;
                        dupBtn.disabled = true;
                        fetch(ajaxurl, {
                            method: 'POST',
                            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                            body: new URLSearchParams({
                                action: 'forge_forms_duplicate',
                                form_id: formId,
                                nonce: nonce
                            })
                        })
                        .then(function(r) { return r.json(); })
                        .then(function(data) {
                            dupBtn.disabled = false;
                            if (data.success && data.data.html) {
                                var tmp = document.createElement('div');
                                tmp.innerHTML = data.data.html.trim();
                                var newRow = tmp.firstElementChild;
                                var list = document.getElementById('forge-form-list');
                                if (list && newRow) {
                                    list.insertBefore(newRow, list.firstChild);
                                    bindRow(newRow);
                                }
                            } else {
                                alert((data.data && data.data.message) || 'Fehler');
                            }
                        });
                    });
                }

                if (delBtn) {
                    delBtn.addEventListener('click', function() {
                        closeAllDropdowns();
                        var formId = delBtn.dataset.id;
                        var nonce  = delBtn.dataset.nonce;
                        var rowEl  = row;
                        showDeleteModal('Formular wirklich löschen?').then(function(confirmed) {
                            if (!confirmed) return;
                            fetch(ajaxurl, {
                                method: 'POST',
                                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                                body: new URLSearchParams({
                                    action: 'forge_forms_delete',
                                    form_id: formId,
                                    nonce: nonce
                                })
                            })
                            .then(function(r) { return r.json(); })
                            .then(function(data) {
                                if (data.success) {
                                    if (rowEl) fadeRemoveRow(rowEl, updateBulkBar);
                                } else {
                                    alert((data.data && data.data.message) || 'Fehler');
                                }
                            });
                        });
                    });
                }

                if (expBtn) {
                    expBtn.addEventListener('click', function() {
                        closeAllDropdowns();
                        showExportLoading();
                        fetch(ajaxurl, {
                            method: 'POST',
                            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                            body: new URLSearchParams({
                                action: 'forge_forms_export',
                                form_id: expBtn.dataset.id,
                                nonce: expBtn.dataset.nonce
                            })
                        })
                        .then(function(r) { return r.json(); })
                        .then(function(data) {
                            if (data.success) {
                                showExportResult(data.data.string);
                            } else {
                                closeExportModal();
                                alert((data.data && data.data.message) || 'Fehler');
                            }
                        });
                    });
                }

                if (cb) cb.addEventListener('change', updateBulkBar);
            }

            document.querySelectorAll('.forge-form-row').forEach(bindRow);

            if (selectAll) {
                selectAll.addEventListener('change', function() {
                    document.querySelectorAll('.forge-row-check').forEach(function(cb) {
                        if (!cb.closest('.forge-form-row').hidden) cb.checked = selectAll.checked;
                    });
                    updateBulkBar();
                });
            }

            /* ── Bulk apply ── */
            if (bulkApply) {
                bulkApply.addEventListener('click', function() {
                    var action  = selectedBulkAction;
                    var checked = getChecked();
                    if (!action)         { alert('Bitte eine Aktion wählen.'); return; }
                    if (!checked.length) return;

                    var ids    = [];
                    var nonces = [];
                    checked.forEach(function(cb) {
                        ids.push(cb.value);
                        nonces.push(cb.dataset.delNonce);
                    });

                    if (action === 'delete') {
                        showDeleteModal(checked.length + ' Formular(e) wirklich löschen?').then(function(confirmed) {
                        if (!confirmed) return;
                        bulkApply.disabled = true;
                        fetch(ajaxurl, {
                            method: 'POST',
                            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                            body: new URLSearchParams({
                                action: 'forge_forms_bulk_delete',
                                ids: JSON.stringify(ids),
                                nonces: JSON.stringify(nonces)
                            })
                        })
                        .then(function(r) { return r.json(); })
                        .then(function(data) {
                            if (data.success) {
                                (data.data.deleted || []).forEach(function(id) {
                                    var cb  = document.querySelector('.forge-row-check[value="' + id + '"]');
                                    var row = cb ? cb.closest('.forge-form-row') : null;
                                    if (row) fadeRemoveRow(row, updateBulkBar);
                                });
                            } else {
                                alert((data.data && data.data.message) || 'Fehler');
                            }
                            bulkApply.disabled = false;
                            selectedBulkAction = '';
                            if (bulkActionLbl) bulkActionLbl.textContent = 'Aktion wählen';
                        });
                        }); // showDeleteModal

                    } else if (action === 'duplicate') {
                        var dupNonces = [];
                        checked.forEach(function(cb) { dupNonces.push(cb.dataset.dupNonce); });
                        bulkApply.disabled = true;
                        fetch(ajaxurl, {
                            method: 'POST',
                            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                            body: new URLSearchParams({
                                action: 'forge_forms_bulk_duplicate',
                                ids: JSON.stringify(ids),
                                nonces: JSON.stringify(dupNonces)
                            })
                        })
                        .then(function(r) { return r.json(); })
                        .then(function(data) {
                            bulkApply.disabled = false;
                            if (data.success && data.data.created && data.data.created.length) {
                                window.location.reload();
                            } else if (!data.success) {
                                alert((data.data && data.data.message) || 'Fehler');
                            }
                        });
                    }
                });
            }

            /* ── Export ── */
            var exportModal   = document.getElementById('forge-export-modal');
            var exportLoading = document.getElementById('forge-export-loading');
            var exportResult  = document.getElementById('forge-export-result');
            var exportBar     = document.getElementById('forge-export-bar');
            var exportString  = document.getElementById('forge-export-string');
            var exportCopy    = document.getElementById('forge-export-copy');
            var exportClose   = document.getElementById('forge-export-close');
            var exportBarTimer = null;

            function closeExportModal() {
                if (exportModal) exportModal.hidden = true;
                clearTimeout(exportBarTimer);
            }

            function showExportLoading() {
                exportLoading.hidden = false;
                exportResult.hidden  = true;
                exportModal.hidden   = false;
                /* Animate bar: quick run to ~70%, then slow crawl waiting for response */
                exportBar.style.transition = 'none';
                exportBar.style.width = '0%';
                requestAnimationFrame(function() {
                    exportBar.style.transition = 'width .6s ease-out';
                    exportBar.style.width = '70%';
                    exportBarTimer = setTimeout(function() {
                        exportBar.style.transition = 'width 6s linear';
                        exportBar.style.width = '92%';
                    }, 650);
                });
            }

            function showExportResult(str) {
                clearTimeout(exportBarTimer);
                exportBar.style.transition = 'width .2s ease-out';
                exportBar.style.width = '100%';
                setTimeout(function() {
                    exportLoading.hidden    = true;
                    exportResult.hidden     = false;
                    exportString.value      = str;
                    exportString.style.height = 'auto';
                    exportString.style.height = exportString.scrollHeight + 'px';
                    exportString.select();
                }, 220);
            }


            if (exportCopy) {
                exportCopy.addEventListener('click', function() {
                    exportString.select();
                    navigator.clipboard.writeText(exportString.value).then(function() {
                        exportCopy.textContent = '✓ Kopiert!';
                        setTimeout(function() {
                            exportCopy.innerHTML = '<i class="fa-solid fa-copy"></i> Kopieren';
                        }, 1500);
                    });
                });
            }
            if (exportClose) { exportClose.addEventListener('click', closeExportModal); }
            if (exportModal) {
                exportModal.addEventListener('click', function(e) {
                    if (e.target === exportModal) closeExportModal();
                });
            }

            /* ── Import ── */
            var importInput  = document.getElementById('forge-import-input');
            var importSubmit = document.getElementById('forge-import-submit');
            var importNonce  = <?php echo wp_json_encode(wp_create_nonce('forge_forms_import')); ?>;

            function doImport() {
                var str = importInput ? importInput.value.trim() : '';
                if (!str) return;
                importSubmit.disabled = true;
                fetch(ajaxurl, {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: new URLSearchParams({
                        action: 'forge_forms_import',
                        string: str,
                        nonce: importNonce
                    })
                })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    importSubmit.disabled = false;
                    if (data.success && data.data.html) {
                        var tmp = document.createElement('div');
                        tmp.innerHTML = data.data.html.trim();
                        var newRow = tmp.firstElementChild;
                        var list = document.getElementById('forge-form-list');
                        if (list && newRow) {
                            list.insertBefore(newRow, list.firstChild);
                            bindRow(newRow);
                        }
                        if (importInput) importInput.value = '';
                    } else {
                        alert((data.data && data.data.message) || 'Importfehler');
                        if (importInput) importInput.value = '';
                    }
                });
            }

            if (importSubmit) { importSubmit.addEventListener('click', doImport); }
            if (importInput) {
                importInput.addEventListener('keydown', function(e) {
                    if (e.key === 'Enter') doImport();
                });
            }

            /* ── Particle canvas ── */
            var canvas = document.getElementById('forge-particle-canvas');
            if (!canvas) return;

            var ctx  = canvas.getContext('2d');
            var mouse = { x: -9999, y: -9999 };
            var _ah = getComputedStyle(document.documentElement).getPropertyValue('--forge-admin-accent').trim()||'#2271b1';
            var _rgb = function(h){return parseInt(h.slice(1,3),16)+','+parseInt(h.slice(3,5),16)+','+parseInt(h.slice(5,7),16);};
            var DOTS  = Math.min(120, Math.max(40, Math.round(window.innerWidth * window.innerHeight / 26000)));
            var LINK = 150, SPEED = 1.0, COLOR = _rgb(_ah);
            var particles = [], paused = false, FRAME_MS = 1000 / 30;

            function resize() { canvas.width = window.innerWidth; canvas.height = window.innerHeight; }
            function rand(min, max) { return min + Math.random() * (max - min); }

            function initParticles() {
                particles = [];
                for (var i = 0; i < DOTS; i++) {
                    particles.push({ x: rand(0, canvas.width), y: rand(0, canvas.height),
                        vx: rand(-SPEED, SPEED), vy: rand(-SPEED, SPEED), r: rand(2, 3.5) });
                }
            }

            function draw() {
                if (paused) return;
                ctx.clearRect(0, 0, canvas.width, canvas.height);
                for (var i = 0; i < particles.length; i++) {
                    var p = particles[i];
                    p.x += p.vx; p.y += p.vy;
                    if (p.x < 0 || p.x > canvas.width)  p.vx *= -1;
                    if (p.y < 0 || p.y > canvas.height)  p.vy *= -1;
                }
                ctx.lineWidth = 1;
                for (var i = 0; i < particles.length; i++) {
                    for (var j = i + 1; j < particles.length; j++) {
                        var dx = particles[i].x - particles[j].x, dy = particles[i].y - particles[j].y;
                        var d  = Math.sqrt(dx * dx + dy * dy);
                        if (d < LINK) {
                            ctx.beginPath();
                            ctx.moveTo(particles[i].x, particles[i].y);
                            ctx.lineTo(particles[j].x, particles[j].y);
                            ctx.strokeStyle = 'rgba(' + COLOR + ',' + (1 - d / LINK) * 0.3 + ')';
                            ctx.stroke();
                        }
                    }
                    var mdx = particles[i].x - mouse.x, mdy = particles[i].y - mouse.y;
                    var md  = Math.sqrt(mdx * mdx + mdy * mdy);
                    if (md < LINK) {
                        ctx.beginPath();
                        ctx.moveTo(particles[i].x, particles[i].y);
                        ctx.lineTo(mouse.x, mouse.y);
                        ctx.strokeStyle = 'rgba(' + COLOR + ',' + (1 - md / LINK) * 0.55 + ')';
                        ctx.stroke();
                    }
                }
                ctx.fillStyle = 'rgba(' + COLOR + ', 0.5)';
                for (var i = 0; i < particles.length; i++) {
                    ctx.beginPath(); ctx.arc(particles[i].x, particles[i].y, particles[i].r, 0, Math.PI * 2); ctx.fill();
                }
                setTimeout(function() { requestAnimationFrame(draw); }, FRAME_MS - 2);
            }

            document.addEventListener('mousemove', function(e) { mouse.x = e.clientX; mouse.y = e.clientY; });
            document.addEventListener('visibilitychange', function() {
                paused = document.hidden;
                if (!paused) requestAnimationFrame(draw);
            });
            window.addEventListener('resize', function() { resize(); initParticles(); });
            resize(); initParticles(); requestAnimationFrame(draw);

        }());
        </script>
        <?php
    }

    /**
     * AJAX handler to delete a single form.
     *
     * @return void
     */
    public static function ajaxDelete(): void
    {
        // wp_send_json_error() terminates the request via wp_die(), so execution
        // never falls through past a failed check below (no explicit return needed).
        if (!\ForgeForms\Plugin::userCan('edit_forms')) {
            wp_send_json_error(['message' => 'Forbidden'], 403);
        }
        $form_id = isset($_POST['form_id']) ? absint(wp_unslash($_POST['form_id'])) : 0;
        if (!$form_id || !wp_verify_nonce(sanitize_key($_POST['nonce'] ?? ''), 'forge_forms_delete_' . $form_id)) {
            wp_send_json_error(['message' => 'Nonce verification failed.'], 403);
        }
        FormModel::delete($form_id);
        wp_send_json_success(['message' => __('Form deleted.', 'form-forge')]);
    }

    /**
     * Renders the HTML for a single form-list row.
     *
     * @param \ForgeForms\Form\FormModel $form The form model instance.
     *
     * @return string Row HTML.
     */
    private static function renderRow(\ForgeForms\Form\FormModel $form): string
    {
        $edit_url  = admin_url('admin.php?page=forge-forms-editor&form_id=' . $form->id);
        $shortcode = '[forge_form id="' . $form->id . '"]';
        $count     = count($form->fields);
        $del_nonce = wp_create_nonce('forge_forms_delete_' . $form->id);
        $dup_nonce = wp_create_nonce('forge_forms_duplicate_' . $form->id);
        $exp_nonce = wp_create_nonce('forge_forms_export_' . $form->id);
        ob_start();
        ?>
        <div class="forge-form-row" data-title="<?php echo esc_attr(strtolower($form->title)); ?>">
            <label class="forge-row-check-wrap">
                <input type="checkbox" class="forge-row-check"
                       value="<?php echo esc_attr($form->id); ?>"
                       data-del-nonce="<?php echo esc_attr($del_nonce); ?>"
                       data-dup-nonce="<?php echo esc_attr($dup_nonce); ?>">
            </label>
            <div class="forge-form-row-icon">
                <i class="fa-solid fa-table-list"></i>
            </div>
            <div class="forge-form-row-main">
                <a href="<?php echo esc_url($edit_url); ?>" class="forge-form-row-title">
                    <?php echo esc_html($form->title); ?>
                </a>
                <div class="forge-form-row-meta">
                    <?php // translators: %d: number of fields in the form. ?>
                    <span><?php echo esc_html(sprintf(_n('%d Field', '%d Fields', $count, 'form-forge'), $count)); ?></span>
                    <span class="forge-meta-sep">&middot;</span>
                    <code class="forge-form-row-code"><?php echo esc_html($shortcode); ?></code>
                </div>
            </div>
            <div class="forge-form-row-actions">
                <a href="<?php echo esc_url($edit_url); ?>" class="button forge-btn-edit">
                    <?php esc_html_e('Edit', 'form-forge'); ?>
                </a>
                <div class="forge-row-menu-wrap">
                    <button class="button forge-row-menu-btn" title="<?php echo esc_attr__('More actions', 'form-forge'); ?>">&#8942;</button>
                    <div class="forge-row-dropdown" hidden>
                        <button class="forge-dd-item forge-copy-shortcode"
                                data-code="<?php echo esc_attr($shortcode); ?>">
                            <i class="fa-solid fa-clipboard"></i> <?php esc_html_e('Copy shortcode', 'form-forge'); ?>
                        </button>
                        <button class="forge-dd-item forge-duplicate-form"
                                data-id="<?php echo esc_attr($form->id); ?>"
                                data-nonce="<?php echo esc_attr($dup_nonce); ?>">
                            <i class="fa-solid fa-copy"></i> <?php esc_html_e('Duplicate', 'form-forge'); ?>
                        </button>
                        <div class="forge-dd-sep"></div>
                        <button class="forge-dd-item forge-export-form"
                                data-id="<?php echo esc_attr($form->id); ?>"
                                data-nonce="<?php echo esc_attr($exp_nonce); ?>">
                            <i class="fa-solid fa-file-export"></i> <?php esc_html_e('Export', 'form-forge'); ?>
                        </button>
                        <div class="forge-dd-sep"></div>
                        <button class="forge-dd-item forge-dd-item--danger forge-delete-form"
                                data-id="<?php echo esc_attr($form->id); ?>"
                                data-nonce="<?php echo esc_attr($del_nonce); ?>">
                            <i class="fa-solid fa-trash"></i> <?php esc_html_e('Delete', 'form-forge'); ?>
                        </button>
                    </div>
                </div>
            </div>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * AJAX handler to duplicate a single form.
     *
     * @return void
     */
    public static function ajaxDuplicate(): void
    {
        if (!\ForgeForms\Plugin::userCan('edit_forms')) {
            wp_send_json_error(['message' => 'Forbidden'], 403);
        }
        $form_id = isset($_POST['form_id']) ? absint(wp_unslash($_POST['form_id'])) : 0;
        if (!$form_id || !wp_verify_nonce(sanitize_key($_POST['nonce'] ?? ''), 'forge_forms_duplicate_' . $form_id)) {
            wp_send_json_error(['message' => 'Nonce verification failed.'], 403);
        }
        $result = FormModel::duplicate($form_id);
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()], 500);
        }
        $new_form = FormModel::get((int) $result);
        $row_html = $new_form ? self::renderRow($new_form) : '';
        wp_send_json_success(
            ['message' => __('Form duplicated.', 'form-forge'), 'new_id' => $result, 'html' => $row_html]
        );
    }

    /**
     * AJAX handler to delete multiple forms at once.
     *
     * @return void
     */
    public static function ajaxBulkDelete(): void
    {
        if (!\ForgeForms\Plugin::userCan('edit_forms')) {
            wp_send_json_error(['message' => 'Forbidden'], 403);
        }
        $ids    = json_decode(\ForgeForms\Utils\Sanitize::str(sanitize_text_field(wp_unslash($_POST['ids'] ?? '[]')), '[]'), true);
        $nonces = json_decode(\ForgeForms\Utils\Sanitize::str(sanitize_text_field(wp_unslash($_POST['nonces'] ?? '[]')), '[]'), true);
        if (!is_array($ids) || !is_array($nonces)) {
            wp_send_json_error(['message' => 'Invalid data.'], 400);
        }
        $deleted = [];
        // $ids and $nonces are positionally paired (same index = same form) rather
        // than keyed by id, since each row has its own per-id delete nonce.
        foreach ($ids as $i => $raw_id) {
            $form_id = (int)$raw_id;
            $nonce   = sanitize_key($nonces[$i] ?? '');
            if (!$form_id || !wp_verify_nonce($nonce, 'forge_forms_delete_' . $form_id)) {
                continue;
            }
            FormModel::delete($form_id);
            $deleted[] = $form_id;
        }
        wp_send_json_success(['deleted' => $deleted]);
    }

    /**
     * AJAX handler to duplicate multiple forms at once.
     *
     * @return void
     */
    public static function ajaxBulkDuplicate(): void
    {
        if (!\ForgeForms\Plugin::userCan('edit_forms')) {
            wp_send_json_error(['message' => 'Forbidden'], 403);
        }
        $ids    = json_decode(\ForgeForms\Utils\Sanitize::str(sanitize_text_field(wp_unslash($_POST['ids'] ?? '[]')), '[]'), true);
        $nonces = json_decode(\ForgeForms\Utils\Sanitize::str(sanitize_text_field(wp_unslash($_POST['nonces'] ?? '[]')), '[]'), true);
        if (!is_array($ids) || !is_array($nonces)) {
            wp_send_json_error(['message' => 'Invalid data.'], 400);
        }
        $created = [];
        foreach ($ids as $i => $raw_id) {
            $form_id = (int)$raw_id;
            $nonce   = sanitize_key($nonces[$i] ?? '');
            if (!$form_id || !wp_verify_nonce($nonce, 'forge_forms_duplicate_' . $form_id)) {
                continue;
            }
            $result = FormModel::duplicate($form_id);
            if (!is_wp_error($result)) {
                $created[] = $result;
            }
        }
        wp_send_json_success(['created' => $created]);
    }

    /**
     * AJAX handler that exports a form as a compressed base64 string.
     *
     * @return void
     */
    public static function ajaxExport(): void
    {
        if (!\ForgeForms\Plugin::userCan('view_forms')) {
            wp_send_json_error(['message' => 'Forbidden'], 403);
        }
        $form_id = isset($_POST['form_id']) ? absint(wp_unslash($_POST['form_id'])) : 0;
        $nonce   = sanitize_key($_POST['nonce'] ?? '');
        if (!$form_id || !wp_verify_nonce($nonce, 'forge_forms_export_' . $form_id)) {
            wp_send_json_error(['message' => 'Nonce verification failed.'], 403);
        }
        $form = FormModel::get($form_id);
        if (!$form) {
            wp_send_json_error(['message' => __('Form not found.', 'form-forge')], 404);
        }
        $payload = ['v' => 2, 't' => $form->title];
        $payload['f'] = self::stripFieldDefaults($form->fields);
        if (!empty($form->notifications)) {
            $payload['n'] = $form->notifications;
        }
        if (!empty($form->settings)) {
            $payload['s'] = $form->settings;
        }
        $json       = (string)wp_json_encode($payload, JSON_UNESCAPED_UNICODE);
        $compressed = gzdeflate($json, 9);
        $string     = rtrim(strtr(base64_encode($compressed), '+/', '-_'), '=');
        wp_send_json_success(['string' => $string]);
    }

    /**
     * AJAX handler that imports a form from a compressed base64 string.
     *
     * @return void
     */
    public static function ajaxImport(): void
    {
        if (!\ForgeForms\Plugin::userCan('edit_forms')) {
            wp_send_json_error(['message' => 'Forbidden'], 403);
        }
        $nonce = sanitize_key($_POST['nonce'] ?? '');
        if (!wp_verify_nonce($nonce, 'forge_forms_import')) {
            wp_send_json_error(['message' => 'Nonce verification failed.'], 403);
        }
        $raw = sanitize_text_field(wp_unslash($_POST['string'] ?? ''));
        if ($raw === '') {
            wp_send_json_error(['message' => __('No import string provided.', 'form-forge')], 400);
        }
        $padded  = $raw . str_repeat('=', (4 - strlen($raw) % 4) % 4);
        $decoded = base64_decode(strtr($padded, '-_', '+/'), true);
        if ($decoded === false) {
            wp_send_json_error(['message' => __('Invalid import string.', 'form-forge')], 400);
        }
        // Support both v2 (gzdeflate) and v1 (gzencode) strings.
        // Bound the inflated size so a small crafted payload can't expand into a
        // multi-gigabyte decompression bomb (CERT MEM10-C / resource exhaustion).
        $max_inflated_size = 5 * 1024 * 1024; // 5 MB is generous for a form export
        $json = @gzinflate($decoded, $max_inflated_size);
        if ($json === false) {
            $json = @gzdecode($decoded, $max_inflated_size);
        }
        if ($json === false) {
            wp_send_json_error(['message' => __('Decompression failed.', 'form-forge')], 400);
        }
        // Import always creates a NEW form (FormModel::save() with no id) rather than
        // overwriting an existing one, even if the exported payload originated from a
        // form that still exists — avoids clobbering forms via a shared/pasted export string.
        $payload = json_decode($json, true);
        if (!is_array($payload)
            || !isset($payload['v'], $payload['t'], $payload['f'])
            || !in_array((int)$payload['v'], [1, 2], true)
        ) {
            wp_send_json_error(['message' => __('Unknown format.', 'form-forge')], 400);
        }
        $fields = is_array($payload['f']) ? $payload['f'] : [];
        if ((int)$payload['v'] === 2) {
            $fields = self::restoreFieldDefaults($fields);
        }
        // Route imported content through the same sanitizers ajaxSave() applies
        // to admin-authored fields/notifications/settings — an import string
        // is untrusted input (it may be pasted from anywhere) and must not
        // bypass the HTML/config sanitization pipeline just because it arrives
        // via a different admin action.
        $payload_title = $payload['t'];
        $result = FormModel::save(
            [
            'title'         => sanitize_text_field(is_string($payload_title) ? $payload_title : ''),
            'fields'        => \ForgeForms\Admin\FormEditor::sanitizeFields($fields),
            'notifications' => \ForgeForms\Admin\FormEditor::sanitizeNotifications(
                is_array($payload['n'] ?? null) ? $payload['n'] : []
            ),
            'settings'      => \ForgeForms\Admin\FormEditor::sanitizeSettings(
                is_array($payload['s'] ?? null) ? $payload['s'] : []
            ),
            ]
        );
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()], 500);
        }
        $new_form = FormModel::get((int) $result);
        $row_html = $new_form ? self::renderRow($new_form) : '';
        wp_send_json_success(['new_id' => $result, 'html' => $row_html]);
    }

    /**
     * Remove field config keys that match the field type's defaults.
     *
     * @param array $fields Fields array from the form model.
     *
     * @return array Compacted fields array without redundant keys.
     */
    private static function stripFieldDefaults(array $fields): array
    {
        $out = [];
        foreach ($fields as $field) {
            $type     = $field['type'] ?? '';
            $instance = \ForgeForms\Fields\FieldRegistry::get($type);
            if (!$instance) {
                $out[] = $field;
                continue;
            }
            $defaults = $instance->getDefaultConfig();
            $compact  = [];
            foreach ($field as $k => $v) {
                // Always keep structural keys ('type'/'id'/'col' are needed to reconstruct
                // the field even if their value happens to match the type's default);
                // drop any other key whose value equals the default to shrink the export string.
                if ($k === 'type' || $k === 'id' || $k === 'col') {
                    $compact[$k] = $v;
                } elseif (!array_key_exists($k, $defaults) || $defaults[$k] !== $v) {
                    $compact[$k] = $v;
                }
            }
            $out[] = $compact;
        }
        return $out;
    }

    /**
     * Re-merge field defaults stripped during export.
     *
     * @param array $fields Compacted fields array from import payload.
     *
     * @return array Fields array with defaults restored.
     */
    private static function restoreFieldDefaults(array $fields): array
    {
        $out = [];
        foreach ($fields as $field) {
            $type     = $field['type'] ?? '';
            $instance = \ForgeForms\Fields\FieldRegistry::get($type);
            if ($instance) {
                $out[] = array_merge($instance->getDefaultConfig(), $field);
            } else {
                $out[] = $field;
            }
        }
        return $out;
    }
}
