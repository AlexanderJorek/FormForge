<?php

/**
 * Admin editor for reusable select-field option lists.
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
 */

namespace ForgeForms\Admin;

defined('ABSPATH') || exit;

use ForgeForms\Form\FormModel;
use ForgeForms\Form\FormSelectModel;
use ForgeForms\Form\FormRenderer;

/**
 * Admin editor for reusable select-field option lists.
 */
class FormSelectList
{
    /**
     * Registers admin hooks for the form-select list page.
     *
     * @return void
     */
    public static function init(): void
    {
        add_action('admin_menu', [self::class, 'menu']);
        add_action('wp_ajax_forge_fsel_save', [self::class, 'ajaxSave']);
        add_action('wp_ajax_forge_fsel_delete', [self::class, 'ajaxDelete']);
        add_filter('admin_body_class', [self::class, 'bodyClass']);
    }

    /**
     * Appends a CSS class on the form-select page.
     *
     * @param string $classes Existing admin body classes.
     *
     * @return string Modified body class string.
     */
    public static function bodyClass(string $classes): string
    {
        if (isset($_GET['page']) && $_GET['page'] === 'forge-forms-select') {
            $classes .= ' forge-list-page';
        }
        return $classes;
    }

    /**
     * Registers the Formular-Auswahl submenu page.
     *
     * @return void
     */
    public static function menu(): void
    {
        if (\ForgeForms\Plugin::userCan('edit_forms')) {
            add_submenu_page(
                'forge-forms',
                'Formular-Auswahl',
                'Formular-Auswahl',
                'read',
                'forge-forms-select',
                [self::class, 'render']
            );
        }
    }

    /* ------------------------------------------------------------------ */
    /* Admin page                                                           */
    /* ------------------------------------------------------------------ */

