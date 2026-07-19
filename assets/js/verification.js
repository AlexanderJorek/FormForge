/*!
 * FormForge
 * @copyright 2026 Alexander Jorek
 * @license   GPL-2.0-or-later
 */
/**
 * FormForge — PDF Verification Page
 */

/* ── Mount verification content in a fixed overlay ── */
function mountVerificationOverlay() {
    if (!document.body.classList.contains('formforge_page_forge-pdf-verification')) { return; }
    const wrap      = document.querySelector('.wrap');
    const wpContent = document.getElementById('wpcontent');
    const adminBar  = document.getElementById('wpadminbar');
    if (!wrap) { return; }

    const topOffset  = adminBar  ? adminBar.offsetHeight                  : 0;
    const leftOffset = wpContent ? wpContent.getBoundingClientRect().left : 0;

    const overlay = document.createElement('div');
    Object.assign(overlay.style, {
        position:   'fixed',
        top:        topOffset + 'px',
        left:       leftOffset + 'px',
        right:      '0',
        bottom:     '0',
        overflowX:  'hidden',
        overflowY:  'auto',
        background: '#f0f0f1',
        zIndex:     '9000',
    });

    overlay.id = 'forge-verification-overlay';
    document.body.appendChild(overlay);

    /* Lock page scroll while overlay is open — target WP's actual scroll containers */
    document.documentElement.style.overflow = 'hidden';
    document.body.style.overflow            = 'hidden';
    var wpWrap = document.getElementById('wpwrap');
    if (wpWrap)    { wpWrap.style.overflow    = 'hidden'; }
    if (wpContent) { wpContent.style.overflow = 'hidden'; }

    /* ── Particle canvas (matches FormList particle system) ── */
    const canvas = document.createElement('canvas');
    canvas.id = 'forge-particle-canvas';
    canvas.style.cssText = 'position:absolute;top:0;left:0;pointer-events:none;z-index:0;';
    overlay.appendChild(canvas);

    (function () {
        var ctx    = canvas.getContext('2d');
        var mouse  = { x: -9999, y: -9999 };
        var _ah = getComputedStyle(document.documentElement).getPropertyValue('--forge-admin-accent').trim()||'#2271b1';
        var _rgb = function(h){return parseInt(h.slice(1,3),16)+','+parseInt(h.slice(3,5),16)+','+parseInt(h.slice(5,7),16);};
        var DOTS   = Math.min(120, Math.max(40, Math.round(overlay.clientWidth * overlay.clientHeight / 26000)));
        var LINK = 150, SPEED = 1.0, COLOR = _rgb(_ah);
        var particles = [], paused = false, FRAME_MS = 1000 / 30;

        function resize() {
            var r = canvas.getBoundingClientRect();
            canvas.width  = r.width  || overlay.clientWidth;
            canvas.height = r.height || overlay.clientHeight;
        }

        function rand(min, max) { return min + Math.random() * (max - min); }

        function initParticles() {
            particles = [];
            for (var i = 0; i < DOTS; i++) {
                particles.push({
                    x: rand(0, canvas.width), y: rand(0, canvas.height),
                    vx: rand(-SPEED, SPEED),  vy: rand(-SPEED, SPEED),
                    r: rand(2, 3.5)
                });
            }
        }

        function draw() {
            if (paused) return;
            ctx.clearRect(0, 0, canvas.width, canvas.height);
            for (var i = 0; i < particles.length; i++) {
                var p = particles[i];
                p.x += p.vx; p.y += p.vy;
                if (p.x < 0 || p.x > canvas.width)  { p.vx *= -1; }
                if (p.y < 0 || p.y > canvas.height)  { p.vy *= -1; }
            }
            ctx.lineWidth = 1;
            for (var i = 0; i < particles.length; i++) {
                for (var j = i + 1; j < particles.length; j++) {
                    var dx = particles[i].x - particles[j].x;
                    var dy = particles[i].y - particles[j].y;
                    var d  = Math.sqrt(dx * dx + dy * dy);
                    if (d < LINK) {
                        ctx.beginPath();
                        ctx.moveTo(particles[i].x, particles[i].y);
                        ctx.lineTo(particles[j].x, particles[j].y);
                        ctx.strokeStyle = 'rgba(' + COLOR + ',' + (1 - d / LINK) * 0.3 + ')';
                        ctx.stroke();
                    }
                }
                var mdx = particles[i].x - mouse.x;
                var mdy = particles[i].y - mouse.y;
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
                ctx.beginPath();
                ctx.arc(particles[i].x, particles[i].y, particles[i].r, 0, Math.PI * 2);
                ctx.fill();
            }
            setTimeout(function() { requestAnimationFrame(draw); }, FRAME_MS - 2);
        }

        overlay.addEventListener('mousemove', function (e) {
            var rect = canvas.getBoundingClientRect();
            mouse.x = e.clientX - rect.left;
            mouse.y = e.clientY - rect.top;
        });
        document.addEventListener('visibilitychange', function () {
            paused = document.hidden;
            if (!paused) requestAnimationFrame(draw);
        });

        resize();
        initParticles();
        requestAnimationFrame(draw);
        window.addEventListener('resize', function () { resize(); initParticles(); });
    }());

    wrap.style.position = 'relative';
    wrap.style.zIndex   = '1';
    overlay.appendChild(wrap);
}

