/*!
 * FormForge
 * @copyright 2026 Alexander Jorek
 * @license   GPL-2.0-or-later
 */
/*
 * FormForge - Admin Builder
 */
(function () {
'use strict';

/* ── Drag-to-reorder ──────────────────────────────────────────────────────── *
 * makeSortable(list, handleSel, rowSel, onReorder)
 *   list       – container element
 *   handleSel  – CSS selector for the drag grip within each row
 *   rowSel     – CSS selector for draggable rows within list
 *   onReorder  – called with the new DOM order of rows after a drop
 */
function makeSortable(list, handleSel, rowSel, onReorder) {
    list.addEventListener('pointerdown', function (e) {
        var handle = e.target.closest(handleSel);
        if (!handle) { return; }
        var item = handle.closest(rowSel);
        if (!item || !list.contains(item)) { return; }

        e.preventDefault();
        var rect = item.getBoundingClientRect();
        var offY = e.clientY - rect.top;

        var ph = document.createElement('div');
        ph.style.cssText = 'height:' + rect.height + 'px;margin-bottom:4px;' +
            'border:2px dashed #c3c4c7;border-radius:6px;box-sizing:border-box;flex-shrink:0;';
        item.after(ph);

        item.style.cssText = 'position:fixed;left:' + rect.left + 'px;' +
            'top:' + (e.clientY - offY) + 'px;width:' + rect.width + 'px;' +
            'z-index:200000;opacity:.9;pointer-events:none;' +
            'box-shadow:0 4px 16px rgba(0,0,0,.2);';
        document.body.appendChild(item);

        function onMove(ev) {
            item.style.top = (ev.clientY - offY) + 'px';
            var rows = Array.from(list.querySelectorAll(rowSel));
            var before = null;
            for (var i = 0; i < rows.length; i++) {
                var r = rows[i].getBoundingClientRect();
                if (ev.clientY < r.top + r.height / 2) { before = rows[i]; break; }
            }
            before ? list.insertBefore(ph, before) : list.appendChild(ph);
        }

        function onUp() {
            document.removeEventListener('pointermove', onMove);
            document.removeEventListener('pointerup', onUp);
            item.style.cssText = '';
            ph.replaceWith(item);
            if (onReorder) { onReorder(Array.from(list.querySelectorAll(rowSel))); }
        }

        document.addEventListener('pointermove', onMove);
        document.addEventListener('pointerup', onUp);
    });
}

var AJAX_URL       = '/wp-admin/admin-ajax.php';
var NONCE          = '';
var FORM_ID        = 0;
var PALETTE_GROUPS  = [];
var TYPE_COLOR_MAP  = {};

var state = {
    fields: [], notifications: [], formName: 'Neues Formular',
    settings: {
        submit_label:      'Absenden',
        submit_working:    'Wird gesendet…',
        success_message:   'Vielen Dank f\xfcr Ihren Eintrag!',
        submit_conditions: { enabled: false, match: 'all', rules: [] },
    },
};
var dragSrcIdx           = null;
var dragBrokenPartnerIdx = null; /* partner idx whose cols we set to 12 on dragstart */
var dropSideMode         = null; /* 'left' | 'right' | null */
var dropSideTargetIdx    = null;
var dropLineTarget       = null; /* { beforeEl } or { atEnd: true } */
var dropLineEl           = null;
var settingsCtx            = null; /* { groupIdx, childIdx } when editing a group child, else null */
var fieldPickerTargetGroup = null; /* { groupIdx, onAdd } when adding a child to a group */
var dragChildSrc           = null; /* { groupIdx, childIdx } | null — set when dragging a group child */
var childDropSideMode      = null; /* 'left' | 'right' | null — current side-drop mode for child rows */
var childDropTgtIdx        = null; /* child index being side-dropped onto */
var childGroupDropTarget   = null; /* { beforeEl } | { atEnd: true } — drop-line position within a group */

var VALIDATION_OPTIONS = {
    number: [['', 'Keine'], ['integer', 'Nur Ganzzahlen'], ['positive', 'Positive Zahlen'], ['positive_int', 'Positive Ganzzahlen']],
};

var OPERATORS = [
    ['equals',      'ist gleich'],
    ['not_equals',  'ist ungleich'],
    ['contains',    'enthält'],
    ['not_contains','enthält nicht'],
    ['empty',       'ist leer'],
    ['not_empty',   'ist nicht leer'],
    ['greater',     'größer als'],
    ['less',        'kleiner als'],
];

var fieldPickerModal  = null;
var settingsModal     = null;
var settingsModalIdx  = null;

var cachedMidpoints = [];
var rafPending      = false;
var pendingY        = 0;

/* ================================================================
 * Bootstrap
 * ================================================================ */
document.addEventListener('DOMContentLoaded', function () {
    var el = document.getElementById('forge-editor');
    if (el) {
        try {
            var fd  = JSON.parse(el.dataset.form    || '{}');
            var pal = JSON.parse(el.dataset.palette || '[]');
            state.fields        = fd.fields        || [];
            state.notifications = (fd.notifications || []).map(normalizeNotifBodyFields);
            state.formName      = fd.title         || 'Neues Formular';
            if (fd.settings) {
                var s = fd.settings;
                if (s.submit_label    !== undefined) state.settings.submit_label    = s.submit_label;
                if (s.submit_working  !== undefined) state.settings.submit_working  = s.submit_working;
                if (s.success_message !== undefined) state.settings.success_message = s.success_message;
                if (s.submit_conditions) state.settings.submit_conditions = s.submit_conditions;
            }
            AJAX_URL = el.dataset.ajaxUrl || AJAX_URL;
            NONCE    = el.dataset.nonce   || NONCE;
            FORM_ID  = fd.id              || 0;
            for (var i = 0; i < pal.length; i++) PALETTE_GROUPS.push(pal[i]);
            /* Build type→color lookup from palette groups */
            for (var g = 0; g < pal.length; g++) {
                var gc = pal[g].color || '';
                if (!gc) continue;
                for (var gi = 0; gi < pal[g].items.length; gi++) {
                    TYPE_COLOR_MAP[pal[g].items[gi].type] = gc;
                }
            }
        } catch (e) { /* ignore */ }
    }

    createFieldPickerModal();
    createSettingsModal();
    createNotifModal();
    createSubmitModal();
    renderFieldList();
    initDragDrop();
    bindAddFieldButton();
    bindFormName();
    bindSave();
    bindPreview();
    bindCanvasTabs();
    renderNotifications();
    renderSubmitPreview();
});

/* ================================================================
 * Field List
 * ================================================================ */
function renderFieldList() {
    var list = document.getElementById('forge-field-list');
    if (!list) return;
    list.innerHTML = '';

    if (state.fields.length === 0) {
        var empty = document.createElement('div');
        empty.className = 'forge-empty-canvas';
        empty.innerHTML =
            '<div class="forge-empty-icon"><i class="fa-solid fa-layer-group"></i></div>' +
            '<h2 style="font-size:16px;font-weight:700;color:#1d2327;margin:0;">Noch keine Felder</h2>' +
            '<p>Fügen Sie Ihr erstes Feld über <strong>Feld hinzufügen</strong> ein.</p>';
        list.appendChild(empty);
    } else {
        for (var i = 0; i < state.fields.length; i++) {
            list.appendChild(buildFieldRow(state.fields[i], i));
        }
    }
}

function buildFieldRow(field, idx) {
    if (field.type === 'group') { return buildGroupRow(field, idx); }
    var row      = document.createElement('div');
    row.className    = 'forge-field-row';
    row.draggable    = true;
    row.dataset.idx  = idx;
    row.dataset.type = field.type || '';
    row.dataset.cols = field.cols || 12;

    var pal       = findPaletteItem(field.type);
    var icon      = pal ? pal.icon  : 'fa-solid fa-square';
    var typeLabel = pal ? pal.label : field.type;
    var condBadge = (field.conditions && field.conditions.rules && field.conditions.rules.length)
        ? ' <i class="fa-solid fa-code-branch forge-cond-badge" title="Bedingungen aktiv"></i>' : '';
    var color = TYPE_COLOR_MAP[field.type] || '';
    if (color) { row.style.borderLeftColor = color; }

    row.innerHTML =
        '<div class="forge-row-handle"><i class="fa-solid fa-grip-vertical"></i></div>' +
        '<div class="forge-row-icon"><i class="' + escHtml(icon) + '"></i></div>' +
        '<div class="forge-row-info">' +
            '<div class="forge-row-label">' + escHtml(field.label || '(kein Label)') + condBadge + '</div>' +
            '<div class="forge-row-type">' + escHtml(typeLabel) + '</div>' +
        '</div>' +
        '<span class="forge-row-id">' + escHtml(field.id || '') + '</span>' +
        '<div class="forge-row-actions">' +
            '<button class="forge-row-btn forge-row-edit"      title="Bearbeiten"><i class="fa-solid fa-pen-to-square"></i></button>' +
            '<button class="forge-row-btn forge-row-duplicate" title="Duplizieren"><i class="fa-solid fa-copy"></i></button>' +
            '<button class="forge-row-btn forge-row-delete"    title="Entfernen"><i class="fa-solid fa-trash"></i></button>' +
        '</div>';

    row.addEventListener('click', function (e) {
        if (e.target.closest('.forge-row-delete') ||
            e.target.closest('.forge-row-duplicate')) return;
        openSettingsModal(parseInt(row.dataset.idx, 10));
    });

    row.querySelector('.forge-row-duplicate').addEventListener('click', function (e) {
        e.stopPropagation();
        var i  = parseInt(row.dataset.idx, 10);
        var cl = JSON.parse(JSON.stringify(state.fields[i]));
        cl.id  = generateId(cl.type);
        state.fields.splice(i + 1, 0, cl);
        renderFieldList();
    });

    row.querySelector('.forge-row-delete').addEventListener('click', function (e) {
        e.stopPropagation();
        var di = parseInt(row.dataset.idx, 10);
        var df = state.fields[di];
        if (df && df.cols === 6) {
            var dp = state.fields[di - 1];
            var dn = state.fields[di + 1];
            if (dp && dp.cols === 6) { dp.cols = 12; }
            if (dn && dn.cols === 6) { dn.cols = 12; }
        }
        state.fields.splice(di, 1);
        renderFieldList();
    });

    /* Per-row dragover: detect left/right quarter for side-by-side pairing */
    row.addEventListener('dragover', function (e) {
        if (dragSrcIdx === null) return;
        /* Groups can't participate in side-by-side pairs */
        var srcF2 = state.fields[dragSrcIdx];
        if (!srcF2 || srcF2.type === 'group') return;

        /* Already paired — no side-dropping allowed */
        if (field.cols === 6) {
            if (dropSideMode !== null) {
                clearDropIndicators();
                dropSideMode      = null;
                dropSideTargetIdx = null;
            }
            return;
        }

        var rect = row.getBoundingClientRect();
        var pct  = (e.clientX - rect.left) / rect.width;
        var tIdx = parseInt(row.dataset.idx, 10);

        if (pct < 0.25) {
            if (dropSideMode !== 'left' || dropSideTargetIdx !== tIdx) {
                clearDropIndicators();
                row.classList.add('forge-drop-left');
                dropSideMode      = 'left';
                dropSideTargetIdx = tIdx;
                hideDropLine();
            }
        } else if (pct > 0.75) {
            if (dropSideMode !== 'right' || dropSideTargetIdx !== tIdx) {
                clearDropIndicators();
                row.classList.add('forge-drop-right');
                dropSideMode      = 'right';
                dropSideTargetIdx = tIdx;
                hideDropLine();
            }
        } else {
            if (dropSideMode !== null) {
                clearDropIndicators();
                dropSideMode      = null;
                dropSideTargetIdx = null;
            }
        }
    });

    row.addEventListener('dragleave', function (e) {
        if (row.contains(e.relatedTarget)) return;
        if (dropSideTargetIdx === parseInt(row.dataset.idx, 10)) {
            row.classList.remove('forge-drop-left', 'forge-drop-right');
            dropSideMode      = null;
            dropSideTargetIdx = null;
        }
    });

    row.addEventListener('drop', function (e) {
        if (dragSrcIdx === null || dropSideMode === null) return;
        /* Groups cannot participate in 2-col pairs */
        var srcField = state.fields[dragSrcIdx];
        if (srcField && srcField.type === 'group') {
            dragSrcIdx = null; dragBrokenPartnerIdx = null;
            dropSideMode = null; dropSideTargetIdx = null;
            cachedMidpoints = []; rafPending = false;
            clearDropIndicators(); hideDropLine();
            var lEl = document.getElementById('forge-field-list');
            if (lEl) lEl.classList.remove('forge-drag-active');
            renderFieldList(); return;
        }
        e.preventDefault();
        e.stopPropagation();

        var mode      = dropSideMode;
        var targetIdx = dropSideTargetIdx;
        var srcIdx    = dragSrcIdx;

        dragSrcIdx           = null;
        dragBrokenPartnerIdx = null; /* drop handled */
        dropSideMode         = null;
        dropSideTargetIdx    = null;
        cachedMidpoints      = [];
        rafPending           = false;

        clearDropIndicators();
        hideDropLine();
        var listEl = document.getElementById('forge-field-list');
        if (listEl) { listEl.classList.remove('forge-drag-active'); }

        if (srcIdx === targetIdx) { renderFieldList(); return; }

        var item = state.fields.splice(srcIdx, 1)[0];
        if (srcIdx < targetIdx) { targetIdx--; }

        item.cols = 6;
        state.fields[targetIdx].cols = 6;

        if (mode === 'left') {
            state.fields.splice(targetIdx, 0, item);
        } else {
            state.fields.splice(targetIdx + 1, 0, item);
        }
        repairPairs();
        renderFieldList();
    });

    row.addEventListener('dragstart', function (e) {
        dragSrcIdx = parseInt(row.dataset.idx, 10);
        var f = state.fields[dragSrcIdx];
        dragBrokenPartnerIdx = null;
        if (f && f.cols === 6) {
            /* Find the actual pair partner via isSecondInPair, not just the first col-6 neighbor */
            var partnerIdx = null;
            if (isSecondInPair(dragSrcIdx)) {
                partnerIdx = dragSrcIdx - 1;
            } else {
                var nx = state.fields[dragSrcIdx + 1];
                if (nx && nx.cols === 6 && isSecondInPair(dragSrcIdx + 1)) {
                    partnerIdx = dragSrcIdx + 1;
                }
            }
            if (partnerIdx !== null) {
                state.fields[partnerIdx].cols = 12;
                dragBrokenPartnerIdx = partnerIdx;
            }
            /* Set dragged field to cols:12 now so it can't corrupt isSecondInPair during drag */
            f.cols = 12;
        }
        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData('text/plain', String(dragSrcIdx));
        setTimeout(function () {
            /* Hide from grid flow AND mark class so cacheMidpoints excludes it */
            row.style.display = 'none';
            row.classList.add('forge-row-dragging');
            var listEl = document.getElementById('forge-field-list');
            if (listEl) {
                if (dragBrokenPartnerIdx !== null) {
                    var pEl = listEl.querySelector('.forge-field-row[data-idx="' + dragBrokenPartnerIdx + '"]');
                    if (pEl) pEl.dataset.cols = 12;
                }
                listEl.classList.add('forge-drag-active');
            }
            cacheMidpoints();
        }, 0);
    });
    row.addEventListener('dragend', function () {
        /* If no drop handler fired (cancelled drag), restore state */
        if (dragSrcIdx !== null) {
            /* Dragged field had cols set to 12 in dragstart — restore if it was paired */
            if (dragBrokenPartnerIdx !== null) {
                if (state.fields[dragSrcIdx])           { state.fields[dragSrcIdx].cols = 6; }
                if (state.fields[dragBrokenPartnerIdx]) { state.fields[dragBrokenPartnerIdx].cols = 6; }
            }
        }
        repairPairs();
        dragSrcIdx           = null;
        dragBrokenPartnerIdx = null;
        dropSideMode         = null;
        dropSideTargetIdx    = null;
        dropLineTarget       = null;
        cachedMidpoints      = [];
        rafPending           = false;
        clearDropIndicators();
        hideDropLine();
        var listEl = document.getElementById('forge-field-list');
        if (listEl) listEl.classList.remove('forge-drag-active');
        renderFieldList();
    });

    return row;
}

/* ================================================================
 * Group field row (always full-width, expandable child list)
 * ================================================================ */
function buildGroupRow(field, idx) {
    var row      = document.createElement('div');
    row.className    = 'forge-field-row forge-field-row--group';
    row.draggable    = true;
    row.dataset.idx  = idx;
    row.dataset.type = 'group';
    row.dataset.cols = 12;
    var groupColor = TYPE_COLOR_MAP['group'] || '';
    if (groupColor) { row.style.borderLeftColor = groupColor; }

    /* ---- Compact header bar ---- */
    var hdr = document.createElement('div');
    hdr.className = 'forge-group-row-hdr';
    hdr.innerHTML =
        '<div class="forge-row-handle"><i class="fa-solid fa-grip-vertical"></i></div>' +
        '<div class="forge-row-icon"><i class="fa-solid fa-layer-group"></i></div>' +
        '<div class="forge-row-info">' +
            '<div class="forge-row-label">' + escHtml(field.label || '(kein Label)') + '</div>' +
            '<div class="forge-row-type">Feldgruppe</div>' +
        '</div>' +
        '<div class="forge-row-actions">' +
            '<button class="forge-row-btn forge-row-edit"   title="Einstellungen"><i class="fa-solid fa-pen-to-square"></i></button>' +
            '<button class="forge-row-btn forge-row-delete" title="Entfernen"><i class="fa-solid fa-trash"></i></button>' +
        '</div>';
    row.appendChild(hdr);

    /* Click anywhere on the header (except delete) opens settings */
    hdr.addEventListener('click', function (e) {
        if (e.target.closest('.forge-row-delete')) return;
        openSettingsModal(parseInt(row.dataset.idx, 10));
    });

    /* ---- Always-open children drop zone ---- */
    var zone = document.createElement('div');
    zone.className = 'forge-group-zone';

    var childList = document.createElement('div');
    childList.className = 'forge-group-children-list';
    zone.appendChild(childList);

    var addChildBtn = document.createElement('button');
    addChildBtn.type      = 'button';
    addChildBtn.className = 'forge-group-add-child-btn';
    addChildBtn.innerHTML = '<i class="fa-solid fa-plus"></i> Felder zur Gruppe hinzufügen';
    zone.appendChild(addChildBtn);
    row.appendChild(zone);

    /* Helpers scoped to this group's childList closure */
    function childBeforeY(y) {
        var rows = childList.querySelectorAll('.forge-child-row');
        var best = null, bestDist = Infinity;
        for (var i = 0; i < rows.length; i++) {
            var box = rows[i].getBoundingClientRect();
            var mid = box.top + box.height / 2;
            if (y < mid && mid - y < bestDist) { bestDist = mid - y; best = rows[i]; }
        }
        return best;
    }
    function childInsertIdx(gi, target) {
        var ch = (state.fields[gi] && state.fields[gi].children) || [];
        if (!target || target.atEnd) return ch.length;
        var rows = childList.querySelectorAll('.forge-child-row');
        for (var k = 0; k < rows.length; k++) { if (rows[k] === target.beforeEl) return k; }
        return ch.length;
    }

    function renderChildList() {
        childList.innerHTML = '';
        var currentIdx   = parseInt(row.dataset.idx, 10);
        var currentField = state.fields[currentIdx];
        if (!currentField) return;
        var children = currentField.children || [];
        zone.classList.toggle('forge-group-zone--empty', children.length === 0);
        children.forEach(function (child, ci) {
            var childRow = buildChildRow(child, ci, currentIdx, renderChildList);
            childRow.dataset.cols = child.cols || 12;
            childRow.draggable = true;

            childRow.addEventListener('dragstart', function (e) {
                e.stopPropagation(); /* prevent group row's own dragstart from hiding the zone */
                var gi = parseInt(row.dataset.idx, 10);
                dragChildSrc = { groupIdx: gi, childIdx: ci };
                var ch = state.fields[gi] && state.fields[gi].children || [];
                if (ch[ci] && ch[ci].cols === 6) {
                    if (isSecondInGroupPair(gi, ci)) { if (ch[ci - 1]) ch[ci - 1].cols = 12; }
                    else { if (ch[ci + 1] && ch[ci + 1].cols === 6) ch[ci + 1].cols = 12; }
                    ch[ci].cols = 12;
                }
                e.dataTransfer.effectAllowed = 'move';
                e.dataTransfer.setData('text/plain', 'child');
                setTimeout(function () {
                    childRow.style.display = 'none';
                    /* Sync data-cols on siblings without rebuilding — preserves drag ghost */
                    var stCh = (state.fields[gi] && state.fields[gi].children) || [];
                    var domRows = childList.querySelectorAll('.forge-child-row');
                    domRows.forEach(function (cr, k) {
                        if (stCh[k]) cr.dataset.cols = stCh[k].cols || 12;
                    });
                    var listEl = document.getElementById('forge-field-list');
                    if (listEl) listEl.classList.add('forge-drag-active');
                    cacheMidpoints();
                }, 0);
            });

            childRow.addEventListener('dragend', function () {
                if (dragChildSrc !== null) {
                    repairGroupPairs(dragChildSrc.groupIdx);
                    dragChildSrc = null;
                }
                childDropSideMode = null; childDropTgtIdx = null; childGroupDropTarget = null;
                clearGroupDropIndicators();
                var listEl = document.getElementById('forge-field-list');
                if (listEl) listEl.classList.remove('forge-drag-active');
                hideDropLine(); cachedMidpoints = []; rafPending = false;
                renderFieldList();
            });

            childRow.addEventListener('dragover', function (e) {
                var gi           = parseInt(row.dataset.idx, 10);
                var isDragCanvas = dragSrcIdx !== null &&
                                   state.fields[dragSrcIdx] &&
                                   state.fields[dragSrcIdx].type !== 'group';
                var isDragChild  = dragChildSrc !== null &&
                                   dragChildSrc.groupIdx === gi &&
                                   dragChildSrc.childIdx !== ci;
                if (!isDragCanvas && !isDragChild) return;

                var ch   = (state.fields[gi] && state.fields[gi].children) || [];
                var rect = childRow.getBoundingClientRect();
                var pct  = (e.clientX - rect.left) / rect.width;

                /* Target is already in a 2-col pair — block side drop, let center bubble */
                if (ch[ci] && ch[ci].cols === 6) {
                    if (pct < 0.25 || pct > 0.75) {
                        e.preventDefault(); e.stopPropagation();
                        clearGroupDropIndicators();
                        childDropSideMode = null; childDropTgtIdx = null;
                    }
                    return;
                }

                if (pct < 0.25) {
                    e.preventDefault(); e.stopPropagation();
                    clearGroupDropIndicators();
                    childRow.classList.add('forge-drop-left');
                    childDropSideMode = 'left'; childDropTgtIdx = ci;
                    childGroupDropTarget = null;
                    hideDropLine();
                    zone.classList.remove('forge-group-zone--hover');
                } else if (pct > 0.75) {
                    e.preventDefault(); e.stopPropagation();
                    clearGroupDropIndicators();
                    childRow.classList.add('forge-drop-right');
                    childDropSideMode = 'right'; childDropTgtIdx = ci;
                    childGroupDropTarget = null;
                    hideDropLine();
                    zone.classList.remove('forge-group-zone--hover');
                } else {
                    /* Center: clear side indicators, let event bubble to zone for drop line */
                    if (childDropTgtIdx === ci) {
                        childRow.classList.remove('forge-drop-left', 'forge-drop-right');
                        childDropSideMode = null; childDropTgtIdx = null;
                    }
                }
            });

            childRow.addEventListener('dragleave', function (e) {
                if (!childRow.contains(e.relatedTarget) && childDropTgtIdx === ci) {
                    childRow.classList.remove('forge-drop-left', 'forge-drop-right');
                    childDropSideMode = null; childDropTgtIdx = null;
                }
            });

            childRow.addEventListener('drop', function (e) {
                var gi = parseInt(row.dataset.idx, 10);

                /* Side-drop: main canvas field → group child as 2-col pair */
                if (dragSrcIdx !== null && childDropSideMode !== null && childDropTgtIdx === ci) {
                    var srcF = state.fields[dragSrcIdx];
                    if (!srcF || srcF.type === 'group') return;
                    e.preventDefault(); e.stopPropagation();
                    hideDropLine(); zone.classList.remove('forge-group-zone--hover');
                    var from  = dragSrcIdx;
                    var mode  = childDropSideMode;
                    var tci   = childDropTgtIdx;
                    dragSrcIdx = null; dragBrokenPartnerIdx = null;
                    childDropSideMode = null; childDropTgtIdx = null; childGroupDropTarget = null;
                    clearGroupDropIndicators(); clearDropIndicators();
                    var moved   = state.fields.splice(from, 1)[0];
                    moved.cols  = 6;
                    var actualGi = from < gi ? gi - 1 : gi;
                    var chArr   = state.fields[actualGi].children;
                    chArr[tci].cols = 6;
                    if (mode === 'left') { chArr.splice(tci, 0, moved); }
                    else                 { chArr.splice(tci + 1, 0, moved); }
                    repairGroupPairs(actualGi);
                    repairPairs();
                    dropSideMode = null; dropSideTargetIdx = null;
                    dropLineTarget = null; cachedMidpoints = []; rafPending = false;
                    var lEl = document.getElementById('forge-field-list');
                    if (lEl) lEl.classList.remove('forge-drag-active');
                    renderFieldList();
                    return;
                }

                /* Side-drop: child within group as 2-col pair */
                if (dragChildSrc !== null && childDropSideMode !== null && childDropTgtIdx === ci) {
                    if (dragChildSrc.groupIdx !== gi) return;
                    e.preventDefault(); e.stopPropagation();
                    var src  = dragChildSrc;
                    var mode2 = childDropSideMode;
                    var tci2  = childDropTgtIdx;
                    dragChildSrc = null; childDropSideMode = null;
                    childDropTgtIdx = null; childGroupDropTarget = null;
                    clearGroupDropIndicators();
                    var chArr2 = state.fields[gi].children;
                    var item   = chArr2.splice(src.childIdx, 1)[0];
                    if (src.childIdx < tci2) tci2--;
                    item.cols = 6; chArr2[tci2].cols = 6;
                    if (mode2 === 'left') { chArr2.splice(tci2, 0, item); }
                    else                  { chArr2.splice(tci2 + 1, 0, item); }
                    repairGroupPairs(gi);
                    var listEl2 = document.getElementById('forge-field-list');
                    if (listEl2) listEl2.classList.remove('forge-drag-active');
                    hideDropLine(); cachedMidpoints = []; rafPending = false;
                    renderFieldList();
                }
            });

            childList.appendChild(childRow);
        });
    }
    renderChildList();

    hdr.querySelector('.forge-row-edit').addEventListener('click', function (e) {
        e.stopPropagation();
        openSettingsModal(parseInt(row.dataset.idx, 10), null);
    });
    hdr.querySelector('.forge-row-delete').addEventListener('click', function (e) {
        e.stopPropagation();
        state.fields.splice(parseInt(row.dataset.idx, 10), 1);
        renderFieldList();
    });
    addChildBtn.addEventListener('click', function (e) {
        e.stopPropagation();
        fieldPickerTargetGroup = { groupIdx: parseInt(row.dataset.idx, 10), onAdd: renderChildList };
        openFieldPickerModal();
    });
    /* Clicking the empty zone itself also opens picker */
    zone.addEventListener('click', function (e) {
        if (e.target === zone || e.target === childList) {
            addChildBtn.click();
        }
    });

    /* ---- Zone: accepts drops from main canvas AND handles child reorder within group ---- */
    zone.addEventListener('dragover', function (e) {
        if (dragSrcIdx !== null) {
            var srcField = state.fields[dragSrcIdx];
            if (!srcField || srcField.type === 'group') return;
            e.preventDefault(); e.stopPropagation();
            dropLineTarget = null; /* prevent applyDropLine from overriding group line */
            zone.classList.add('forge-group-zone--hover');
            var bef = childBeforeY(e.clientY);
            childGroupDropTarget = bef ? { beforeEl: bef } : { atEnd: true };
            showGroupDropLine(childList, bef);
        } else if (dragChildSrc !== null) {
            e.preventDefault(); e.stopPropagation();
            var bef2 = childBeforeY(e.clientY);
            childGroupDropTarget = bef2 ? { beforeEl: bef2 } : { atEnd: true };
            showGroupDropLine(childList, bef2);
        }
    });

    zone.addEventListener('dragleave', function (e) {
        if (!zone.contains(e.relatedTarget)) {
            zone.classList.remove('forge-group-zone--hover');
            childGroupDropTarget = null;
            hideDropLine();
        }
    });

    zone.addEventListener('drop', function (e) {
        var currentGi = parseInt(row.dataset.idx, 10);
        zone.classList.remove('forge-group-zone--hover');
        clearGroupDropIndicators();
        hideDropLine();

        if (dragSrcIdx !== null) {
            var srcField = state.fields[dragSrcIdx];
            if (!srcField || srcField.type === 'group') return;
            /* Side drops on child rows are already handled by child drop (stopPropagation) */
            e.preventDefault(); e.stopPropagation();
            var from     = dragSrcIdx;
            var moved    = state.fields.splice(from, 1)[0];
            moved.cols   = 12;
            var actualGi = from < currentGi ? currentGi - 1 : currentGi;
            if (!state.fields[actualGi].children) state.fields[actualGi].children = [];
            var ins = childInsertIdx(actualGi, childGroupDropTarget);
            state.fields[actualGi].children.splice(ins, 0, moved);
            childGroupDropTarget = null;
            dragSrcIdx = null; dragBrokenPartnerIdx = null;
            dropSideMode = null; dropSideTargetIdx = null;
            dropLineTarget = null; cachedMidpoints = []; rafPending = false;
            clearDropIndicators();
            var lEl = document.getElementById('forge-field-list');
            if (lEl) lEl.classList.remove('forge-drag-active');
            repairPairs();
            renderFieldList();
            return;
        }

        if (dragChildSrc !== null) {
            e.preventDefault(); e.stopPropagation();
            var src = dragChildSrc;
            dragChildSrc = null; childDropSideMode = null; childDropTgtIdx = null;
            if (src.groupIdx === currentGi) {
                var chArr = state.fields[currentGi].children;
                var itm   = chArr.splice(src.childIdx, 1)[0];
                itm.cols  = 12;
                var ins2  = childInsertIdx(currentGi, childGroupDropTarget);
                if (src.childIdx < ins2) ins2--;
                chArr.splice(ins2, 0, itm);
                repairGroupPairs(currentGi);
            }
            childGroupDropTarget = null;
            var lEl2 = document.getElementById('forge-field-list');
            if (lEl2) lEl2.classList.remove('forge-drag-active');
            cachedMidpoints = []; rafPending = false;
            renderFieldList();
            return;
        }
    });

    /* ---- Drag events (full-width only, no side-drop) ---- */
    row.addEventListener('dragover', function (e) {
        if (dragSrcIdx === null) return;
        if (dropSideMode !== null) {
            clearDropIndicators();
            dropSideMode = null;
            dropSideTargetIdx = null;
        }
    });
    row.addEventListener('dragstart', function (e) {
        dragSrcIdx           = parseInt(row.dataset.idx, 10);
        dragBrokenPartnerIdx = null;
        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData('text/plain', String(dragSrcIdx));
        setTimeout(function () {
            row.style.display = 'none';
            row.classList.add('forge-row-dragging');
            var listEl = document.getElementById('forge-field-list');
            if (listEl) listEl.classList.add('forge-drag-active');
            cacheMidpoints();
        }, 0);
    });
    row.addEventListener('dragend', function () {
        repairPairs();
        dragSrcIdx = null; dragBrokenPartnerIdx = null;
        dropSideMode = null; dropSideTargetIdx = null;
        dropLineTarget = null; cachedMidpoints = []; rafPending = false;
        clearDropIndicators(); hideDropLine();
        var listEl = document.getElementById('forge-field-list');
        if (listEl) listEl.classList.remove('forge-drag-active');
        renderFieldList();
    });

    return row;
}

function buildChildRow(child, childIdx, groupIdx, onRerender) {
    var row = document.createElement('div');
    row.className    = 'forge-child-row';
    row.dataset.type = child.type || '';

    var pal       = findPaletteItem(child.type);
    var icon      = pal ? pal.icon  : 'fa-solid fa-square';
    var typeLabel = pal ? pal.label : child.type;
    var childColor = TYPE_COLOR_MAP[child.type] || '';
    if (childColor) { row.style.borderLeftColor = childColor; }

    row.innerHTML =
        '<div class="forge-child-row-icon"><i class="' + escHtml(icon) + '"></i></div>' +
        '<div class="forge-child-row-info">' +
            '<div class="forge-child-row-label">' + escHtml(child.label || '(kein Label)') + '</div>' +
            '<div class="forge-child-row-type">'  + escHtml(typeLabel) + '</div>' +
        '</div>' +
        '<div class="forge-row-actions">' +
            '<button class="forge-row-btn forge-row-edit"   title="Bearbeiten"><i class="fa-solid fa-pen-to-square"></i></button>' +
            '<button class="forge-row-btn forge-row-delete" title="Entfernen"><i class="fa-solid fa-trash"></i></button>' +
        '</div>';

    row.addEventListener('click', function (e) {
        if (e.target.closest('.forge-row-delete')) return;
        openSettingsModal(groupIdx, { groupIdx: groupIdx, childIdx: childIdx });
    });
    row.querySelector('.forge-row-edit').addEventListener('click', function (e) {
        e.stopPropagation();
        openSettingsModal(groupIdx, { groupIdx: groupIdx, childIdx: childIdx });
    });
    row.querySelector('.forge-row-delete').addEventListener('click', function (e) {
        e.stopPropagation();
        state.fields[groupIdx].children.splice(childIdx, 1);
        if (onRerender) onRerender();
    });

    return row;
}

/* ================================================================
 * Drag & Drop
 * ================================================================ */
function initDragDrop() {
    var list = document.getElementById('forge-field-list');
    if (!list) return;

    list.addEventListener('dragover', function (e) {
        if (dragSrcIdx === null && dragChildSrc === null) return;
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';
        pendingY = e.clientY;
        if (!rafPending) {
            rafPending = true;
            requestAnimationFrame(applyDropLine);
        }
    });

    list.addEventListener('dragleave', function (e) {
        if (!list.contains(e.relatedTarget)) {
            hideDropLine();
            clearDropIndicators();
            dropSideMode      = null;
            dropSideTargetIdx = null;
            dropLineTarget    = null;
        }
    });

    list.addEventListener('drop', function (e) {
        e.preventDefault();
        rafPending = false;
        hideDropLine();
        list.classList.remove('forge-drag-active');

        /* Child dragged OUT of group → becomes a top-level field */
        if (dragChildSrc !== null) {
            clearGroupDropIndicators();
            if (!dropLineTarget) { dragChildSrc = null; cachedMidpoints = []; return; }
            var csrc = dragChildSrc;
            dragChildSrc = null;
            childDropSideMode = null; childDropTgtIdx = null;
            var cgf = state.fields[csrc.groupIdx];
            if (!cgf || !cgf.children) { dropLineTarget = null; cachedMidpoints = []; renderFieldList(); return; }
            var childField = cgf.children.splice(csrc.childIdx, 1)[0];
            if (!childField) { dropLineTarget = null; cachedMidpoints = []; renderFieldList(); return; }
            childField.cols = 12;
            var cInsert;
            if (dropLineTarget.atEnd) {
                cInsert = state.fields.length;
            } else {
                cInsert = parseInt(dropLineTarget.beforeEl.dataset.idx, 10);
            }
            dropLineTarget = null; cachedMidpoints = [];
            state.fields.splice(cInsert, 0, childField);
            repairGroupPairs(csrc.groupIdx);
            repairPairs();
            renderFieldList();
            return;
        }

        if (dragSrcIdx === null || !dropLineTarget) {
            dragSrcIdx     = null;
            dropLineTarget = null;
            cachedMidpoints = [];
            return;
        }

        var from = dragSrcIdx;
        dragSrcIdx           = null;
        dragBrokenPartnerIdx = null; /* drop handled — don't restore pair in dragend */
        cachedMidpoints      = [];

        var insertIdx;
        if (dropLineTarget.atEnd) {
            insertIdx = state.fields.length;
        } else {
            insertIdx = parseInt(dropLineTarget.beforeEl.dataset.idx, 10);
        }

        if (from < insertIdx) { insertIdx--; }
        dropLineTarget = null;
        if (insertIdx === from) { renderFieldList(); return; }

        var item = state.fields.splice(from, 1)[0];
        item.cols = 12; /* reset to full-width on regular drop */
        state.fields.splice(insertIdx, 0, item);
        repairPairs();
        renderFieldList();
    });
}

/* Reset any cols=6 field that no longer has a consecutive partner */
function repairPairs() {
    var i = 0;
    while (i < state.fields.length) {
        if (state.fields[i].cols === 6) {
            if (i + 1 < state.fields.length && state.fields[i + 1].cols === 6) {
                i += 2; /* valid pair — skip both */
            } else {
                state.fields[i].cols = 12; /* orphan — reset */
                i++;
            }
        } else {
            i++;
        }
    }
}

function repairGroupPairs(groupIdx) {
    var children = (state.fields[groupIdx] && state.fields[groupIdx].children) || [];
    var i = 0;
    while (i < children.length) {
        if (children[i].cols === 6) {
            if (i + 1 < children.length && children[i + 1].cols === 6) { i += 2; }
            else { children[i].cols = 12; i++; }
        } else { i++; }
    }
}

function clearDropIndicators() {
    var els = document.querySelectorAll('.forge-field-row.forge-drop-left, .forge-field-row.forge-drop-right');
    for (var i = 0; i < els.length; i++) {
        els[i].classList.remove('forge-drop-left', 'forge-drop-right');
    }
}

function clearGroupDropIndicators() {
    var els = document.querySelectorAll('.forge-child-row.forge-drop-left, .forge-child-row.forge-drop-right');
    for (var i = 0; i < els.length; i++) {
        els[i].classList.remove('forge-drop-left', 'forge-drop-right');
    }
}

function applyDropLine() {
    rafPending = false;
    if ((dragSrcIdx === null && dragChildSrc === null) || dropSideMode !== null) return;
    var list = document.getElementById('forge-field-list');
    if (!list) return;
    var beforeEl = getAfterFromCache(pendingY);
    dropLineTarget = beforeEl ? { beforeEl: beforeEl } : { atEnd: true };
    showDropLine(list, beforeEl);
}

function getAfterFromCache(y) {
    var closest = null, closestDist = Infinity;
    for (var i = 0; i < cachedMidpoints.length; i++) {
        var entry = cachedMidpoints[i];
        if (y < entry.midY) {
            var dist = entry.midY - y;
            if (dist < closestDist) { closestDist = dist; closest = entry.el; }
        }
    }
    return closest;
}

function showDropLine(list, beforeEl) {
    if (!dropLineEl) {
        dropLineEl = document.createElement('div');
        dropLineEl.className = 'forge-drop-line';
        document.body.appendChild(dropLineEl);
    }
    var listRect = list.getBoundingClientRect();
    var y;
    if (beforeEl) {
        y = beforeEl.getBoundingClientRect().top; /* CSS translateY(-50%) centres it in the gap */
    } else {
        var rows = list.querySelectorAll('.forge-field-row:not(.forge-row-dragging)');
        y = rows.length
            ? rows[rows.length - 1].getBoundingClientRect().bottom + 2
            : listRect.top;
    }
    dropLineEl.style.top     = y + 'px';
    dropLineEl.style.left    = listRect.left + 'px';
    dropLineEl.style.width   = listRect.width + 'px';
    dropLineEl.style.display = 'block';
}

function hideDropLine() {
    if (dropLineEl) { dropLineEl.style.display = 'none'; }
}

function showGroupDropLine(childListEl, beforeEl) {
    if (!dropLineEl) {
        dropLineEl = document.createElement('div');
        dropLineEl.className = 'forge-drop-line';
        document.body.appendChild(dropLineEl);
    }
    var listRect = childListEl.getBoundingClientRect();
    var y;
    if (beforeEl) {
        y = beforeEl.getBoundingClientRect().top;
    } else {
        var rows = childListEl.querySelectorAll('.forge-child-row');
        y = rows.length
            ? rows[rows.length - 1].getBoundingClientRect().bottom + 2
            : listRect.bottom;
    }
    dropLineEl.style.top     = y + 'px';
    dropLineEl.style.left    = listRect.left + 'px';
    dropLineEl.style.width   = listRect.width + 'px';
    dropLineEl.style.display = 'block';
}

function isSecondInGroupPair(groupIdx, childIdx) {
    var children = (state.fields[groupIdx] && state.fields[groupIdx].children) || [];
    if (!children[childIdx] || children[childIdx].cols !== 6) return false;
    var count = 0;
    for (var j = childIdx - 1; j >= 0 && children[j] && children[j].cols === 6; j--) count++;
    return count % 2 === 1;
}

function isSecondInPair(idx) {
    if (!state.fields[idx] || state.fields[idx].cols !== 6) return false;
    var count = 0;
    for (var j = idx - 1; j >= 0 && state.fields[j] && state.fields[j].cols === 6; j--) count++;
    return count % 2 === 1;
}

function cacheMidpoints() {
    var list = document.getElementById('forge-field-list');
    if (!list) return;
    cachedMidpoints = [];
    var rows = list.querySelectorAll('.forge-field-row:not(.forge-row-dragging)');
    for (var i = 0; i < rows.length; i++) {
        var r   = rows[i];
        var idx = parseInt(r.dataset.idx, 10);
        if (isSecondInPair(idx)) continue;
        var box = r.getBoundingClientRect();
        cachedMidpoints.push({ el: r, midY: box.top + box.height / 2 });
    }
}

/* ================================================================
 * Add-field button
 * ================================================================ */
function bindAddFieldButton() {
    var btn = document.getElementById('forge-add-field-btn');
    if (btn) btn.addEventListener('click', openFieldPickerModal);
}

/* ================================================================
 * Field Picker Modal
 * ================================================================ */
function createFieldPickerModal() {
    fieldPickerModal           = document.createElement('div');
    fieldPickerModal.id        = 'forge-field-modal';
    fieldPickerModal.className = 'forge-modal-backdrop';
    fieldPickerModal.hidden    = true;
    fieldPickerModal.innerHTML =
        '<div class="forge-modal" role="dialog" aria-modal="true">' +
            '<div class="forge-modal-header">' +
                '<h2 class="forge-modal-title">Feld hinzufügen</h2>' +
                '<button class="forge-modal-close" type="button">&#x2715;</button>' +
            '</div>' +
            '<div class="forge-modal-search">' +
                '<input id="forge-field-search" type="text" placeholder="Feldtyp suchen…" autocomplete="off" />' +
            '</div>' +
            '<div class="forge-modal-body" id="forge-field-modal-body"></div>' +
        '</div>';
    document.body.appendChild(fieldPickerModal);

    fieldPickerModal.querySelector('.forge-modal-close').addEventListener('click', closeFieldPickerModal);
    fieldPickerModal.addEventListener('click', function (e) { if (e.target === fieldPickerModal) closeFieldPickerModal(); });
    fieldPickerModal.querySelector('#forge-field-search').addEventListener('input', function () {
        renderFieldPickerGroups(this.value);
    });
}

function renderFieldPickerGroups(query) {
    var body = document.getElementById('forge-field-modal-body');
    if (!body) return;
    body.innerHTML = '';
    var q = query.toLowerCase().trim();

    PALETTE_GROUPS.forEach(function (group) {
        var items = group.items.filter(function (item) {
            if (fieldPickerTargetGroup !== null && item.type === 'group') return false;
            return !q || item.label.toLowerCase().indexOf(q) !== -1 || item.type.toLowerCase().indexOf(q) !== -1;
        });
        if (!items.length) return;

        if (!q) {
            var hdr = document.createElement('div');
            hdr.className = 'forge-modal-group-label';
            if (group.color) {
                hdr.innerHTML = '<span class="forge-palette-dot" style="background:' + escHtml(group.color) + '"></span>' + escHtml(group.label);
            } else {
                hdr.textContent = group.label;
            }
            body.appendChild(hdr);
        }
        var grid = document.createElement('div');
        grid.className = 'forge-modal-grid';
        items.forEach(function (item) {
            var card = document.createElement('button');
            card.type      = 'button';
            card.className = 'forge-modal-card';
            card.innerHTML =
                '<span class="forge-modal-card-icon"><i class="' + escHtml(item.icon) + '"></i></span>' +
                '<span class="forge-modal-card-label">' + escHtml(item.label) + '</span>';
            card.addEventListener('click', function () { var tgt = fieldPickerTargetGroup; closeFieldPickerModal(); addField(item.type, tgt); });
            grid.appendChild(card);
        });
        body.appendChild(grid);
    });

    if (!body.children.length) {
        var msg = document.createElement('p');
        msg.className   = 'forge-modal-empty';
        msg.textContent = 'Keine Felder gefunden.';
        body.appendChild(msg);
    }
}

function openFieldPickerModal() {
    if (!fieldPickerModal) return;
    renderFieldPickerGroups('');
    fieldPickerModal.hidden = false;
    document.body.style.overflow = 'hidden';
    var inp = fieldPickerModal.querySelector('#forge-field-search');
    if (inp) { inp.value = ''; setTimeout(function () { inp.focus(); }, 50); }
    document.addEventListener('keydown', onFieldPickerEsc);
}
function closeFieldPickerModal() {
    if (!fieldPickerModal) return;
    fieldPickerModal.hidden = true;
    document.body.style.overflow = '';
    fieldPickerTargetGroup = null;
    document.removeEventListener('keydown', onFieldPickerEsc);
}
function onFieldPickerEsc(e) { if (e.key === 'Escape') closeFieldPickerModal(); }

function addField(type, targetGroup) {
    var pal      = findPaletteItem(type);
    var defaults = (pal && pal.defaults) ? shallowCopy(pal.defaults) : {};
    var field    = shallowCopy(defaults);
    field.id     = generateId(type);
    field.type   = type;
    if (!field.label) field.label = pal ? pal.label : type;

    if (targetGroup !== null && targetGroup !== undefined) {
        /* Adding a child field into a group */
        var ctx = targetGroup;
        var gf = state.fields[ctx.groupIdx];
        if (!gf) return;
        if (!gf.children) gf.children = [];
        /* Fields with noPanel cannot be nested inside groups */
        if (pal && pal.noPanel) return;
        var childIdx = gf.children.length;
        gf.children.push(field);
        renderFieldList();
        openSettingsModal(ctx.groupIdx, { groupIdx: ctx.groupIdx, childIdx: childIdx });
    } else {
        /* Regular top-level add */
        if (type === 'group') { field.children = []; }
        state.fields.push(field);
        renderFieldList();
        openSettingsModal(state.fields.length - 1, null);
    }
}

/* ================================================================
 * Settings Modal
 * ================================================================ */
function createSettingsModal() {
    settingsModal           = document.createElement('div');
    settingsModal.id        = 'forge-settings-modal';
    settingsModal.className = 'forge-modal-backdrop';
    settingsModal.hidden    = true;
    settingsModal.innerHTML =
        '<div class="forge-modal forge-modal--settings" role="dialog" aria-modal="true">' +
            '<div class="forge-modal-header">' +
                '<div class="forge-settings-titlerow">' +
                    '<span class="forge-settings-field-icon"></span>' +
                    '<h2 class="forge-modal-title" id="forge-sp-title">Feldeinstellungen</h2>' +
                '</div>' +
                '<button class="forge-modal-close" type="button">&#x2715;</button>' +
            '</div>' +
            '<div class="forge-stab-bar">' +
                '<button class="forge-stab forge-stab-active" data-stab="general">Allgemein</button>' +
                '<button class="forge-stab" data-stab="advanced">Erweitert</button>' +
                '<button class="forge-stab" data-stab="conditions">Bedingungen</button>' +
            '</div>' +
            '<div class="forge-modal-body forge-settings-body">' +
                '<div id="forge-stab-general"    class="forge-stab-panel forge-stab-active"></div>' +
                '<div id="forge-stab-advanced"   class="forge-stab-panel"></div>' +
                '<div id="forge-stab-conditions" class="forge-stab-panel"></div>' +
            '</div>' +
            '<div class="forge-settings-footer">' +
                '<button class="forge-btn-primary" id="forge-sp-done">Fertig</button>' +
            '</div>' +
        '</div>';
    document.body.appendChild(settingsModal);

    settingsModal.querySelector('.forge-modal-close').addEventListener('click', closeSettingsModal);
    settingsModal.querySelector('#forge-sp-done').addEventListener('click', closeSettingsModal);
    settingsModal.addEventListener('click', function (e) { if (e.target === settingsModal) closeSettingsModal(); });

    settingsModal.querySelectorAll('.forge-stab').forEach(function (btn) {
        btn.addEventListener('click', function () {
            settingsModal.querySelectorAll('.forge-stab').forEach(function (b) { b.classList.remove('forge-stab-active'); });
            settingsModal.querySelectorAll('.forge-stab-panel').forEach(function (p) { p.classList.remove('forge-stab-active'); });
            btn.classList.add('forge-stab-active');
            var panel = document.getElementById('forge-stab-' + btn.dataset.stab);
            if (panel) panel.classList.add('forge-stab-active');
        });
    });
}

function openSettingsModal(idx, ctx) {
    if (!settingsModal || !state.fields[idx]) return;
    settingsCtx      = ctx || null;
    settingsModalIdx = idx;

    var field = ctx
        ? (state.fields[ctx.groupIdx] && state.fields[ctx.groupIdx].children
            ? state.fields[ctx.groupIdx].children[ctx.childIdx] : null)
        : state.fields[idx];
    if (!field) return;

    var pal = findPaletteItem(field.type);
    if (pal && pal.noPanel) return;

    settingsModal.querySelectorAll('.forge-stab').forEach(function (b) { b.classList.remove('forge-stab-active'); });
    settingsModal.querySelectorAll('.forge-stab-panel').forEach(function (p) { p.classList.remove('forge-stab-active'); });
    settingsModal.querySelector('[data-stab="general"]').classList.add('forge-stab-active');
    document.getElementById('forge-stab-general').classList.add('forge-stab-active');

    buildSettingsTabs(idx, field);
    settingsModal.hidden = false;
    document.body.style.overflow = 'hidden';
    document.addEventListener('keydown', onSettingsEsc);
}

function closeSettingsModal() {
    if (!settingsModal) return;
    settingsModal.hidden = true;
    document.body.style.overflow = '';
    document.removeEventListener('keydown', onSettingsEsc);
    settingsCtx      = null;
    settingsModalIdx = null;
    renderFieldList();
}
function onSettingsEsc(e) { if (e.key === 'Escape') closeSettingsModal(); }

function buildSettingsTabs(idx, overrideField) {
    var field = overrideField || state.fields[idx];
    var pal   = findPaletteItem(field.type);

    document.getElementById('forge-sp-title').textContent =
        (pal ? pal.label : field.type) + ' bearbeiten';
    settingsModal.querySelector('.forge-settings-field-icon').innerHTML =
        '<i class="' + escHtml(pal ? pal.icon : 'fa-solid fa-square') + '"></i>';

    buildGeneralTab(idx, field, pal);
    buildAdvancedTab(idx, field);
    buildConditionsTab(idx, field);
}

/* ---- General tab ---- */
function buildGeneralTab(idx, field, pal) {
    var panel  = document.getElementById('forge-stab-general');
    panel.innerHTML = '';
    var schema = (pal && pal.generalSchema) ? pal.generalSchema : [];

    function change(key, val) {
        if (settingsCtx) {
            /* Writing to a group child field */
            var gf = state.fields[settingsCtx.groupIdx];
            if (gf && gf.children) gf.children[settingsCtx.childIdx][key] = val;
        } else {
            state.fields[idx][key] = val;
            if (key === 'label') {
                var rowEl = document.querySelector('.forge-field-row[data-idx="' + idx + '"] .forge-row-label');
                if (rowEl) {
                    var badge = rowEl.querySelector('.forge-cond-badge');
                    rowEl.textContent = val || '(kein Label)';
                    if (badge) rowEl.appendChild(badge);
                }
            }
        }
    }

    spRow(panel, 'label', 'Label', 'text', field.label || '', function (v) { change('label', v); });

    /* Hide the global "Pflichtfeld" checkbox for fields that manage required
     * per sub-component (identified by having a subfields schema entry that is
     * currently active — i.e. expanded mode). */
    var hasActiveSubfields = schema.some(function (s) {
        if (s.type !== 'subfields') return false;
        if (!s.depends_on) return true;
        var depKey = Object.keys(s.depends_on)[0];
        return !!field[depKey] === !!s.depends_on[depKey];
    });
    var hideRequired = (pal && pal.noRequired) || hasActiveSubfields;
    if (!hideRequired) {
        spCheckbox(panel, 'required', 'Pflichtfeld', !!field.required, function (v) { change('required', v); });
    }

    schema.forEach(function (s) {
        /* depends_on: skip this entry if condition not met */
        if (s.depends_on) {
            var depField = state.fields[idx];
            if (s.depends_on.not !== undefined) {
                /* {key: 'fieldKey', not: 'value'} — show when field !== value */
                if (depField[s.depends_on.key] === s.depends_on.not) return;
            } else {
                var depKey = Object.keys(s.depends_on)[0];
                var depExpected = s.depends_on[depKey];
                if (!!depField[depKey] !== !!depExpected) return;
            }
        }

        var cur = field[s.key] !== undefined ? field[s.key] : (s.default !== undefined ? s.default : '');

        if (s.type === 'section_title') {
            spSectionTitle(panel, s.label);
        } else if (s.type === 'text' || s.type === 'email' || s.type === 'url') {
            (function (schema_entry) {
                spRow(panel, schema_entry.key, schema_entry.label, schema_entry.type, cur, function (v) {
                    change(schema_entry.key, v);
                    if (schema_entry.rebuild) {
                        /* rebuild on blur only — not on every keystroke */
                        var inp = document.getElementById('forge-sp-' + schema_entry.key);
                        if (inp && !inp._rebuildBound) {
                            inp._rebuildBound = true;
                            inp.addEventListener('blur', function () {
                                buildGeneralTab(idx, state.fields[idx], findPaletteItem(state.fields[idx].type));
                            });
                        }
                    }
                }, schema_entry.hint);
            }(s));
        } else if (s.type === 'textarea') {
            spTextarea(panel, s.key, s.label, cur, function (v) { change(s.key, v); }, s.hint);
        } else if (s.type === 'number') {
            (function (schema_entry) {
                spRow(panel, schema_entry.key, schema_entry.label, 'number', cur,
                    function (v) {
                        change(schema_entry.key, v === '' ? '' : (parseFloat(v) || 0));
                        if (schema_entry.rebuild) {
                            buildGeneralTab(idx, state.fields[idx], findPaletteItem(state.fields[idx].type));
                        }
                    }, schema_entry.hint);
            }(s));
        } else if (s.type === 'checkbox') {
            spCheckbox(panel, s.key, s.label, !!cur, function (v) { change(s.key, v); });
        } else if (s.type === 'select') {
            spSelect(panel, s.key, s.label, s.options || [], cur, function (v) { change(s.key, v); });
        } else if (s.type === 'options_list' || s.type === 'options') {
            spOptionsList(panel, s.key, s.label, Array.isArray(cur) ? cur : [], function (v) { change(s.key, v); });
        } else if (s.type === 'bool_seg') {
            (function (schema_entry) {
                spBoolSeg(panel, schema_entry.key, schema_entry.label, !!cur,
                    schema_entry.false_label || 'Nein', schema_entry.true_label || 'Ja',
                    function (v) {
                        change(schema_entry.key, v);
                        if (schema_entry.rebuild) {
                            buildGeneralTab(idx, state.fields[idx], findPaletteItem(state.fields[idx].type));
                        }
                    }, !!schema_entry.swap);
            }(s));
        } else if (s.type === 'subfields') {
            spSubfields(panel, s.items || [], field, change);
        } else if (s.type === 'limit_row') {
            (function (schema_entry) {
                var countKey = schema_entry.count_key || (schema_entry.key + '_max');
                spLimitRow(panel, schema_entry.key, schema_entry.label, countKey,
                    field[schema_entry.key] || 'chars',
                    field[countKey] !== undefined ? field[countKey] : '',
                    function (typeVal, countVal) {
                        change(schema_entry.key, typeVal);
                        change(countKey, countVal);
                    });
            }(s));
        } else if (s.type === 'notice') {
            spNotice(panel, s.text || s.label || '', s.level || 'info');
        } else if (s.type === 'html_editor') {
            (function (schema_entry) {
                spHtmlEditor(panel, schema_entry.key, schema_entry.label, cur,
                    function (v) { change(schema_entry.key, v); });
            }(s));
        } else if (s.type === 'icon_row') {
            (function (schema_entry) {
                var halfKey = schema_entry.half_key || 'allow_half';
                var halfCur = !!field[halfKey];
                spIconRow(panel, schema_entry.key, schema_entry.label,
                    schema_entry.options || [], cur, halfKey, halfCur,
                    function (iconVal, halfVal) {
                        change(schema_entry.key, iconVal);
                        change(halfKey, halfVal);
                        if (schema_entry.rebuild) {
                            buildGeneralTab(idx, state.fields[idx], findPaletteItem(state.fields[idx].type));
                        }
                    });
            }(s));
        } else if (s.type === 'media_upload') {
            (function (schema_entry) {
                spMediaUpload(panel, schema_entry.key, schema_entry.label, cur, function (v) {
                    change(schema_entry.key, v);
                    if (schema_entry.rebuild) { rebuildPreview(); }
                }, schema_entry.hint || '');
            }(s));
        } else if (s.type === 'rating_preview') {
            spRatingPreview(panel, state.fields[idx]);
        } else if (s.type === 'time_row') {
            (function (schema_entry) {
                spTimeRow(panel,
                    schema_entry.format_key  || 'time_format',
                    schema_entry.prefill_key || 'prefill_now',
                    !!field[schema_entry.format_key  || 'time_format'],
                    !!field[schema_entry.prefill_key || 'prefill_now'],
                    function (fmtVal, prefillVal) {
                        change(schema_entry.format_key  || 'time_format',  fmtVal);
                        change(schema_entry.prefill_key || 'prefill_now', prefillVal);
                    });
            }(s));
        } else if (s.type === 'country_tags') {
            (function (schema_entry) {
                spCountryTags(panel, schema_entry.key, schema_entry.label,
                    Array.isArray(cur) ? cur : [],
                    function (v) { change(schema_entry.key, v); });
            }(s));
        } else if (s.type === 'pill3') {
            (function (schema_entry) {
                var pillRow = document.createElement('div');
                pillRow.className = 'forge-sp-row';
                var lbl = document.createElement('div');
                lbl.className   = 'forge-sp-label';
                lbl.textContent = schema_entry.label || '';
                pillRow.appendChild(lbl);
                var pill = mkSeg(
                    schema_entry.values || [],
                    schema_entry.labels || schema_entry.values || [],
                    cur,
                    function (v) {
                        change(schema_entry.key, v);
                        if (schema_entry.rebuild) {
                            buildGeneralTab(idx, state.fields[idx],
                                findPaletteItem(state.fields[idx].type));
                        }
                    }
                );
                pillRow.appendChild(pill);
                panel.appendChild(pillRow);
            }(s));
        } else if (s.type === 'pill_multi') {
            (function (schema_entry) {
                var pillRow = document.createElement('div');
                pillRow.className = 'forge-sp-row';
                var lbl = document.createElement('div');
                lbl.className   = 'forge-sp-label';
                lbl.textContent = schema_entry.label || '';
                pillRow.appendChild(lbl);
                var curArr = Array.isArray(cur) ? cur.slice() : (cur ? [cur] : []);
                var pill = mkSegMulti(
                    schema_entry.values || [],
                    schema_entry.labels || schema_entry.values || [],
                    curArr,
                    function (v) { change(schema_entry.key, v); }
                );
                pillRow.appendChild(pill);
                panel.appendChild(pillRow);
            }(s));
        }
    });
}

/* ─── Custom advanced-tab blocks ────────────────────────────────────────────
 *
 * Some field types need UI that the schema system can't express — multi-step
 * conditionals, tag inputs, nested pills, etc.  Instead of scattering
 * field-specific `if` branches inside buildAdvancedTab, each field registers
 * a render function here.
 *
 * HOW TO ADD A CUSTOM BLOCK FOR A NEW FIELD TYPE
 * ─────────────────────────────────────────────────
 * 1. Add a key matching the field's type string (same value used in
 *    FieldRegistry::registerDefaults() on the PHP side).
 * 2. Write a function(panel, field, change) where:
 *      panel  — the DOM element to append UI into
 *      field  — the live field config object (read current values from here)
 *      change — function(key, value) that persists a config change and saves
 * 3. The function runs BEFORE the schema-driven entries from getAdvancedSchema(),
 *    so schema entries always appear below your custom block.
 * 4. If your field has NO schema entries (advancedSchema returns []) you can
 *    put everything here.  If it has some, do both — schema handles the simple
 *    rows, the custom block handles the complex widget.
 *
 * ─────────────────────────────────────────────────────────────────────────── */
var FIELD_ADVANCED_BLOCKS = {

    sepa: function (panel, field, change) {
        spSectionTitle(panel, 'Länderfilter');

        var modeRow = document.createElement('div');
        modeRow.className = 'forge-sp-row';
        var modeLbl = document.createElement('div');
        modeLbl.className   = 'forge-sp-label';
        modeLbl.textContent = 'Länderfilter';
        modeRow.appendChild(modeLbl);

        var listRow = document.createElement('div');

        function updateFilterList() {
            listRow.innerHTML = '';
            if (field['country_filter_mode'] === 'off' || !field['country_filter_mode']) {
                listRow.hidden = true;
                return;
            }
            listRow.hidden = false;
            spCountryTags(listRow, 'country_filter_list', 'Länderliste',
                Array.isArray(field['country_filter_list']) ? field['country_filter_list'] : [],
                function (v) { change('country_filter_list', v); }
            );
        }

        var modePill = mkSeg(
            ['off', 'allow', 'disallow'],
            ['Aus', 'Erlaubt', 'Verboten'],
            field['country_filter_mode'] || 'off',
            function (v) { change('country_filter_mode', v); updateFilterList(); }
        );
        modeRow.appendChild(modePill);
        panel.appendChild(modeRow);
        panel.appendChild(listRow);
        updateFilterList();
    },

    phone: function (panel, field, change) {
        spSectionTitle(panel, 'Format-Validierung');

        var phoneModeRow = document.createElement('div');
        phoneModeRow.className = 'forge-sp-row';

        var phoneExtraSection = document.createElement('div');

        function updatePhoneExtraSection() {
            phoneExtraSection.innerHTML = '';
            var mode = field['phone_mode'] || '';

            if (mode === 'any') {
                var hint = document.createElement('p');
                hint.className   = 'forge-sp-hint';
                hint.textContent = 'Beliebiges gültiges Format: mind. 7 Ziffern, optional mit + und Ländervorwahl.';
                phoneExtraSection.appendChild(hint);
                phoneExtraSection.hidden = false;
                return;
            }

            if (mode === 'countries') {
                phoneExtraSection.hidden = false;
                var subRow = document.createElement('div');
                subRow.className = 'forge-sp-row';
                var subPill = mkSeg(
                    ['allow', 'disallow'],
                    ['Erlaubt', 'Verboten'],
                    field['phone_country_mode'] || 'allow',
                    function (v) { change('phone_country_mode', v); }
                );
                subRow.appendChild(subPill);
                phoneExtraSection.appendChild(subRow);
                spCallingCodeTags(phoneExtraSection, 'phone_country_list', 'Vorwahlen',
                    Array.isArray(field['phone_country_list']) ? field['phone_country_list'] : [],
                    function (v) { change('phone_country_list', v); }
                );
                return;
            }

            phoneExtraSection.hidden = true;
        }

        var phonePill = mkSeg(
            ['', 'any', 'countries'],
            ['Aus', 'Beliebig', 'Länder'],
            field['phone_mode'] || '',
            function (v) { change('phone_mode', v); updatePhoneExtraSection(); }
        );
        phoneModeRow.appendChild(phonePill);
        panel.appendChild(phoneModeRow);
        panel.appendChild(phoneExtraSection);
        updatePhoneExtraSection();
    },

};

/* ---- Advanced tab ---- */
function buildAdvancedTab(idx, field) {
    var panel = document.getElementById('forge-stab-advanced');
    panel.innerHTML = '';

    function change(key, val) {
        if (settingsCtx) {
            var gf = state.fields[settingsCtx.groupIdx];
            if (gf && gf.children) gf.children[settingsCtx.childIdx][key] = val;
        } else {
            state.fields[idx][key] = val;
        }
    }

    var idRow = document.createElement('div');
    idRow.className = 'forge-sp-row';
    var idLbl = document.createElement('label');
    idLbl.className   = 'forge-sp-label';
    idLbl.textContent = 'Feld-ID';
    idLbl.htmlFor     = 'forge-sp-field-id';
    idRow.appendChild(idLbl);
    var idInp = document.createElement('input');
    idInp.type       = 'text';
    idInp.id         = 'forge-sp-field-id';
    idInp.className  = 'forge-sp-input forge-sp-field-id';
    idInp.value      = field.id || '';
    idInp.spellcheck = false;
    idInp.addEventListener('input', function () {
        var slug = this.value.toLowerCase().replace(/[^a-z0-9-]/g, '-').replace(/-{2,}/g, '-');
        this.value = slug;
        state.fields[idx].id = slug || generateId(field.type);
        var hintCode = idRow.querySelector('code');
        if (hintCode) hintCode.textContent = '{' + (slug || state.fields[idx].id) + '}';
        var rowIdBadge = document.querySelector('.forge-field-row[data-idx="' + idx + '"] .forge-row-id');
        if (rowIdBadge) rowIdBadge.textContent = state.fields[idx].id;
    });
    idRow.appendChild(idInp);
    var idHint = document.createElement('p');
    idHint.className = 'forge-sp-hint';
    idHint.innerHTML = 'In E-Mails verwenden: <code>{' + escHtml(field.id) + '}</code>';
    idRow.appendChild(idHint);
    panel.appendChild(idRow);

    var validOpts = VALIDATION_OPTIONS[field.type];
    if (validOpts) {
        spSectionTitle(panel, 'Validierung');
        spSelect(panel, 'validation', 'Validierungsregel',
            validOpts.map(function (o) { return { value: o[0], label: o[1] }; }),
            field.validation || '',
            function (v) { change('validation', v); }
        );
    }

    if (['select','radio','checkbox'].indexOf(field.type) === -1) {
        spSectionTitle(panel, 'Browser-Autovervollständigung');
        spCheckbox(panel, 'autocomplete_on', 'Browser-Ausfüllen aktivieren',
            field.autocomplete_on !== false,
            function (v) { change('autocomplete_on', v); }
        );
        spRow(panel, 'autocomplete', 'Autocomplete-Wert', 'text',
            field.autocomplete || '',
            function (v) { change('autocomplete', v); },
            'Z.B. "name", "email", "tel". Leer = Browser-Standard.'
        );
    }

    spSectionTitle(panel, 'Darstellung');
    spRow(panel, 'custom_class', 'CSS-Klasse(n)', 'text',
        field.custom_class || '',
        function (v) { change('custom_class', v); },
        'Mehrere Klassen mit Leerzeichen trennen.'
    );

    /* ---- Schema-driven advanced entries ----
     * Rendered inside the "Darstellung" section, directly below Feld-ID.
     * Custom blocks (FIELD_ADVANCED_BLOCKS) render after, for field-specific
     * sections like the SEPA Länderfilter. */
    var advPal = findPaletteItem(field.type);
    var advSchema = (advPal && advPal.advancedSchema) ? advPal.advancedSchema : [];

    if (advSchema.length) {
        var advField = settingsCtx
            ? (state.fields[settingsCtx.groupIdx].children[settingsCtx.childIdx])
            : state.fields[idx];

        advSchema.forEach(function (s) {
            if (s.type === 'notice') {
                var nEl = document.createElement('div');
                nEl.className = 'forge-sp-notice forge-sp-notice--' + (s.level || 'info');
                nEl.textContent = s.text || '';
                panel.appendChild(nEl);
                return;
            }

            if (s.type === 'pill3') {
                if (s.label) spSectionTitle(panel, s.label);

                var pillRow = document.createElement('div');
                pillRow.className = 'forge-sp-row';
                var curVal = advField[s.key] !== undefined ? advField[s.key] : (s.values ? s.values[0] : '');
                /* Capture s in closure so the onChange sees the right schema entry */
                (function (entry) {
                    var pill = mkSeg(
                        entry.values || [],
                        entry.labels || entry.values || [],
                        curVal,
                        function (v) {
                            change(entry.key, v);
                            rebuildAdvDeps();
                        }
                    );
                    pillRow.appendChild(pill);
                }(s));
                panel.appendChild(pillRow);

                return;
            }

            /* depends_on: skip entry when condition not met */
            if (s.depends_on) {
                if (s.depends_on.not !== undefined) {
                    if (advField[s.depends_on.key] === s.depends_on.not) return;
                } else {
                    var depKey2 = Object.keys(s.depends_on)[0];
                    if (!!advField[depKey2] !== !!s.depends_on[depKey2]) return;
                }
            }

            (function (schema_entry) {
                var cur2 = advField[schema_entry.key] !== undefined ? advField[schema_entry.key] : '';
                if (schema_entry.type === 'text' || schema_entry.type === 'email' || schema_entry.type === 'url') {
                    spRow(panel, schema_entry.key, schema_entry.label, schema_entry.type, cur2,
                        function (v) { change(schema_entry.key, v); }, schema_entry.hint || '');
                } else if (schema_entry.type === 'number') {
                    spRow(panel, schema_entry.key, schema_entry.label, 'number', cur2,
                        function (v) { change(schema_entry.key, v === '' ? '' : (parseFloat(v) || 0)); },
                        schema_entry.hint || '');
                } else if (schema_entry.type === 'select') {
                    spSelect(panel, schema_entry.key, schema_entry.label,
                        (schema_entry.options || []).map(function (o) {
                            return typeof o === 'string' ? { value: o, label: o } : o;
                        }),
                        cur2,
                        function (v) { change(schema_entry.key, v); });
                } else if (schema_entry.type === 'textarea') {
                    spTextarea(panel, schema_entry.key, schema_entry.label, cur2,
                        function (v) { change(schema_entry.key, v); }, schema_entry.hint || '');
                } else if (schema_entry.type === 'checkbox') {
                    spCheckbox(panel, schema_entry.key, schema_entry.label, !!cur2,
                        function (v) { change(schema_entry.key, v); });
                } else if (schema_entry.type === 'media_upload') {
                    spMediaUpload(panel, schema_entry.key, schema_entry.label, cur2,
                        function (v) { change(schema_entry.key, v); }, schema_entry.hint || '');
                }
            }(s));
        });

        function rebuildAdvDeps() {
            buildAdvancedTab(idx, settingsCtx
                ? (state.fields[settingsCtx.groupIdx].children[settingsCtx.childIdx])
                : state.fields[idx]);
        }
    }

    /* Run the custom block for this field type (e.g. SEPA Länderfilter, Phone mode).
     * Placed last so field-specific sections appear below the shared Darstellung section. */
    if (FIELD_ADVANCED_BLOCKS[field.type]) {
        FIELD_ADVANCED_BLOCKS[field.type](panel, field, change);
    }
}

/* ---- Conditions tab ---- */
function buildConditionsTab(idx, field) {
    var panel = document.getElementById('forge-stab-conditions');
    panel.innerHTML = '';

    /* Resolve which object holds the conditions — group child or top-level field */
    var condOwner;
    if (settingsCtx) {
        var gf = state.fields[settingsCtx.groupIdx];
        condOwner = gf && gf.children ? gf.children[settingsCtx.childIdx] : null;
    } else {
        condOwner = state.fields[idx];
    }
    if (!condOwner) return;

    if (!condOwner.conditions) {
        condOwner.conditions = { action: 'show', match: 'all', rules: [] };
    }
    var cond = condOwner.conditions;

    var sentenceRow = document.createElement('div');
    sentenceRow.className = 'forge-cond-sentence';
    sentenceRow.appendChild(mkSeg(
        ['show', 'hide'],
        ['Anzeigen', 'Ausblenden'],
        cond.action,
        function (v) { cond.action = v; }
    ));
    sentenceRow.appendChild(mkSpan(' dieses Feld, wenn '));
    sentenceRow.appendChild(mkSeg(
        ['all', 'any'],
        ['alle', 'eine'],
        cond.match,
        function (v) { cond.match = v; }
    ));
    sentenceRow.appendChild(mkSpan(' der folgenden Bedingungen zutrifft:'));
    panel.appendChild(sentenceRow);

    var body = document.createElement('div');
    body.className = 'forge-cond-body';
    panel.appendChild(body);

    var rulesList = document.createElement('div');
    rulesList.className = 'forge-cond-rules';
    body.appendChild(rulesList);

    function rebuildRules() {
        rulesList.innerHTML = '';
        (cond.rules || []).forEach(function (rule, ri) {
            rulesList.appendChild(buildRuleRow(rule, ri));
        });
    }

    function buildRuleRow(rule, ri) {
        var CHOICE_TYPES = ['select', 'radio', 'checkbox', 'multivalue'];

        var row = document.createElement('div');
        row.className = 'forge-cond-rule';

        /* Left content: two plain rows */
        var content = document.createElement('div');
        content.className = 'forge-cond-rule-content';
        row.appendChild(content);

        /* Collect all referenceable fields: top-level + group children (flattened) */
        var SKIP_TYPES = ['group', 'html', 'pagebreak'];
        var currentId  = settingsCtx
            ? (state.fields[settingsCtx.groupIdx].children[settingsCtx.childIdx] || {}).id
            : (state.fields[idx] || {}).id;
        var others = [];
        state.fields.forEach(function (f, fi) {
            if (SKIP_TYPES.indexOf(f.type) !== -1) {
                /* Descend into group children */
                (f.children || []).forEach(function (child) {
                    if (child.id && child.id !== currentId && SKIP_TYPES.indexOf(child.type) === -1) {
                        others.push(child);
                    }
                });
                return;
            }
            if (f.id !== currentId) {
                others.push(f);
            }
        });

        /* Row 1: field selector + operator selector */
        var topRow = document.createElement('div');
        topRow.className = 'forge-cond-rule-top';

        var fieldSel = document.createElement('select');
        fieldSel.className = 'forge-cond-sel';
        if (!others.length) {
            var placeholder = document.createElement('option');
            placeholder.textContent = '(keine anderen Felder)';
            fieldSel.appendChild(placeholder);
            fieldSel.disabled = true;
        } else {
            others.forEach(function (f) {
                var o = document.createElement('option');
                o.value       = f.id;
                o.textContent = (f.label || f.id) + ' [' + f.id + ']';
                if (f.id === rule.field_id) o.selected = true;
                fieldSel.appendChild(o);
            });
        }
        topRow.appendChild(fieldSel);

        var opSel = mkSel(
            OPERATORS.map(function (o) { return o[0]; }),
            OPERATORS.map(function (o) { return o[1]; }),
            rule.operator || 'equals',
            function (v) { rule.operator = v; rebuildValueCtrl(); }
        );
        topRow.appendChild(opSel);
        content.appendChild(topRow);

        /* Row 2: value area (mode pill + control) */
        var valArea = document.createElement('div');
        valArea.className = 'forge-cond-val-area';
        content.appendChild(valArea);

        var OPTION_OPERATORS = [
            ['equals',     'ist gleich'],
            ['not_equals', 'ist ungleich'],
        ];
        var VALUE_OPERATORS = OPERATORS;

        function getCtrlField() {
            for (var i = 0; i < state.fields.length; i++) {
                if (state.fields[i].id === fieldSel.value) return state.fields[i];
                var children = state.fields[i].children || [];
                for (var j = 0; j < children.length; j++) {
                    if (children[j].id === fieldSel.value) return children[j];
                }
            }
            return null;
        }

        function fieldIsChoice(f) {
            return f && CHOICE_TYPES.indexOf(f.type) !== -1 && (f.options || []).length;
        }

        /* Default use_option based on the initial controller field */
        if (rule.use_option === undefined) {
            rule.use_option = fieldIsChoice(getCtrlField());
        }

        function rebuildOpSel(forOptionMode) {
            var ops = forOptionMode ? OPTION_OPERATORS : VALUE_OPERATORS;
            var cur = opSel.value;
            while (opSel.firstChild) opSel.removeChild(opSel.firstChild);
            ops.forEach(function (pair) {
                var o = document.createElement('option');
                o.value = pair[0]; o.textContent = pair[1];
                if (pair[0] === cur) o.selected = true;
                opSel.appendChild(o);
            });
            /* If old operator no longer valid, reset to first available */
            if (!opSel.value) opSel.selectedIndex = 0;
            rule.operator = opSel.value;
        }

        function rebuildValueCtrl() {
            valArea.innerHTML = '';
            var op = opSel.value;
            if (op === 'empty' || op === 'not_empty') {
                return;
            }

            var ctrlField = getCtrlField();
            var isChoice  = fieldIsChoice(ctrlField);

            if (isChoice) {
                /* Mode toggle: Option | Wert */
                var modeToggle = mkSeg(
                    ['option', 'value'],
                    ['Option', 'Wert'],
                    rule.use_option ? 'option' : 'value',
                    function (v) {
                        rule.use_option = (v === 'option');
                        rule.value      = '';
                        rebuildOpSel(rule.use_option);
                        rebuildValueCtrl();
                    }
                );
                modeToggle.className += ' forge-cond-mode-seg';
                valArea.appendChild(modeToggle);
            }

            if (isChoice && rule.use_option) {
                /* Dropdown of the field's saved options */
                var optSel = document.createElement('select');
                optSel.className = 'forge-cond-sel forge-cond-opt-sel';
                var blank = document.createElement('option');
                blank.value = ''; blank.textContent = 'Option wählen';
                if (!rule.value) blank.selected = true;
                optSel.appendChild(blank);
                ctrlField.options.forEach(function (opt) {
                    var val   = (opt && typeof opt === 'object') ? (opt.value || '') : String(opt);
                    var lbl   = (opt && typeof opt === 'object') ? (opt.label || val)  : val;
                    var o = document.createElement('option');
                    o.value = val; o.textContent = lbl;
                    if (val === rule.value) o.selected = true;
                    optSel.appendChild(o);
                });
                optSel.addEventListener('change', function () { rule.value = this.value; });
                valArea.appendChild(optSel);
            } else {
                /* Free text / number input */
                var inp = document.createElement('input');
                inp.type        = 'text';
                inp.className   = 'forge-cond-val';
                inp.value       = rule.value || '';
                inp.placeholder = 'Wert';
                inp.addEventListener('input', function () { rule.value = this.value; });
                valArea.appendChild(inp);
            }
        }

        /* Sync operator list on initial render */
        rebuildOpSel(rule.use_option && fieldIsChoice(getCtrlField()));

        fieldSel.addEventListener('change', function () {
            rule.field_id   = this.value;
            rule.value      = '';
            rule.use_option = fieldIsChoice(getCtrlField());
            rebuildOpSel(rule.use_option);
            rebuildValueCtrl();
        });

        rebuildValueCtrl();

        /* ---- Delete — right-side trash button ---- */
        var rm = document.createElement('button');
        rm.type      = 'button';
        rm.className = 'forge-cond-rm';
        rm.title     = 'Bedingung entfernen';
        rm.innerHTML = '<i class="fa-solid fa-trash"></i>';
        rm.addEventListener('click', function () { cond.rules.splice(ri, 1); rebuildRules(); });
        row.appendChild(rm);

        return row;
    }

    rebuildRules();

    var addBtn = document.createElement('button');
    addBtn.type      = 'button';
    addBtn.className = 'forge-cond-add';
    addBtn.innerHTML = '<i class="fa-solid fa-plus"></i> Bedingung hinzufügen';
    addBtn.addEventListener('click', function () {
        var others = state.fields.filter(function (f, fi) { return fi !== idx; });
        cond.rules.push({ field_id: others.length ? others[0].id : '', operator: 'equals', value: '' });
        rebuildRules();
    });
    body.appendChild(addBtn);
}

/* ================================================================
 * Settings panel element builders
 * ================================================================ */
function spRow(parent, key, label, type, value, onChange, hint) {
    var row = document.createElement('div');
    row.className = 'forge-sp-row';
    var lbl = document.createElement('label');
    lbl.className   = 'forge-sp-label';
    lbl.textContent = label;
    lbl.htmlFor     = 'forge-sp-' + key;
    row.appendChild(lbl);
    var inp = document.createElement('input');
    inp.type      = type || 'text';
    inp.id        = 'forge-sp-' + key;
    inp.className = 'forge-sp-input';
    inp.value     = value !== null && value !== undefined ? String(value) : '';
    inp.addEventListener('input', function () { onChange(this.value); });
    row.appendChild(inp);
    if (hint) {
        var h = document.createElement('p');
        h.className   = 'forge-sp-hint';
        h.textContent = hint;
        row.appendChild(h);
    }
    parent.appendChild(row);
}

function spMediaUpload(parent, key, label, value, onChange, hint) {
    var row = document.createElement('div');
    row.className = 'forge-sp-row';

    var lbl = document.createElement('label');
    lbl.className   = 'forge-sp-label';
    lbl.textContent = label;
    lbl.htmlFor     = 'forge-sp-' + key;
    row.appendChild(lbl);

    var wrap = document.createElement('div');
    wrap.className = 'forge-sp-media-wrap';

    var inp = document.createElement('input');
    inp.type        = 'text';
    inp.id          = 'forge-sp-' + key;
    inp.className   = 'forge-sp-input forge-sp-media-url';
    inp.value       = value || '';
    inp.placeholder = 'https://…';
    inp.addEventListener('input', function () { onChange(this.value); updateThumb(); });
    wrap.appendChild(inp);

    var btn = document.createElement('button');
    btn.type      = 'button';
    btn.className = 'button forge-sp-media-btn';
    btn.innerHTML = '<i class="fa-solid fa-photo-film"></i> Mediathek';
    btn.addEventListener('click', function () {
        if (typeof wp === 'undefined' || !wp.media) {
            var u = prompt('Bild-URL:');
            if (u) { inp.value = u; onChange(u); updateThumb(); }
            return;
        }
        var frame = wp.media({ title: label, button: { text: 'Übernehmen' }, multiple: false, library: { type: 'image' } });
        frame.on('open', function () {
            var modal   = document.querySelector('.media-modal');
            var overlay = document.querySelector('.media-modal-backdrop');
            if (modal)   modal.style.zIndex   = '200000';
            if (overlay) overlay.style.zIndex = '199999';
        });
        frame.on('select', function () {
            var att = frame.state().get('selection').first().toJSON();
            inp.value = att.url;
            onChange(att.url);
            updateThumb();
        });
        frame.open();
    });
    wrap.appendChild(btn);

    var thumb = document.createElement('img');
    thumb.className     = 'forge-sp-media-thumb';
    thumb.alt           = '';
    thumb.style.display = value ? 'block' : 'none';
    if (value) { thumb.src = value; }
    wrap.appendChild(thumb);

    function updateThumb() {
        var url = inp.value.trim();
        thumb.src           = url;
        thumb.style.display = url ? 'block' : 'none';
    }

    row.appendChild(wrap);

    if (hint) {
        var h = document.createElement('p');
        h.className   = 'forge-sp-hint';
        h.textContent = hint;
        row.appendChild(h);
    }
    parent.appendChild(row);
}

function spTextarea(parent, key, label, value, onChange, hint) {
    var row = document.createElement('div');
    row.className = 'forge-sp-row';
    var lbl = document.createElement('label');
    lbl.className   = 'forge-sp-label';
    lbl.textContent = label;
    lbl.htmlFor     = 'forge-sp-' + key;
    row.appendChild(lbl);
    var ta = document.createElement('textarea');
    ta.id        = 'forge-sp-' + key;
    ta.className = 'forge-sp-input';
    ta.rows      = 4;
    ta.value     = value || '';
    ta.addEventListener('input', function () { onChange(this.value); });
    row.appendChild(ta);
    if (hint) {
        var h = document.createElement('p');
        h.className   = 'forge-sp-hint';
        h.textContent = hint;
        row.appendChild(h);
    }
    parent.appendChild(row);
}

/* Toggle switch — used for all simple on/off settings fields */
function spCheckbox(parent, key, label, checked, onChange) {
    var row = document.createElement('div');
    row.className = 'forge-sp-toggle-row';

    var toggleLbl = document.createElement('label');
    toggleLbl.className = 'forge-toggle';
    toggleLbl.htmlFor   = 'forge-sp-' + key;

    var inp = document.createElement('input');
    inp.type    = 'checkbox';
    inp.id      = 'forge-sp-' + key;
    inp.checked = !!checked;
    inp.addEventListener('change', function () { onChange(this.checked); });
    toggleLbl.appendChild(inp);

    var slider = document.createElement('span');
    slider.className = 'forge-toggle-slider';
    toggleLbl.appendChild(slider);

    var textLbl = document.createElement('label');
    textLbl.className   = 'forge-toggle-label';
    textLbl.htmlFor     = 'forge-sp-' + key;
    textLbl.textContent = label;

    row.appendChild(toggleLbl);
    row.appendChild(textLbl);
    parent.appendChild(row);
}

function spCheckboxHint(parent, key, label, hint, checked, onChange) {
    var wrap = document.createElement('div');
    wrap.className = 'forge-sp-toggle-row forge-sp-toggle-row--hint';

    var left = document.createElement('div');
    left.className = 'forge-sp-toggle-row-text';
    var textLbl = document.createElement('label');
    textLbl.className   = 'forge-toggle-label';
    textLbl.htmlFor     = 'forge-sp-' + key;
    textLbl.textContent = label;
    left.appendChild(textLbl);
    if (hint) {
        var hintEl = document.createElement('div');
        hintEl.className   = 'forge-sp-hint';
        hintEl.textContent = hint;
        left.appendChild(hintEl);
    }

    var toggleLbl = document.createElement('label');
    toggleLbl.className = 'forge-toggle';
    toggleLbl.htmlFor   = 'forge-sp-' + key;
    var inp = document.createElement('input');
    inp.type    = 'checkbox';
    inp.id      = 'forge-sp-' + key;
    inp.checked = !!checked;
    inp.addEventListener('change', function () { onChange(this.checked); });
    toggleLbl.appendChild(inp);
    var slider = document.createElement('span');
    slider.className = 'forge-toggle-slider';
    toggleLbl.appendChild(slider);

    wrap.appendChild(left);
    wrap.appendChild(toggleLbl);
    parent.appendChild(wrap);
}

function spSelect(parent, key, label, options, value, onChange) {
    var row = document.createElement('div');
    row.className = 'forge-sp-row';
    var lbl = document.createElement('label');
    lbl.className   = 'forge-sp-label';
    lbl.textContent = label;
    lbl.htmlFor     = 'forge-sp-' + key;
    row.appendChild(lbl);
    var sel = document.createElement('select');
    sel.id        = 'forge-sp-' + key;
    sel.className = 'forge-sp-input';
    options.forEach(function (opt) {
        var o = document.createElement('option');
        o.value       = opt.value !== undefined ? opt.value : opt;
        o.textContent = opt.label !== undefined ? opt.label : opt;
        if (String(o.value) === String(value)) o.selected = true;
        sel.appendChild(o);
    });
    sel.addEventListener('change', function () { onChange(this.value); });
    row.appendChild(sel);
    parent.appendChild(row);
}

/* Country names for IBAN-capable countries */
var COUNTRY_NAMES = {
    AD:'Andorra',AE:'Vereinigte Arab. Emirate',AL:'Albanien',AT:'Österreich',AZ:'Aserbaidschan',
    BA:'Bosnien-Herzegowina',BE:'Belgien',BG:'Bulgarien',BH:'Bahrain',BR:'Brasilien',
    CH:'Schweiz',CR:'Costa Rica',CY:'Zypern',CZ:'Tschechien',DE:'Deutschland',
    DJ:'Dschibuti',DK:'Dänemark',DO:'Dominikanische Republik',EE:'Estland',EG:'Ägypten',
    ES:'Spanien',FI:'Finnland',FR:'Frankreich',GB:'Vereinigtes Königreich',GE:'Georgien',
    GI:'Gibraltar',GL:'Grönland',GR:'Griechenland',GT:'Guatemala',HR:'Kroatien',
    HU:'Ungarn',IE:'Irland',IL:'Israel',IQ:'Irak',IS:'Island',IT:'Italien',
    JO:'Jordanien',KW:'Kuwait',KZ:'Kasachstan',LB:'Libanon',LC:'St. Lucia',
    LI:'Liechtenstein',LT:'Litauen',LU:'Luxemburg',LV:'Lettland',LY:'Libyen',
    MA:'Marokko',MC:'Monaco',MD:'Moldau',ME:'Montenegro',MK:'Nordmazedonien',
    MR:'Mauretanien',MT:'Malta',MU:'Mauritius',NI:'Nicaragua',NL:'Niederlande',
    NO:'Norwegen',PK:'Pakistan',PL:'Polen',PT:'Portugal',QA:'Katar',
    RO:'Rumänien',RS:'Serbien',SA:'Saudi-Arabien',SC:'Seychellen',SE:'Schweden',
    SI:'Slowenien',SK:'Slowakei',SM:'San Marino',SV:'El Salvador',TN:'Tunesien',
    TR:'Türkei',UA:'Ukraine',VA:'Vatikanstadt',VG:'Brit. Jungferninseln',XK:'Kosovo'
};

function spCountryTags(parent, key, label, values, onChange) {
    var row = document.createElement('div');
    row.className = 'forge-sp-row';

    var lbl = document.createElement('div');
    lbl.className   = 'forge-sp-label';
    lbl.textContent = label;
    row.appendChild(lbl);

    /*
     * One unified box: chips + search input inline.
     * Clicking anywhere in the box focuses the search input.
     */
    var box = document.createElement('div');
    box.className = 'forge-ctag-box';

    var search = document.createElement('input');
    search.type        = 'text';
    search.placeholder = 'Land suchen und hinzufügen…';
    search.className   = 'forge-ctag-search';
    search.autocomplete = 'off';

    var dropdown = document.createElement('div');
    dropdown.className = 'forge-ctag-dropdown';
    dropdown.hidden    = true;

    /* Clicking the box background focuses the search input */
    box.addEventListener('mousedown', function (e) {
        if (e.target === box) { e.preventDefault(); search.focus(); }
    });

    var current = values.slice();

    function chip(cc) {
        var c = document.createElement('span');
        c.className = 'forge-ctag-chip';

        var code = document.createElement('span');
        code.className   = 'forge-ctag-chip-code';
        code.textContent = cc;
        c.appendChild(code);

        var name = document.createElement('span');
        name.className   = 'forge-ctag-chip-name';
        name.textContent = COUNTRY_NAMES[cc] || '';
        c.appendChild(name);

        var rm = document.createElement('button');
        rm.type      = 'button';
        rm.className = 'forge-ctag-rm';
        rm.setAttribute('aria-label', 'Entfernen');
        rm.innerHTML = '&times;';
        rm.addEventListener('mousedown', function (e) {
            e.preventDefault();
            current = current.filter(function (x) { return x !== cc; });
            render();
            onChange(current.slice());
        });
        c.appendChild(rm);
        return c;
    }

    function render() {
        /* Clear everything except search and dropdown */
        Array.from(box.children).forEach(function (el) {
            if (el !== search && el !== dropdown) box.removeChild(el);
        });
        /* Insert chips before search */
        current.forEach(function (cc) {
            box.insertBefore(chip(cc), search);
        });
        search.placeholder = current.length ? '' : 'Land suchen und hinzufügen…';
    }

    function positionDropdown() {
        var r          = box.getBoundingClientRect();
        var spaceBelow = window.innerHeight - r.bottom - 8;
        var spaceAbove = r.top - 8;

        dropdown.style.left  = r.left + 'px';
        dropdown.style.width = r.width + 'px';

        if (spaceBelow >= spaceAbove) {
            /* open downward, hard-cap height to available space */
            dropdown.style.top       = (r.bottom + 4) + 'px';
            dropdown.style.bottom    = '';
            dropdown.style.maxHeight = Math.min(220, spaceBelow) + 'px';
        } else {
            /* flip upward, cap to space above */
            dropdown.style.top       = '';
            dropdown.style.bottom    = (window.innerHeight - r.top + 4) + 'px';
            dropdown.style.maxHeight = Math.min(220, spaceAbove) + 'px';
        }
    }

    function renderDropdown(query) {
        dropdown.innerHTML = '';
        var q = query.trim().toUpperCase();
        var matches = Object.keys(COUNTRY_NAMES).filter(function (cc) {
            if (current.indexOf(cc) !== -1) return false;
            if (!q) return true;
            return cc.indexOf(q) !== -1 ||
                COUNTRY_NAMES[cc].toUpperCase().indexOf(q) !== -1;
        }).slice(0, 10);

        if (!matches.length) { dropdown.hidden = true; return; }

        matches.forEach(function (cc) {
            var opt = document.createElement('div');
            opt.className = 'forge-ctag-opt';
            var codeSpan = document.createElement('span');
            codeSpan.className   = 'forge-ctag-opt-code';
            codeSpan.textContent = cc;
            var nameSpan = document.createElement('span');
            nameSpan.className   = 'forge-ctag-opt-name';
            nameSpan.textContent = COUNTRY_NAMES[cc] || '';
            opt.appendChild(codeSpan);
            opt.appendChild(nameSpan);
            opt.addEventListener('mousedown', function (e) {
                e.preventDefault();
                if (current.indexOf(cc) === -1) current.push(cc);
                search.value = '';
                render();
                onChange(current.slice());
                search.focus();
                renderDropdown('');
            });
            dropdown.appendChild(opt);
        });
        positionDropdown();
        dropdown.hidden = false;
    }

    search.addEventListener('input', function () { renderDropdown(this.value); });
    search.addEventListener('focus', function () { renderDropdown(this.value); });
    search.addEventListener('blur',  function () {
        setTimeout(function () {
            dropdown.hidden = true;
        }, 150);
    });

    /* Reposition on scroll/resize; remove dropdown from DOM on row removal */
    function onScroll() { if (!dropdown.hidden) positionDropdown(); }
    window.addEventListener('scroll', onScroll, true);
    window.addEventListener('resize', onScroll);

    var observer = new MutationObserver(function () {
        if (!document.body.contains(box)) {
            dropdown.remove();
            observer.disconnect();
            window.removeEventListener('scroll', onScroll, true);
            window.removeEventListener('resize', onScroll);
        }
    });
    observer.observe(document.body, { childList: true, subtree: true });

    box.appendChild(search);
    document.body.appendChild(dropdown); /* fixed-position, outside scroll container */
    render();

    row.appendChild(box);
    parent.appendChild(row);
}

/* Calling codes for phone country filter — stored value is the code string e.g. '+49' */
var PHONE_CALLING_CODES = {
    '+1':   'USA / Kanada',   '+7':   'Russland',       '+20':  'Ägypten',
    '+27':  'Südafrika',      '+30':  'Griechenland',   '+31':  'Niederlande',
    '+32':  'Belgien',        '+33':  'Frankreich',     '+34':  'Spanien',
    '+36':  'Ungarn',         '+39':  'Italien',        '+40':  'Rumänien',
    '+41':  'Schweiz',        '+43':  'Österreich',     '+44':  'Vereinigtes Königreich',
    '+45':  'Dänemark',       '+46':  'Schweden',       '+47':  'Norwegen',
    '+48':  'Polen',          '+49':  'Deutschland',    '+51':  'Peru',
    '+52':  'Mexiko',         '+54':  'Argentinien',    '+55':  'Brasilien',
    '+56':  'Chile',          '+57':  'Kolumbien',      '+61':  'Australien',
    '+62':  'Indonesien',     '+63':  'Philippinen',    '+64':  'Neuseeland',
    '+65':  'Singapur',       '+66':  'Thailand',       '+81':  'Japan',
    '+82':  'Südkorea',       '+84':  'Vietnam',        '+86':  'China',
    '+90':  'Türkei',         '+91':  'Indien',         '+92':  'Pakistan',
    '+94':  'Sri Lanka',      '+98':  'Iran',           '+212': 'Marokko',
    '+213': 'Algerien',       '+216': 'Tunesien',       '+220': 'Gambia',
    '+234': 'Nigeria',        '+254': 'Kenia',          '+351': 'Portugal',
    '+352': 'Luxemburg',      '+353': 'Irland',         '+354': 'Island',
    '+356': 'Malta',          '+357': 'Zypern',         '+358': 'Finnland',
    '+359': 'Bulgarien',      '+370': 'Litauen',        '+371': 'Lettland',
    '+372': 'Estland',        '+380': 'Ukraine',        '+385': 'Kroatien',
    '+386': 'Slowenien',      '+420': 'Tschechien',     '+421': 'Slowakei',
    '+423': 'Liechtenstein'
};

function spCallingCodeTags(parent, key, label, values, onChange) {
    var row = document.createElement('div');
    row.className = 'forge-sp-row';

    var lbl = document.createElement('div');
    lbl.className   = 'forge-sp-label';
    lbl.textContent = label || '';
    row.appendChild(lbl);

    var box = document.createElement('div');
    box.className = 'forge-ctag-box';

    var search = document.createElement('input');
    search.type         = 'text';
    search.placeholder  = 'Vorwahl suchen (+49)…';
    search.className    = 'forge-ctag-search';
    search.autocomplete = 'off';

    var dropdown = document.createElement('div');
    dropdown.className = 'forge-ctag-dropdown';
    dropdown.hidden    = true;

    box.addEventListener('mousedown', function (e) {
        if (e.target === box) { e.preventDefault(); search.focus(); }
    });

    var current = values.slice();

    function chip(code) {
        var c = document.createElement('span');
        c.className = 'forge-ctag-chip';

        var codeSpan = document.createElement('span');
        codeSpan.className   = 'forge-ctag-chip-code';
        codeSpan.textContent = code;
        c.appendChild(codeSpan);

        var nameSpan = document.createElement('span');
        nameSpan.className   = 'forge-ctag-chip-name';
        nameSpan.textContent = PHONE_CALLING_CODES[code] || '';
        c.appendChild(nameSpan);

        var rm = document.createElement('button');
        rm.type      = 'button';
        rm.className = 'forge-ctag-rm';
        rm.setAttribute('aria-label', 'Entfernen');
        rm.innerHTML = '&times;';
        rm.addEventListener('mousedown', function (e) {
            e.preventDefault();
            current = current.filter(function (x) { return x !== code; });
            render();
            onChange(current.slice());
        });
        c.appendChild(rm);
        return c;
    }

    function render() {
        Array.from(box.children).forEach(function (el) {
            if (el !== search && el !== dropdown) box.removeChild(el);
        });
        current.forEach(function (code) { box.insertBefore(chip(code), search); });
        search.placeholder = current.length ? '' : 'Vorwahl suchen (+49)…';
    }

    function positionDropdown() {
        var r = box.getBoundingClientRect();
        var spaceBelow = window.innerHeight - r.bottom - 8;
        var spaceAbove = r.top - 8;
        dropdown.style.left  = r.left + 'px';
        dropdown.style.width = r.width + 'px';
        if (spaceBelow >= spaceAbove) {
            dropdown.style.top       = (r.bottom + 4) + 'px';
            dropdown.style.bottom    = '';
            dropdown.style.maxHeight = Math.min(220, spaceBelow) + 'px';
        } else {
            dropdown.style.top       = '';
            dropdown.style.bottom    = (window.innerHeight - r.top + 4) + 'px';
            dropdown.style.maxHeight = Math.min(220, spaceAbove) + 'px';
        }
    }

    function renderDropdown(query) {
        dropdown.innerHTML = '';
        var q = query.trim().toLowerCase();
        var matches = Object.keys(PHONE_CALLING_CODES).filter(function (code) {
            if (current.indexOf(code) !== -1) return false;
            if (!q) return true;
            return code.indexOf(q) !== -1 ||
                PHONE_CALLING_CODES[code].toLowerCase().indexOf(q) !== -1;
        }).slice(0, 10);

        if (!matches.length) { dropdown.hidden = true; return; }

        matches.forEach(function (code) {
            var opt = document.createElement('div');
            opt.className = 'forge-ctag-opt';
            var cSpan = document.createElement('span');
            cSpan.className   = 'forge-ctag-opt-code';
            cSpan.textContent = code;
            var nSpan = document.createElement('span');
            nSpan.className   = 'forge-ctag-opt-name';
            nSpan.textContent = PHONE_CALLING_CODES[code] || '';
            opt.appendChild(cSpan);
            opt.appendChild(nSpan);
            opt.addEventListener('mousedown', function (e) {
                e.preventDefault();
                if (current.indexOf(code) === -1) current.push(code);
                search.value = '';
                render();
                onChange(current.slice());
                search.focus();
                renderDropdown('');
            });
            dropdown.appendChild(opt);
        });
        positionDropdown();
        dropdown.hidden = false;
    }

    search.addEventListener('input', function () { renderDropdown(this.value); });
    search.addEventListener('focus', function () { renderDropdown(this.value); });
    search.addEventListener('blur',  function () {
        setTimeout(function () { dropdown.hidden = true; }, 150);
    });

    function onScroll() { if (!dropdown.hidden) positionDropdown(); }
    window.addEventListener('scroll', onScroll, true);
    window.addEventListener('resize', onScroll);

    var observer = new MutationObserver(function () {
        if (!document.body.contains(box)) {
            dropdown.remove();
            observer.disconnect();
            window.removeEventListener('scroll', onScroll, true);
            window.removeEventListener('resize', onScroll);
        }
    });
    observer.observe(document.body, { childList: true, subtree: true });

    box.appendChild(search);
    document.body.appendChild(dropdown);
    render();

    row.appendChild(box);
    parent.appendChild(row);
}


/*
 * Options list editor for select/radio/checkbox fields.
 * Each option is stored as {value, label, default}. Accepts plain strings
 * from legacy data and normalises them on first render.
 */
function spOptionsList(parent, key, label, values, onChange) {
    var row = document.createElement('div');
    row.className = 'forge-sp-row';

    var titleRow = document.createElement('div');
    titleRow.className = 'forge-sp-label';
    titleRow.textContent = label;
    row.appendChild(titleRow);

    /* Normalise: plain strings or {value,label} → {value, label, default} */
    var opts = values.map(function (v) {
        if (v && typeof v === 'object') {
            return { value: v.value || '', label: v.label || '', 'default': !!v['default'] };
        }
        var str = String(v || '');
        return { value: str, label: str, 'default': false };
    });

    var colHeader = document.createElement('div');
    colHeader.className = 'forge-sp-option-cols-header';
    colHeader.innerHTML =
        '<span></span>' +
        '<span>Bezeichnung</span>' +
        '<span>Wert</span>' +
        '<span title="Vorausgewählt"><i class="fa-regular fa-star"></i></span>' +
        '<span></span>';
    row.appendChild(colHeader);

    var listEl = document.createElement('div');
    listEl.className = 'forge-sp-options-list';

    function rebuild() {
        listEl.innerHTML = '';
        opts.forEach(function (opt, i) {
            var optRow = document.createElement('div');
            optRow.className = 'forge-sp-option-row';

            /* Drag handle */
            var handle = document.createElement('span');
            handle.className = 'forge-sp-opt-handle';
            handle.innerHTML = '<i class="fa-solid fa-grip-vertical"></i>';
            optRow.appendChild(handle);

            var labelInp = document.createElement('input');
            labelInp.type        = 'text';
            labelInp.className   = 'forge-sp-opt-label';
            labelInp.value       = opt.label;
            labelInp.placeholder = 'Bezeichnung';
            labelInp.addEventListener('input', function () {
                opts[i].label = this.value;
                if (!opts[i].value || opts[i].value === slugify(this.value.slice(0, -1))) {
                    opts[i].value = slugify(this.value);
                    var vi = optRow.querySelector('.forge-sp-opt-value');
                    if (vi) vi.value = opts[i].value;
                }
                onChange(opts.slice());
            });
            optRow.appendChild(labelInp);

            var valueInp = document.createElement('input');
            valueInp.type        = 'text';
            valueInp.className   = 'forge-sp-opt-value';
            valueInp.value       = opt.value;
            valueInp.placeholder = 'wert';
            valueInp.addEventListener('input', function () {
                opts[i].value = this.value;
                onChange(opts.slice());
            });
            optRow.appendChild(valueInp);

            /* Default-selected star toggle */
            var starBtn = document.createElement('button');
            starBtn.type      = 'button';
            starBtn.className = 'forge-sp-opt-star' + (opt['default'] ? ' forge-sp-opt-star--active' : '');
            starBtn.title     = 'Vorausgewählt';
            starBtn.innerHTML = opt['default']
                ? '<i class="fa-solid fa-star"></i>'
                : '<i class="fa-regular fa-star"></i>';
            starBtn.addEventListener('click', function () {
                var isNowActive = !opts[i]['default'];
                opts.forEach(function (o) { o['default'] = false; });
                opts[i]['default'] = isNowActive;
                rebuild();
                onChange(opts.slice());
            });
            optRow.appendChild(starBtn);

            var rm = document.createElement('button');
            rm.type      = 'button';
            rm.className = 'forge-sp-option-remove';
            rm.innerHTML = '<i class="fa-solid fa-trash"></i>';
            rm.addEventListener('click', function () { opts.splice(i, 1); rebuild(); onChange(opts.slice()); });
            optRow.appendChild(rm);

            optRow.dataset.optIdx = String(i);
            listEl.appendChild(optRow);
        });
    }
    rebuild();
    makeSortable(listEl, '.forge-sp-opt-handle', '.forge-sp-option-row', function (rows) {
        var reordered = rows.map(function (r) { return opts[parseInt(r.dataset.optIdx, 10)]; });
        opts.length = 0;
        reordered.forEach(function (o) { opts.push(o); });
        rebuild();
        onChange(opts.slice());
    });
    row.appendChild(listEl);

    var addBtn = document.createElement('button');
    addBtn.type      = 'button';
    addBtn.className = 'forge-sp-add-option';
    addBtn.innerHTML = '<i class="fa-solid fa-plus"></i> Option hinzufügen';
    addBtn.addEventListener('click', function () {
        var n = opts.length + 1;
        opts.push({ value: 'option-' + n, label: 'Option ' + n, 'default': false });
        rebuild();
        onChange(opts.slice());
        var inputs = listEl.querySelectorAll('.forge-sp-opt-label');
        if (inputs.length) inputs[inputs.length - 1].focus();
    });
    row.appendChild(addBtn);
    parent.appendChild(row);
}

/* Labeled segmented control for a boolean field value */
function spBoolSeg(parent, key, label, value, falseLabel, trueLabel, onChange, swap) {
    var row = document.createElement('div');
    row.className = 'forge-sp-row';
    var lbl = document.createElement('div');
    lbl.className   = 'forge-sp-label';
    lbl.textContent = label;
    row.appendChild(lbl);
    var vals   = swap ? ['1', '0'] : ['0', '1'];
    var labels = swap ? [trueLabel, falseLabel] : [falseLabel, trueLabel];
    row.appendChild(mkSeg(vals, labels, value ? '1' : '0', function (v) { onChange(v === '1'); }));
    parent.appendChild(row);
}

function spSectionTitle(parent, text) {
    var t = document.createElement('div');
    t.className   = 'forge-sp-section-title';
    t.textContent = text;
    parent.appendChild(t);
}

function spLimitRow(parent, typeKey, label, countKey, typeVal, countVal, onChange) {
    var row = document.createElement('div');
    row.className = 'forge-sp-row';
    var lbl = document.createElement('div');
    lbl.className   = 'forge-sp-label';
    lbl.textContent = label;
    row.appendChild(lbl);
    var inner = document.createElement('div');
    inner.className = 'forge-sp-limit-row';
    var pill = mkSeg(['chars', 'words'], ['Zeichen', 'Wörter'], typeVal || 'chars', function (v) {
        onChange(v, inp.value);
    });
    inner.appendChild(pill);
    var inp = document.createElement('input');
    inp.type        = 'number';
    inp.min         = '0';
    inp.className   = 'forge-sp-input';
    inp.value       = countVal !== null && countVal !== undefined ? String(countVal) : '';
    inp.placeholder = '∞';
    inp.addEventListener('input', function () {
        var curType = pill.querySelector('.forge-seg-btn.forge-seg-active');
        onChange(curType ? curType.dataset.value : 'chars', this.value);
    });
    inner.appendChild(inp);
    row.appendChild(inner);
    parent.appendChild(row);
}

function spNotice(parent, text, level) {
    var el = document.createElement('div');
    el.className   = 'forge-sp-notice forge-sp-notice--' + (level || 'info');
    el.textContent = text;
    parent.appendChild(el);
}

/* HTML/Section editor: textarea, optionally with a Code|Vorschau toggle */
function spHtmlEditor(parent, key, label, value, onChange, opts) {
    opts = opts || {};
    var showToggle = opts.showToggle !== false;

    var row = document.createElement('div');
    row.className = 'forge-sp-row';

    var hdr = document.createElement('div');
    hdr.className = 'forge-sp-html-hdr';

    if (label) {
        var lbl = document.createElement('div');
        lbl.className   = 'forge-sp-label';
        lbl.textContent = label;
        hdr.appendChild(lbl);
    }

    var ta = document.createElement('textarea');
    ta.className  = 'forge-sp-input forge-sp-html-textarea';
    ta.rows       = 8;
    ta.value      = value || '';
    ta.spellcheck = false;

    var previewDiv;
    if (showToggle) {
        var modeBar = document.createElement('div');
        modeBar.className = 'forge-sp-html-modebar';
        var modePill = mkSeg(['code', 'preview'], ['Code', 'Vorschau'], 'code', switchMode);
        modeBar.appendChild(modePill);
        hdr.appendChild(modeBar);

        previewDiv = document.createElement('div');
        previewDiv.className = 'forge-sp-html-preview';
        previewDiv.hidden    = true;
        previewDiv.innerHTML = value || '';
    }

    if (hdr.children.length) row.appendChild(hdr);

    var resizeTimer = null;
    function resizeTa() {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(function () {
            ta.style.height = 'auto';
            ta.style.height = Math.max(ta.scrollHeight, 140) + 'px';
        }, 30);
    }

    ta.addEventListener('input', function () {
        onChange(this.value);
        if (previewDiv && !previewDiv.hidden) previewDiv.innerHTML = this.value;
        resizeTa();
    });
    row.appendChild(ta);
    if (previewDiv) row.appendChild(previewDiv);

    ta.style.overflowY = 'hidden';
    ta.style.resize     = 'none';
    resizeTa();
    if (window.IntersectionObserver) {
        var taIo = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) { if (entry.isIntersecting) resizeTa(); });
        });
        taIo.observe(row);
    }

    function switchMode(mode) {
        if (mode === 'preview') {
            ta.hidden = true;
            previewDiv.hidden = false;
            previewDiv.innerHTML = ta.value;
        } else {
            ta.hidden = false;
            previewDiv.hidden = true;
        }
    }

    parent.appendChild(row);
}

