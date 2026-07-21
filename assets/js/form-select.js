/*!
 * FormForge — Form Select shortcode — show/hide logic
 * @copyright 2026 Alexander Jorek
 * @license   GPL-3.0-or-later
 */
(function () {
    'use strict';

    function initFsel(wrap) {
        var trigger  = wrap.querySelector('.fsel-trigger');
        var options  = wrap.querySelector('.fsel-options');
        var optItems = wrap.querySelectorAll('.fsel-option');
        var forms    = wrap.querySelectorAll('.fsel-form');
        var favIdx   = parseInt(wrap.dataset.fav || '0', 10); /* server-chosen default form index, set by the [form_select] shortcode render */

        function select(idx) {
            var opt = wrap.querySelector('.fsel-option[data-idx="' + idx + '"]');
            if (!opt) { return; }

            /* update trigger text */
            var label = opt.dataset.label || '';
            var desc  = opt.dataset.desc  || '';
            wrap.querySelector('.fsel-trigger-label').textContent = label;
            wrap.querySelector('.fsel-trigger-desc').textContent  = desc;

            /* active state on options */
            optItems.forEach(function (o) {
                o.classList.toggle('fsel-option--active', o.dataset.idx === String(idx));
                o.setAttribute('aria-selected', o.dataset.idx === String(idx) ? 'true' : 'false');
            });

            /* show matching form, hide others */
            forms.forEach(function (f) {
                f.classList.toggle('fsel-form--hidden', f.dataset.idx !== String(idx));
            });
        }

        function openOptions() {
            options.hidden = false;
            trigger.setAttribute('aria-expanded', 'true');
        }

        function closeOptions() {
            options.hidden = true;
            trigger.setAttribute('aria-expanded', 'false');
        }

        /* initial selection */
        select(favIdx);

        /* toggle on trigger click / keyboard */
        trigger.addEventListener('click', function () {
            options.hidden ? openOptions() : closeOptions();
        });
        trigger.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                options.hidden ? openOptions() : closeOptions();
            }
        });

        /* option selection */
        optItems.forEach(function (opt) {
            opt.addEventListener('click', function () {
                select(parseInt(opt.dataset.idx, 10));
                closeOptions();
            });
            opt.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    select(parseInt(opt.dataset.idx, 10));
                    closeOptions();
                }
            });
        });

        /* close on outside click */
        document.addEventListener('click', function (e) {
            if (!wrap.contains(e.target)) { closeOptions(); }
        });
    }

    document.querySelectorAll('.fsel-wrap').forEach(initFsel);
}());