    /**
     * Renders the admin form-select list page.
     *
     * @return void
     */
    public static function render(): void
    {
        if (!\ForgeForms\Plugin::userCan('edit_forms')) {
            wp_die('Keine Berechtigung.');
        }

        $selects    = FormSelectModel::getAll();
        $all_forms  = FormModel::getAll();
        $save_nonce = wp_create_nonce('forge_fsel_save');
        ?>
        <canvas id="forge-particle-canvas"></canvas>

        <div class="wrap forge-list-wrap">
            <div class="forge-title-pill">Formular-Auswahl</div>
            <hr class="wp-header-end" style="display:none">

            <?php $noSelects = empty($selects) ? ' hidden' : ''; ?>
            <div class="forge-list-toolbar" id="forge-fsel-toolbar">
                <!-- Left: select-all + bulk actions -->
                <div class="forge-toolbar-left" id="forge-fsel-toolbar-left"<?php echo $noSelects; ?>>
                    <label class="forge-select-all-wrap">
                        <input type="checkbox" id="forge-fsel-select-all" title="Alle auswählen">
                    </label>
                    <div class="forge-bulk-bar" id="forge-fsel-bulk-bar" hidden>
                        <span class="forge-bulk-count" id="forge-fsel-bulk-count"></span>
                        <div class="forge-bulk-action-wrap">
                            <button class="button forge-list-btn" id="forge-fsel-bulk-action-btn">
                                <span id="forge-fsel-bulk-action-label">Aktion wählen</span> &#9660;
                            </button>
                            <div class="forge-row-dropdown" id="forge-fsel-bulk-action-dd" hidden>
                                <button class="forge-dd-item forge-dd-item--danger" data-action="delete">
                                    <i class="fa-solid fa-trash"></i> Löschen
                                </button>
                            </div>
                        </div>
                        <button class="button forge-list-btn button-primary" id="forge-fsel-bulk-apply">
                            Anwenden
                        </button>
                    </div>
                </div>
                <!-- Center: search -->
                <div class="forge-toolbar-center" id="forge-fsel-toolbar-center"<?php echo $noSelects; ?>>
                    <input type="search" id="forge-fsel-form-search"
                           placeholder="Auswahl suchen…" autocomplete="off">
                </div>
                <!-- Right: new -->
                <div class="forge-toolbar-right">
                    <button type="button" class="button button-primary forge-list-btn forge-fsel-new-btn">
                        + Neue Auswahl
                    </button>
                </div>
            </div>

            <div class="forge-list-empty" id="forge-fsel-empty"<?php echo !empty($selects) ? ' hidden' : ''; ?>>
                <h2>Noch keine Formular-Auswahl</h2>
                <p>Erstellen Sie Ihre erste Auswahl und betten Sie sie per Shortcode in jede Seite ein.</p>
                <button type="button" class="button button-primary forge-fsel-new-btn">
                    + Erste Auswahl erstellen
                </button>
            </div>

            <div class="forge-form-list" id="forge-fsel-list">
                <?php foreach ($selects as $fsel) : ?>
                    <?php self::renderRow($fsel); ?>
                <?php endforeach; ?>
                <div class="forge-no-results" id="forge-fsel-no-results" hidden></div>
            </div>
        </div>

        <!-- Editor modal -->
        <div id="forge-fsel-modal" class="forge-modal-backdrop" hidden>
            <div class="forge-modal forge-modal--settings" role="dialog" aria-modal="true">

                <div class="forge-modal-header">
                    <div class="forge-settings-titlerow">
                        <span class="forge-settings-field-icon">
                            <i class="fa-solid fa-layer-group"></i>
                        </span>
                        <h2 class="forge-modal-title" id="forge-fsel-modal-title">Auswahl bearbeiten</h2>
                    </div>
                    <button class="forge-modal-close" type="button" id="forge-fsel-cancel">&#x2715;</button>
                </div>

                <div class="forge-stab-bar">
                    <button class="forge-stab forge-stab-active">Allgemein</button>
                </div>

                <div class="forge-modal-body forge-settings-body">
                    <div class="forge-stab-panel forge-stab-active">

                        <div class="forge-sp-row">
                            <label class="forge-sp-label">Name dieser Auswahl</label>
                            <input type="text" id="forge-fsel-title-input" class="forge-sp-input"
                                   placeholder="z.B. Kontakt-Auswahl">
                        </div>

                        <div class="forge-sp-row">
                            <label class="forge-sp-label">Formulare in dieser Auswahl</label>
                            <div class="forge-fsel-cols-header" id="forge-fsel-col-header" hidden>
                                <span></span>
                                <span>Formular</span>
                                <span>Bezeichnung</span>
                                <span>Beschreibung</span>
                                <span><i class="fa-regular fa-star"></i></span>
                                <span></span>
                            </div>
                            <div id="forge-fsel-items"
                                 style="display:flex;flex-direction:column;gap:4px;margin-bottom:8px;">
                                <!-- items injected by JS -->
                            </div>

                            <!-- Add button + dropdown -->
                            <div style="position:relative;">
                                <button type="button" class="forge-sp-add-option" id="forge-fsel-add-btn">
                                    <i class="fa-solid fa-plus"></i> Formular hinzufügen
                                </button>
                                <div id="forge-fsel-search-wrap" hidden
                                     style="position:absolute;left:0;right:0;z-index:1000;
                                            background:#fff;border:1px solid #dcdcde;
                                            border-radius:8px;box-shadow:0 6px 20px rgba(0,0,0,.15);
                                            overflow:hidden;">
                                    <div class="forge-fsel-search-row">
                                        <i class="fa-solid fa-magnifying-glass forge-fsel-search-icon"></i>
                                        <input type="text" id="forge-fsel-search"
                                               class="forge-fsel-search-input"
                                               placeholder="Formular suchen…" autocomplete="off">
                                    </div>
                                    <div id="forge-fsel-search-results"
                                         style="max-height:200px;overflow-y:auto;
                                                border-top:1px solid #f0f0f1;"></div>
                                </div>
                            </div>
                        </div>

                    </div>
                </div>

                <div class="forge-settings-footer">
                    <button class="forge-btn-primary" id="forge-fsel-save">Speichern</button>
                </div>

            </div>
        </div>

        <!-- Delete confirmation modal -->
        <div id="forge-fsel-del-modal" class="forge-modal-backdrop" hidden>
            <div class="forge-modal">
                <p class="forge-modal-msg">Formular-Auswahl wirklich löschen?</p>
                <div class="forge-modal-actions">
                    <button class="button forge-list-btn" id="forge-fsel-del-cancel">Abbrechen</button>
                    <button class="button forge-list-btn forge-btn-danger" id="forge-fsel-del-confirm">Löschen</button>
                </div>
            </div>
        </div>

        <script>
        window._forgeFselSaveNonce = <?php echo wp_json_encode($save_nonce); ?>;
        window._forgeAllForms     = <?php echo wp_json_encode(array_map(fn($f) => ['id' => $f->id, 'title' => $f->title], $all_forms)); ?>;
        window._forgeFselData     = <?php echo wp_json_encode(array_map(fn($s) => ['id' => $s->id, 'title' => $s->title, 'items' => $s->items], $selects)); ?>;
        </script>
        <script>
        (function () {
            var canvas = document.getElementById('forge-particle-canvas');
            if (!canvas) { return; }
            var ctx = canvas.getContext('2d');
            var mouse = { x: -9999, y: -9999 };
            var DOTS = 80, LINK = 150, SPEED = 0.4, COLOR = '99, 132, 180';
            var particles = [];
            function rand(a, b) { return a + Math.random() * (b - a); }
            function resize() { canvas.width = window.innerWidth; canvas.height = window.innerHeight; }
            function init() {
                particles = [];
                for (var i = 0; i < DOTS; i++) {
                    particles.push({ x: rand(0, canvas.width), y: rand(0, canvas.height),
                        vx: rand(-SPEED, SPEED), vy: rand(-SPEED, SPEED), r: rand(2, 3.5) });
                }
            }
            function draw() {
                ctx.clearRect(0, 0, canvas.width, canvas.height);
                particles.forEach(function (p) {
                    p.x += p.vx; p.y += p.vy;
                    if (p.x < 0 || p.x > canvas.width)  { p.vx *= -1; }
                    if (p.y < 0 || p.y > canvas.height) { p.vy *= -1; }
                });
                for (var i = 0; i < particles.length; i++) {
                    for (var j = i + 1; j < particles.length; j++) {
                        var dx = particles[i].x - particles[j].x, dy = particles[i].y - particles[j].y;
                        var d = Math.sqrt(dx * dx + dy * dy);
                        if (d < LINK) {
                            ctx.beginPath();
                            ctx.moveTo(particles[i].x, particles[i].y);
                            ctx.lineTo(particles[j].x, particles[j].y);
                            ctx.strokeStyle = 'rgba(' + COLOR + ',' + (1 - d / LINK) * 0.3 + ')';
                            ctx.lineWidth = 1; ctx.stroke();
                        }
                    }
                    var mdx = particles[i].x - mouse.x, mdy = particles[i].y - mouse.y;
                    var md = Math.sqrt(mdx * mdx + mdy * mdy);
                    if (md < LINK) {
                        ctx.beginPath();
                        ctx.moveTo(particles[i].x, particles[i].y);
                        ctx.lineTo(mouse.x, mouse.y);
                        ctx.strokeStyle = 'rgba(' + COLOR + ',' + (1 - md / LINK) * 0.55 + ')';
                        ctx.lineWidth = 1; ctx.stroke();
                    }
                }
                particles.forEach(function (p) {
                    ctx.beginPath(); ctx.arc(p.x, p.y, p.r, 0, Math.PI * 2);
                    ctx.fillStyle = 'rgba(' + COLOR + ', 0.5)'; ctx.fill();
                });
                requestAnimationFrame(draw);
            }
            window.addEventListener('mousemove', function (e) { mouse.x = e.clientX; mouse.y = e.clientY; });
            window.addEventListener('resize', function () { resize(); init(); });
            resize(); init(); draw();
        }());
        </script>
        <?php self::renderScript(); ?>
        <?php
    }