/* Rich text editor: sandboxed iframe in designMode (not contenteditable —
 * that strips the <html>/<head>/<body> wrapper and is an extension target). */
function spRichTextEditor(parent, key, label, value, onChange) {
    var row = document.createElement('div');
    row.className = 'forge-sp-row';

    if (label) {
        var lbl = document.createElement('div');
        lbl.className   = 'forge-sp-label';
        lbl.textContent = label;
        row.appendChild(lbl);
    }

    var toolbar = document.createElement('div');
    toolbar.className = 'forge-sp-richtext-toolbar';
    var commands = [
        ['bold', 'Fett', 'fa-bold'],
        ['italic', 'Kursiv', 'fa-italic'],
        ['underline', 'Unterstrichen', 'fa-underline'],
        ['insertUnorderedList', 'Liste', 'fa-list-ul'],
        ['insertOrderedList', 'Nummerierte Liste', 'fa-list-ol'],
        ['createLink', 'Link', 'fa-link'],
    ];
    commands.forEach(function (cmd) {
        var btn = document.createElement('button');
        btn.type      = 'button';
        btn.className = 'forge-sp-richtext-btn';
        btn.title     = cmd[1];
        btn.innerHTML = '<i class="fa-solid ' + cmd[2] + '"></i>';
        btn.addEventListener('mousedown', function (e) { e.preventDefault(); });
        btn.addEventListener('click', function () {
            var doc = iframe.contentDocument;
            iframe.contentWindow.focus();
            if (cmd[0] === 'createLink') {
                var url = window.prompt('Link-URL eingeben:', 'https://');
                if (!url) return;
                doc.execCommand('createLink', false, url);
            } else {
                doc.execCommand(cmd[0], false, null);
            }
            emitChange();
        });
        toolbar.appendChild(btn);
    });
    row.appendChild(toolbar);

    var iframe = document.createElement('iframe');
    iframe.className = 'forge-sp-input forge-sp-richtext-editor';
    iframe.setAttribute('sandbox', 'allow-same-origin');
    iframe.setAttribute('title', label || 'Nachricht');
    row.appendChild(iframe);
    parent.appendChild(row);

    function emitChange() {
        onChange(sanitizeRichDoc(iframe.contentDocument));
        resize();
    }

    var resizeTimer = null;
    function resize() {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(doResize, 30);
    }

    function doResize() {
        var doc = iframe.contentDocument;
        if (!doc || !doc.documentElement || !doc.body) return;
        /* scroll metrics are unreliable with overflow:hidden containers
           (e.g. rounded email "card" tables), so measure the real bottom
           edge of every element instead. */
        var max = 0;
        var nodes = doc.body.querySelectorAll('*');
        for (var i = 0; i < nodes.length; i++) {
            var bottom = nodes[i].getBoundingClientRect().bottom;
            if (bottom > max) max = bottom;
        }
        var bodyRect = doc.body.getBoundingClientRect();
        if (bodyRect.bottom > max) max = bodyRect.bottom;

        var fallback = Math.max(
            doc.documentElement.scrollHeight,
            doc.documentElement.offsetHeight,
            doc.body.scrollHeight,
            doc.body.offsetHeight
        );
        var h = Math.max(max, fallback);
        iframe.style.height = Math.max(Math.ceil(h) + 2, 140) + 'px';
    }

    function loadDoc(html) {
        var doc = iframe.contentDocument;
        var full = /<html[\s>]/i.test(html || '')
            ? html
            : '<!DOCTYPE html><html><head><meta charset="utf-8"><style>'
                + 'html,body{margin:0;}'
                + 'body{padding:10px 12px;box-sizing:border-box;'
                + 'font-family:-apple-system,Segoe UI,Arial,sans-serif;'
                + 'font-size:13px;line-height:1.6;color:#1d2327;}</style></head>'
                + '<body>' + (html || '') + '</body></html>';
        doc.open();
        doc.write(full);
        doc.close();
        doc.designMode = 'on';
        doc.addEventListener('input', emitChange);

        /* Email markup is often a fixed-width table wider than the editor;
           a horizontal scrollbar would eat into clientHeight and force an
           unwanted vertical scroll too, so clip overflow-x instead. */
        doc.documentElement.style.overflowX = 'hidden';
        doc.body.style.overflowX = 'hidden';

        /* Images load async and can change the height after the fact. */
        doc.querySelectorAll('img').forEach(function (img) {
            img.addEventListener('load', resize);
            img.addEventListener('error', resize);
        });

        resize();
    }

    loadDoc(value);

    /* Catch late layout shifts (fonts, async assets). */
    setTimeout(resize, 300);

    /* The tab this editor lives in starts hidden (display:none), so every
       resize() call above measures a 0x0 box and falls back to 140px.
       Re-measure the instant the tab actually becomes visible. */
    if (window.IntersectionObserver) {
        var io = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) resize();
            });
        });
        io.observe(row);
    }
}