document.addEventListener('DOMContentLoaded', mountVerificationOverlay);

const Y_THRESHOLD = 3;

/* Set PDF.js worker — local file only, no CDN */
if (typeof pdfjsLib !== 'undefined') {
    const workerSrc = window.ForgeVerifier && window.ForgeVerifier.pdfJsWorker;
    if (!workerSrc) {
        console.error('[FormForge] pdfJsWorker is not set. PDF.js worker may be missing.');
    } else {
        pdfjsLib.GlobalWorkerOptions.workerSrc = workerSrc;
    }
}

/* ── Per-PDF inline progress cards ── */

function _forgeCardPdfName(url) {
    try { return decodeURIComponent(url.split('/').pop().split('?')[0]); } catch (_) { return url; }
}

function _forgeCreateProgressCard(name) {
    var card = document.createElement('div');
    card.className = 'forge-vpc';
    card.innerHTML =
        '<div class="forge-vpc__header">' +
            '<span class="forge-vpc__icon"><span class="dashicons dashicons-pdf"></span></span>' +
            '<span class="forge-vpc__name">' + name + '</span>' +
        '</div>' +
        '<div class="forge-vpc__step">Wird geladen…</div>' +
        '<div class="forge-vpc__bar-wrap">' +
            '<div class="forge-vpc__bar" style="width:0%"></div>' +
        '</div>' +
        '<div class="forge-vpc__foot">' +
            '<span class="forge-vpc__pct">0 %</span>' +
            '<span class="forge-vpc__elapsed"></span>' +
        '</div>';
    return card;
}

function _forgeUpdateCard(card, step, pct) {
    var s = card.querySelector('.forge-vpc__step');
    var b = card.querySelector('.forge-vpc__bar');
    var p = card.querySelector('.forge-vpc__pct');
    if (s) s.textContent = step;
    if (b) b.style.width = Math.round(pct) + '%';
    if (p) p.textContent = Math.round(pct) + ' %';
}

/* ── Queue for PDFs pushed by PHP ── */
window.FORGE_VERIFICATION_QUEUE = window.FORGE_VERIFICATION_QUEUE || [];