    /**
     * Renders a single form-select list row.
     *
     * @param FormSelectModel $fsel The form-select model instance to render.
     *
     * @return void
     */
    private static function renderRow(FormSelectModel $fsel): void
    {
        $shortcode = '[forge_form_select id="' . $fsel->id . '"]';
        $count     = count($fsel->items);
        $del_nonce = wp_create_nonce('forge_fsel_delete_' . $fsel->id);
        ?>
        <div class="forge-form-row"
             data-id="<?php echo esc_attr($fsel->id); ?>"
             data-title="<?php echo esc_attr(strtolower($fsel->title)); ?>">
            <label class="forge-row-check-wrap">
                <input type="checkbox" class="forge-row-check"
                       value="<?php echo esc_attr($fsel->id); ?>"
                       data-del-nonce="<?php echo esc_attr($del_nonce); ?>">
            </label>
            <div class="forge-form-row-icon">
                <i class="fa-solid fa-layer-group"></i>
            </div>
            <div class="forge-form-row-main">
                <span class="forge-form-row-title forge-fsel-edit-link"
                      style="cursor:pointer;"
                      data-id="<?php echo esc_attr($fsel->id); ?>">
                    <?php echo esc_html($fsel->title); ?>
                </span>
                <div class="forge-form-row-meta">
                    <span><?php echo $count; ?> Formular<?php echo $count !== 1 ? 'e' : ''; ?></span>
                    <span class="forge-meta-sep">&middot;</span>
                    <code class="forge-form-row-code"><?php echo esc_html($shortcode); ?></code>
                </div>
            </div>
            <div class="forge-form-row-actions">
                <button type="button" class="button forge-btn-edit forge-fsel-edit-btn"
                        data-id="<?php echo esc_attr($fsel->id); ?>">
                    Bearbeiten
                </button>
                <div class="forge-row-menu-wrap">
                    <button class="button forge-row-menu-btn" title="Weitere Aktionen">&#8942;</button>
                    <div class="forge-row-dropdown" hidden>
                        <button class="forge-dd-item forge-copy-shortcode"
                                data-code="<?php echo esc_attr($shortcode); ?>">
                            <i class="fa-solid fa-clipboard"></i> Shortcode kopieren
                        </button>
                        <div class="forge-dd-sep"></div>
                        <button class="forge-dd-item forge-dd-item--danger forge-fsel-del-btn"
                                data-id="<?php echo esc_attr($fsel->id); ?>"
                                data-nonce="<?php echo esc_attr($del_nonce); ?>">
                            <i class="fa-solid fa-trash"></i> Löschen
                        </button>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /* ------------------------------------------------------------------ */
    /* AJAX handlers                                                        */
    /* ------------------------------------------------------------------ */

    /**
     * AJAX handler to save or create a form-select entry.
     *
     * @return void
     */
    public static function ajaxSave(): void
    {
        if (!\ForgeForms\Plugin::userCan('edit_forms')) {
            wp_send_json_error(['message' => 'Forbidden'], 403);
        }
        check_ajax_referer('forge_fsel_save', 'nonce');

        $id        = (int) ($_POST['id'] ?? 0);
        $title     = sanitize_text_field(wp_unslash($_POST['title'] ?? ''));
        $items_raw = json_decode(wp_unslash($_POST['items'] ?? '[]'), true);
        if (!is_array($items_raw)) {
            wp_send_json_error(['message' => 'Ungültige Daten.']);
        }

        $new_id = FormSelectModel::save(['title' => $title, 'items' => $items_raw], $id);
        $fsel   = FormSelectModel::get($new_id);

        ob_start();
        self::renderRow($fsel);
        $row_html = ob_get_clean();

        $response = [
            'id'     => $new_id,
            'html'   => $row_html,
            'is_new' => $id === 0,
            'title'  => $fsel->title,
            'items'  => $fsel->items,
        ];
        wp_send_json_success($response);
    }

    /**
     * AJAX handler to delete a form-select entry.
     *
     * @return void
     */
    public static function ajaxDelete(): void
    {
        if (!\ForgeForms\Plugin::userCan('edit_forms')) {
            wp_send_json_error(['message' => 'Forbidden'], 403);
        }
        $id = (int) ($_POST['id'] ?? 0);
        check_ajax_referer('forge_fsel_delete_' . $id, 'nonce');

        FormSelectModel::delete($id);
        wp_send_json_success();
    }

    /* ------------------------------------------------------------------ */
    /* Frontend shortcode                                                   */
    /* ------------------------------------------------------------------ */

    /**
     * Renders the forge_form_select shortcode output.
     *
     * @param array $atts Shortcode attributes.
     *
     * @return string Rendered HTML output.
     */
    public static function shortcode(array $atts): string
    {
        $atts = shortcode_atts(['id' => 0], $atts);
        $id   = (int) $atts['id'];
        if ($id <= 0) {
            return '';
        }

        $fsel = FormSelectModel::get($id);
        if (!$fsel || empty($fsel->items)) {
            return '';
        }

        $fav_idx = 0;
        foreach ($fsel->items as $i => $item) {
            if ($item['favorite']) {
                $fav_idx = $i;
                break;
            }
        }

        ob_start();
        $uid = 'fsel-' . $id;
        ?>
        <div class="fsel-wrap" id="<?php echo esc_attr($uid); ?>"
             data-fav="<?php echo esc_attr($fav_idx); ?>">

            <div class="fsel-selector">
                <div class="fsel-trigger" role="button" tabindex="0"
                     aria-haspopup="listbox" aria-expanded="false">
                    <div class="fsel-trigger-inner">
                        <strong class="fsel-trigger-label"></strong>
                        <span class="fsel-trigger-desc"></span>
                    </div>
                    <svg class="fsel-chevron" viewBox="0 0 20 20" fill="none"
                         xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                        <path d="M5 7.5 10 12.5 15 7.5" stroke="currentColor"
                              stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </div>

                <div class="fsel-options" role="listbox" hidden>
                <?php foreach ($fsel->items as $i => $item) :
                    $form  = FormModel::get($item['form_id']);
                    if (!$form) {
                        continue;
                    }
                    $label = $item['label'] !== '' ? $item['label'] : $form->title;
                    $desc  = $item['description'];
                    ?>
                    <div class="fsel-option<?php echo $i === $fav_idx ? ' fsel-option--active' : ''; ?>"
                         role="option"
                         aria-selected="<?php echo $i === $fav_idx ? 'true' : 'false'; ?>"
                         data-idx="<?php echo esc_attr($i); ?>"
                         data-label="<?php echo esc_attr($label); ?>"
                         data-desc="<?php echo esc_attr($desc); ?>"
                         tabindex="-1">
                        <strong class="fsel-opt-label"><?php echo esc_html($label); ?></strong>
                        <?php if ($desc !== '') : ?>
                            <span class="fsel-opt-desc"><?php echo esc_html($desc); ?></span>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
            </div><!-- /.fsel-selector -->

            <div class="fsel-forms">
                <?php foreach ($fsel->items as $i => $item) :
                    $form = FormModel::get($item['form_id']);
                    if (!$form) {
                        continue;
                    }
                    ?>
                    <div class="fsel-form<?php echo $i !== $fav_idx ? ' fsel-form--hidden' : ''; ?>"
                         data-idx="<?php echo esc_attr($i); ?>">
                        <?php echo FormRenderer::render($item['form_id']); ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    /* ------------------------------------------------------------------ */
    /* Inline JS                                                            */
    /* ------------------------------------------------------------------ */

    /**
     * Outputs the inline JS that drives the form-select admin UI.
     *
     * @return void
     */
    private static function renderScript(): void
    {
        ?>
        <script>
        (function () {
            var ajaxurl   = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
            var saveNonce = window._forgeFselSaveNonce;
            var allForms  = window._forgeAllForms  || [];
            var fselData  = window._forgeFselData  || [];

            var list         = document.getElementById('forge-fsel-list');
            var emptyEl      = document.getElementById('forge-fsel-empty');
            var toolbarLeft  = document.getElementById('forge-fsel-toolbar-left');
            var toolbarCenter = document.getElementById('forge-fsel-toolbar-center');

            /* ── modal elements ── */
            var modal      = document.getElementById('forge-fsel-modal');
            var modalTitle = document.getElementById('forge-fsel-modal-title');
            var titleInput = document.getElementById('forge-fsel-title-input');
            var searchInput  = document.getElementById('forge-fsel-search');
            var searchResults = document.getElementById('forge-fsel-search-results');
            var itemsEl    = document.getElementById('forge-fsel-items');
            var saveBtn    = document.getElementById('forge-fsel-save');
            var cancelBtn  = document.getElementById('forge-fsel-cancel');

            /* ── delete modal ── */
            var delModal   = document.getElementById('forge-fsel-del-modal');
            var delConfirm = document.getElementById('forge-fsel-del-confirm');
            var delCancel  = document.getElementById('forge-fsel-del-cancel');

            var currentId  = 0;
            var favName    = 'fsel-fav-modal';

            /* ── helpers ── */
            function post(action, body, cb) {
                var fd = new FormData();
                fd.append('action', action);
                for (var k in body) { fd.append(k, body[k]); }
                fetch(ajaxurl, { method: 'POST', body: fd })
                    .then(function (r) { return r.json(); })
                    .then(cb)
                    .catch(function () { cb({ success: false }); });
            }

            function getChecked() {
                return list.querySelectorAll('.forge-row-check:checked');
            }

            function syncBulkBar() {
                var checked = getChecked();
                var bulkBar = document.getElementById('forge-fsel-bulk-bar');
                var countEl = document.getElementById('forge-fsel-bulk-count');
                if (bulkBar) { bulkBar.hidden = checked.length === 0; }
                if (countEl) { countEl.textContent = checked.length + ' ausgewählt'; }
            }

            function syncEmpty() {
                var rows = list.querySelectorAll('.forge-form-row');
                var isEmpty = rows.length === 0;
                emptyEl.hidden = !isEmpty;
                if (toolbarLeft)   { toolbarLeft.hidden   = isEmpty; }
                if (toolbarCenter) { toolbarCenter.hidden = isEmpty; }
                if (isEmpty) { syncBulkBar(); }
            }

            function escHtml(str) {
                return String(str)
                    .replace(/&/g, '&amp;').replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
            }

            var colHeader      = document.getElementById('forge-fsel-col-header');
            var addBtn         = document.getElementById('forge-fsel-add-btn');
            var searchWrap     = document.getElementById('forge-fsel-search-wrap');

            function syncColHeader() {
                if (colHeader) { colHeader.hidden = itemsEl.children.length === 0; }
            }

            function openSearch() {
                var rect = addBtn.getBoundingClientRect();
                if (window.innerHeight - rect.bottom > 260) {
                    searchWrap.style.top = 'calc(100% + 4px)';
                    searchWrap.style.bottom = 'auto';
                } else {
                    searchWrap.style.bottom = 'calc(100% + 4px)';
                    searchWrap.style.top = 'auto';
                }
                searchWrap.hidden = false;
                searchInput.value = '';
                doSearch('');
                searchInput.focus();
            }
            function closeSearch() {
                searchWrap.hidden = true;
            }

            addBtn.addEventListener('click', function (e) {
                e.stopPropagation();
                searchWrap.hidden ? openSearch() : closeSearch();
            });
            document.addEventListener('click', function (e) {
                if (!searchWrap.hidden && !searchWrap.contains(e.target) && e.target !== addBtn) {
                    closeSearch();
                }
            });

            /* ── open editor modal ── */
            function openModal(id) {
                currentId = id;
                itemsEl.innerHTML = '';
                closeSearch();

                if (id === 0) {
                    modalTitle.textContent = 'Neue Auswahl erstellen';
                    titleInput.value = 'Neue Auswahl';
                    saveBtn.textContent = 'Erstellen';
                } else {
                    modalTitle.textContent = 'Auswahl bearbeiten';
                    saveBtn.textContent = 'Speichern';
                    var rec = fselData.find(function (r) { return r.id === id; });
                    if (rec) {
                        titleInput.value = rec.title;
                        (rec.items || []).forEach(function (item) {
                            var form = allForms.find(function (f) { return f.id === item.form_id; });
                            addItem(item.form_id, form ? form.title : '#' + item.form_id,
                                    item.label, item.description, item.favorite);
                        });
                    }
                }

                syncColHeader();
                modal.hidden = false;
                titleInput.focus();
            }

            function closeModal() {
                modal.hidden = true;
                closeSearch();
            }

            /* ── items ── */
            function addItem(formId, formTitle, label, desc, fav) {
                if (itemsEl.querySelector('[data-form-id="' + formId + '"]')) { return; }

                var div = document.createElement('div');
                div.className = 'forge-fsel-item';
                div.dataset.formId = formId;

                var isChecked = fav ? 'checked' : '';
                div.innerHTML =
                    '<span class="forge-fsel-item-drag"><i class="fa-solid fa-grip-vertical"></i></span>' +
                    '<span class="forge-fsel-item-badge" title="' + escHtml(formTitle) + '">' +
                        escHtml(formTitle) +
                    '</span>' +
                    '<input type="text" class="forge-fsel-item-label" ' +
                           'value="' + escHtml(label || '') + '" ' +
                           'placeholder="Bezeichnung">' +
                    '<input type="text" class="forge-fsel-item-desc" ' +
                           'value="' + escHtml(desc || '') + '" ' +
                           'placeholder="Beschreibung">' +
                    '<label class="forge-fsel-item-fav" title="Als Standard vorauswählen">' +
                        '<input type="radio" name="' + favName + '" ' +
                               'class="forge-fsel-item-fav-radio" ' + isChecked + '>' +
                        '<i class="' + (isChecked ? 'fa-solid' : 'fa-regular') + ' fa-star"></i>' +
                    '</label>' +
                    '<button type="button" class="forge-fsel-item-remove" title="Entfernen">' +
                        '<i class="fa-solid fa-trash"></i>' +
                    '</button>';

                div.querySelector('.forge-fsel-item-remove').addEventListener('click', function () {
                    div.remove();
                    syncColHeader();
                });
                div.querySelector('.forge-fsel-item-fav-radio').addEventListener('change', function () {
                    itemsEl.querySelectorAll('.forge-fsel-item-fav-radio').forEach(function (r) {
                        var icon = r.nextElementSibling;
                        if (!icon) { return; }
                        icon.className = r.checked ? 'fa-solid fa-star' : 'fa-regular fa-star';
                    });
                });
                itemsEl.appendChild(div);
                syncColHeader();
            }

            /* ── form search (client-side) ── */
            var srchTimer;
            searchInput.addEventListener('input', function () {
                clearTimeout(srchTimer);
                srchTimer = setTimeout(function () { doSearch(searchInput.value.trim()); }, 150);
            });

            function getAddedIds() {
                var ids = {};
                itemsEl.querySelectorAll('.forge-fsel-item').forEach(function (el) {
                    ids[el.dataset.formId] = true;
                });
                return ids;
            }

            function doSearch(q) {
                var added = getAddedIds();
                var ql = q.toLowerCase();
                var hits = allForms.filter(function (f) {
                    if (added[f.id]) { return false; }
                    return q === '' ||
                           f.title.toLowerCase().indexOf(ql) !== -1 ||
                           String(f.id).indexOf(q) !== -1;
                }).slice(0, 15);
                showResults(hits);
            }

            function showResults(forms) {
                searchResults.innerHTML = '';
                if (forms.length === 0) {
                    var empty = document.createElement('div');
                    empty.className = 'forge-fsel-sr-empty';
                    empty.textContent = 'Keine Formulare gefunden.';
                    searchResults.appendChild(empty);
                } else {
                    forms.forEach(function (f) {
                        var btn = document.createElement('button');
                        btn.type = 'button';
                        btn.className = 'forge-fsel-sr-item';
                        btn.textContent = f.title;
                        btn.addEventListener('click', function () {
                            addItem(f.id, f.title, '', '', false);
                            closeSearch();
                        });
                        searchResults.appendChild(btn);
                    });
                }
                searchResults.hidden = false;
            }

            /* ── save ── */
            saveBtn.addEventListener('click', function () {
                saveBtn.disabled = true;
                var title = titleInput.value.trim();
                var items = [];
                itemsEl.querySelectorAll('.forge-fsel-item').forEach(function (el) {
                    items.push({
                        form_id:     parseInt(el.dataset.formId, 10),
                        label:       el.querySelector('.forge-fsel-item-label').value.trim(),
                        description: el.querySelector('.forge-fsel-item-desc').value.trim(),
                        favorite:    el.querySelector('.forge-fsel-item-fav-radio').checked,
                    });
                });

                post('forge_fsel_save', {
                    nonce: saveNonce,
                    id:    currentId,
                    title: title,
                    items: JSON.stringify(items),
                }, function (data) {
                    saveBtn.disabled = false;
                    if (!data.success) { return; }

                    /* update local data cache */
                    var idx = fselData.findIndex(function (r) { return r.id === data.data.id; });
                    var rec = { id: data.data.id, title: data.data.title, items: data.data.items };
                    if (idx >= 0) { fselData[idx] = rec; } else { fselData.push(rec); }

                    /* inject / replace row */
                    var tmp = document.createElement('div');
                    tmp.innerHTML = data.data.html;
                    var newRow = tmp.firstElementChild;
                    wireRow(newRow);

                    if (data.data.is_new) {
                        list.insertBefore(newRow, list.firstChild);
                    } else {
                        var old = list.querySelector('.forge-form-row[data-id="' + currentId + '"]');
                        if (old) { old.replaceWith(newRow); }
                    }

                    syncEmpty();
                    closeModal();
                });
            });

            cancelBtn.addEventListener('click', closeModal);
            modal.addEventListener('click', function (e) {
                if (e.target === modal) { closeModal(); }
            });
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape' && !modal.hidden) { closeModal(); }
            });

            /* ── delete modal ── */
            var pendingDelId    = 0;
            var pendingDelNonce = '';

            function openDelModal(id, nonce) {
                pendingDelId    = id;
                pendingDelNonce = nonce;
                delModal.hidden = false;
            }

            delCancel.addEventListener('click', function () { delModal.hidden = true; });
            delModal.addEventListener('click', function (e) {
                if (e.target === delModal) { delModal.hidden = true; }
            });
            delConfirm.addEventListener('click', function () {
                delConfirm.disabled = true;
                post('forge_fsel_delete', { nonce: pendingDelNonce, id: pendingDelId },
                function (data) {
                    delConfirm.disabled = false;
                    if (!data.success) { return; }
                    var row = list.querySelector('.forge-form-row[data-id="' + pendingDelId + '"]');
                    if (row) { row.remove(); }
                    fselData = fselData.filter(function (r) { return r.id !== pendingDelId; });
                    delModal.hidden = true;
                    syncEmpty();
                });
            });

            /* ── row dropdown (hoisted to body, same pattern as FormList) ── */
            var openDd = null;
            function closeAllDropdowns() {
                if (openDd) { openDd.hidden = true; openDd = null; }
            }

            function wireDropdown(row) {
                var btn = row.querySelector('.forge-row-menu-btn');
                if (!btn) { return; }
                var dd = btn.nextElementSibling;
                document.body.appendChild(dd);

                btn.addEventListener('click', function (e) {
                    e.stopPropagation();
                    var alreadyOpen = (openDd === dd && !dd.hidden);
                    closeAllDropdowns();
                    if (alreadyOpen) { return; }

                    var rect = btn.getBoundingClientRect();
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

            document.addEventListener('click', closeAllDropdowns);

            /* ── wire a row's actions ── */
            function wireRow(row) {
                var cb = row.querySelector('.forge-row-check');
                if (cb) { cb.addEventListener('change', function () { syncBulkBar(); }); }

                /* edit button + clickable title */
                row.querySelectorAll('.forge-fsel-edit-btn, .forge-fsel-edit-link')
                   .forEach(function (el) {
                    el.addEventListener('click', function () {
                        openModal(parseInt(row.dataset.id, 10));
                        closeAllDropdowns();
                    });
                });

                /* delete */
                var delBtn = row.querySelector('.forge-fsel-del-btn');
                if (delBtn) {
                    delBtn.addEventListener('click', function () {
                        closeAllDropdowns();
                        openDelModal(
                            parseInt(delBtn.dataset.id, 10),
                            delBtn.dataset.nonce
                        );
                    });
                }

                /* copy shortcode */
                var copyBtn = row.querySelector('.forge-copy-shortcode');
                if (copyBtn) {
                    copyBtn.addEventListener('click', function () {
                        closeAllDropdowns();
                        navigator.clipboard && navigator.clipboard.writeText(copyBtn.dataset.code);
                    });
                }

                wireDropdown(row);
            }

            /* ── "New" buttons ── */
            document.querySelectorAll('.forge-fsel-new-btn').forEach(function (btn) {
                btn.addEventListener('click', function () { openModal(0); });
            });

            /* ── drag-to-reorder for fsel items ── */
            (function () {
                var ghost = null, ph = null, startY = 0, offY = 0;

                function getItems() {
                    return Array.from(itemsEl.querySelectorAll('.forge-fsel-item'));
                }

                itemsEl.addEventListener('pointerdown', function (e) {
                    var handle = e.target.closest('.forge-fsel-item-drag');
                    if (!handle) { return; }
                    var item = handle.closest('.forge-fsel-item');
                    if (!item) { return; }

                    e.preventDefault();
                    startY = e.clientY;
                    var rect = item.getBoundingClientRect();
                    offY = e.clientY - rect.top;

                    /* placeholder keeps the space */
                    ph = document.createElement('div');
                    ph.style.cssText = 'height:' + rect.height + 'px;margin-bottom:4px;' +
                        'border:2px dashed #c3c4c7;border-radius:6px;box-sizing:border-box;';
                    item.after(ph);

                    /* ghost floats under cursor */
                    ghost = item;
                    ghost.style.cssText = 'position:fixed;left:' + rect.left + 'px;' +
                        'top:' + (e.clientY - offY) + 'px;width:' + rect.width + 'px;' +
                        'z-index:200000;opacity:.9;pointer-events:none;box-shadow:0 4px 16px rgba(0,0,0,.18);';

                    document.body.appendChild(ghost);

                    function onMove(ev) {
                        ghost.style.top = (ev.clientY - offY) + 'px';

                        /* find sibling to insert before */
                        var siblings = getItems();
                        var insertBefore = null;
                        for (var i = 0; i < siblings.length; i++) {
                            var sr = siblings[i].getBoundingClientRect();
                            if (ev.clientY < sr.top + sr.height / 2) {
                                insertBefore = siblings[i];
                                break;
                            }
                        }
                        if (insertBefore) {
                            itemsEl.insertBefore(ph, insertBefore);
                        } else {
                            itemsEl.appendChild(ph);
                        }
                    }

                    function onUp() {
                        document.removeEventListener('pointermove', onMove);
                        document.removeEventListener('pointerup', onUp);

                        /* restore item to placeholder position */
                        ghost.style.cssText = '';
                        ph.replaceWith(ghost);
                        ph = null;
                        ghost = null;
                    }

                    document.addEventListener('pointermove', onMove);
                    document.addEventListener('pointerup', onUp);
                });
            }());

            /* ── live search ── */
            var searchInput = document.getElementById('forge-fsel-form-search');
            if (searchInput) {
                searchInput.addEventListener('input', function () {
                    var q = this.value.toLowerCase().trim();
                    list.querySelectorAll('.forge-form-row').forEach(function (row) {
                        row.hidden = q !== '' && row.dataset.title.indexOf(q) === -1;
                    });
                    var noRes = document.getElementById('forge-fsel-no-results');
                    if (noRes) {
                        noRes.hidden = list.querySelectorAll('.forge-form-row:not([hidden])').length > 0;
                    }
                });
            }

            /* ── select-all ── */
            var selectAll = document.getElementById('forge-fsel-select-all');
            if (selectAll) {
                selectAll.addEventListener('change', function () {
                    list.querySelectorAll('.forge-row-check').forEach(function (cb) {
                        cb.checked = selectAll.checked;
                    });
                    syncBulkBar();
                });
            }
            list.addEventListener('change', function (e) {
                if (e.target.classList.contains('forge-row-check')) {
                    if (selectAll && !e.target.checked) { selectAll.checked = false; }
                    syncBulkBar();
                }
            });

            /* ── bulk action dropdown ── */
            var bulkActionBtn = document.getElementById('forge-fsel-bulk-action-btn');
            var bulkActionDd  = document.getElementById('forge-fsel-bulk-action-dd');
            var bulkApply     = document.getElementById('forge-fsel-bulk-apply');
            var bulkAction    = 'delete';

            if (bulkActionBtn && bulkActionDd) {
                bulkActionBtn.addEventListener('click', function (e) {
                    e.stopPropagation();
                    bulkActionDd.hidden = !bulkActionDd.hidden;
                });
                document.addEventListener('click', function () { bulkActionDd.hidden = true; });
                bulkActionDd.querySelectorAll('.forge-dd-item').forEach(function (item) {
                    item.addEventListener('click', function () {
                        bulkAction = item.dataset.action;
                        bulkActionDd.hidden = true;
                    });
                });
            }

            if (bulkApply) {
                bulkApply.addEventListener('click', function () {
                    if (bulkAction !== 'delete') { return; }
                    var checked = Array.from(getChecked());
                    if (checked.length === 0) { return; }
                    var remaining = checked.length;
                    checked.forEach(function (cb) {
                        post('forge_fsel_delete', { nonce: cb.dataset.delNonce, id: cb.value },
                        function (data) {
                            if (data.success) {
                                var row = list.querySelector('.forge-form-row[data-id="' + cb.value + '"]');
                                if (row) { row.remove(); }
                                fselData = fselData.filter(function (r) {
                                    return r.id !== parseInt(cb.value, 10);
                                });
                            }
                            remaining--;
                            if (remaining === 0) { syncEmpty(); }
                        });
                    });
                });
            }

            /* ── wire existing rows ── */
            list.querySelectorAll('.forge-form-row').forEach(wireRow);

            syncEmpty();
        }());
        </script>
        <?php
    }
}