/* Strips scripts, event handlers, javascript:/vbscript: URIs, and known
 * Dark Reader artifacts from the iframe's live document, then serializes
 * it back with its doctype intact. */
function sanitizeRichDoc(doc) {
    doc.querySelectorAll('script').forEach(function (el) { el.remove(); });
    doc.querySelectorAll(
        'style.darkreader, link.darkreader, meta[name="darkreader"], .darkreader--fallback'
    ).forEach(function (el) { el.remove(); });
    doc.querySelectorAll('*').forEach(function (el) {
        Array.prototype.slice.call(el.attributes).forEach(function (attr) {
            var name = attr.name.toLowerCase();
            var val  = attr.value || '';
            if (/^on/.test(name) || name.indexOf('data-darkreader') === 0) {
                el.removeAttribute(attr.name);
            } else if ((name === 'href' || name === 'src') && /^\s*(javascript|vbscript):/i.test(val)) {
                el.removeAttribute(attr.name);
            }
        });
    });
    return (doc.doctype ? '<!DOCTYPE ' + doc.doctype.name + '>' : '')
        + doc.documentElement.outerHTML;
}

/* Back-compat: migrates legacy body_html_content/body_text_content fields
   into the single canonical HTML body field. */
function normalizeNotifBodyFields(notif) {
    if (notif.body === undefined) {
        var legacy = notif.body_html ? (notif.body_html_content || '')
            : (notif.body_text_content || '');
        notif.body = /<[a-z][\s\S]*>/i.test(legacy)
            ? legacy
            : (function (text) {
                var div = document.createElement('div');
                div.textContent = text;
                return div.innerHTML.replace(/\n/g, '<br>');
            })(legacy);
    }
    delete notif.body_html_content;
    delete notif.body_text_content;
    return notif;
}

