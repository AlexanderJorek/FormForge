/*!
 * FormForge
 * @copyright 2026 Alexander Jorek
 * @license   GPL-3.0-or-later
 */
/**
 * FormForge — PDF Verification Page
 */

/* Strips <script>, on*="" handlers and javascript:/vbscript: URIs from a
   server-rendered HTML fragment before it's assigned to innerHTML. The
   fragment is already expected to be escaped/kses'd server-side in
   Verificationpage.php — this is defense-in-depth so this file doesn't
   depend entirely on that other file/language never missing a spot for a
   future field type, mirroring the same mitigation used for admin-authored
   HTML previews elsewhere in this codebase. */
function _forgeSanitizeFragment(html) {
    html = String(html || '').replace(/<script\b[^<]*(?:(?!<\/script>)<[^<]*)*<\/script>/gi, '');
    html = html.replace(/[\s\/]+on[a-z]+\s*=\s*(?:"[^"]*"|'[^']*'|[^\s>]+)/gi, '');
    html = html.replace(/\b(href|src)\s*=\s*(["'])\s*(?:javascript|vbscript)\s*:[^"']*\2/gi, '$1=$2#$2');
    return html;
}

/* ── Particle canvas on the PHP-rendered canvas element ── */
document.addEventListener('DOMContentLoaded', function () {
    var canvas = document.getElementById('forge-particle-canvas');
    if (!canvas) { return; }

    var ctx   = canvas.getContext('2d');
    var mouse = { x: -9999, y: -9999 };
    var _ah   = getComputedStyle(document.documentElement).getPropertyValue('--forge-admin-accent').trim() || '#2271b1';
    var _rgb  = function (h) { return parseInt(h.slice(1,3),16)+','+parseInt(h.slice(3,5),16)+','+parseInt(h.slice(5,7),16); };
    var COLOR = _rgb(_ah);
    var LINK  = 150, SPEED = 1.0;
    var particles = [], paused = false, FRAME_MS = 1000 / 30;

    function resize() {
        canvas.width  = canvas.offsetWidth  || window.innerWidth;
        canvas.height = canvas.offsetHeight || window.innerHeight;
        var DOTS = Math.min(120, Math.max(40, Math.round(canvas.width * canvas.height / 26000)));
        particles = [];
        for (var i = 0; i < DOTS; i++) {
            particles.push({
                x: Math.random() * canvas.width,  y: Math.random() * canvas.height,
                vx: (Math.random() - 0.5) * SPEED * 2, vy: (Math.random() - 0.5) * SPEED * 2,
                r: 2 + Math.random() * 1.5
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
            if (p.y < 0 || p.y > canvas.height) { p.vy *= -1; }
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
            ctx.beginPath();
            ctx.arc(particles[i].x, particles[i].y, particles[i].r, 0, Math.PI * 2);
            ctx.fill();
        }
        setTimeout(function () { requestAnimationFrame(draw); }, FRAME_MS - 2);
    }

    canvas.addEventListener('mousemove', function (e) {
        var rect = canvas.getBoundingClientRect();
        mouse.x = e.clientX - rect.left;
        mouse.y = e.clientY - rect.top;
    });
    document.addEventListener('visibilitychange', function () {
        paused = document.hidden;
        if (!paused) requestAnimationFrame(draw);
    });
    window.addEventListener('resize', resize);

    resize();
    requestAnimationFrame(draw);
});

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
    var i18n = (window.ForgeVerifier && window.ForgeVerifier.i18n) || {};
    card.innerHTML =
        '<div class="forge-vpc__header">' +
            '<span class="forge-vpc__icon"><span class="dashicons dashicons-pdf"></span></span>' +
            '<span class="forge-vpc__name"></span>' +
        '</div>' +
        '<div class="forge-vpc__step">' + (i18n.loading || 'Loading…') + '</div>' +
        '<div class="forge-vpc__bar-wrap">' +
            '<div class="forge-vpc__bar" style="width:0%"></div>' +
        '</div>' +
        '<div class="forge-vpc__foot">' +
            '<span class="forge-vpc__pct">0 %</span>' +
            '<span class="forge-vpc__elapsed"></span>' +
        '</div>';
    // Set via textContent rather than interpolating into the innerHTML string
    // above — doesn't depend on Verificationpage.php's sanitize_file_name()
    // upstream remaining the only source of this value forever.
    var nameEl = card.querySelector('.forge-vpc__name');
    if (nameEl) nameEl.textContent = name;
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

    var i18n = (window.ForgeVerifier && window.ForgeVerifier.i18n) || {};
    try {
        _forgeUpdateCard(card, i18n.pdf_loading || 'Loading PDF…', 5);
        const pdf = await pdfjsLib.getDocument({ url: pdfUrl, withCredentials: true }).promise;
        const allLines = [];

        for (let pageNum = 1; pageNum <= pdf.numPages; pageNum++) {
            var pagePct = 10 + Math.round((pageNum - 1) / pdf.numPages * 60);
            var pageMsg = (i18n.page_reading || 'Reading page %1$d of %2$d…')
                .replace('%1$d', pageNum).replace('%2$d', pdf.numPages);
            _forgeUpdateCard(card, pageMsg, pagePct);
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

        _forgeUpdateCard(card, i18n.text_extracted || 'Text extracted — server analyzing…', 75);

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
            _forgeUpdateCard(card, i18n.processing || 'Processing response…', 98);
            const rawText = await res.text();

            let json = null;
            try {
                json = JSON.parse(rawText);
            } catch (_) {
                console.error('[FormForge] Non-JSON response (HTTP ' + res.status + ') for', pdfUrl, '\n', rawText);
                _forgeUpdateCard(card, (i18n.server_error || 'Server error (HTTP %d)').replace('%d', res.status), 100);
                card.classList.add('forge-vpc--error');
                done();
                return allLines;
            }

            _forgeUpdateCard(card, i18n.done || 'Done', 100);
            done();

            if (json.success === true && json.data && typeof json.data.html === 'string') {
                // Server-rendered fragment: every dynamic value in it is passed through
                // esc_html()/wp_kses() in Verificationpage.php before reaching here;
                // _forgeSanitizeFragment() is an additional client-side backstop.
                const tmp = document.createElement('div');
                tmp.innerHTML = _forgeSanitizeFragment(json.data.html);
                card.parentNode.replaceChild(tmp.firstElementChild || tmp, card);
            } else {
                console.error('[FormForge] Server returned error:', json);
                card.classList.add('forge-vpc--error');
                var msg = (json.data && json.data.message) || (i18n.unknown_error || 'Unknown server error');
                var stepEl = card.querySelector('.forge-vpc__step');
                if (stepEl) stepEl.textContent = (i18n.error_prefix || 'Error: ') + msg;
            }
        } catch (err) {
            stopPoll();
            console.error('[FormForge] Fetch error for', pdfUrl, err);
            _forgeUpdateCard(card, i18n.network_error || 'Network error', 100);
            card.classList.add('forge-vpc--error');
            done();
        }

        return allLines;
    } catch (e) {
        stopPoll();
        console.error('[FormForge] Error parsing PDF', pdfUrl, e);
        _forgeUpdateCard(card, (i18n.pdf_load_error || 'PDF load error: ') + e.message, 100);
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
