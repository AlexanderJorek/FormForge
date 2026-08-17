/*!
 * FormForge — Frontend interactions
 * @copyright 2026 Alexander Jorek
 * @license   GPL-3.0-or-later
 */
(function () {
    'use strict';

    /* ── Helpers ──────────────────────────────────────────────────────────── */

    function on(root, sel, evt, fn) {
        root.querySelectorAll(sel).forEach(function (el) {
            el.addEventListener(evt, fn);
        });
    }

    /* ── Field-type init functions ───────────────────────────────────────── */
    /* Field-specific init logic lives in each field's PHP class via
       getClientInit(); Assets::enqueueFront() collects them into
       window.ForgeFieldInits keyed by field type, so front.js needs no
       knowledge of specific field types. */

    /* ── Validator registry ───────────────────────────────────────────────── */
    /* Populated by Assets::enqueueFront() from each field's getClientValidation().
       Each entry: function(fieldEl) → error string | null.
       These client-side rules mirror the authoritative checks in each field's
       PHP validate() (includes/Fields/*.php), which runs again server-side.
       If a validate() rule changes, update the matching client rule too, or
       the two will silently drift. */
    var VALIDATORS = window.ForgeValidators || {};

    /* ── Client-side validation ──────────────────────────────────────────── */

    function validatePage(scope) {
        var i18n        = (window.ForgeForms && window.ForgeForms.i18n) || {};
        var hasRequired = false;
        var hasInvalid  = false;
        var firstError  = null;

        scope.querySelectorAll('.forge-field-error').forEach(function (e) {
            e.textContent = '';
        });

        function markError(fieldEl, msg) {
            if (!fieldEl) return;
            var errEl = fieldEl.querySelector('.forge-field-error');
            if (errEl && !errEl.textContent) errEl.textContent = msg;
            if (!firstError) firstError = fieldEl;
        }

        function fieldIsEmpty(fieldEl) {
            var type    = (fieldEl.className.match(/forge-field--(\S+)/) || [])[1] || '';
            var checker = (window.ForgeEmptyChecks || {})[type];
            if (checker) return checker(fieldEl);
            /* Generic fallback */
            var inp = fieldEl.querySelector('input:not([type="hidden"]):not([type="submit"]), textarea, select');
            if (!inp) return true;
            if (inp.type === 'radio') return !fieldEl.querySelector('input[type="radio"]:checked');
            return !inp.value.trim();
        }

        function isConditionallyHidden(el) {
            while (el) {
                if (el.dataset && 'conditions' in el.dataset && el.style.display === 'none') return true;
                el = el.parentElement;
            }
            return false;
        }

        var skipTypes      = window.ForgeSkipValidation || [];
        var ignoreRequired = !!window.ForgeIgnoreRequired;
        scope.querySelectorAll('.forge-field').forEach(function (fieldEl) {
            var type = (fieldEl.className.match(/forge-field--(\S+)/) || [])[1] || '';
            if (skipTypes.indexOf(type) !== -1) return;
            if (isConditionallyHidden(fieldEl)) return;

            var isRequired = fieldEl.classList.contains('forge-required-field')
                || fieldEl.dataset.required === 'true';
            var empty = fieldIsEmpty(fieldEl);

            /* 1 — Required check */
            if (isRequired && empty && !ignoreRequired) {
                hasRequired = true;
                markError(fieldEl, i18n.field_required || 'This field is required.');
                return; /* skip format check on empty required field */
            }

            /* 2 — Per-sub-input required check (composite fields like address/name expanded) */
            if (!ignoreRequired) {
                fieldEl.querySelectorAll('input[required], select[required], textarea[required]').forEach(function (inp) {
                    if (inp.type === 'hidden' || inp.value.trim()) return;
                    hasRequired = true;
                    var container = inp.parentNode;
                    var errEl = (container && container.querySelector('.forge-field-error'))
                        || fieldEl.querySelector('.forge-field-error');
                    if (errEl && !errEl.textContent) {
                        errEl.textContent = i18n.field_required || 'This field is required.';
                    }
                    if (!firstError) firstError = fieldEl;
                });
            }

            /* 3 — Format/validity check (only when field has content) */
            if (!empty) {
                var rules = [];
                try { rules = JSON.parse(fieldEl.dataset.validate || '[]'); } catch (_) {}
                rules.forEach(function (rule) {
                    if (!VALIDATORS[rule]) return;
                    var err = VALIDATORS[rule](fieldEl);
                    if (err) {
                        hasInvalid = true;
                        markError(fieldEl, err);
                    }
                });
            }
        });

        return {
            valid:       !hasRequired && !hasInvalid,
            hasRequired: hasRequired,
            hasInvalid:  hasInvalid,
            firstError:  firstError,
        };
    }

    function scrollToField(fieldEl) {
        if (!fieldEl) return;
        var top = fieldEl.getBoundingClientRect().top + window.pageYOffset - 80;
        window.scrollTo(0, Math.max(0, top));
    }

    /* ── Multi-page navigation ────────────────────────────────────────────── */

    function initPageBreaks(root) {
        var forms = root.querySelectorAll('.forge-form');
        forms.forEach(function (form) {
            /* Guards against front.js's init() running more than once against the
             * same form (e.g. a page builder or caching setup that includes the
             * script twice) — without this, a second pass rebuilds a second click
             * listener + step bar on top of the first one instead of a no-op. */
            if (form.dataset.forgePagesInit) return;
            form.dataset.forgePagesInit = '1';

            var pages = Array.from(form.querySelectorAll('.forge-form-page'));
            if (!pages.length) return;

            /* A '.forge-page-header' field may end up rendered inside one specific
             * page div depending on where it's placed relative to page breaks.
             * Relocate its wrapper row to sit before the pages so it can stay
             * visible from its own page onward regardless of which later page is
             * active. (The field's own init reads its original page position
             * before this runs — see PageHeaderField::getClientInit().) */
            var headerEl  = form.querySelector('.forge-page-header');
            var headerRow = headerEl && headerEl.closest('.forge-row');
            if (headerRow && pages.indexOf(headerRow.parentNode) !== -1) {
                pages[0].parentNode.insertBefore(headerRow, pages[0]);
            }

            var footer   = form.querySelector('.forge-form-footer');
            var wrap     = form.closest('.forge-form-wrap') || form;
            var furthest = 0;

            function applyPage(idx) {
                pages.forEach(function (p, i) {
                    p.classList.toggle('forge-page-active', i === idx);
                });
                if (footer) {
                    footer.style.display = (idx === pages.length - 1) ? '' : 'none';
                }
                if (idx > furthest) furthest = idx;
                form.dispatchEvent(new CustomEvent('forge:page-change', {
                    bubbles: false, cancelable: false,
                    detail: { index: idx, furthest: furthest, total: pages.length },
                }));
            }

            function showPage(idx, scroll) {
                if (!scroll) {
                    applyPage(idx);
                    return;
                }

                var target   = Math.max(0, wrap.getBoundingClientRect().top + window.pageYOffset - 20);
                var startY   = window.pageYOffset;
                var distance = startY - target;

                if (distance < 4) {
                    applyPage(idx);
                    return;
                }

                /* Swap the page content and measure the height difference. */
                var tallHeight = document.body.scrollHeight;
                applyPage(idx);
                var shortHeight = document.body.scrollHeight;
                var gap = tallHeight - shortHeight;

                /* Insert a spacer after the form wrap so the footer stays at its
                 * current position (bottom of tallHeight). During the scroll the
                 * spacer shrinks in sync, so the footer descends smoothly with it
                 * rather than snapping to the shorter page height immediately. */
                var spacer = document.createElement('div');
                spacer.style.height = gap + 'px';
                wrap.parentNode.insertBefore(spacer, wrap.nextSibling);

                var duration  = Math.min(1050, Math.max(450, distance * 0.225));
                var startTime = null;

                function ease(t) {
                    return t < 0.5 ? 2 * t * t : -1 + (4 - 2 * t) * t;
                }

                function tick(ts) {
                    if (!startTime) startTime = ts;
                    var p = Math.min(1, (ts - startTime) / duration);
                    var e = ease(p);
                    window.scrollTo(0, startY - distance * e);
                    spacer.style.height = Math.ceil(gap * (1 - e)) + 'px';
                    if (p < 1) {
                        requestAnimationFrame(tick);
                    } else {
                        spacer.parentNode.removeChild(spacer);
                    }
                }
                requestAnimationFrame(tick);
            }

            function currentIdx() {
                return pages.findIndex(function (p) {
                    return p.classList.contains('forge-page-active');
                });
            }

            form.addEventListener('forge:reset', function () {
                /* A fresh submission means none of the later pages have been
                 * revisited yet, so the "reachable" state (furthest) must drop
                 * back to just page 0 too — otherwise a step bar's later steps
                 * stay clickable from the previous run even though the visitor
                 * hasn't stepped through them again this time. */
                furthest = 0;
                showPage(0, false);
            });

            form.addEventListener('click', function (e) {
                if (e.target.classList.contains('forge-btn-next')) {
                    var idx    = currentIdx();
                    var result = validatePage(pages[idx]);
                    if (!result.valid) {
                        scrollToField(result.firstError);
                        return;
                    }
                    if (idx < pages.length - 1) showPage(idx + 1, true);
                }
                if (e.target.classList.contains('forge-btn-prev')) {
                    var idx2 = currentIdx();
                    if (idx2 > 0) showPage(idx2 - 1, true);
                }
                var gotoEl = e.target.closest('[data-forge-goto-page]');
                if (gotoEl) {
                    var gotoIdx = parseInt(gotoEl.getAttribute('data-forge-goto-page'), 10);
                    if (!isNaN(gotoIdx) && gotoIdx >= 0 && gotoIdx <= furthest && gotoIdx !== currentIdx()) {
                        showPage(gotoIdx, true);
                    }
                }
            });

            showPage(0, false);
        });
    }

    /* ── Conditional logic ───────────────────────────────────────────────── */

    function initConditions(root) {
        root.querySelectorAll('.forge-form').forEach(function (form) {
            if (!form.querySelector('[data-conditions]')) return;
            /* See initPageBreaks() — same double-init guard. */
            if (form.dataset.forgeConditionsInit) return;
            form.dataset.forgeConditionsInit = '1';

            function getFieldValue(fieldId) {
                var name = CSS.escape(fieldId);
                /* CheckboxField renders its inputs as name="{id}[]" (PHP array-submission
                 * convention) — every other field type uses the bare id. Match both, or a
                 * condition rule referencing a checkbox field would never find its inputs
                 * and always see an empty value. */
                var all = Array.from(form.querySelectorAll('[name="' + name + '"], [name="' + name + '[]"]'));
                if (!all.length) return '';
                /* Treat inputs inside a hidden conditional ancestor as absent */
                var inputs = all.filter(function (i) {
                    var p = i.parentElement;
                    while (p && p !== form) {
                        if ('conditions' in (p.dataset || {}) && p.style.display === 'none') return false;
                        p = p.parentElement;
                    }
                    return true;
                });
                if (!inputs.length) return all[0].type === 'checkbox' ? [] : '';
                var first = inputs[0];
                if (first.type === 'radio') {
                    var chk = inputs.find(function (i) { return i.checked; });
                    return chk ? chk.value : '';
                }
                if (first.type === 'checkbox') {
                    return inputs.filter(function (i) { return i.checked; }).map(function (i) { return i.value; });
                }
                if (first.tagName === 'SELECT' && first.multiple) {
                    return Array.from(first.selectedOptions).map(function (o) { return o.value; });
                }
                return first.value;
            }

            function testRule(rule) {
                var val  = getFieldValue(rule.field_id || '');
                var op   = rule.operator || 'equals';
                var rv   = (rule.value || '').toString().toLowerCase();
                var isArr = Array.isArray(val);
                var str   = isArr ? '' : (val || '').toString().toLowerCase();
                switch (op) {
                    case 'equals':       return isArr ? val.some(function (v) { return v.toLowerCase() === rv; }) : str === rv;
                    case 'not_equals':   return isArr ? val.every(function (v) { return v.toLowerCase() !== rv; }) : str !== rv;
                    case 'contains':     return rv !== '' && (isArr
                        ? val.some(function (v) { return v.toLowerCase().indexOf(rv) !== -1; })
                        : str.indexOf(rv) !== -1);
                    case 'not_contains': return rv === '' || (isArr
                        ? val.every(function (v) { return v.toLowerCase().indexOf(rv) === -1; })
                        : str.indexOf(rv) === -1);
                    case 'empty':        return isArr ? val.length === 0 : str === '';
                    case 'not_empty':    return isArr ? val.length > 0   : str !== '';
                    case 'greater':      { var a = parseFloat(str), b = parseFloat(rv); return !isNaN(a) && !isNaN(b) && a > b; }
                    case 'less':         { var a2 = parseFloat(str), b2 = parseFloat(rv); return !isNaN(a2) && !isNaN(b2) && a2 < b2; }
                    default:             return true;
                }
            }

            function applyAll() {
                form.querySelectorAll('[data-conditions]').forEach(function (el) {
                    var cond;
                    try { cond = JSON.parse(el.dataset.conditions); } catch (_) { return; }
                    var rules = cond.rules || [];
                    if (!rules.length) return;
                    var pass = cond.match === 'any'
                        ? rules.some(testRule)
                        : rules.every(testRule);
                    el.style.display = (cond.action === 'hide' ? !pass : pass) ? '' : 'none';
                });
            }

            form.addEventListener('change', applyAll);
            form.addEventListener('input',  applyAll);
            applyAll();
        });
    }

    /* ── AJAX form submission ─────────────────────────────────────────────── */

    function initForms(root) {
        root.querySelectorAll('.forge-form').forEach(function (form) {
            /* See initPageBreaks() — same double-init guard, critical here since a
             * second bound submit handler would submit the form twice per click. */
            if (form.dataset.forgeFormsInit) return;
            form.dataset.forgeFormsInit = '1';

            var isSubmitting = false;
            form.addEventListener('submit', function (e) {
                e.preventDefault();
                if (isSubmitting) return;

                var wrap    = form.closest('.forge-form-wrap');
                var msgBox  = wrap && wrap.querySelector('.forge-form-messages');
                var btn     = form.querySelector('.forge-submit-btn');
                var label   = btn && btn.querySelector('.forge-submit-label');
                var spinner = btn && btn.querySelector('.forge-submit-spinner');
                var i18n    = (window.ForgeForms && window.ForgeForms.i18n) || {};

                /* Client-side validation before sending */
                var vr = validatePage(form);
                if (!vr.valid) {
                    var msg = vr.hasRequired && vr.hasInvalid
                        ? (i18n.validation_both || 'Please fill in all required fields and correct the invalid entries.')
                        : vr.hasRequired
                            ? (i18n.validation_required || 'Please fill in all required fields.')
                            : (i18n.validation_invalid || 'Please enter valid data.');
                    if (msgBox) {
                        msgBox.className    = 'forge-form-messages error';
                        msgBox.textContent  = msg;
                        msgBox.style.display = '';
                    }
                    scrollToField(vr.firstError);
                    return;
                }

                /* Cross-field file count guard.
                   Sums data-forge-file-count across all file-bearing elements.
                   phpMax reflects PHP's max_file_uploads limit (set via data-max-files). */
                var overflowDetected = false;
                (function () {
                    var counters = form.querySelectorAll('[data-forge-file-count]');
                    if (!counters.length) { return; }
                    var phpMax = parseInt(form.dataset.forgeFileMax || '0', 10);
                    if (!phpMax) {
                        form.querySelectorAll('[data-max-files]').forEach(function (z) {
                            var m = parseInt(z.dataset.maxFiles || '0', 10);
                            if (m > phpMax) { phpMax = m; }
                        });
                    }
                    if (!phpMax) { return; }
                    var total = 0;
                    counters.forEach(function (el) {
                        total += parseInt(el.dataset.forgeFileCount || '0', 10);
                    });
                    if (total > phpMax) {
                        form.dispatchEvent(new CustomEvent('forge:upload-overflow', {
                            bubbles: false, cancelable: false,
                            detail: { total: total, max: phpMax },
                        }));
                        overflowDetected = true;
                    }
                }());
                if (overflowDetected) { return; }

                var origLabel = label ? label.textContent : '';

                if (btn) btn.disabled = true;
                if (label) label.textContent = (btn && btn.dataset.working) || i18n.submitting || 'Sending…';
                if (spinner) spinner.style.display = '';
                if (msgBox) { msgBox.style.display = 'none'; msgBox.className = 'forge-form-messages'; }

                isSubmitting = true;
                var data = new FormData(form);

                fetch((window.ForgeForms && window.ForgeForms.ajaxUrl) || '', {
                    method: 'POST',
                    body: data,
                    credentials: 'same-origin',
                })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (spinner) spinner.style.display = 'none';

                    isSubmitting = false;
                    if (res.success) {
                        if (label) label.textContent = origLabel;
                        if (btn) btn.disabled = false;
                        if (msgBox) {
                            msgBox.className = 'forge-form-messages success';
                            msgBox.textContent = (btn && btn.dataset.success)
                                || (res.data && res.data.message)
                                || i18n.thank_you
                                || 'Thank you!';
                            msgBox.style.display = '';
                        }
                        form.reset();
                        form.dispatchEvent(new Event('change'));
                        form.dispatchEvent(new Event('forge:reset'));
                        /* Re-init dynamic fields after reset */
                        var fi = window.ForgeFieldInits || {};
                        Object.keys(fi).forEach(function (t) { fi[t](form); });
                        /* Scroll to form top so the success message is in view */
                        var scrollTarget = wrap || form;
                        var scrollTop = scrollTarget.getBoundingClientRect().top + window.pageYOffset - 20;
                        window.scrollTo({ top: Math.max(0, scrollTop), behavior: 'smooth' });
                    } else {
                        if (label) label.textContent = origLabel;
                        if (btn) btn.disabled = false;
                        var errMsg = (res.data && res.data.message)
                            || i18n.error_server
                            || 'Server error. Please try again.';
                        if (msgBox) {
                            msgBox.className    = 'forge-form-messages error';
                            msgBox.textContent  = errMsg;
                            msgBox.style.display = '';
                        }

                        /* Show per-field server errors */
                        var fieldErrors = res.data && res.data.errors;
                        if (fieldErrors) {
                            var firstErrEl = null;
                            Object.keys(fieldErrors).forEach(function (fid) {
                                var errEl = form.querySelector('#' + CSS.escape(fid) + '-error');
                                if (errEl) {
                                    errEl.textContent = fieldErrors[fid];
                                    if (!firstErrEl) firstErrEl = errEl.closest('.forge-field');
                                }
                            });
                            scrollToField(firstErrEl);
                        }
                    }
                })
                .catch(function () {
                    isSubmitting = false;
                    if (label) label.textContent = origLabel;
                    if (btn) btn.disabled = false;
                    if (spinner) spinner.style.display = 'none';
                    if (msgBox) {
                        msgBox.className = 'forge-form-messages error';
                        msgBox.textContent = i18n.error_server || 'Server error. Please try again.';
                        msgBox.style.display = '';
                    }
                });
            });
        });
    }

    /* ── CAPTCHA click-to-activate ───────────────────────────────────────────
       The reCAPTCHA script (and the connection to Google it triggers) is only
       requested once the visitor explicitly clicks the placeholder button —
       not on page load — so the field doesn't load a third-party script
       before the visitor has interacted with the form. */
    var recaptchaLoading = false;
    var recaptchaCallbacks = [];
    function loadRecaptchaScript(cb) {
        if (window.grecaptcha && window.grecaptcha.render) {
            cb();
            return;
        }
        recaptchaCallbacks.push(cb);
        if (recaptchaLoading) {
            return;
        }
        recaptchaLoading = true;
        window.__forgeRecaptchaOnLoad = function () {
            recaptchaCallbacks.forEach(function (fn) { fn(); });
            recaptchaCallbacks = [];
        };
        var s = document.createElement('script');
        s.src = 'https://www.google.com/recaptcha/api.js?onload=__forgeRecaptchaOnLoad&render=explicit';
        s.async = true;
        s.defer = true;
        document.head.appendChild(s);
    }
    function initCaptchaGates(root) {
        root.querySelectorAll('.forge-captcha-gate').forEach(function (gate) {
            /* See initPageBreaks() — same double-init guard. */
            if (gate.dataset.forgeCaptchaInit) return;
            gate.dataset.forgeCaptchaInit = '1';

            var btn = gate.querySelector('.forge-captcha-activate');
            if (!btn) return;
            btn.addEventListener('click', function () {
                btn.disabled = true;
                loadRecaptchaScript(function () {
                    var widget = document.createElement('div');
                    gate.innerHTML = '';
                    gate.appendChild(widget);
                    window.grecaptcha.render(widget, { sitekey: gate.dataset.sitekey });
                });
            });
        });
    }

    /* ── Boot ─────────────────────────────────────────────────────────────── */

    function init(root) {
        root = root || document;
        /* Guards the whole boot sequence against running twice against the same
         * root (e.g. a page builder/caching setup that includes this script
         * more than once) — field getClientInit() implementations aren't all
         * written to be safe to re-run, so this is the single choke point that
         * keeps them from double-binding listeners or duplicating markup. */
        if (root === document && window.__forgeFrontInited) return;
        if (root === document) window.__forgeFrontInited = true;

        var fieldInits = window.ForgeFieldInits || {};
        Object.keys(fieldInits).forEach(function (type) { fieldInits[type](root); });
        initPageBreaks(root);
        initConditions(root);
        initForms(root);
        initCaptchaGates(root);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { init(document); });
    } else {
        init(document);
    }

    /* ── Test hook (WP_DEBUG only) ────────────────────────────────────────── */
    if (window.__FORGE_TEST__) {
        window.ForgeTestHooks = {
            validatePage:   validatePage,
            initConditions: initConditions,
        };
    }
}());