/* Rating icon row: select (icon type) + half-values pill in one line */
function spIconRow(parent, iconKey, label, options, iconVal, halfKey, halfVal, onChange) {
    var row = document.createElement('div');
    row.className = 'forge-sp-row';

    var lbl = document.createElement('div');
    lbl.className   = 'forge-sp-label';
    lbl.textContent = label;
    row.appendChild(lbl);

    var inner = document.createElement('div');
    inner.className = 'forge-sp-icon-row';

    var sel = document.createElement('select');
    sel.className = 'forge-sp-input';
    options.forEach(function (opt) {
        var o = document.createElement('option');
        o.value       = opt.value !== undefined ? opt.value : opt;
        o.textContent = opt.label !== undefined ? opt.label : opt;
        if (String(o.value) === String(iconVal)) o.selected = true;
        sel.appendChild(o);
    });

    var halfPill = mkSeg(
        ['0', '1'], ['Ganzzahlen', 'Halbe Werte'],
        halfVal ? '1' : '0',
        function (v) { onChange(sel.value, v === '1'); }
    );

    sel.addEventListener('change', function () {
        var active = halfPill.querySelector('.forge-seg-btn.forge-seg-active');
        onChange(this.value, active ? active.dataset.value === '1' : false);
    });

    inner.appendChild(sel);
    inner.appendChild(halfPill);
    row.appendChild(inner);
    parent.appendChild(row);
}