window.FORGE_VERIFICATION_PROCESS_PDF = async function processPdf(pdfInfo) {
    if (!pdfInfo) return;

    const pdfUrl   = typeof pdfInfo === 'string' ? pdfInfo : pdfInfo.url;
    const pdfToken = typeof pdfInfo === 'string' ? null    : (pdfInfo.token || null);
    const pdfName  = typeof pdfInfo === 'string' ? _forgeCardPdfName(pdfInfo) : (pdfInfo.name || _forgeCardPdfName(pdfInfo.url));
    if (!pdfUrl) return;

    const container = document.getElementById('forge-pdf-verification-results') || document.body;
    const name      = pdfName;
    const card      = _forgeCreateProgressCard(name);
    container.appendChild(card);
    var _pollTimer  = null;
    function stopPoll() { if (_pollTimer) { clearInterval(_pollTimer); _pollTimer = null; } }

    /* Elapsed timer */
    const t0 = Date.now();
    const elapsedEl = card.querySelector('.forge-vpc__elapsed');
    const elapsedTimer = setInterval(function () {
        if (elapsedEl) elapsedEl.textContent = ((Date.now() - t0) / 1000).toFixed(1) + ' s';
    }, 100);

    function done() { clearInterval(elapsedTimer); }

    try {
        _forgeUpdateCard(card, 'PDF wird geladen…', 5);
        const pdf = await pdfjsLib.getDocument({ url: pdfUrl, withCredentials: true }).promise;
        const allLines = [];

        for (let pageNum = 1; pageNum <= pdf.numPages; pageNum++) {
            var pagePct = 10 + Math.round((pageNum - 1) / pdf.numPages * 60);
            _forgeUpdateCard(
                card,
                'Seite ' + pageNum + ' von ' + pdf.numPages + ' wird gelesen…',
                pagePct
            );
            const page    = await pdf.getPage(pageNum);
            const content = await page.getTextContent();

            const items = content.items.sort(function (a, b) {
                const yDiff = b.transform[5] - a.transform[5];
                if (Math.abs(yDiff) > Y_THRESHOLD) return yDiff;
                return a.transform[4] - b.transform[4];
            });

            const lines = [];
            let current = null;
            for (const item of items) {
                const y = item.transform[5];
                if (!current || Math.abs(current.y - y) > Y_THRESHOLD) {
                    current = { y: y, items: [item] };
                    lines.push(current);
                } else {
                    current.items.push(item);
                }
            }

            allLines.push(...lines.map(function (line) {
                return line.items.map(function (i) { return i.str; }).join('');
            }));
        }

        _forgeUpdateCard(card, 'Text extrahiert — Server analysiert…', 75);

        const ajaxUrl = window.ForgeVerifier && window.ForgeVerifier.ajaxUrl;
        if (!ajaxUrl) { done(); return allLines; }

        const formData = new FormData();
        formData.append('action',      'forge_verify_push_lines');
        formData.append('pdf_token',   pdfToken || '');
        formData.append('visualLines', JSON.stringify(allLines));
        formData.append('nonce',       (window.ForgeVerifier && window.ForgeVerifier.nonce) || '');

        /* Poll server-side progress while the main request is in flight */
        var lastServerPct = 0;
        _pollTimer = pdfToken ? setInterval(function () {
            var pf = new FormData();
            pf.append('action', 'forge_verify_progress');
            pf.append('token',  pdfToken);
            pf.append('nonce',  (window.ForgeVerifier && window.ForgeVerifier.nonce) || '');
            fetch(ajaxUrl, { method: 'POST', body: pf, credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (d.success && d.data && d.data.step && d.data.pct > lastServerPct) {
                        lastServerPct = d.data.pct;
                        _forgeUpdateCard(card, d.data.step, d.data.pct);
                    }
                })
                .catch(function () {});
        }, 400) : null;

        try {
            const res     = await fetch(ajaxUrl, { method: 'POST', body: formData, credentials: 'same-origin' });
            stopPoll();
            _forgeUpdateCard(card, 'Antwort wird verarbeitet…', 98);
            const rawText = await res.text();

            let json = null;
            try {
                json = JSON.parse(rawText);
            } catch (_) {
                console.error('[FormForge] Non-JSON response (HTTP ' + res.status + ') for', pdfUrl, '\n', rawText);
                _forgeUpdateCard(card, 'Serverfehler (HTTP ' + res.status + ')', 100);
                card.classList.add('forge-vpc--error');
                done();
                return allLines;
            }

            _forgeUpdateCard(card, 'Fertig', 100);
            done();

            if (json.success === true && json.data && typeof json.data.html === 'string') {
                // Server-rendered fragment: every dynamic value in it is passed through
                // esc_html()/wp_kses() in Verificationpage.php before reaching here.
                const tmp = document.createElement('div');
                tmp.innerHTML = json.data.html;
                card.parentNode.replaceChild(tmp.firstElementChild || tmp, card);
            } else {
                console.error('[FormForge] Server returned error:', json);
                card.classList.add('forge-vpc--error');
                var msg = (json.data && json.data.message) || 'Unbekannter Serverfehler';
                var stepEl = card.querySelector('.forge-vpc__step');
                if (stepEl) stepEl.textContent = 'Fehler: ' + msg;
            }
        } catch (err) {
            stopPoll();
            console.error('[FormForge] Fetch error for', pdfUrl, err);
            _forgeUpdateCard(card, 'Netzwerkfehler', 100);
            card.classList.add('forge-vpc--error');
            done();
        }

        return allLines;
    } catch (e) {
        stopPoll();
        console.error('[FormForge] Error parsing PDF', pdfUrl, e);
        _forgeUpdateCard(card, 'PDF-Ladefehler: ' + e.message, 100);
        card.classList.add('forge-vpc--error');
        done();
        return [];
    }
};

/* Process any PDFs queued before this script loaded */
if (window.FORGE_VERIFICATION_QUEUE.length) {
    window.FORGE_VERIFICATION_QUEUE.forEach(function (item) {
        window.FORGE_VERIFICATION_PROCESS_PDF(item);
    });
    window.FORGE_VERIFICATION_QUEUE = [];
}