/* Time format + prefill in one row */
function spTimeRow(parent, formatKey, prefillKey, formatVal, prefillVal, onChange) {
    var row = document.createElement('div');
    row.className = 'forge-sp-time-row';

    /* Left: Format pill */
    var fmtWrap = document.createElement('div');
    fmtWrap.className = 'forge-sp-time-fmt';
    var fmtLbl = document.createElement('div');
    fmtLbl.className   = 'forge-sp-label';
    fmtLbl.textContent = 'Format';
    fmtWrap.appendChild(fmtLbl);
    var fmtPill = mkSeg(['0', '1'], ['24h', '12h (AM/PM)'], formatVal ? '1' : '0', function (v) {
        onChange(v === '1', prefillChk.checked);
    });
    fmtWrap.appendChild(fmtPill);

    /* Right: Prefill toggle */
    var prefillWrap = document.createElement('div');
    prefillWrap.className = 'forge-sp-time-prefill';
    var toggleLbl = document.createElement('label');
    toggleLbl.className = 'forge-toggle';
    var prefillChk = document.createElement('input');
    prefillChk.type    = 'checkbox';
    prefillChk.checked = !!prefillVal;
    prefillChk.addEventListener('change', function () {
        var active = fmtPill.querySelector('.forge-seg-btn.forge-seg-active');
        onChange(active ? active.dataset.value === '1' : false, this.checked);
    });
    toggleLbl.appendChild(prefillChk);
    var slider = document.createElement('span');
    slider.className = 'forge-toggle-slider';
    toggleLbl.appendChild(slider);
    var textLbl = document.createElement('label');
    textLbl.className   = 'forge-toggle-label';
    textLbl.textContent = 'Jetzt vorausfüllen';
    prefillWrap.appendChild(toggleLbl);
    prefillWrap.appendChild(textLbl);

    row.appendChild(fmtWrap);
    row.appendChild(prefillWrap);
    parent.appendChild(row);
}

/* Rating live preview — renders current icon/half/max config */
function spRatingPreview(parent, field) {
    /* Use only the filled glyph for every state — empty is the same glyph at low
     * opacity. This eliminates the Unicode size mismatch between filled/outline
     * pairs (● vs ○, ◆ vs ◇, etc.) which rendered at visually different sizes. */
    var ICONS_JS = {
        star:    '★',
        heart:   '♥',
        circle:  '●',
        diamond: '◆',
    };
    var max       = parseInt(field.max || 5, 10);
    var half      = !!field.allow_half;
    var custom    = !!field.icon_source && !!field.custom_icon_url;
    var iconKey   = field.icon_type || 'star';
    var glyph     = ICONS_JS[iconKey] || ICONS_JS.star;
    var customUrl = custom ? field.custom_icon_url : '';

    var wrap = document.createElement('div');
    wrap.className = 'forge-sp-rating-preview';

    var filledFull = Math.floor(max * 0.6);
    var filledHalf = half && (filledFull < max) ? 1 : 0;

    for (var i = 1; i <= max; i++) {
        var outer = document.createElement('span');
        outer.className = 'forge-sp-rating-star';

        var isFull     = i <= filledFull;
        var isHalfStep = !isFull && half && (i === filledFull + 1) && filledHalf;

        if (customUrl) {
            outer.className += ' forge-sp-rating-img-star';
            var baseImg = document.createElement('img');
            baseImg.src    = customUrl;
            baseImg.width  = 24;
            baseImg.height = 24;
            baseImg.style.opacity = isFull ? '1' : '0.25';
            outer.appendChild(baseImg);
            if (isHalfStep) {
                outer.className += ' forge-sp-rating-half';
                var clipImg = document.createElement('img');
                clipImg.src       = customUrl;
                clipImg.width     = 24;
                clipImg.height    = 24;
                clipImg.className = 'forge-sp-rating-half-fill';
                outer.appendChild(clipImg);
            }
        } else if (isHalfStep) {
            outer.className += ' forge-sp-rating-half';
            var baseSpan = document.createElement('span');
            baseSpan.textContent      = glyph;
            baseSpan.style.opacity    = '0.2';
            outer.appendChild(baseSpan);
            var clipSpan = document.createElement('span');
            clipSpan.className   = 'forge-sp-rating-half-fill';
            clipSpan.textContent = glyph;
            outer.appendChild(clipSpan);
        } else {
            outer.textContent  = glyph;
            outer.style.opacity = isFull ? '1' : '0.2';
        }
        wrap.appendChild(outer);
    }

    parent.appendChild(wrap);
}

/*
 * Sub-fields editor — collapsible accordion cards, one per sub-field.
 * Header click expands/collapses. Enable toggle (optional fields) is
 * independent — it does not open/close the card.
 * Calls change(key, val) for every individual config key.
 */
function spSubfields(parent, items, field, change) {
    spSectionTitle(parent, 'Teilfelder');

    var wrap = document.createElement('div');
    wrap.className = 'forge-sp-subfields';

    items.forEach(function (item) {
        var k = item.key;

        var card = document.createElement('div');
        card.className = 'forge-sp-subfield-card';

        var isEnabled = item.optional ? field[k + '_enabled'] !== false : true;
        if (item.optional && !isEnabled) {
            card.classList.add('forge-sp-subfield--off');
        }

        /* ---- header: [toggle] [name] [chevron] ---- */
        var header = document.createElement('div');
        header.className = 'forge-sp-subfield-hdr';

        /* Enable toggle on the LEFT — always shown, stop-propagation so it
           doesn't fire the accordion toggle handler on the header */
        var togWrap = document.createElement('label');
        togWrap.className = 'forge-toggle forge-toggle--sm';
        togWrap.title     = 'Teilfeld aktivieren';
        togWrap.addEventListener('click', function (e) { e.stopPropagation(); });
        var togInp = document.createElement('input');
        togInp.type    = 'checkbox';
        togInp.checked = isEnabled;
        (function (cardEl, sfKey, optional) {
            togInp.addEventListener('change', function () {
                var v = this.checked;
                if (optional) {
                    change(sfKey + '_enabled', v);
                    cardEl.classList.toggle('forge-sp-subfield--off', !v);
                }
            });
        }(card, k, item.optional));
        /* Non-optional sub-fields: toggle is always checked and disabled */
        if (!item.optional) {
            togInp.checked  = true;
            togInp.disabled = true;
        }
        togWrap.appendChild(togInp);
        var togSlider = document.createElement('span');
        togSlider.className = 'forge-toggle-slider';
        togWrap.appendChild(togSlider);
        header.appendChild(togWrap);

        var nameLbl = document.createElement('span');
        nameLbl.className   = 'forge-sp-subfield-name';
        nameLbl.textContent = item.label;
        header.appendChild(nameLbl);

        /* Chevron on the RIGHT */
        var chevron = document.createElement('span');
        chevron.className = 'forge-sp-subfield-chevron';
        chevron.innerHTML = '<i class="fa-solid fa-chevron-down"></i>';
        header.appendChild(chevron);

        card.appendChild(header);

        /* ---- body (collapsed by default) ---- */
        var body = document.createElement('div');
        body.className = 'forge-sp-subfield-body forge-sp-subfield-body--collapsed';

        /* Label */
        var lblRow = document.createElement('div');
        lblRow.className = 'forge-sp-subrow';
        var lblLabel = document.createElement('label');
        lblLabel.className   = 'forge-sp-sublabel';
        lblLabel.textContent = 'Label';
        var lblInp = document.createElement('input');
        lblInp.type      = 'text';
        lblInp.className = 'forge-sp-input';
        lblInp.value     = field[k + '_label'] !== undefined ? field[k + '_label'] : item.label;
        (function (sfKey) {
            lblInp.addEventListener('input', function () { change(sfKey + '_label', this.value); });
        }(k));
        lblRow.appendChild(lblLabel);
        lblRow.appendChild(lblInp);
        body.appendChild(lblRow);

        /* Placeholder (skip for prefix select) */
        if (!item.is_select) {
            var phRow = document.createElement('div');
            phRow.className = 'forge-sp-subrow';
            var phLabel = document.createElement('label');
            phLabel.className   = 'forge-sp-sublabel';
            phLabel.textContent = 'Platzhalter';
            var phInp = document.createElement('input');
            phInp.type      = 'text';
            phInp.className = 'forge-sp-input';
            phInp.value     = field[k + '_placeholder'] || '';
            (function (sfKey) {
                phInp.addEventListener('input', function () { change(sfKey + '_placeholder', this.value); });
            }(k));
            phRow.appendChild(phLabel);
            phRow.appendChild(phInp);
            body.appendChild(phRow);
        }

        /* Required toggle */
        var reqRow = document.createElement('div');
        reqRow.className = 'forge-sp-toggle-row forge-sp-subrow-toggle';
        var reqTogWrap = document.createElement('label');
        reqTogWrap.className = 'forge-toggle';
        var reqInp = document.createElement('input');
        reqInp.type    = 'checkbox';
        reqInp.checked = !!field[k + '_required'];
        (function (sfKey) {
            reqInp.addEventListener('change', function () { change(sfKey + '_required', this.checked); });
        }(k));
        reqTogWrap.appendChild(reqInp);
        var reqSlider = document.createElement('span');
        reqSlider.className = 'forge-toggle-slider';
        reqTogWrap.appendChild(reqSlider);
        var reqLabel = document.createElement('label');
        reqLabel.className   = 'forge-toggle-label';
        reqLabel.textContent = 'Pflichtfeld';
        reqRow.appendChild(reqTogWrap);
        reqRow.appendChild(reqLabel);
        body.appendChild(reqRow);

        card.appendChild(body);

        /* Accordion toggle on header click */
        header.addEventListener('click', function () {
            var collapsed = body.classList.toggle('forge-sp-subfield-body--collapsed');
            chevron.classList.toggle('forge-sp-subfield-chevron--up', !collapsed);
        });

        wrap.appendChild(card);
    });

    parent.appendChild(wrap);
}

/* ================================================================
 * Notifications — list + modal pattern (mirrors field list)
 * ================================================================ */
var notifModal    = null;
var notifModalIdx = null;

function renderNotifications() {
    var panel = document.getElementById('forge-notifications-panel');
    if (!panel) return;
    panel.innerHTML = '';

    var list = document.createElement('div');
    list.id = 'forge-notif-list';
    if (state.notifications.length === 0) {
        var empty = document.createElement('div');
        empty.className = 'forge-empty-canvas';
        empty.innerHTML =
            '<div class="forge-empty-icon"><i class="fa-solid fa-bell"></i></div>' +
            '<p>Noch keine Benachrichtigungen. Klicke <strong>Benachrichtigung hinzufügen</strong>.</p>';
        list.appendChild(empty);
    } else {
        state.notifications.forEach(function (notif, idx) {
            list.appendChild(buildNotifRow(notif, idx));
        });
    }
    panel.appendChild(list);

    var bar = document.createElement('div');
    bar.id = 'forge-add-notif-bar';
    var addBtn = document.createElement('button');
    addBtn.type      = 'button';
    addBtn.id        = 'forge-add-notif-btn';
    addBtn.innerHTML = '<i class="fa-solid fa-plus"></i> Benachrichtigung hinzufügen';
    addBtn.addEventListener('click', function () {
        var n = state.notifications.length + 1;
        state.notifications.push({
            slug: 'notification-' + n,
            name: 'Benachrichtigung ' + n,
            to: '{admin_email}', subject: 'Formular: {form_title}',
            body: '{all_fields}',
            from_name: '{site_name}',
            from_email: '{admin_email}', attach_pdf: true, enabled: true,
        });
        renderNotifications();
        openNotifModal(state.notifications.length - 1);
    });
    bar.appendChild(addBtn);
    panel.appendChild(bar);
}

function buildNotifRow(notif, idx) {
    var row = document.createElement('div');
    row.className = 'forge-field-row';

    var recipientText = notif.recipient_mode === 'routing'
        ? 'Routing aktiv'
        : (notif.to || '');

    row.innerHTML =
        '<div class="forge-row-handle" style="visibility:hidden"><i class="fa-solid fa-grip-vertical"></i></div>' +
        '<div class="forge-row-icon"><i class="fa-solid fa-bell"></i></div>' +
        '<div class="forge-row-info">' +
            '<div class="forge-row-label">' + escHtml(notif.name || '(kein Name)') + '</div>' +
            '<div class="forge-row-type">' + escHtml(recipientText) + '</div>' +
        '</div>' +
        '<div class="forge-row-actions">' +
            '<button class="forge-row-btn forge-row-edit"   title="Bearbeiten"><i class="fa-solid fa-pen-to-square"></i></button>' +
            '<button class="forge-row-btn forge-row-delete" title="Entfernen"><i class="fa-solid fa-trash"></i></button>' +
        '</div>';

    row.addEventListener('click', function (e) {
        if (e.target.closest('.forge-row-delete')) return;
        openNotifModal(idx);
    });
    row.querySelector('.forge-row-delete').addEventListener('click', function (e) {
        e.stopPropagation();
        state.notifications.splice(idx, 1);
        renderNotifications();
    });

    return row;
}

function createNotifModal() {
    notifModal           = document.createElement('div');
    notifModal.id        = 'forge-notif-modal';
    notifModal.className = 'forge-modal-backdrop';
    notifModal.hidden    = true;
    notifModal.innerHTML =
        '<div class="forge-modal forge-modal--settings" role="dialog" aria-modal="true">' +
            '<div class="forge-modal-header">' +
                '<div class="forge-settings-titlerow">' +
                    '<span class="forge-settings-field-icon"><i class="fa-solid fa-bell"></i></span>' +
                    '<input type="text" id="forge-notif-name-inp" class="forge-notif-modal-name" placeholder="Benachrichtigung">' +
                '</div>' +
                '<button class="forge-modal-close" type="button">&#x2715;</button>' +
            '</div>' +
            '<div class="forge-stab-bar">' +
                '<button class="forge-stab forge-stab-active" data-nstab="recipient">Empfänger</button>' +
                '<button class="forge-stab" data-nstab="content">Inhalt</button>' +
                '<button class="forge-stab" data-nstab="sender">Absender</button>' +
            '</div>' +
            '<div class="forge-modal-body forge-settings-body">' +
                '<div id="forge-nstab-recipient" class="forge-stab-panel forge-stab-active"></div>' +
                '<div id="forge-nstab-content"   class="forge-stab-panel"></div>' +
                '<div id="forge-nstab-sender"    class="forge-stab-panel"></div>' +
            '</div>' +
            '<div class="forge-settings-footer">' +
                '<button class="forge-btn-primary" id="forge-notif-done">Fertig</button>' +
            '</div>' +
        '</div>';
    document.body.appendChild(notifModal);
    notifModal.querySelector('.forge-modal-close').addEventListener('click', closeNotifModal);
    notifModal.querySelector('#forge-notif-done').addEventListener('click', closeNotifModal);
    notifModal.addEventListener('click', function (e) { if (e.target === notifModal) closeNotifModal(); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !notifModal.hidden) closeNotifModal(); });
    notifModal.querySelectorAll('.forge-stab').forEach(function (btn) {
        btn.addEventListener('click', function () {
            notifModal.querySelectorAll('.forge-stab').forEach(function (b) { b.classList.remove('forge-stab-active'); });
            notifModal.querySelectorAll('.forge-stab-panel').forEach(function (p) { p.classList.remove('forge-stab-active'); });
            btn.classList.add('forge-stab-active');
            var p = document.getElementById('forge-nstab-' + btn.dataset.nstab);
            if (p) p.classList.add('forge-stab-active');
        });
    });
}

function openNotifModal(idx) {
    if (!notifModal) return;
    notifModalIdx = idx;
    var notif = state.notifications[idx];

    /* Reset to first tab */
    notifModal.querySelectorAll('.forge-stab').forEach(function (b) { b.classList.remove('forge-stab-active'); });
    notifModal.querySelectorAll('.forge-stab-panel').forEach(function (p) { p.classList.remove('forge-stab-active'); });
    notifModal.querySelector('[data-nstab="recipient"]').classList.add('forge-stab-active');
    document.getElementById('forge-nstab-recipient').classList.add('forge-stab-active');

    var nameInp = document.getElementById('forge-notif-name-inp');
    nameInp.value  = notif.name || '';
    nameInp.oninput = function () { state.notifications[notifModalIdx].name = this.value; };

    buildNotifRecipientTab(notif);
    buildNotifContentTab(notif);
    buildNotifSenderTab(notif);

    notifModal.hidden = false;
    document.body.style.overflow = 'hidden';
}

function closeNotifModal() {
    if (!notifModal) return;
    notifModal.hidden = true;
    document.body.style.overflow = '';
    notifModalIdx = null;
    renderNotifications();
}

function buildNotifRecipientTab(notif) {
    var panel = document.getElementById('forge-nstab-recipient');
    panel.innerHTML = '';

    var mode = notif.recipient_mode || 'single';

    /* Mode pill + enabled toggle in one row */
    var modeRow = document.createElement('div');
    modeRow.className = 'forge-sp-row';
    var modeLbl = document.createElement('div');
    modeLbl.className   = 'forge-sp-label';
    modeLbl.textContent = 'Empfänger-Modus';
    modeRow.appendChild(modeLbl);

    var modeCtrl = document.createElement('div');
    modeCtrl.className = 'forge-sp-row-ctrl';
    modeCtrl.appendChild(mkSeg(
        ['single', 'routing'], ['Direkt', 'Routing'], mode,
        function (v) {
            state.notifications[notifModalIdx].recipient_mode = v;
            buildNotifRecipientTab(state.notifications[notifModalIdx]);
        }
    ));
    var modeCtrlSpacer = document.createElement('div');
    modeCtrlSpacer.style.flex = '1';
    modeCtrl.appendChild(modeCtrlSpacer);

    /* Enabled toggle — inline right */
    var enabledId  = 'forge-sp-notif-enabled-' + notifModalIdx;
    var toggleWrap = document.createElement('div');
    toggleWrap.className = 'forge-sp-inline-toggle';
    var toggleEl = document.createElement('label');
    toggleEl.className = 'forge-toggle';
    toggleEl.htmlFor   = enabledId;
    var toggleInp = document.createElement('input');
    toggleInp.type    = 'checkbox';
    toggleInp.id      = enabledId;
    toggleInp.checked = notif.enabled !== false;
    toggleInp.addEventListener('change', function () {
        state.notifications[notifModalIdx].enabled = this.checked;
    });
    toggleEl.appendChild(toggleInp);
    var toggleSlider = document.createElement('span');
    toggleSlider.className = 'forge-toggle-slider';
    toggleEl.appendChild(toggleSlider);
    toggleWrap.appendChild(toggleEl);
    var toggleLabel = document.createElement('label');
    toggleLabel.className   = 'forge-sp-inline-toggle-label';
    toggleLabel.htmlFor     = enabledId;
    toggleLabel.textContent = 'Aktiv';
    toggleWrap.appendChild(toggleLabel);
    modeCtrl.appendChild(toggleWrap);

    modeRow.appendChild(modeCtrl);
    var modeHint = document.createElement('div');
    modeHint.className   = 'forge-sp-hint';
    modeHint.textContent = mode === 'single'
        ? 'Alle Eintr\xe4ge werden an eine feste Adresse gesendet.'
        : 'E-Mail-Adresse wird anhand von Feldbedingungen gewählt.';
    modeRow.appendChild(modeHint);
    panel.appendChild(modeRow);

    if (mode === 'single') {
        spRow(panel, 'notif-to', 'An (E-Mail)', 'text', notif.to || '', function (v) {
            state.notifications[notifModalIdx].to = v;
        });
    } else {
        /* Routing rules */
        if (!notif.routing_rules) { notif.routing_rules = []; state.notifications[notifModalIdx].routing_rules = []; }
        var rules = notif.routing_rules;

        var rulesWrap = document.createElement('div');
        rulesWrap.className = 'forge-notif-routing';
        panel.appendChild(rulesWrap);

        var CHOICE_TYPES  = ['select', 'radio', 'checkbox', 'multivalue'];
        var ALL_OPS       = OPERATORS; /* reuse global OPERATORS array */
        var OPTION_OPS    = [['equals','ist gleich'],['not_equals','ist ungleich']];

        function rebuildRoutingRules() {
            rulesWrap.innerHTML = '';

            rules.forEach(function (rule, ri) {
                var rr = document.createElement('div');
                rr.className = 'forge-cond-rule';

                var content = document.createElement('div');
                content.className = 'forge-cond-rule-content';
                rr.appendChild(content);

                /* Row 1: field selector + operator selector */
                var topRow = document.createElement('div');
                topRow.className = 'forge-cond-rule-top';

                var fSel = document.createElement('select');
                fSel.className = 'forge-cond-sel';
                var eligible = state.fields.filter(function (f) {
                    return f.id && ['group','html','pagebreak'].indexOf(f.type) === -1;
                });
                if (!eligible.length) {
                    var ph = document.createElement('option');
                    ph.textContent = '(keine Felder)'; fSel.appendChild(ph); fSel.disabled = true;
                } else {
                    eligible.forEach(function (f) {
                        var o = document.createElement('option');
                        o.value = f.id; o.textContent = (f.label || f.id) + ' [' + f.id + ']';
                        if (f.id === rule.field_id) o.selected = true;
                        fSel.appendChild(o);
                    });
                }
                topRow.appendChild(fSel);

                var opSel = mkSel(
                    ALL_OPS.map(function (o) { return o[0]; }),
                    ALL_OPS.map(function (o) { return o[1]; }),
                    rule.operator || 'equals',
                    function (v) { rule.operator = v; rebuildValCtrl(); }
                );
                topRow.appendChild(opSel);
                content.appendChild(topRow);

                /* Row 2: value area */
                var valArea = document.createElement('div');
                valArea.className = 'forge-cond-val-area';
                content.appendChild(valArea);

                function getCtrl() {
                    for (var i = 0; i < state.fields.length; i++) {
                        if (state.fields[i].id === fSel.value) return state.fields[i];
                    }
                    return null;
                }
                function isChoice(f) {
                    return f && CHOICE_TYPES.indexOf(f.type) !== -1 && (f.options || []).length;
                }

                if (rule.use_option === undefined) { rule.use_option = isChoice(getCtrl()); }

                function rebuildOpSel(forOption) {
                    var ops = forOption ? OPTION_OPS : ALL_OPS;
                    var cur = opSel.value;
                    while (opSel.firstChild) opSel.removeChild(opSel.firstChild);
                    ops.forEach(function (pair) {
                        var o = document.createElement('option');
                        o.value = pair[0]; o.textContent = pair[1];
                        if (pair[0] === cur) o.selected = true;
                        opSel.appendChild(o);
                    });
                    if (!opSel.value) opSel.selectedIndex = 0;
                    rule.operator = opSel.value;
                }

                function rebuildValCtrl() {
                    valArea.innerHTML = '';
                    if (opSel.value === 'empty' || opSel.value === 'not_empty') { return; }
                    var cf = getCtrl();
                    var choice = isChoice(cf);
                    if (choice) {
                        var modePill = mkSeg(['option','value'],['Option','Wert'],
                            rule.use_option ? 'option' : 'value',
                            function (v) {
                                rule.use_option = (v === 'option');
                                rule.value = '';
                                rebuildOpSel(rule.use_option);
                                rebuildValCtrl();
                            });
                        modePill.className += ' forge-cond-mode-seg';
                        valArea.appendChild(modePill);
                    }
                    if (choice && rule.use_option) {
                        var optSel = document.createElement('select');
                        optSel.className = 'forge-cond-sel forge-cond-opt-sel';
                        var blank = document.createElement('option');
                        blank.value = ''; blank.textContent = 'Option wählen';
                        if (!rule.value) blank.selected = true;
                        optSel.appendChild(blank);
                        cf.options.forEach(function (opt) {
                            var val = (opt && typeof opt === 'object') ? (opt.value || '') : String(opt);
                            var lbl = (opt && typeof opt === 'object') ? (opt.label || val) : val;
                            var o = document.createElement('option');
                            o.value = val; o.textContent = lbl;
                            if (val === rule.value) o.selected = true;
                            optSel.appendChild(o);
                        });
                        optSel.addEventListener('change', function () { rule.value = this.value; });
                        valArea.appendChild(optSel);
                    } else {
                        var inp = document.createElement('input');
                        inp.type = 'text'; inp.className = 'forge-cond-val';
                        inp.value = rule.value || ''; inp.placeholder = 'Wert';
                        inp.addEventListener('input', function () { rule.value = this.value; });
                        valArea.appendChild(inp);
                    }
                }

                rebuildOpSel(rule.use_option && isChoice(getCtrl()));
                fSel.addEventListener('change', function () {
                    rule.field_id   = this.value;
                    rule.value      = '';
                    rule.use_option = isChoice(getCtrl());
                    rebuildOpSel(rule.use_option);
                    rebuildValCtrl();
                });
                rebuildValCtrl();

                /* Email target */
                var emailRow = document.createElement('div');
                emailRow.className = 'forge-notif-routing-email-row';
                var arrowSpan = document.createElement('span');
                arrowSpan.className   = 'forge-notif-routing-arrow';
                arrowSpan.textContent = '→ An:';
                emailRow.appendChild(arrowSpan);
                var emailInp = document.createElement('input');
                emailInp.type = 'text'; emailInp.className = 'forge-cond-val';
                emailInp.value = rule.email || ''; emailInp.placeholder = 'E-Mail';
                emailInp.addEventListener('input', function () { rules[ri].email = this.value; });
                emailRow.appendChild(emailInp);
                content.appendChild(emailRow);

                /* Delete */
                var rm = document.createElement('button');
                rm.type = 'button'; rm.className = 'forge-cond-rm'; rm.title = 'Regel entfernen';
                rm.innerHTML = '<i class="fa-solid fa-trash"></i>';
                rm.addEventListener('click', function () {
                    rules.splice(ri, 1);
                    state.notifications[notifModalIdx].routing_rules = rules;
                    rebuildRoutingRules();
                });
                rr.appendChild(rm);

                rulesWrap.appendChild(rr);
            });

            var addRule = document.createElement('button');
            addRule.type = 'button'; addRule.className = 'forge-cond-add';
            addRule.innerHTML = '<i class="fa-solid fa-plus"></i> Regel hinzufügen';
            addRule.addEventListener('click', function () {
                var first = state.fields.find(function (f) {
                    return f.id && ['group','html','pagebreak'].indexOf(f.type) === -1;
                });
                rules.push({ field_id: first ? first.id : '', operator: 'equals', value: '', email: '' });
                state.notifications[notifModalIdx].routing_rules = rules;
                rebuildRoutingRules();
            });
            rulesWrap.appendChild(addRule);
        }
        rebuildRoutingRules();

        spRow(panel, 'notif-fallback', 'Fallback-E-Mail', 'text', notif.routing_fallback || '',
            function (v) { state.notifications[notifModalIdx].routing_fallback = v; },
            'Wird verwendet wenn keine Regel zutrifft');
    }

}

function buildNotifContentTab(notif) {
    var panel = document.getElementById('forge-nstab-content');
    panel.innerHTML = '';

    spRow(panel, 'notif-subject', 'Betreff', 'text', notif.subject || '',
        function (v) { state.notifications[notifModalIdx].subject = v; });

    /* Body: Visual | Code — both views read/write notif.body directly.
       body_html is a transient in-memory flag for the active view; it's
       never persisted, so every fresh load defaults to Visual. */
    var isHtml = !!notif.body_html;
    var bodyWrap = document.createElement('div');
    bodyWrap.className = 'forge-sp-row';

    var bodyHdr = document.createElement('div');
    bodyHdr.className = 'forge-sp-html-hdr';
    var bodyLbl = document.createElement('div');
    bodyLbl.className = 'forge-sp-label'; bodyLbl.textContent = 'Nachricht';
    bodyHdr.appendChild(bodyLbl);
    var typePill = mkSeg(['text','html'], ['Visuell','Code'], isHtml ? 'html' : 'text', function (v) {
        var notif = state.notifications[notifModalIdx];
        notif.body_html = (v === 'html');
        buildNotifContentTab(notif);
    });
    var modeBar = document.createElement('div');
    modeBar.className = 'forge-sp-html-modebar';
    modeBar.appendChild(typePill);
    bodyHdr.appendChild(modeBar);
    bodyWrap.appendChild(bodyHdr);
    panel.appendChild(bodyWrap);

    if (isHtml) {
        spHtmlEditor(panel, 'notif-body', '', notif.body || '',
            function (v) { state.notifications[notifModalIdx].body = v; },
            { showToggle: false });
    } else {
        spRichTextEditor(panel, 'notif-body', '', notif.body || '',
            function (v) { state.notifications[notifModalIdx].body = v; });
    }

    /* Attachments section */
    var attachSep = document.createElement('div');
    attachSep.className   = 'forge-sp-section-sep';
    attachSep.textContent = 'Anhänge';
    panel.appendChild(attachSep);

    spCheckboxHint(panel, 'notif-pdf', 'Generiertes PDF anhängen',
        'Das ausgefüllte Formular wird als PDF-Dokument beigefügt.',
        !!notif.attach_pdf,
        function (v) { state.notifications[notifModalIdx].attach_pdf = v; });

    spCheckboxHint(panel, 'notif-uploads', 'Hochgeladene Dateien anhängen',
        'Alle Datei-Uploads aus dem Formular werden als Anhänge weitergeleitet.',
        !!notif.attach_uploads,
        function (v) { state.notifications[notifModalIdx].attach_uploads = v; });
}

function buildNotifSenderTab(notif) {
    var panel = document.getElementById('forge-nstab-sender');
    panel.innerHTML = '';

    function sr(key, label, hint) {
        spRow(panel, 'notif-' + key, label, 'text', notif[key] || '',
            function (v) { state.notifications[notifModalIdx][key] = v; }, hint);
    }
    sr('from_name',  'Absendername',                  '{site_name} für Standardwert');
    sr('from_email', 'E-Mail-Adresse des Absenders',  '{admin_email} für Standardwert');
    sr('reply_to',   'Antwort-E-Mail',                'Leer = Absender-E-Mail');
    sr('cc',         'CC-E-Mails',                    'Mehrere mit Semikolon trennen');
    sr('bcc',        'BCC-E-Mails',                   'Mehrere mit Semikolon trennen');
}

/* ================================================================
 * Submit button preview + settings modal
 * ================================================================ */
var submitModal = null;

function createSubmitModal() {
    submitModal           = document.createElement('div');
    submitModal.id        = 'forge-submit-modal';
    submitModal.className = 'forge-modal-backdrop';
    submitModal.hidden    = true;
    submitModal.innerHTML =
        '<div class="forge-modal forge-modal--settings" role="dialog" aria-modal="true">' +
            '<div class="forge-modal-header">' +
                '<div class="forge-settings-titlerow">' +
                    '<span class="forge-settings-field-icon"><i class="fa-solid fa-paper-plane"></i></span>' +
                    '<span class="forge-settings-field-label">Absende-Schaltfläche</span>' +
                '</div>' +
                '<button class="forge-modal-close" type="button">&#x2715;</button>' +
            '</div>' +
            '<div class="forge-stab-bar">' +
                '<button class="forge-stab forge-stab-active" data-smtab="labels">Beschriftung</button>' +
                '<button class="forge-stab" data-smtab="conditions">Bedingungen</button>' +
            '</div>' +
            '<div class="forge-modal-body forge-settings-body">' +
                '<div id="forge-smtab-labels"     class="forge-stab-panel forge-stab-active"></div>' +
                '<div id="forge-smtab-conditions" class="forge-stab-panel"></div>' +
            '</div>' +
            '<div class="forge-settings-footer">' +
                '<button class="forge-btn-primary" id="forge-submit-done">Fertig</button>' +
            '</div>' +
        '</div>';
    document.body.appendChild(submitModal);
    submitModal.querySelector('.forge-modal-close').addEventListener('click', closeSubmitModal);
    submitModal.querySelector('#forge-submit-done').addEventListener('click', closeSubmitModal);
    submitModal.addEventListener('click', function (e) { if (e.target === submitModal) closeSubmitModal(); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !submitModal.hidden) closeSubmitModal(); });
    submitModal.querySelectorAll('.forge-stab').forEach(function (btn) {
        btn.addEventListener('click', function () {
            submitModal.querySelectorAll('.forge-stab').forEach(function (b) { b.classList.remove('forge-stab-active'); });
            submitModal.querySelectorAll('.forge-stab-panel').forEach(function (p) { p.classList.remove('forge-stab-active'); });
            btn.classList.add('forge-stab-active');
            document.getElementById('forge-smtab-' + btn.dataset.smtab).classList.add('forge-stab-active');
        });
    });
}

function openSubmitModal() {
    if (!submitModal) return;

    /* Reset to first tab */
    submitModal.querySelectorAll('.forge-stab').forEach(function (b) { b.classList.remove('forge-stab-active'); });
    submitModal.querySelectorAll('.forge-stab-panel').forEach(function (p) { p.classList.remove('forge-stab-active'); });
    submitModal.querySelector('[data-smtab="labels"]').classList.add('forge-stab-active');
    document.getElementById('forge-smtab-labels').classList.add('forge-stab-active');

    buildSubmitLabelsTab();
    buildSubmitConditionsTab();

    submitModal.hidden = false;
    document.body.style.overflow = 'hidden';
}

function closeSubmitModal() {
    if (!submitModal) return;
    submitModal.hidden = true;
    document.body.style.overflow = '';
    renderSubmitPreview();
}

function buildSubmitLabelsTab() {
    var panel = document.getElementById('forge-smtab-labels');
    panel.innerHTML = '';
    var s = state.settings;

    spRow(panel, 'smt-label', 'Beschriftung', 'text', s.submit_label || 'Absenden',
        function (v) { state.settings.submit_label = v; renderSubmitPreview(); },
        'Sichtbarer Text der Schaltfläche');

    spRow(panel, 'smt-working', 'Beschriftung beim Senden', 'text', s.submit_working || 'Wird gesendet…',
        function (v) { state.settings.submit_working = v; },
        'Wird w\xe4hrend der \xdcbermittlung angezeigt');

    spRow(panel, 'smt-success', 'Erfolgsmeldung', 'textarea', s.success_message || '',
        function (v) { state.settings.success_message = v; },
        'Nachricht nach erfolgreichem Eintrag');
}

function buildSubmitConditionsTab() {
    var panel = document.getElementById('forge-smtab-conditions');
    panel.innerHTML = '';
    var s = state.settings;
    if (!s.submit_conditions) {
        s.submit_conditions = { match: 'all', rules: [] };
    }
    var cond = s.submit_conditions;

    /* Sentence: Schaltfläche anzeigen wenn [all|any] ... */
    var smSentence = document.createElement('div');
    smSentence.className = 'forge-cond-sentence';
    smSentence.appendChild(mkSpan('Schaltfl\xe4che anzeigen, wenn '));
    smSentence.appendChild(mkSeg(
        ['all', 'any'], ['alle', 'eine'],
        cond.match || 'all',
        function (v) { state.settings.submit_conditions.match = v; }
    ));
    smSentence.appendChild(mkSpan(' der Bedingungen zutrifft:'));
    panel.appendChild(smSentence);

    var smBody = document.createElement('div');
    smBody.className = 'forge-cond-body';
    panel.appendChild(smBody);

    var smRulesList = document.createElement('div');
    smRulesList.className = 'forge-cond-rules';
    smBody.appendChild(smRulesList);

    function smRebuildRules() {
        smRulesList.innerHTML = '';
        (cond.rules || []).forEach(function (rule, ri) {
            smRulesList.appendChild(smBuildRuleRow(rule, ri));
        });
    }

    function smBuildRuleRow(rule, ri) {
        var CHOICE_TYPES = ['select', 'radio', 'checkbox', 'multivalue'];
        var rr = document.createElement('div');
        rr.className = 'forge-cond-rule';

        var content = document.createElement('div');
        content.className = 'forge-cond-rule-content';
        rr.appendChild(content);

        var eligible = state.fields.filter(function (f) {
            return f.id && ['group','html','pagebreak'].indexOf(f.type) === -1;
        });

        var topRow = document.createElement('div');
        topRow.className = 'forge-cond-rule-top';

        var fSel = document.createElement('select');
        fSel.className = 'forge-cond-sel';
        if (!eligible.length) {
            var ph = document.createElement('option');
            ph.textContent = '(keine Felder)';
            fSel.appendChild(ph);
            fSel.disabled = true;
        } else {
            eligible.forEach(function (f) {
                var o = document.createElement('option');
                o.value = f.id;
                o.textContent = (f.label || f.id) + ' [' + f.id + ']';
                if (f.id === rule.field_id) o.selected = true;
                fSel.appendChild(o);
            });
        }
        topRow.appendChild(fSel);

        var OPTION_OPERATORS = [['equals','ist gleich'],['not_equals','ist ungleich']];

        var opSel = mkSel(
            OPERATORS.map(function (o) { return o[0]; }),
            OPERATORS.map(function (o) { return o[1]; }),
            rule.operator || 'equals',
            function (v) { rule.operator = v; smRebuildValCtrl(); }
        );
        topRow.appendChild(opSel);
        content.appendChild(topRow);

        var valArea = document.createElement('div');
        valArea.className = 'forge-cond-val-area';
        content.appendChild(valArea);

        function smGetField() {
            for (var i = 0; i < state.fields.length; i++) {
                if (state.fields[i].id === fSel.value) return state.fields[i];
            }
            return null;
        }
        function smIsChoice(f) {
            return f && CHOICE_TYPES.indexOf(f.type) !== -1 && (f.options || []).length;
        }
        if (rule.use_option === undefined) { rule.use_option = smIsChoice(smGetField()); }

        function smRebuildOpSel(forOption) {
            var ops = forOption ? OPTION_OPERATORS : OPERATORS;
            var cur = opSel.value;
            while (opSel.firstChild) opSel.removeChild(opSel.firstChild);
            ops.forEach(function (pair) {
                var o = document.createElement('option');
                o.value = pair[0]; o.textContent = pair[1];
                if (pair[0] === cur) o.selected = true;
                opSel.appendChild(o);
            });
            if (!opSel.value) opSel.selectedIndex = 0;
            rule.operator = opSel.value;
        }

        function smRebuildValCtrl() {
            valArea.innerHTML = '';
            if (opSel.value === 'empty' || opSel.value === 'not_empty') { return; }
            var cf = smGetField();
            var choice = smIsChoice(cf);
            if (choice) {
                var modePill = mkSeg(['option','value'],['Option','Wert'],
                    rule.use_option ? 'option' : 'value',
                    function (v) {
                        rule.use_option = (v === 'option');
                        rule.value = '';
                        smRebuildOpSel(rule.use_option);
                        smRebuildValCtrl();
                    });
                modePill.className += ' forge-cond-mode-seg';
                valArea.appendChild(modePill);
            }
            if (choice && rule.use_option) {
                var optSel = document.createElement('select');
                optSel.className = 'forge-cond-sel forge-cond-opt-sel';
                var blank = document.createElement('option');
                blank.value = ''; blank.textContent = 'Option w\xe4hlen';
                if (!rule.value) blank.selected = true;
                optSel.appendChild(blank);
                cf.options.forEach(function (opt) {
                    var val = (opt && typeof opt === 'object') ? (opt.value || '') : String(opt);
                    var lbl = (opt && typeof opt === 'object') ? (opt.label || val)  : val;
                    var o = document.createElement('option');
                    o.value = val; o.textContent = lbl;
                    if (val === rule.value) o.selected = true;
                    optSel.appendChild(o);
                });
                optSel.addEventListener('change', function () { rule.value = this.value; });
                valArea.appendChild(optSel);
            } else {
                var inp = document.createElement('input');
                inp.type = 'text'; inp.className = 'forge-cond-val';
                inp.value = rule.value || ''; inp.placeholder = 'Wert';
                inp.addEventListener('input', function () { rule.value = this.value; });
                valArea.appendChild(inp);
            }
        }

        smRebuildOpSel(rule.use_option && smIsChoice(smGetField()));
        fSel.addEventListener('change', function () {
            rule.field_id   = this.value;
            rule.value      = '';
            rule.use_option = smIsChoice(smGetField());
            smRebuildOpSel(rule.use_option);
            smRebuildValCtrl();
        });
        smRebuildValCtrl();

        var rm = document.createElement('button');
        rm.type = 'button'; rm.className = 'forge-cond-rm'; rm.title = 'Bedingung entfernen';
        rm.innerHTML = '<i class="fa-solid fa-trash"></i>';
        rm.addEventListener('click', function () { cond.rules.splice(ri, 1); smRebuildRules(); });
        rr.appendChild(rm);

        return rr;
    }

    smRebuildRules();

    var smAddBtn = document.createElement('button');
    smAddBtn.type = 'button'; smAddBtn.className = 'forge-cond-add';
    smAddBtn.innerHTML = '<i class="fa-solid fa-plus"></i> Bedingung hinzuf\xfcgen';
    smAddBtn.addEventListener('click', function () {
        var first = state.fields.find(function (f) {
            return f.id && ['group','html','pagebreak'].indexOf(f.type) === -1;
        });
        cond.rules.push({ field_id: first ? first.id : '', operator: 'equals', value: '' });
        smRebuildRules();
    });
    smBody.appendChild(smAddBtn);
}

function renderSubmitPreview() {
    var bar = document.getElementById('forge-submit-preview-bar');
    if (!bar) return;
    var label = state.settings.submit_label || 'Absenden';
    var cond  = state.settings.submit_conditions;
    var condActive = cond && (cond.rules || []).length > 0;

    bar.innerHTML = '';
    var wrap = document.createElement('div');
    wrap.className = 'forge-submit-preview';
    wrap.title     = 'Schaltfläche konfigurieren';
    wrap.addEventListener('click', openSubmitModal);

    var btnPreview = document.createElement('button');
    btnPreview.type      = 'button';
    btnPreview.className = 'forge-submit-preview-btn';
    btnPreview.textContent = label;
    btnPreview.tabIndex  = -1;
    wrap.appendChild(btnPreview);

    if (condActive) {
        var badge = document.createElement('span');
        badge.className   = 'forge-submit-cond-badge';
        badge.title       = 'Sichtbarkeit: Bedingungen aktiv';
        badge.innerHTML   = '<i class="fa-solid fa-eye-slash"></i> bedingt';
        wrap.appendChild(badge);
    }

    var gear = document.createElement('span');
    gear.className = 'forge-submit-preview-gear';
    gear.innerHTML = '<i class="fa-solid fa-gear"></i>';
    wrap.appendChild(gear);

    bar.appendChild(wrap);
}

/* ================================================================
 * Canvas tabs / form name / save
 * ================================================================ */
function bindCanvasTabs() {
    var tabs = document.querySelectorAll('.forge-tab-btn');
    tabs.forEach(function (btn) {
        btn.addEventListener('click', function () {
            tabs.forEach(function (b) { b.classList.remove('forge-tab-active'); });
            btn.classList.add('forge-tab-active');
            var target = btn.dataset.tab;
            document.querySelectorAll('.forge-tab-panel').forEach(function (p) {
                p.classList.toggle('forge-panel-active', p.id === target);
            });
        });
    });
}

function bindFormName() {
    var inp = document.getElementById('forge-form-name');
    if (!inp) return;
    inp.value = state.formName;
    inp.addEventListener('input', function () { state.formName = this.value; });
}

function setSaveStatus(status, state, msg) {
    if (!status) return;
    clearTimeout(status._fadeTimer);
    clearTimeout(status._clearTimer);
    status.className = '';
    status.innerHTML = '';
    if (state === 'saving') {
        status.className = 'forge-ss--saving';
        status.innerHTML = '<span class="forge-spinner"></span> Wird gespeichert…';
    } else if (state === 'ok') {
        status.className = 'forge-ss--ok';
        status.innerHTML = '<i class="fa-solid fa-circle-check"></i> Gespeichert';
        status._fadeTimer = setTimeout(function () { status.classList.add('forge-ss--fade'); }, 2200);
        status._clearTimer = setTimeout(function () { status.className = ''; status.innerHTML = ''; }, 2600);
    } else if (state === 'err') {
        status.className = 'forge-ss--err';
        status.innerHTML = '<i class="fa-solid fa-triangle-exclamation"></i> ' + (msg || 'Fehler');
    }
}

function bindSave() {
    var btn    = document.getElementById('forge-save-btn');
    var status = document.getElementById('forge-save-status');
    if (!btn) return;
    btn.addEventListener('click', function () {
        btn.disabled = true;
        setSaveStatus(status, 'saving');
        var payload = {
            id: FORM_ID, title: state.formName,
            fields: state.fields, notifications: state.notifications, settings: state.settings,
        };
        var fd = new FormData();
        fd.append('action',    'forge_forms_save_form');
        fd.append('nonce',     NONCE);
        fd.append('form_data', btoa(unescape(encodeURIComponent(JSON.stringify(payload)))));
        fetch(AJAX_URL, { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                btn.disabled = false;
                if (data.success && data.data && data.data.form_id) FORM_ID = data.data.form_id;
                if (data.success) {
                    setSaveStatus(status, 'ok');
                } else {
                    var msg = (data.data && data.data.message) ? data.data.message : (typeof data.data === 'string' ? data.data : 'Unbekannter Fehler');
                    setSaveStatus(status, 'err', msg);
                }
            })
            .catch(function () { btn.disabled = false; setSaveStatus(status, 'err', 'Serverfehler'); });
    });
}

/* ================================================================
 * Form Preview
 * ================================================================ */
function bindPreview() {
    var btn = document.getElementById('forge-preview-btn');
    if (!btn) return;
    btn.addEventListener('click', function () {
        if (!FORM_ID) return;
        btn.disabled = true;
        btn.innerHTML = '<span class="forge-spinner"></span> Vorschau';
        var fd = new FormData();
        fd.append('action',   'forge_forms_preview');
        fd.append('nonce',    NONCE);
        fd.append('form_id',  FORM_ID);
        fd.append('settings', JSON.stringify(state.settings));
        fetch(AJAX_URL, { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (resp) {
                if (resp.success && resp.data.html) {
                    var blob = new Blob([resp.data.html], { type: 'text/html' });
                    var url  = URL.createObjectURL(blob);
                    window.open(url, '_blank');
                    setTimeout(function () { URL.revokeObjectURL(url); }, 30000);
                } else {
                    alert((resp.data && resp.data.message) || 'Vorschau fehlgeschlagen.');
                }
            })
            .catch(function () { alert('Netzwerkfehler.'); })
            .finally(function () {
                btn.disabled = false;
                btn.innerHTML = '<i class="fa-solid fa-eye"></i> Vorschau';
            });
    });
}

/* ================================================================
 * Shared utilities
 * ================================================================ */

/*
 * Segmented pill control — shows all options at once as connected pills.
 * The selected one is filled (active), others are outlined.
 * Replaces the old single cycling-button mkSwap.
 */
function mkSeg(values, labels, current, onChange) {
    var wrap = document.createElement('div');
    wrap.className = 'forge-seg';

    values.forEach(function (val, i) {
        var btn = document.createElement('button');
        btn.type      = 'button';
        btn.className = 'forge-seg-btn' + (val === current ? ' forge-seg-active' : '');
        btn.textContent = labels[i];
        btn.dataset.value = val;
        btn.addEventListener('click', function () {
            wrap.querySelectorAll('.forge-seg-btn').forEach(function (b) { b.classList.remove('forge-seg-active'); });
            btn.classList.add('forge-seg-active');
            onChange(val);
        });
        wrap.appendChild(btn);
    });

    return wrap;
}

/* Multi-select pill: each button toggles independently; current is an array. */
function mkSegMulti(values, labels, current, onChange) {
    var wrap = document.createElement('div');
    wrap.className = 'forge-seg';

    values.forEach(function (val, i) {
        var btn = document.createElement('button');
        btn.type        = 'button';
        btn.className   = 'forge-seg-btn' + (current.indexOf(val) !== -1 ? ' forge-seg-active' : '');
        btn.textContent = labels[i];
        btn.dataset.value = val;
        btn.addEventListener('click', function () {
            var idx2 = current.indexOf(val);
            if (idx2 === -1) { current.push(val); }
            else             { current.splice(idx2, 1); }
            btn.classList.toggle('forge-seg-active', current.indexOf(val) !== -1);
            onChange(current.slice());
        });
        wrap.appendChild(btn);
    });

    return wrap;
}

function mkSel(values, labels, selected, onChange) {
    var sel = document.createElement('select');
    sel.className = 'forge-cond-sel';
    values.forEach(function (v, i) {
        var o = document.createElement('option');
        o.value       = v;
        o.textContent = labels[i];
        if (v === selected) o.selected = true;
        sel.appendChild(o);
    });
    sel.addEventListener('change', function () { onChange(this.value); });
    return sel;
}

function mkSpan(t) { var s = document.createElement('span'); s.textContent = t; return s; }

function findPaletteItem(type) {
    for (var i = 0; i < PALETTE_GROUPS.length; i++) {
        var items = PALETTE_GROUPS[i].items;
        for (var j = 0; j < items.length; j++) {
            if (items[j].type === type) return items[j];
        }
    }
    return null;
}

function removePlaceholder() { /* no-op — replaced by drop line */ }

/* Generate sequential IDs like "text-1", "email-3" — unique across top-level and all group children */
function generateId(type) {
    if (!type) type = 'field';
    var used = {};
    for (var i = 0; i < state.fields.length; i++) {
        used[state.fields[i].id] = true;
        var children = state.fields[i].children || [];
        for (var j = 0; j < children.length; j++) {
            if (children[j].id) used[children[j].id] = true;
        }
    }
    var n = 1;
    while (used[type + '-' + n]) n++;
    return type + '-' + n;
}

/* Slugify a string for use as an option value */
function slugify(str) {
    return str.toLowerCase().replace(/\s+/g, '-').replace(/[^a-z0-9-]/g, '').replace(/-{2,}/g, '-');
}

function escHtml(str) {
    var d = document.createElement('div');
    d.appendChild(document.createTextNode(String(str || '')));
    return d.innerHTML;
}

function shallowCopy(obj) {
    var out = {};
    for (var k in obj) { if (Object.prototype.hasOwnProperty.call(obj, k)) out[k] = obj[k]; }
    return out;
}

})();
