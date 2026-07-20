<?php

/**
 * AJAX handler for PDF hash-seal verification.
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

use Smalot\PdfParser\Parser;
use ForgeForms\PDF\HashSeal;

add_action(
    'wp_ajax_forge_verify_push_lines',
    function () {

        /* ---- Raise limits for heavy PDF parsing ---- */
        @ini_set('memory_limit', '1024M');
        @ini_set('pcre.backtrack_limit', '33554432'); // 32 M — needed for [\s\S]*? across large PDFs
        if (!ini_get('safe_mode')) {
            set_time_limit(300);
        }

        /* ---- Capability ---- */
        if (!\ForgeForms\Plugin::userCan('use_verifier')) {
            wp_send_json_error(['message' => 'Forbidden'], 403);
        }

        /* ---- Nonce ---- */
        check_ajax_referer('forge_verifier_nonce', 'nonce');

        /* ---- Input ---- */
        $pdf_token   = sanitize_key($_POST['pdf_token'] ?? '');
        $visualLines = isset($_POST['visualLines'])
        ? json_decode(\ForgeForms\Utils\Sanitize::str(wp_unslash($_POST['visualLines']), '[]'), true)
        : [];

        if (!$pdf_token) {
            wp_send_json_error(['message' => 'Invalid input: missing token'], 400);
        }
        $visualLines = array_slice(
            array_values(
                array_filter(
                    is_array($visualLines) ? $visualLines : [],
                    'is_string'
                )
            ),
            0,
            5000
        );
        foreach ($visualLines as $i => $line) {
            if (strlen($line) > 10000) {
                $visualLines[$i] = substr($line, 0, 10000);
            }
        }

        /* ---- Resolve path from transient (avoids URL-to-path mapping) ---- */
        $target_path = get_transient('forge_pdf_' . $pdf_token);
        if (!$target_path || !is_string($target_path)) {
            wp_send_json_error(['message' => 'PDF not found or token expired'], 404);
        }

        $upload_dir   = wp_upload_dir();
        $safe_dir     = $upload_dir['basedir'] . '/forge-secure-pdf';
        $verfiles_dir = $safe_dir . '/verfiles';

        /* ---- Path-traversal guard ---- */
        $real_verfiles_dir = realpath($verfiles_dir);
        $real_target_path  = realpath($target_path);
        if (!$real_verfiles_dir
            || !$real_target_path
            || strpos($real_target_path, $real_verfiles_dir . DIRECTORY_SEPARATOR) !== 0
        ) {
            wp_send_json_error(['message' => 'Invalid PDF path'], 400);
        }

        /* ---- MIME re-validation on the server-side path ---- */
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $detected_mime = $finfo->file($real_target_path);
        if (!in_array($detected_mime, ['application/pdf', 'application/x-pdf'], true)) {
            wp_send_json_error(['message' => 'File is not a valid PDF'], 400);
        }

        $file_size = filesize($real_target_path);
        if ($file_size > 50 * 1024 * 1024) {
            wp_send_json_error(
                [
                'message' => 'PDF too large ('
                . round($file_size / 1048576, 1)
                . ' MB). Maximum for verification is 50 MB.',
                ],
                400
            );
        }

        $file = [
        'name'     => preg_replace('/^[0-9a-f]{16}-/i', '', basename($real_target_path)),
        'tmp_name' => $real_target_path,
        'type'     => $detected_mime,
        'error'    => 0,
        'size'     => $file_size,
        ];

        /* ---- Capture output ---- */
        ob_start();
        try {
            Verificationpage::handleUpload($file, $visualLines, $pdf_token);
        } catch (\Throwable $ajax_err) {
            error_log('ForgeForms forge_verify_push_lines: handleUpload threw: ' . $ajax_err->getMessage());
            echo '<p style="color:red">' . esc_html__('Internal error while processing this PDF. See server log for details.', 'form-forge') . '</p>';
        }
        $raw_html = ob_get_clean();

        if ($raw_html === false || $raw_html === '') {
            error_log(
                'ForgeForms forge_verify_push_lines: raw_html is empty after handleUpload — ob level was '
                . ob_get_level()
            );
            wp_send_json_error(['message' => 'PDF processing produced no output. Check the PHP error log.'], 500);
            return;
        }

        /* ---- SANITIZE OUTPUT (critical) ---- */
        try {
            $safe_html = forge_sanitize_verifier_html($raw_html);
        } catch (\Throwable $san_err) {
            error_log('ForgeForms forge_verify_push_lines: forge_sanitize_verifier_html threw: ' . $san_err->getMessage());
            wp_send_json_error(['message' => 'Output sanitization failed. See server log for details.'], 500);
            return;
        }

        if ($safe_html === '') {
            error_log(
                'ForgeForms forge_verify_push_lines: safe_html is empty after wp_kses (raw len='
                . strlen($raw_html) . ')'
            );
            // Fall back to escaping raw html if kses strips everything (e.g. encoding issue)
            $safe_html = '<p style="color:orange">Result was sanitized to empty. Check PHP error log.</p>';
        }

        delete_transient('forge_vp_' . $pdf_token);

        wp_send_json_success(
            [
            'lines_received' => count($visualLines),
            'pdf'            => basename($real_target_path),
            'html'           => $safe_html,
            ]
        );
    }
);

/* ---- Progress polling endpoint ---- */
add_action(
    'wp_ajax_forge_verify_progress',
    function () {
        // Capability-first, matching forge_verify_push_lines/forge_serve_pdf,
        // so this doesn't rely on nonce-then-capability ordering being
        // preserved if either check is edited independently later.
        if (!\ForgeForms\Plugin::userCan('use_verifier')) {
            wp_send_json_error([], 403);
        }
        check_ajax_referer('forge_verifier_nonce', 'nonce');
        $key  = sanitize_key($_POST['token'] ?? '');
        $data = $key ? get_transient('forge_vp_' . $key) : false;
        wp_send_json_success($data ?: ['step' => '', 'pct' => 0]);
    }
);

/* ---- Authenticated PDF file-serving endpoint ---- */
add_action(
    'wp_ajax_forge_serve_pdf',
    function () {

        if (!\ForgeForms\Plugin::userCan('use_verifier')) {
            wp_die('Forbidden', '', ['response' => 403]);
        }

        // Nonce passed as query-string param by verification.js
        if (!wp_verify_nonce(sanitize_key($_GET['nonce'] ?? ''), 'forge_verifier_nonce')) {
            wp_die('Nonce verification failed', '', ['response' => 403]);
        }

        $token = sanitize_key($_GET['token'] ?? '');
        if (!$token) {
            wp_die('Missing token', '', ['response' => 400]);
        }

        $path = get_transient('forge_pdf_' . $token);
        if (!$path || !is_string($path) || !file_exists($path)) {
            wp_die('PDF not found or token expired', '', ['response' => 404]);
        }

        // Extra path-safety check
        $upload_dir   = wp_upload_dir();
        $safe_dir     = realpath($upload_dir['basedir'] . '/forge-secure-pdf');
        $real_path    = realpath($path);
        if (!$safe_dir || !$real_path || strpos($real_path, $safe_dir . DIRECTORY_SEPARATOR) !== 0) {
            wp_die('Invalid path', '', ['response' => 403]);
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $detected_mime = $finfo->file($real_path);
        if (!in_array($detected_mime, ['application/pdf', 'application/x-pdf'], true)) {
            wp_die('Not a PDF', '', ['response' => 400]);
        }

        header('Content-Type: application/pdf');
        header('X-Content-Type-Options: nosniff');
        header('Content-Disposition: inline; filename="verified.pdf"');
        header('Content-Length: ' . filesize($real_path));
        header('Cache-Control: no-store');
        readfile($real_path);
        exit;
    }
);

/**
 * Sanitizes HTML output from the PDF verifier using wp_kses,
 * with data-URI preservation.
 *
 * @param string $html Raw HTML to sanitize.
 *
 * @return string Sanitized HTML.
 */
function forge_sanitize_verifier_html(string $html): string
{

    $allowed = [
        // Layout & containers
        'div'    => ['class' => true, 'id' => true, 'style' => true, 'data-*' => true],
        'p'      => ['class' => true, 'style' => true, 'data-*' => true],
        'span'   => ['class' => true, 'style' => true, 'data-*' => true],
        'pre'    => ['class' => true, 'id' => true, 'style' => true, 'data-*' => true],
        'code'   => ['class' => true, 'style' => true, 'data-*' => true],

        // Buttons / interactivity
        'button' => ['class' => true, 'type' => true, 'data-*' => true, 'style' => true],

        // Lists
        'ul' => [], 'ol' => [], 'li' => [],

        // Tables
        'table' => ['class' => true, 'style' => true],
        'thead' => [], 'tbody' => [],
        'tr' => ['class' => true, 'data-target' => true, 'title' => true],
        'th' => ['class' => true, 'scope' => true], 'td' => ['class' => true, 'colspan' => true, 'rowspan' => true],

        // Formatting
        'strong' => [], 'em' => [], 'b' => [], 'i' => [], 'br' => [], 'hr' => [],

        // Images / SVG / media
        'img' => [
            'src' => true, 'alt' => true, 'class' => true, 'id' => true,
            'width' => true, 'height' => true, 'style' => true, 'data-*' => true,
        ],
        'svg' => ['class' => true, 'id' => true, 'style' => true, 'data-*' => true],
        'canvas' => ['class' => true, 'id' => true, 'style' => true, 'data-*' => true],
    ];

    // wp_kses uses regex internally and catastrophically fails on multi-MB strings
    // (e.g. base64-encoded image data URIs). Extract data URIs before sanitizing
    // and restore them afterwards — they are PHP-generated, not user-supplied.
    $data_uris = [];
    $html = preg_replace_callback(
        '/\bsrc=(["\'])data:[^"\']+\1/i',
        static function (array $m) use (&$data_uris): string {
            $key = '__FORGE_DATA_URI_' . count($data_uris) . '__';
            $data_uris[$key] = $m[0];
            return 'src=' . $m[1] . $key . $m[1];
        },
        $html
    );

    $html = wp_kses($html, $allowed);

    // Restore data URIs — replace the placeholder src attributes verbatim.
    foreach ($data_uris as $key => $original) {
        $html = str_replace('src="' . $key . '"', $original, $html);
        $html = str_replace("src='" . $key . "'", $original, $html);
    }

    return $html;
}


/**
 * Admin page for uploading and verifying PDF seal signatures.
 */
final class Verificationpage
{
    /**
     * Registers the verification page menu and suppresses admin notices.
     *
     * @return void
     */
    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'menu']);
        add_filter('admin_body_class', [self::class, 'bodyClass']);
        add_action(
            'in_admin_header',
            static function (): void {
                $screen = get_current_screen();
                if ($screen && $screen->id === 'forge-forms_page_forge-pdf-verification') {
                    remove_all_actions('admin_notices');
                    remove_all_actions('all_admin_notices');
                    remove_all_actions('user_admin_notices');
                    remove_all_actions('network_admin_notices');
                }
            }
        );
        add_action('forge_verifier_cleanup_files', [self::class, 'cronCleanupFiles']);

        // Fallback sweep: the per-file wp_schedule_single_event() cleanups above
        // depend on WP-Cron actually firing, which isn't guaranteed on every
        // deployment (DISABLE_WP_CRON, no real system cron, or a low-traffic
        // admin-only page that rarely gets the pseudo-cron triggered). Without
        // this, a missed single-event cleanup leaves the file in verfiles/ or
        // verimages/ forever. This recurring sweep is a safety net that simply
        // age-based-deletes anything older than self::SWEEP_MAX_AGE, independent
        // of whether the original single-event cleanup ever ran.
        add_action('forge_verifier_sweep_tmp_dirs', [self::class, 'cronSweepTmpDirs']);
        if (!wp_next_scheduled('forge_verifier_sweep_tmp_dirs')) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'hourly', 'forge_verifier_sweep_tmp_dirs');
        }
    }

    /**
     * Maximum age (seconds) a file may sit in the verifier temp directories
     * before the fallback sweep removes it, regardless of why the original
     * single-event cleanup didn't run. Comfortably above the longest
     * intentional single-event delay (600s) plus the up-to-300s verification
     * window that can still be reading the file.
     *
     * @var int
     */
    private const SWEEP_MAX_AGE = 3600;

    /**
     * WP-Cron callback (hourly): sweeps the verifier's temp directories for
     * any file older than self::SWEEP_MAX_AGE, as a fallback for sites where
     * WP-Cron doesn't reliably run the one-off cleanup events scheduled by
     * emitImageSlot()/scheduleDeletion().
     *
     * @return void
     */
    public static function cronSweepTmpDirs(): void
    {
        $upload_dir = wp_upload_dir();
        $safe_dir   = $upload_dir['basedir'] . '/forge-secure-pdf';
        $now        = time();

        foreach (['/verfiles', '/verimages'] as $sub) {
            $dir = $safe_dir . $sub;
            if (!is_dir($dir)) {
                continue;
            }
            foreach ((glob($dir . '/*') ?: []) as $file) {
                if (!is_file($file) || basename($file) === 'index.php') {
                    continue;
                }
                $mtime = @filemtime($file);
                if ($mtime !== false && ($now - $mtime) > self::SWEEP_MAX_AGE) {
                    if (!@unlink($file)) {
                        \ForgeForms\forge_log("ForgeForms Verificationpage: sweep failed to remove stale temp file {$file}");
                    }
                }
            }
        }
    }

    /**
     * WP-Cron callback that deletes temp verifier files after a delay.
     *
     * Runs on the cron schedule rather than blocking a live PHP-FPM worker
     * with a sleep() — a handful of concurrent PDF verifications previously
     * held a worker each for up to 120s, which could exhaust the worker pool.
     *
     * @param array<int, string> $files Absolute paths to delete.
     *
     * @return void
     */
    public static function cronCleanupFiles(array $files): void
    {
        foreach ($files as $file) {
            if (!is_string($file) || $file === '') {
                continue;
            }
            clearstatcache(true, $file);
            for ($i = 0; $i < 5; $i++) {
                if (!file_exists($file)) {
                    break;
                }
                if (@unlink($file)) {
                    break;
                }
                usleep(200000);
                clearstatcache(true, $file);
            }
        }
    }

    /**
     * Appends forge-verification-page body class on the verification page.
     *
     * @param string $classes Existing admin body classes.
     *
     * @return string Modified body class string.
     */
    public static function bodyClass(string $classes): string
    {
        if (isset($_GET['page']) && $_GET['page'] === 'forge-pdf-verification') {
            $classes .= ' forge-verification-page';
        }
        return $classes;
    }

    /**
     * Registers the PDF Verification submenu page.
     *
     * @return void
     */
    public static function menu(): void
    {
        if (\ForgeForms\Plugin::userCan('use_verifier')) {
            add_submenu_page(
                'forge-forms',
                __('FormForge Verification', 'form-forge'),
                __('PDF Verification', 'form-forge'),
                'read',
                'forge-pdf-verification',
                [self::class, 'render']
            );
        }
    }

    /**
     * Renders the PDF upload and verification results page.
     *
     * @return void
     */
    public static function render(): void
    {
        // menu() only registers this page's submenu for capable users, so an
        // unauthorized request is normally rejected by WP core before this
        // callback ever runs — but this function processes file uploads to
        // disk, so it checks again explicitly rather than depending solely on
        // admin_menu registration semantics holding across future refactors.
        if (!\ForgeForms\Plugin::userCan('use_verifier')) {
            wp_die(__('Insufficient permissions.', 'form-forge'), '', ['response' => 403]);
        }
        echo '<canvas id="forge-particle-canvas" aria-hidden="true"></canvas>';
        echo '<div class="wrap forge-verification-wrap">';
        echo '<div id="forge-verification-body">';

        // --- Handle POST uploads securely ---
        $is_request_post = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST';
        if ($is_request_post) {
            // Nonce is the unconditional first gate — before touching any $_FILES.
            if (!isset($_POST['forge_verifier_nonce'])
                || !check_admin_referer('forge_verifier_upload', 'forge_verifier_nonce')
            ) {
                wp_die('Security check failed', 'Error', ['response' => 403]);
            }
        }
        if ($is_request_post && !empty($_FILES['pdfs']['name'][0])) {
            // Process uploaded files
            $upload_dir = wp_upload_dir();
            $safe_dir   = $upload_dir['basedir'] . '/forge-secure-pdf';

            // Ensure directories exist with restricted permissions.
            foreach (['', '/verfiles', '/log'] as $sub) {
                $dir = $safe_dir . $sub;
                if (!is_dir($dir)) {
                    wp_mkdir_p($dir);
                    chmod($dir, 0750);
                    file_put_contents($dir . '/index.php', "<?php // Silence is golden ?>");
                    chmod($dir . '/index.php', 0640);
                }
            }

            // Block all direct HTTP access — .htaccess is the last line of defence.
            $htaccess = $safe_dir . '/.htaccess';
            if (!file_exists($htaccess)) {
                file_put_contents($htaccess, "Options -Indexes\nDeny from all\n");
                chmod($htaccess, 0640);
            }

            $verfiles_dir = $safe_dir . '/verfiles';

            $max_upload_bytes = 50 * 1024 * 1024; // 50 MB

            foreach ($_FILES['pdfs']['tmp_name'] as $key => $tmpName) {
                if (!is_readable($tmpName) || !is_uploaded_file($tmpName)) {
                    continue;
                }

                // File size guard — use the actual file on disk, not the browser-reported size.
                if (filesize($tmpName) > $max_upload_bytes) {
                    echo self::noticeHtml(__('Upload skipped: file exceeds 50 MB limit.', 'form-forge'), 'warning');
                    continue;
                }

                $original_name = (string) ($_FILES['pdfs']['name'][$key] ?? '');
                $type_check = wp_check_filetype_and_ext(
                    $tmpName,
                    $original_name,
                    ['pdf' => 'application/pdf']
                );

                if (($type_check['ext'] ?? '') !== 'pdf') {
                    echo self::noticeHtml(__('Upload skipped: only PDF files are allowed.', 'form-forge'), 'warning');
                    continue;
                }

                $finfo = new \finfo(FILEINFO_MIME_TYPE);
                $detected_mime = $finfo->file($tmpName);
                if (!in_array($detected_mime, ['application/pdf', 'application/x-pdf'], true)) {
                    echo self::noticeHtml(__('Upload skipped: MIME validation failed.', 'form-forge'), 'warning');
                    continue;
                }

                $safe_name = sanitize_file_name($original_name);

                if ($safe_name === '' || !preg_match('/\.pdf$/i', $safe_name)) {
                    echo self::noticeHtml(__('Upload skipped: invalid PDF filename.', 'form-forge'), 'warning');
                    continue;
                }

                // Always use a unique random prefix — never rely on time() for collision avoidance.
                $storage_name = bin2hex(random_bytes(8)) . '-' . $safe_name;
                $target_path  = $verfiles_dir . '/' . $storage_name;

                if (move_uploaded_file($tmpName, $target_path)) {
                    self::scheduleDeletion($target_path);

                    // Issue a short-lived transient token; JS uses the serve endpoint
                    // instead of the direct (now HTTP-blocked) verfiles URL.
                    $token = bin2hex(random_bytes(16));
                    set_transient('forge_pdf_' . $token, $target_path, 600); // 10 minutes

                    $serve_url = add_query_arg(
                        [
                        'action' => 'forge_serve_pdf',
                        'nonce'  => wp_create_nonce('forge_verifier_nonce'),
                        'token'  => $token,
                        ],
                        admin_url('admin-ajax.php')
                    );

                    $push_payload = wp_json_encode(
                        ['url' => esc_url_raw($serve_url), 'token' => $token, 'name' => $safe_name],
                        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
                    );
                    if ($push_payload === false) {
                        error_log('FF: wp_json_encode failed for push payload — skipping PDF.');
                        continue;
                    }
                    echo "<script>
                        console.log('PHP pushing PDF for verification');
                        window.FORGE_VERIFICATION_QUEUE = window.FORGE_VERIFICATION_QUEUE || [];
                        window.FORGE_VERIFICATION_QUEUE.push({$push_payload});
                        if (window.FORGE_VERIFICATION_PROCESS_PDF) {
                            window.FORGE_VERIFICATION_PROCESS_PDF({$push_payload});
                        }
                    </script>";
                }
            }
        }

        // --- Render drag-and-drop form with nonce ---
        echo '<style>
            /* move style before flex children so it is not a flex item */
            #forge-pdf-idle-state {
                display: flex;
                flex-direction: column;
                align-items: center;
                justify-content: center;
                flex: 1;
                min-height: 0;
                padding: 40px 20px;
            }
            #forge-pdf-idle-state .forge-pdf-idle-card,
            #forge-pdf-scan-more-backdrop .forge-pdf-idle-card {
                background: #fff;
                border: 1px solid #dcdcde;
                border-radius: 8px;
                padding: 48px 56px;
                text-align: center;
                max-width: 520px;
                width: 100%;
                box-shadow: 0 1px 3px rgba(0,0,0,.07);
            }
            #forge-pdf-idle-state h2 {
                font-size: 20px;
                font-weight: 600;
                margin: 0 0 8px;
                color: #1d2327;
            }
            #forge-pdf-idle-state > .forge-pdf-idle-card > p {
                color: #787c82;
                margin: 0 0 28px;
                font-size: 14px;
            }
            #drop-zone {
                border: 2px dashed #c3c4c7;
                border-radius: 6px;
                padding: 28px 20px;
                cursor: pointer;
                transition: border-color .15s, background .15s;
                background: #f6f7f7;
                color: #50575e;
                font-size: 14px;
                margin-bottom: 16px;
            }
            #drop-zone-more {
                border: 2px dashed #c3c4c7;
                border-radius: 6px;
                padding: 28px 20px;
                cursor: pointer;
                transition: border-color .15s, background .15s;
                background: #f6f7f7;
                color: #50575e;
                font-size: 14px;
                margin-bottom: 16px;
                text-align: center;
            }
            #drop-zone:hover, #drop-zone.forge-pdf-dragover,
            #drop-zone-more:hover, #drop-zone-more.forge-pdf-dragover {
                border-color: var(--forge-admin-accent);
                background: color-mix(in srgb, var(--forge-admin-accent) 8%, #fff);
                color: var(--forge-admin-accent);
            }
            #forge-pdf-file-queue,
            #forge-pdf-file-queue-more {
                list-style: none;
                margin: 0 0 16px;
                padding: 0;
                text-align: left;
            }
            #forge-pdf-file-queue li,
            #forge-pdf-file-queue-more li {
                display: flex;
                align-items: center;
                justify-content: space-between;
                padding: 6px 10px;
                border-radius: 4px;
                font-size: 13px;
                color: #1d2327;
                background: #f6f7f7;
                margin-bottom: 4px;
            }
            #forge-pdf-file-queue li:last-child,
            #forge-pdf-file-queue-more li:last-child { margin-bottom: 0; }
            #forge-pdf-file-queue .forge-pdf-remove-file,
            #forge-pdf-file-queue-more .forge-pdf-remove-file {
                background: none;
                border: none;
                cursor: pointer;
                color: #787c82;
                font-size: 16px;
                line-height: 1;
                padding: 0 2px;
            }
            #forge-pdf-file-queue .forge-pdf-remove-file:hover,
            #forge-pdf-file-queue-more .forge-pdf-remove-file:hover { color: #b32d2e; }

            /* ── Scan-more modal overlay ── */
            #forge-pdf-scan-more-backdrop {
                display: none;
                position: fixed;
                inset: 0;
                background: rgba(0,0,0,.45);
                z-index: 99998;
                align-items: center;
                justify-content: center;
            }
            #forge-pdf-scan-more-backdrop.forge-pdf-open {
                display: flex;
            }
            #forge-pdf-scan-more-backdrop .forge-pdf-idle-card {
                position: relative;
                max-width: 520px;
                width: calc(100% - 40px);
                animation: forge-pdf-modal-in .15s ease;
            }
            @keyframes forge-pdf-modal-in {
                from { opacity: 0; transform: translateY(-12px); }
                to   { opacity: 1; transform: translateY(0);     }
            }
            #forge-pdf-scan-more-close {
                position: absolute;
                top: 12px;
                right: 14px;
                background: none;
                border: none;
                font-size: 20px;
                line-height: 1;
                cursor: pointer;
                color: #787c82;
            }
            #forge-pdf-scan-more-close:hover { color: #1d2327; }

            /* ── Forge-coloured primary actions ── */
            #forge-pdf-verify-btn:not([disabled]) {
                background: var(--forge-admin-accent) !important;
                border-color: var(--forge-admin-accent) !important;
                color: var(--forge-accent-text, #fff) !important;
            }
            #forge-pdf-verify-btn:not([disabled]):hover {
                background: color-mix(in srgb, var(--forge-admin-accent) 82%, #000) !important;
                border-color: color-mix(in srgb, var(--forge-admin-accent) 82%, #000) !important;
            }

            /* ── Floating scan-more trigger ── */
            #forge-pdf-scan-more-btn {
                display: none;
                position: fixed;
                bottom: 28px;
                left: calc(50% + 80px); /* +80px centres in content area alongside 160px WP sidebar */
                transform: translateX(-50%);
                z-index: 99997;
                background: var(--forge-admin-accent);
                color: var(--forge-accent-text, #fff);
                border: none;
                border-radius: 8px;
                padding: 11px 22px;
                font-size: 13px;
                font-weight: 500;
                cursor: pointer;
                box-shadow: 0 4px 14px rgb(0 0 0 / 25%);
                letter-spacing: .01em;
            }
            #forge-pdf-scan-more-btn:hover { background: color-mix(in srgb, var(--forge-admin-accent) 82%, #000); }
            #forge-pdf-scan-more-btn.forge-pdf-visible { display: block; }

            /* ── Verification summary panel ── */
            .forge-pdf-summary-panel {
                border: 2px solid #c3c4c7;
                border-radius: 6px;
                overflow: hidden;
                margin-bottom: 14px;
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            }
            .forge-pdf-summary-verdict {
                padding: 11px 16px;
                font-size: 15px;
                font-weight: 700;
                letter-spacing: .01em;
            }
            .forge-pdf-verdict-pass       { background: #00a32a; color: #fff; }
            .forge-pdf-verdict-fail       { background: #b32d2e; color: #fff; }
            .forge-pdf-verdict-compromised { background: #d97706; color: #fff; }
            .forge-pdf-verdict-rotated    { background: #65a30d; color: #fff; }
            .forge-pdf-summary-table {
                width: 100%;
                border-collapse: collapse;
                background: #fff;
            }
            .forge-pdf-summary-table tr { border-bottom: 1px solid #f0f0f1; }
            .forge-pdf-summary-table tr:last-child { border-bottom: none; }
            .forge-pdf-chk-icon {
                width: 28px; padding: 7px 2px 7px 12px; font-size: 14px; vertical-align: middle;
            }
            .forge-pdf-chk-name {
                padding: 7px 6px; font-size: 13px; font-weight: 600;
                white-space: nowrap; vertical-align: middle;
            }
            .forge-pdf-chk-name a { text-decoration: none; color: inherit; }
            .forge-pdf-chk-name a:hover { text-decoration: underline; }
            .forge-pdf-chk-reason {
                padding: 7px 12px 7px 4px; font-size: 12px; color: #50575e; vertical-align: middle;
            }
            .forge-pdf-chk-pass .forge-pdf-chk-icon { color: #00a32a; }
            .forge-pdf-chk-fail .forge-pdf-chk-icon { color: #b32d2e; }
            .forge-pdf-chk-fail .forge-pdf-chk-reason { color: #b32d2e; font-weight: 500; }
            .forge-pdf-chk-warn .forge-pdf-chk-icon { color: #996800; }
            .forge-pdf-chk-warn .forge-pdf-chk-reason { color: #996800; }

            /* ── Detail section wrappers ── */
            .forge-pdf-detail-section { margin: 6px 0; }
            .forge-pdf-detail-hdr { display: none; }
            .forge-pdf-detail-badge {
                font-size: 10px; font-weight: 700; padding: 2px 6px;
                border-radius: 3px; white-space: nowrap; line-height: 1.6;
            }
            .forge-pdf-badge-pass { background: #d6f5df; color: #00a32a; }
            .forge-pdf-badge-fail { background: #fce8e8; color: #b32d2e; }
            .forge-pdf-badge-info { background: #f0f0f1; color: #50575e; }
            /* summary panel check icons */
            .forge-pdf-summary-table th, .forge-pdf-summary-table td {
                padding: 7px 10px; vertical-align: middle; font-size: 13px;
            }
            .forge-pdf-summary-table th:first-child, .forge-pdf-summary-table td:first-child {
                width: 28px; font-size: 15px; text-align: center;
            }
            .forge-pdf-summary-table th:nth-child(2), .forge-pdf-summary-table td:nth-child(2) { width: 220px; }
            .forge-pdf-summary-table thead th { font-weight: 600; border-bottom: 2px solid #e0e0e0; }
            span.forge-pdf-chk-pass     { color: #00a32a; font-weight: 700; }
            span.forge-pdf-chk-fail     { color: #b32d2e; font-weight: 700; }
            span.forge-pdf-chk-warn     { color: #996800; font-weight: 700; }
            span.forge-pdf-chk-rotated  { color: #65a30d; font-weight: 700; }
            /* clickable summary rows */
            tr.forge-pdf-summary-row { cursor: pointer; transition: background .1s; }
            tr.forge-pdf-summary-row:hover { background: #f0f6fc; }
            .forge-pdf-row-ok         { color: #00a32a; font-weight: 600; }
            .forge-pdf-row-fail       { color: #b32d2e; font-weight: 600; }
            .forge-pdf-row-warn       { color: #d97706; font-weight: 600; }
            .forge-pdf-row-rotated    { color: #65a30d; font-weight: 600; }
            .forge-pdf-verdict-legacy { background: #1a56db; color: #fff; }
            .forge-pdf-row-caret-cell { width: 20px; text-align: right; color: #787c82; }
            .forge-pdf-row-caret { font-size: 18px; line-height: 1; transition: transform .15s; }
            tr.forge-pdf-summary-row:hover .forge-pdf-row-caret { color: #2271b1; }
        </style>';

        echo '<form id="pdf-upload-form" method="post" enctype="multipart/form-data">';
        wp_nonce_field('forge_verifier_upload', 'forge_verifier_nonce');
        $idle_style   = $is_request_post ? ' style="display:none"' : '';
        $scanmore_cls = $is_request_post ? ' class="forge-pdf-visible"' : '';
        echo '
        <div id="forge-pdf-idle-state"' . $idle_style . '>
            <div class="forge-pdf-idle-card">
                <h2>' . esc_html__('PDF Verification', 'form-forge') . '</h2>
                <p>' . esc_html__('Upload one or more generated PDFs to verify their embedded seal and check for tampering.', 'form-forge') . '</p>
                <div id="drop-zone">
                    ' . esc_html__('Drag & drop PDF files here', 'form-forge') . '<br>
                    <small style="opacity:.75">' . esc_html__('or click to select', 'form-forge') . '</small>
                </div>
                <input type="file" name="pdfs[]" id="pdf-input"
                    accept="application/pdf" multiple style="display:none;">
                <ul id="forge-pdf-file-queue"></ul>
                <button id="forge-pdf-verify-btn" class="button button-primary"
                    type="submit" style="width:100%;justify-content:center;" disabled>' . esc_html__('Verify PDFs', 'form-forge') . '</button>
            </div>
        </div>
        </form>

        <div id="forge-pdf-scan-more-backdrop">
            <div class="forge-pdf-idle-card">
                <button id="forge-pdf-scan-more-close" title="' . esc_attr__('Close', 'form-forge') . '">&times;</button>
                <h2>' . esc_html__('Scan more PDFs', 'form-forge') . '</h2>
                <p>' . esc_html__('Add more PDFs to verify.', 'form-forge') . '</p>
                <div id="drop-zone-more">
                    ' . esc_html__('Drag & drop PDF files here', 'form-forge') . '<br>
                    <small style="opacity:.75">' . esc_html__('or click to select', 'form-forge') . '</small>
                </div>
                <input type="file" id="pdf-input-more" accept="application/pdf" multiple style="display:none;">
                <ul id="forge-pdf-file-queue-more"></ul>
                <button id="forge-pdf-verify-more-btn" class="button button-primary"
                    style="width:100%;justify-content:center;" disabled>' . esc_html__('Verify PDFs', 'form-forge') . '</button>
            </div>
        </div>

        <button id="forge-pdf-scan-more-btn" type="button"' . $scanmore_cls . '>+ ' . esc_html__('Scan more PDFs', 'form-forge') . '</button>

        <div id="forge-pdf-verification-results"></div>
        ';

        // --- JS drag & drop + file queue with remove ---
        echo '<script>
        const dropZone   = document.getElementById("drop-zone");
        const fileInput  = document.getElementById("pdf-input");
        const fileQueue  = document.getElementById("forge-pdf-file-queue");
        const verifyBtn  = document.getElementById("forge-pdf-verify-btn");

        let stagedFiles = [];

        function mergeFiles(incoming) {
            const names = new Set(stagedFiles.map(f => f.name));
            Array.from(incoming).forEach(f => { if (!names.has(f.name)) stagedFiles.push(f); });
            rebuildInput();
            renderQueue();
        }

        function removeFile(name) {
            stagedFiles = stagedFiles.filter(f => f.name !== name);
            rebuildInput();
            renderQueue();
        }

        function rebuildInput() {
            const dt = new DataTransfer();
            stagedFiles.forEach(f => dt.items.add(f));
            fileInput.files = dt.files;
        }

        function renderQueue() {
            fileQueue.innerHTML = "";
            stagedFiles.forEach(function(f) {
                const li   = document.createElement("li");
                const name = document.createElement("span");
                name.textContent = f.name;
                const btn  = document.createElement("button");
                btn.type      = "button";
                btn.className = "forge-pdf-remove-file";
                btn.title     = "' . esc_js(__('Remove', 'form-forge')) . '";
                btn.textContent = "×";
                btn.addEventListener("click", () => removeFile(f.name));
                li.appendChild(name);
                li.appendChild(btn);
                fileQueue.appendChild(li);
            });
            verifyBtn.disabled = stagedFiles.length === 0;
        }

        dropZone.addEventListener("click", () => fileInput.click());

        dropZone.addEventListener("dragover", (e) => {
            e.preventDefault();
            dropZone.classList.add("forge-pdf-dragover");
        });

        dropZone.addEventListener("dragleave", (e) => {
            e.preventDefault();
            dropZone.classList.remove("forge-pdf-dragover");
        });

        dropZone.addEventListener("drop", (e) => {
            e.preventDefault();
            dropZone.classList.remove("forge-pdf-dragover");
            mergeFiles(e.dataTransfer.files);
        });

        fileInput.addEventListener("change", () => mergeFiles(fileInput.files));

        document.getElementById("pdf-upload-form").addEventListener("submit", () => {
            document.getElementById("forge-pdf-idle-state").style.display = "none";
            document.getElementById("forge-pdf-scan-more-btn").classList.add("forge-pdf-visible");
        });

        // ── Scan-more modal ──
        const backdrop      = document.getElementById("forge-pdf-scan-more-backdrop");
        const scanMoreBtn   = document.getElementById("forge-pdf-scan-more-btn");
        const dropZoneMore  = document.getElementById("drop-zone-more");
        const fileInputMore = document.getElementById("pdf-input-more");
        const fileQueueMore = document.getElementById("forge-pdf-file-queue-more");
        const verifyMoreBtn = document.getElementById("forge-pdf-verify-more-btn");

        let stagedFilesMore = [];

        function openScanMore() { backdrop.classList.add("forge-pdf-open"); }
        function closeScanMore() {
            backdrop.classList.remove("forge-pdf-open");
            stagedFilesMore = [];
            renderQueueMore();
        }

        scanMoreBtn.addEventListener("click", openScanMore);
        document.getElementById("forge-pdf-scan-more-close").addEventListener("click", closeScanMore);
        backdrop.addEventListener("click", (e) => { if (e.target === backdrop) closeScanMore(); });

        function mergeFilesMore(incoming) {
            const names = new Set(stagedFilesMore.map(f => f.name));
            Array.from(incoming).forEach(f => { if (!names.has(f.name)) stagedFilesMore.push(f); });
            renderQueueMore();
        }

        function removeFileMore(name) {
            stagedFilesMore = stagedFilesMore.filter(f => f.name !== name);
            renderQueueMore();
        }

        function renderQueueMore() {
            fileQueueMore.innerHTML = "";
            stagedFilesMore.forEach(function(f) {
                const li   = document.createElement("li");
                const name = document.createElement("span");
                name.textContent = f.name;
                const btn  = document.createElement("button");
                btn.type = "button"; btn.className = "forge-pdf-remove-file";
                btn.title = "' . esc_js(__('Remove', 'form-forge')) . '"; btn.textContent = "×";
                btn.addEventListener("click", () => removeFileMore(f.name));
                li.appendChild(name); li.appendChild(btn);
                fileQueueMore.appendChild(li);
            });
            verifyMoreBtn.disabled = stagedFilesMore.length === 0;
        }

        dropZoneMore.addEventListener("click", () => fileInputMore.click());
        dropZoneMore.addEventListener("dragover",  (e) => {
            e.preventDefault(); dropZoneMore.classList.add("forge-pdf-dragover");
        });
        dropZoneMore.addEventListener("dragleave", (e) => {
            e.preventDefault(); dropZoneMore.classList.remove("forge-pdf-dragover");
        });
        dropZoneMore.addEventListener("drop", (e) => {
            e.preventDefault();
            dropZoneMore.classList.remove("forge-pdf-dragover"); mergeFilesMore(e.dataTransfer.files);
        });
        fileInputMore.addEventListener("change", () => mergeFilesMore(fileInputMore.files));

        verifyMoreBtn.addEventListener("click", () => {
            if (!stagedFilesMore.length) return;
            // Inject files into the main form and submit
            const dt = new DataTransfer();
            stagedFilesMore.forEach(f => dt.items.add(f));
            fileInput.files = dt.files;
            closeScanMore();
            document.getElementById("forge-pdf-scan-more-btn").classList.remove("forge-pdf-visible");
            document.getElementById("pdf-upload-form").submit();
        });
        </script>';

        echo '</div></div>'; // #forge-verification-body + .forge-verification-wrap

        echo <<<JS
        <script>
        (function () {
            if (window.FORGE_PDF_IMAGE_TOGGLE_READY) return;
            window.FORGE_PDF_IMAGE_TOGGLE_READY = true;

            document.addEventListener('click', function (e) {
                const btn = e.target.closest('.forge-pdf-toggle');
                if (!btn) return;

                e.preventDefault();

                const id = btn.getAttribute('data-target');
                if (!id) return;

                const el = document.getElementById(id);
                if (!el) return;

                const isHidden = el.classList.contains('forge-pdf-hidden');
                el.classList.toggle('forge-pdf-hidden', !isHidden);
                el.classList.toggle('forge-pdf-visible', isHidden);

                // Rotate arrow on sub-toggle buttons.
                btn.classList.toggle('forge-pdf-open', isHidden);

                // Show or hide the parent section wrapper to match content visibility.
                const section = el.closest('.forge-pdf-detail-section');
                if (section) {
                    if (isHidden) {
                        section.style.display = 'block';
                    } else {
                        // Only hide the section if no other content inside is still open.
                        const stillOpen = section.querySelector(
                            '.forge-pdf-detail-content:not(.forge-pdf-hidden), .forge-pdf-visible'
                        );
                        if (!stillOpen) {
                            section.style.display = 'none';
                        }
                    }
                }
            });

            // Reveal any section whose content was auto-opened in PHP (e.g. FAIL state).
            document.querySelectorAll('.forge-pdf-detail-section').forEach(function (sec) {
                const content = sec.querySelector('.forge-pdf-detail-content');
                if (content && !content.classList.contains('forge-pdf-hidden')) {
                    sec.style.display = 'block';
                }
            });
        })();
        </script>
        JS;

        echo <<<JS
        <script>
        (function () {
            if (window.FORGE_PDF_IMAGE_SLOT_READY) return;
            window.FORGE_PDF_IMAGE_SLOT_READY = true;

            function processImageSlots(root) {
                root = root || document;

                const blocks = root.querySelectorAll('.img-slot-content');
                if (!blocks.length) return;

                console.group('[FF] Processing image slots');

                blocks.forEach(block => {
                    const uid = block.dataset.slot;
                    const slot = document.getElementById(uid);

                    if (!slot) {
                        console.warn('[FF] Slot not found for', uid);
                        return;
                    }

                    slot.innerHTML = '';
                    slot.appendChild(block);

                    // Start hidden but layout-safe
                    slot.classList.add('forge-pdf-hidden');

                    // Ensure images trigger reflow when loaded
                    slot.querySelectorAll('img').forEach(img => {
                        if (!img.complete) {
                            img.onload = () => img.style.height = 'auto';
                            img.onerror = () =>
                                console.warn('[FF] Image failed:', img.src);
                        }
                    });
                });

                console.groupEnd();
            }

            document.addEventListener('DOMContentLoaded', () => {
                processImageSlots(document);
            });

            // Expose for AJAX
            window.FORGE_PDF_processImageSlots = processImageSlots;
        })();
        </script>
        JS;

        echo '<style>
        /* PDF segment header */
        .forge-pdf-pdf-hdr {
            display: flex;
            align-items: center;
            gap: 8px;
            overflow: hidden;
            width: 100%;
        }
        .forge-pdf-pdf-hdr-name {
            flex: 1;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            min-width: 0;
        }
        .forge-pdf-pdf-hdr-verdict {
            flex-shrink: 0;
            white-space: nowrap;
        }

        /* Layout-safe hidden state: participate in layout but invisible */
        .forge-pdf-hidden {
            display: none;
            visibility: hidden;
            max-height: 0;
            overflow: hidden;
        }

        /* Visible state */
        .forge-pdf-visible {
            display: block;
            visibility: visible;
            max-height: none;
            overflow: visible;
        }

        /* Ensure slots take full width and include all children */
        .img-slot, .img-slot-content {
            width: 100%;
            box-sizing: border-box;
        }

        /* Ensure the container wraps all children correctly */
        .img-slot-content > * {
            display: block;
            width: 100%;
        }
        </style>';
    }

    private static array $image_slots = [];
    private static bool $contains_background_images = false;
    private static array $files_to_delete = [];
    private static array $pdfs_to_delete = [];
    private static bool $image_cleanup_registered = false;
    private static bool $pdf_cleanup_registered = false;
    private static string $progressKey = '';

    /**
     * Stores upload verification progress in a transient.
     *
     * @param string $step Current progress step label.
     * @param int    $pct  Progress percentage (0-100).
     *
     * @return void
     */
    private static function setProgress(string $step, int $pct): void
    {
        if (self::$progressKey === '') {
            return;
        }
        set_transient('forge_vp_' . self::$progressKey, ['step' => $step, 'pct' => $pct], 120);
    }

    /**
     * Processes a single uploaded PDF file and outputs verification results HTML.
     *
     * @param array  $file        Uploaded file data from $_FILES.
     * @param array  $visualLines Lines of text extracted for visual display.
     * @param string $progressKey Transient key for progress reporting.
     *
     * @return void
     */
    public static function handleUpload(array $file, array $visualLines = [], string $progressKey = ''): void
    {
        self::$progressKey = $progressKey;
        $file_name = sanitize_file_name((string) ($file['name'] ?? 'document.pdf'));
        static $upload_id_counter = 0;
        $uid_prefix = 'forge-pdf-' . (++$upload_id_counter) . '-' . substr(md5($file_name), 0, 8);

        if ($file['error'] !== UPLOAD_ERR_OK) {
            echo self::noticeHtml(sprintf(__('Upload failed for %s.', 'form-forge'), esc_html($file_name)), 'error');
            return;
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $detected_mime = $finfo->file($file['tmp_name']);
        if (!in_array($detected_mime, ['application/pdf', 'application/x-pdf'], true)) {
            echo self::noticeHtml(sprintf(__('Invalid file type for %s.', 'form-forge'), esc_html($file_name)), 'error');
            return;
        }

        // --- Store visual lines if provided ---
        $upload_dir   = wp_upload_dir();
        $safe_dir     = $upload_dir['basedir'] . '/forge-secure-pdf';
        $log_dir      = $safe_dir . '/log';

        foreach (['', '/log'] as $sub) {
            $dir = $safe_dir . $sub;
            if (!is_dir($dir)) {
                wp_mkdir_p($dir);
                chmod($dir, 0750);
                file_put_contents($dir . '/index.php', "<?php // Silence is golden ?>");
                chmod($dir . '/index.php', 0640);
            }
        }

        $htaccess = $safe_dir . '/.htaccess';
        if (!file_exists($htaccess)) {
            file_put_contents($htaccess, "Options -Indexes\nDeny from all\n");
            chmod($htaccess, 0640);
        }

        // $visualLines is already available as a parameter — no disk round-trip needed.

        self::setProgress(__('Byte scan: searching for seal…', 'form-forge'), 5);

        // Incremental-update / shadow-attack guard: a legitimately generated PDF
        // has exactly one %%EOF marker.  A second %%EOF signals that new objects
        // were appended after the original cross-reference table — a classic
        // technique to alter visible content while leaving the original seal intact.
        $raw_for_guard = @file_get_contents($file['tmp_name']);
        if ($raw_for_guard === false) {
            echo self::noticeHtml(sprintf(__('Could not read PDF file: %s.', 'form-forge'), esc_html($file_name)), 'error');
            return;
        }
        $eof_count                    = substr_count($raw_for_guard, '%%EOF');
        $incremental_update_detected  = $eof_count > 1;
        $incremental_update_eof_count = $eof_count;
        // Count seal markers in uncompressed (plain-text) parts of the raw bytes.
        // FlateDecode streams are handled by the pdfparser pass; this catches fakes
        // injected into uncompressed streams or appended raw text.
        $raw_plain_seal_count         = substr_count($raw_for_guard, '---BEGIN-SEAL---');
        unset($raw_for_guard);

        // Raw-byte preflight: scan compressed streams for the seal marker without
        // loading the full PDF object graph. Avoids calling pdfparser (and its
        // memory overhead) entirely for PDFs that have no forge seal.
        if (!self::rawPdfHasSeal($file['tmp_name'])) {
            echo self::noticeHtml(
                sprintf(__('%s does not contain a forge-pdf seal and cannot be verified.', 'form-forge'), esc_html($file_name)), // phpcs:ignore Generic.Files.LineLength
                'error'
            );
            return;
        }

        self::setProgress(__('Parsing PDF…', 'form-forge'), 10);

        $document_modified = null;
        ob_start();
        $outer_ob_level = ob_get_level();
        try {
            // $incremental_update_detected is set before the try block — make it available inside.
            $incremental_update_detected = $incremental_update_detected ?? false;
            $parser = new Parser();
            $pdf = $parser->parseFile($file['tmp_name']);
            $text = $pdf->getText();

            // --- Extract Seal (exactly one allowed) ---
            $seal_count = preg_match_all('/---BEGIN-SEAL---(.*?)---END-SEAL---/s', $text, $matches);
            if ($seal_count === 0) {
                throw new \RuntimeException("Seal not found in {$file_name}.");
            }
            // Multiple seal blocks: continue using the last one so all other checks
            // can still run, but record the violation so the panel shows it.
            // Also flag if raw bytes contained seal markers outside compressed streams
            // (e.g. a fake seal injected into a plain uncompressed stream object).
            $multiple_seals_detected = $seal_count > 1
                || ($raw_plain_seal_count ?? 0) > 0;

            // Save now — $matches will be overwritten by later preg_match_all calls.
            $text_seal_b64_list = array_values(
                array_filter(
                    array_map('trim', $matches[1] ?? []),
                    fn($s) => $s !== ''
                )
            );

            $seal_base64 = trim($multiple_seals_detected ? end($matches[1]) : $matches[1][0]);

            if (strlen($seal_base64) > 65536) {
                throw new \RuntimeException("Seal is implausibly large in {$file_name}.");
            }

            $seal_json = base64_decode($seal_base64, true);
            if ($seal_json === false || strlen($seal_json) < 2) {
                throw new \RuntimeException("Base64 decode of seal failed for {$file_name}.");
            }

            $seal_data = json_decode($seal_json, true, 512, JSON_THROW_ON_ERROR);

            self::setProgress(__('Seal found — reconstructing payload…', 'form-forge'), 25);

            // --- Rebuild payload ---
            $rebuilt_payload = self::rebuildPayload($seal_data);

            self::setProgress(__('HMAC check…', 'form-forge'), 35);

            // --- HMAC check (early, before any output, so it's available for the summary) ---
            $seal_result      = HashSeal::verify($rebuilt_payload, $seal_data['seal']);
            $seal_matches     = $seal_result['valid'];
            $seal_key_status  = $seal_result['key_status'];
            $seal_compromised = $seal_result['compromised'];

            // --- Seal vs rebuilt diff ---
            $original_payload = $seal_data;
            unset($original_payload['seal']);
            $diffs = self::diffArrays($original_payload, $rebuilt_payload);
            $seal_rebuilt_match = empty($diffs);

            // ---- Start inner buffer: all detail HTML goes here so the summary panel can be prepended ----
            ob_start();

            // --- Structure integrity section (only present when incremental update detected) ---
            if ($incremental_update_detected) {
                $struct_section_id = 'forge-pdf-content-structure-' . $uid_prefix;
                $struct_sec_attr = esc_attr($uid_prefix);
                echo "<div class='forge-pdf-detail-section'"
                   . " id='forge-pdf-section-structure-{$struct_sec_attr}'>";
                echo "<div class='forge-pdf-detail-hdr'>";
                echo "<button type='button' class='button button-small forge-pdf-toggle'"
                   . " data-target='" . esc_attr($struct_section_id) . "'>" . esc_html__('PDF Structure', 'form-forge') . "</button>";
                echo "<span class='forge-pdf-detail-badge forge-pdf-badge-fail'>FAIL</span>";
                echo "</div>";
                $eof_n_disp = (int) $incremental_update_eof_count;
                echo "<div id='" . esc_attr($struct_section_id) . "'"
                   . " class='forge-pdf-hidden forge-pdf-detail-content'>";
                echo "<div class='forge-pdf-hash-list'>";
                echo "<div class='forge-pdf-hash-row forge-pdf-hash-row--fail'>";
                echo "<span class='forge-pdf-hash-label'>%%EOF count</span>";
                echo "<span class='forge-pdf-hash-value'>{$eof_n_disp} (expected: 1)</span>";
                echo "<span class='forge-pdf-pill forge-pdf-pill--fail'>FAIL</span>";
                echo "</div>";
                echo "<p style='margin:10px 14px 8px;font-size:12px;color:#444;line-height:1.6'>";
                echo "A valid PDF has exactly <strong>one</strong> <code>%%%%EOF</code> marker. ";
                echo "Each extra marker signals that content was <strong>appended after the original ";
                echo "cross-reference table</strong> was written. This is the standard technique for a ";
                echo "<strong>PDF shadow attack / incremental update</strong>: an attacker appends new ";
                echo "objects that override visible content while leaving the original seal intact.";
                echo "</p>";
                echo "</div>";
                echo "</div>";
                echo "</div>";
            }

            // --- Raw debug: Seal Data + Rebuilt Payload (info-only, always collapsed) ---
            $seal_id    = sanitize_html_class($uid_prefix . '-seal');
            $rebuilt_id = sanitize_html_class($uid_prefix . '-rebuilt');
            echo "<div class='forge-pdf-detail-section' id='forge-pdf-section-raw-" . esc_attr($uid_prefix) . "'>";
            echo "<div class='forge-pdf-detail-hdr'>";
            echo "<button type='button' class='button button-small forge-pdf-toggle'"
               . " data-target='" . esc_attr($uid_prefix) . "-raw-content'>" . esc_html__('Raw Seal & Rebuilt Data', 'form-forge') . "</button>";
            echo "<span class='forge-pdf-detail-badge forge-pdf-badge-info'>INFO</span>";
            echo "</div>";
            $flags      = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE;
            $json_seal  = esc_html((string) wp_json_encode($seal_data, $flags));
            $json_built = esc_html((string) wp_json_encode($rebuilt_payload, $flags));

            $raw_id = esc_attr($uid_prefix) . '-raw-content';
            echo "<div id='{$raw_id}' class='forge-pdf-hidden forge-pdf-detail-content' style='padding:0;'>";

            // Column headers — outside the scroll container so they stay fixed
            echo "<div class='forge-pdf-sbs-headers'>";
            echo "<div class='forge-pdf-sbs-col-label'>" . esc_html__('Seal', 'form-forge') . "</div>";
            echo "<div class='forge-pdf-sbs-col-label'>" . esc_html__('Rebuilt', 'form-forge') . "</div>";
            echo "</div>";

            // Single scrollable container — one scroll event, both columns move together
            echo "<div class='forge-pdf-sbs-scroll'>";
            echo "<pre class='forge-pdf-sbs-pre'>{$json_seal}</pre>";
            echo "<div class='forge-pdf-sbs-divider'></div>";
            echo "<pre class='forge-pdf-sbs-pre'>{$json_built}</pre>";
            echo "</div>";

            if (!$seal_rebuilt_match) {
                $json_diff = esc_html((string) wp_json_encode($diffs, $flags));
                echo "<div style='padding:12px 14px;border-top:1px solid #f5c6cb;'>";
                echo "<div class='forge-pdf-seal-pane__label'"
                    . " style='color:#721c24;margin-bottom:4px;'>" . esc_html__('Differences', 'form-forge') . "</div>";
                echo "<pre class='forge-pdf-json-pre'"
                    . " style='border-color:#f5c6cb;color:#721c24;margin:0;'>{$json_diff}</pre>";
                echo "</div>";
            }
            echo "</div></div>";

            // --- Visual content check using invisible field markers ---
            $visual_mismatch_found = false;
            $field_mismatch_count  = 0;

            $normalize = function (string $s): string {
                // NFKC normalization collapses Unicode lookalikes (Cyrillic а→a,
                // fullwidth digits, ligatures, etc.) to their canonical ASCII forms.
                if (class_exists('Normalizer')) {
                    $n = \Normalizer::normalize($s, \Normalizer::NFKC);
                    if ($n !== false) {
                        $s = $n;
                    }
                }
                $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                // Expand common OpenType ligatures substituted by mPDF (U+FB00–FB06)
                $lig_from = ["\xEF\xAC\x80", "\xEF\xAC\x81", "\xEF\xAC\x82",
                             "\xEF\xAC\x83", "\xEF\xAC\x84", "\xEF\xAC\x85", "\xEF\xAC\x86"];
                $lig_to   = ['ff', 'fi', 'fl', 'ffi', 'ffl', 'st', 'st'];
                $s = str_replace($lig_from, $lig_to, $s);
                $s = preg_replace('/[\x00-\x1F\x7F]/u', '', $s); // remove control chars
                $s = str_replace(["\xC2\xA0", "\xAD"], ' ', $s); // NBSP + soft hyphen
                $s = preg_replace('/\s+/u', ' ', $s);            // normalize whitespace
                $s = preg_replace('/([,;])\s*/u', '$1 ', $s);    // one space after , and ;

                return trim($s);
            };

            // Normalize full PDF text
            $normalized_pdf = $normalize($text);
            $normalized_pdf = preg_replace(
                '/\[FORGE_PDF_PAGENO_START\].*?\[FORGE_PDF_PAGENO_END\]/s',
                '',
                $normalized_pdf
            );
            $fields = $rebuilt_payload['fields'] ?? [];

            self::setProgress(__('Checking fields…', 'form-forge'), 45);

            // Section wrapper — badge + open-state injected after check runs via post-processing
            $all_visual_id = 'forge-pdf-content-fields-' . $uid_prefix;
            echo "<div class='forge-pdf-detail-section' id='forge-pdf-section-fields-" . esc_attr($uid_prefix) . "'>";
            echo "<div class='forge-pdf-detail-hdr' id='forge-pdf-hdr-fields-" . esc_attr($uid_prefix) . "'>";
            echo "<button type='button' class='button button-small forge-pdf-toggle'"
               . " data-target='" . esc_attr($all_visual_id) . "'>" . esc_html__('Field Content', 'form-forge') . "</button>";
            echo "<span id='forge-pdf-badge-fields-" . esc_attr($uid_prefix)
               . "' class='forge-pdf-detail-badge forge-pdf-badge-pass'>PASS</span>";
            echo "</div>";
            echo "<div id='" . esc_attr($all_visual_id) . "' class='forge-pdf-hidden forge-pdf-detail-content'>";
            echo "<div class='forge-pdf-cmp-list'>";

            // Track processed array entries and start markers
            $processed_fields = [];
            $processed_markers = [];

            do {
                $new_start_found = false;

                $field_pattern = '/\[FORGE_PDF_FIELD_([^\]]+)\](.*?)\[FORGE_PDF_FIELD_END\]/s';
                if (preg_match_all($field_pattern, $normalized_pdf, $matches, PREG_SET_ORDER)) {
                    foreach ($matches as $match) {
                        $start_marker = $match[1];
                        $pdf_field_text = $normalize($match[2]);

                        // Skip already processed markers
                        if (in_array($start_marker, $processed_markers, true)) {
                            continue;
                        }

                        // Find next unprocessed field in the payload
                        $payload_index = null;
                        foreach ($fields as $i => $f) {
                            if (!in_array($i, $processed_fields, true)) {
                                $payload_index = $i;
                                break;
                            }
                        }

                        if ($payload_index === null) {
                            echo "<div class='forge-pdf-cmp-row forge-pdf-cmp-row--fail'>"
                               . "<div class='forge-pdf-cmp-header'>"
                               . "<span class='forge-pdf-cmp-label'>" . esc_html__('Unknown field', 'form-forge') . "</span>"
                               . "<span class='forge-pdf-cmp-marker'>" . esc_html($start_marker) . "</span>"
                               . "<span class='forge-pdf-pill forge-pdf-pill--fail'>" . esc_html__('NOT IN SEAL', 'form-forge') . "</span>"
                               . "</div></div>\n";
                            $processed_markers[] = $start_marker;
                            $new_start_found = true;
                            continue;
                        }

                        $payload_field = $fields[$payload_index];
                        $expected_text = $normalize($payload_field['value'] ?? '');

                        // Remove all zero-width spaces, trim, and normalize whitespace again
                        $pdf_field_text = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $pdf_field_text);
                        $expected_text = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $expected_text);

                        $repair_missing_spaces_strict = function (string $pdf, string $canonical): ?string {
                            $p = 0;
                            $c = 0;
                            $out = '';

                            $pdf_len = mb_strlen($pdf);
                            $can_len = mb_strlen($canonical);

                            while ($p < $pdf_len && $c < $can_len) {
                                $pdf_ch = mb_substr($pdf, $p, 1);
                                $can_ch = mb_substr($canonical, $c, 1);

                                // Exact match
                                if ($pdf_ch === $can_ch) {
                                    $out .= $pdf_ch;
                                    $p++;
                                    $c++;
                                    continue;
                                }

                                // Canonical has space, PDF lost it → allow ONE thing
                                if ($can_ch === ' ' && $pdf_ch !== ' ') {
                                    $out .= ' ';
                                    $c++;
                                    continue;
                                }

                                // Anything else is a real mismatch
                                return null;
                            }

                            // Canonical must be fully consumed; extra trailing PDF text (layout bleed) is tolerated
                            if (trim(mb_substr($canonical, $c)) !== '') {
                                return null;
                            }

                            return $out;
                        };

                        $repaired_pdf = $repair_missing_spaces_strict(
                            $pdf_field_text,
                            $expected_text
                        );

                        if ($repaired_pdf === null) {
                            $matches_visual = false;
                        } else {
                            $matches_visual = ($repaired_pdf === $expected_text);
                            $pdf_field_text = $repaired_pdf; // IMPORTANT: for printing
                        }

                        if (!$matches_visual) {
                            $visual_mismatch_found = true;
                            $field_mismatch_count++;
                        }

                        // Display results as comparison card
                        $label      = $payload_field['label'] ?? '';
                        $row_state  = $matches_visual ? 'pass' : 'fail';
                        $pill_state = $matches_visual ? 'pass' : 'fail';
                        $pill_text  = $matches_visual ? __('MATCH', 'form-forge') : __('MISMATCH', 'form-forge');
                        $display_label = $label !== '' ? esc_html($label) : 'Field #' . (int) $payload_index;

                        echo "<div class='forge-pdf-cmp-row forge-pdf-cmp-row--{$row_state}'>";
                        echo "<div class='forge-pdf-cmp-header'>"
                           . "<span class='forge-pdf-cmp-label'>{$display_label}</span>"
                           . "<span class='forge-pdf-cmp-marker'>" . esc_html($start_marker) . "</span>"
                           . "<span class='forge-pdf-pill forge-pdf-pill--{$pill_state}'>{$pill_text}</span>"
                           . "</div>";
                        echo "<div class='forge-pdf-cmp-body'>";
                        $seal_val = esc_html((string) ($payload_field['value'] ?? ''));
                        echo "<div class='forge-pdf-cmp-col'>"
                           . "<div class='forge-pdf-cmp-col__label'>" . esc_html__('Seal', 'form-forge') . "</div>"
                           . "<div class='forge-pdf-cmp-col__value'>{$seal_val}</div>"
                           . "</div>";
                        echo "<div class='forge-pdf-cmp-col'>"
                           . "<div class='forge-pdf-cmp-col__label'>" . esc_html__('PDF', 'form-forge') . "</div>"
                           . "<div class='forge-pdf-cmp-col__value'>" . esc_html((string) $pdf_field_text) . "</div>"
                           . "</div>";
                        echo "</div>"; // forge-pdf-cmp-body

                        if (!$matches_visual) {
                            $diff_parts = [];
                            $len = min(100, max(mb_strlen($expected_text), mb_strlen($pdf_field_text)));
                            for ($j = 0; $j < $len; $j++) {
                                $exp_char = mb_substr($expected_text, $j, 1);
                                $pdf_char = mb_substr($pdf_field_text, $j, 1);
                                if ($exp_char !== $pdf_char) {
                                    $diff_parts[] = 'pos ' . $j . ': &laquo;' . esc_html((string) $exp_char)
                                                  . '&raquo; vs &laquo;' . esc_html((string) $pdf_char) . '&raquo;';
                                }
                            }
                            if (!empty($diff_parts)) {
                                echo "<div class='forge-pdf-diff-row'>"
                                . implode(' &nbsp;|&nbsp; ', $diff_parts) . "</div>";
                            }
                        }

                        echo "</div>\n"; // forge-pdf-cmp-row

                        // Mark both as processed
                        $processed_fields[] = $payload_index;
                        $processed_markers[] = $start_marker;
                        $new_start_found = true;
                    }
                }
            } while ($new_start_found);

            echo "</div>"; // forge-pdf-cmp-list
            echo "</div>"; // forge-pdf-detail-content
            echo "</div>"; // forge-pdf-detail-section

            /* ---- PDF RAW PREPARATION ---- */

            $pdf_raw = null;

            if (!empty($file['tmp_name']) && is_readable($file['tmp_name'])) {
                $pdf_raw = file_get_contents($file['tmp_name']);
            } else {
                $pdf_raw = false;
            }

            // --- Multiple seals detail section ---
            // Each seal found here is verified against the same server-side key lookup as the
            // primary seal — none of them are trusted based on their own embedded key_id alone.
            // Regardless of any individual seal's HMAC validity, $multiple_seals_detected forces
            // the overall verdict to fail (a genuine document should only ever carry one seal).
            if ($multiple_seals_detected && $pdf_raw !== false) {
                // Collect all seals:
                // 1. From pdfparser text (decompressed page-content streams) — the real seal.
                //    Use the pre-saved list; $matches is overwritten by later preg_match_all calls.
                // 2. From bytes appended AFTER the last %%EOF only — injected plain-text seals.
                //    Scanning full raw bytes causes false positives in compressed binary data.
                $all_seals_b64 = [];
                foreach ($text_seal_b64_list as $m) {
                    if ($m !== '' && !in_array($m, $all_seals_b64, true)) {
                        $all_seals_b64[] = $m;
                    }
                }
                $last_eof_pos = strrpos((string) $pdf_raw, '%%EOF');
                $appended_raw = $last_eof_pos !== false
                    ? substr((string) $pdf_raw, $last_eof_pos + 5)
                    : '';
                preg_match_all('/---BEGIN-SEAL---(.*?)---END-SEAL---/s', $appended_raw, $raw_text_found);
                foreach ($raw_text_found[1] as $rb64) {
                    $rb64 = trim($rb64);
                    if ($rb64 !== '' && !in_array($rb64, $all_seals_b64, true)) {
                        $all_seals_b64[] = $rb64;
                    }
                }

                $seals_section_id = 'forge-pdf-content-seals-' . $uid_prefix;
                echo "<div class='forge-pdf-detail-section'"
                   . " id='forge-pdf-section-seals-" . esc_attr($uid_prefix) . "'>";
                echo "<div class='forge-pdf-detail-hdr'>";
                echo "<button type='button' class='button button-small forge-pdf-toggle'"
                   . " data-target='" . esc_attr($seals_section_id) . "'>" . esc_html__('Seal Blocks', 'form-forge') . "</button>";
                echo "<span class='forge-pdf-detail-badge forge-pdf-badge-fail'>FAIL</span>";
                echo "</div>";
                echo "<div id='" . esc_attr($seals_section_id) . "'"
                   . " class='forge-pdf-hidden forge-pdf-detail-content'>";
                echo "<div class='forge-pdf-hash-list'>";

                $total_seals = count($all_seals_b64);
                echo "<p style='margin:8px 14px 4px;font-size:12px;color:#d63638;font-weight:600'>";
                echo esc_html($total_seals) . " seal block(s) found — exactly 1 is expected. ";
                echo "Any extra seal block is proof of tampering regardless of its HMAC status.";
                echo "</p>";

                foreach ($all_seals_b64 as $idx => $sb64) {
                    $sb64      = trim((string) $sb64);
                    $seal_num  = $idx + 1;
                    $is_ok     = false;
                    $sd        = null;
                    $parse_err = '';

                    if (strlen($sb64) > 65536) {
                        $parse_err = 'implausibly large — rejected';
                    } else {
                        $sj = base64_decode($sb64, true);
                        if ($sj === false) {
                            $parse_err = 'base64 decode failed';
                        } else {
                            $sd = json_decode($sj, true);
                            if (!is_array($sd)) {
                                $parse_err = 'JSON decode failed';
                            } else {
                                try {
                                    $rp    = self::rebuildPayload($sd);
                                    $vr    = HashSeal::verify($rp, (string)($sd['seal'] ?? ''));
                                    $is_ok = $vr['valid'];
                                } catch (\Throwable $sve) {
                                    error_log('ForgeForms Verificationpage: seal HMAC check threw: ' . $sve->getMessage());
                                    $is_ok     = false;
                                    $parse_err = 'HMAC check failed';
                                }
                            }
                        }
                    }

                    $row_cls  = $is_ok ? 'forge-pdf-hash-row--pass' : 'forge-pdf-hash-row--fail';
                    $pill_cls = $is_ok ? 'forge-pdf-pill--pass'     : 'forge-pdf-pill--fail';
                    $pill_txt = $is_ok ? __('AUTHENTIC', 'form-forge') : __('FORGED / INVALID', 'form-forge');

                    $seal_row_style = 'flex-direction:column;align-items:flex-start;gap:6px;padding:10px 14px';
                    echo "<div class='forge-pdf-hash-row {$row_cls}' style='{$seal_row_style}'>";
                    echo "<div style='display:flex;align-items:center;gap:8px;width:100%'>";
                    echo "<strong style='flex:1'>Seal #" . (int)$seal_num . "</strong>";
                    echo "<span class='forge-pdf-pill {$pill_cls}'>{$pill_txt}</span>";
                    echo "</div>";

                    // Always show a truncated preview of the raw base64 between the markers.
                    $b64_preview = strlen($sb64) > 120
                        ? esc_html(substr($sb64, 0, 60)) . '…' . esc_html(substr($sb64, -30))
                        : esc_html($sb64);
                    echo "<div style='font-size:10px;color:#787c82;font-family:monospace;word-break:break-all'>";
                    echo "Base64: {$b64_preview}";
                    echo "</div>";

                    if ($parse_err !== '') {
                        echo "<div style='font-size:11px;color:#d63638'>" . esc_html($parse_err) . "</div>";
                    } elseif (is_array($sd)) {
                        // Show key fields from the seal so the admin can identify which is real
                        $s_form    = esc_html((string) ($sd['form_name'] ?? '—'));
                        $s_id      = esc_html((string) ($sd['form_id']   ?? '—'));
                        $s_gen     = esc_html((string) ($sd['generated'] ?? '—'));
                        $s_fields  = is_array($sd['fields'] ?? null) ? count($sd['fields']) : '—';
                        $s_pages   = esc_html((string) ($sd['expected_pages'] ?? '—'));
                        echo "<table style='font-size:11px;border-collapse:collapse;width:100%'>";
                        echo "<tr><td style='color:#787c82;padding:1px 8px 1px 0;white-space:nowrap'>" . esc_html__('Form', 'form-forge') . "</td>"
                           . "<td>" . $s_form . " (ID: " . $s_id . ")</td></tr>";
                        echo "<tr><td style='color:#787c82;padding:1px 8px 1px 0'>" . esc_html__('Generated', 'form-forge') . "</td>"
                           . "<td>" . $s_gen . "</td></tr>";
                        echo "<tr><td style='color:#787c82;padding:1px 8px 1px 0'>" . esc_html__('Fields', 'form-forge') . "</td>"
                           . "<td>" . $s_fields . "</td></tr>";
                        echo "<tr><td style='color:#787c82;padding:1px 8px 1px 0'>" . esc_html__('Pages', 'form-forge') . "</td>"
                           . "<td>" . $s_pages . "</td></tr>";
                        echo "</table>";
                    }

                    echo "</div>";
                }

                echo "</div></div></div>";
            }

            $unexpected_detected     = false;
            $annotation_mismatch     = false;
            $pagecount_mismatch      = false;
            $image_missmatch         = false;
            $font_missmatch          = false;
            $content_stream_mismatch = false;

            self::$image_slots = [];

            /* ─────────────────────── ANNOTATION PROCESSING ─────────────────────── */

            // $visualLines comes directly from the AJAX parameter — no log file read needed.
            if (!is_array($visualLines)) {
                $visualLines = [];
            }

            $annotation_fail_count = 0;

            self::setProgress(__('Checking annotations…', 'form-forge'), 58);

            // Section wrapper — badge injected after annotation check via post-processing
            echo "<div class='forge-pdf-detail-section' id='forge-pdf-section-annots-" . esc_attr($uid_prefix) . "'>";

            $fold_id = 'forge-pdf-content-annots-' . $uid_prefix;
            echo "<div class='forge-pdf-detail-hdr'>";
            echo "<button type='button' class='button button-small forge-pdf-toggle'"
               . " data-target='" . esc_attr($fold_id) . "'>"
               . "Annotations</button>";
            $annot_badge_id = 'forge-pdf-badge-annots-' . esc_attr($uid_prefix);
            echo "<span id='{$annot_badge_id}' class='forge-pdf-detail-badge forge-pdf-badge-pass'>PASS</span>";
            echo "</div>";
            echo "<div id='" . esc_attr($fold_id) . "'"
               . " class='forge-pdf-hidden forge-pdf-detail-content'>";

            $fold_dupes_id = 'fold_dupecheck_' . uniqid();
            echo "<div class='forge-pdf-subsection'>";
            echo "<button type='button' class='forge-pdf-subtoggle forge-pdf-toggle'"
               . " data-target='" . esc_attr($fold_dupes_id) . "'>"
               . "<span class='forge-pdf-subtoggle__icon'>&#9656;</span> Chunk Check"
               . "</button>";
            echo "<div id='" . esc_attr($fold_dupes_id) . "'"
               . " class='forge-pdf-hidden forge-pdf-detail-content' style='padding:0;'>";
            echo "<div class='forge-pdf-cmp-list'>";

            $fields = $rebuilt_payload['fields'] ?? [];
            $processed_fields = [];
            $processed_markers = [];
            $potential_dupes = []; // <-- store actual dupes here

            $inside_field = false;
            $current_marker = '';
            $current_chunks = [];

            foreach ($visualLines as $line_number => $chunk) {
                // Strip numeric line prefixes like "58: "
                $chunk_clean = preg_replace('/^\d+:\s*/', '', $chunk);

                // --- Detect end of field first ---
                if ($inside_field && preg_match('/^\[FORGE_PDF_FIELD_END\]/', $chunk_clean)) {
                    $inside_field = false;

                    // Concatenate chunks
                    $full_field_text = implode('', $current_chunks);

                    // Remove page markers inside field
                    $full_field_text = preg_replace(
                        '/\[FORGE_PDF_PAGENO_START\].*?\[FORGE_PDF_PAGENO_END\]/s',
                        '',
                        $full_field_text
                    );

                    if (!in_array($current_marker, $processed_markers, true)) {
                        // Find next unprocessed payload field
                        $payload_index = null;
                        foreach ($fields as $i => $f) {
                            if (!in_array($i, $processed_fields, true)) {
                                $payload_index = $i;
                                break;
                            }
                        }

                        if ($payload_index !== null) {
                            $payload_field = $fields[$payload_index];
                            $expected_text = $payload_field['value'] ?? '';

                            $found_pos = mb_strpos($full_field_text, $expected_text);
                            $potential_dupe = ($found_pos === false || count($current_chunks) > 1);
                            $snippet = $current_chunks[0] ?? '';

                            // Only store actual dupes
                            if ($potential_dupe) {
                                $potential_dupes[] = [
                                    'Sealdata'   => $expected_text,
                                    'chunkcount' => count($current_chunks),
                                ];
                            }

                            $label      = $payload_field['label'] ?? '';
                            $row_state  = $potential_dupe ? 'warn' : 'pass';
                            $pill_state = $potential_dupe ? 'warn' : 'pass';
                            $pill_text  = $potential_dupe ? 'MULTI-CHUNK' : 'OK';
                            $disp_label = $label !== '' ? esc_html($label) : esc_html($current_marker);

                            echo "<div class='forge-pdf-cmp-row forge-pdf-cmp-row--{$row_state}'>";
                            echo "<div class='forge-pdf-cmp-header'>"
                               . "<span class='forge-pdf-cmp-label'>{$disp_label}</span>"
                               . "<span class='forge-pdf-cmp-marker'>" . esc_html($current_marker) . "</span>"
                               . "<span class='forge-pdf-pill forge-pdf-pill--{$pill_state}'>{$pill_text}</span>"
                               . "<span style='font-size:10px;color:#787c82;margin-left:4px;'>"
                               . count($current_chunks) . " chunk(s)</span>"
                               . "</div>";
                            echo "<div class='forge-pdf-cmp-body'>";
                            $seal_v = esc_html((string) $expected_text);
                            $pdf_v  = esc_html((string) $snippet);
                            echo "<div class='forge-pdf-cmp-col'><div class='forge-pdf-cmp-col__label'>Seal</div>"
                               . "<div class='forge-pdf-cmp-col__value'>{$seal_v}</div></div>";
                            echo "<div class='forge-pdf-cmp-col'><div class='forge-pdf-cmp-col__label'>PDF chunk</div>"
                               . "<div class='forge-pdf-cmp-col__value'>{$pdf_v}</div></div>";
                            echo "</div></div>\n"; // forge-pdf-cmp-body + forge-pdf-cmp-row

                            $processed_fields[] = $payload_index;
                        } else {
                            echo "<div class='forge-pdf-cmp-row forge-pdf-cmp-row--fail'>"
                               . "<div class='forge-pdf-cmp-header'>"
                               . "<span class='forge-pdf-cmp-label'>Unknown marker</span>"
                               . "<span class='forge-pdf-cmp-marker'>" . esc_html($current_marker) . "</span>"
                               . "<span class='forge-pdf-pill forge-pdf-pill--fail'>NOT IN SEAL</span>"
                               . "</div></div>\n";
                        }

                        $processed_markers[] = $current_marker;
                    }

                    $current_marker = '';
                    $current_chunks = [];
                    continue;
                }

                // --- Detect start of field ---
                if (preg_match('/^\[FORGE_PDF_FIELD_([^\]]+)\]/', $chunk_clean, $start_match)) {
                    $inside_field = true;
                    $current_marker = $start_match[1];
                    $current_chunks = [];
                    continue;
                }

                // Accumulate chunks if inside a field
                if ($inside_field) {
                    $current_chunks[] = $chunk_clean;
                }
            }

            echo "</div>"; // forge-pdf-cmp-list
            echo "</div>"; // fold_dupes_id content
            echo "</div>"; // forge-pdf-subsection

            // --- RAW PDF ANNOTATION EXTRACTION & SEAL CHECK ---
            if ($pdf_raw === false) {
                echo self::noticeHtml("Could not read PDF content for {$file_name}.", 'error');
            } else {
                $annotations = [];

                // List of standard PDF annotation subtypes (from the PDF spec)
                $validTypes = [
                    'Text','FreeText','Highlight','Underline','Squiggly','StrikeOut',
                    'Line','Square','Circle','Polygon','PolyLine',
                    'Ink','Stamp','Popup','FileAttachment','Sound','Movie',
                    'Screen','Widget','PrinterMark','TrapNet','Watermark',
                    '3D','Redact','Projection','RichMedia'
                ];

                // --- 1) Scan all objects for /Type /Annot ---
                if (preg_match_all('/(\d+\s+\d+)\s+obj([\s\S]*?)endobj/s', $pdf_raw, $allObjs)) {
                    foreach ($allObjs[1] as $index => $objId) {
                        $rawDict = $allObjs[2][$index];

                        if (preg_match('/\/Type\s*\/Annot\b/i', $rawDict)) {
                            // Extract /Subtype if present
                            $subtype = null;
                            if (preg_match('/\/Subtype\s*\/([A-Za-z0-9]+)/i', $rawDict, $subMatch)) {
                                $subtype = $subMatch[1];
                            }

                            // Only accept known types
                            if ($subtype !== null && !in_array($subtype, $validTypes, true)) {
                                $subtype = 'UNKNOWN';
                            }

                            // Extract /Rect if present
                            $rect = [0,0,0,0];
                            if (preg_match('/\/Rect\s*\[(.*?)\]/s', $rawDict, $r)) {
                                $coords = preg_split('/\s+/', trim($r[1]));
                                $rect = array_map('floatval', $coords);
                            }

                            // Extract /Contents if present
                            $content = '';
                            if (preg_match('/\/Contents\s*\((.*?)\)/s', $rawDict, $c)) {
                                $content = $c[1];
                            }

                            // --- If /Contents is empty, try to extract /URI from /A dictionary ---
                            $uri_pat = '/\/A\s*<<[^>]*\/URI\s*\((.*?)\)/';
                            if ($content === '' && preg_match($uri_pat, $rawDict, $uriMatch)) {
                                $content = $uriMatch[1];
                            }

                            $content = preg_replace('/^mailto:/i', '', $content);

                            // Save rawDict for debugging if needed
                            $annotations[] = [
                                'type'    => $subtype ?? 'UNKNOWN',
                                'content' => $content,
                                'rect'    => $rect,
                                'objId'   => $objId,
                                'raw'     => $rawDict,
                            ];
                        }
                    }
                }

                // --- 2) Optional: check /Annots references for completeness ---
                if (str_contains($pdf_raw, '/Annots')) {
                    if (preg_match_all('/\/Annots\s*\[((?:\d+\s+\d+\s+R\s*)+)\]/', $pdf_raw, $annotRefs)) {
                        foreach ($annotRefs[1] as $pageIndex => $refs) {
                            if (preg_match_all('/(\d+\s+\d+)\s+R/', $refs, $objMatches)) {
                                foreach ($objMatches[1] as $objId) {
                                    // Already processed? skip
                                    $exists = false;
                                    foreach ($annotations as $a) {
                                        if ($a['objId'] === $objId) {
                                            $exists = true;
                                            break;
                                        }
                                    }
                                    if (!$exists) {
                                        $annotations[] = [
                                            'type'    => 'UNKNOWN (from /Annots)',
                                            'content' => '',
                                            'rect'    => null,
                                            'objId'   => $objId,
                                        ];
                                    }
                                }
                            }
                        }
                    }
                }

                // --- 3) Foldable unified annotation report ---
                $all_annots_id = sanitize_html_class($uid_prefix . '-all-annots');
                echo "<div class='forge-pdf-subsection'>";
                $annot_btn_target = esc_attr($all_annots_id);
                echo "<button type='button' class='forge-pdf-subtoggle forge-pdf-toggle'"
                   . " data-target='{$annot_btn_target}'>"
                   . "<span class='forge-pdf-subtoggle__icon'>&#9656;</span> Annotation List"
                   . "</button>";
                echo "<div id='" . esc_attr($all_annots_id) . "'"
                   . " class='forge-pdf-hidden forge-pdf-detail-content' style='padding:0;'>";
                echo "<div class='forge-pdf-cmp-list'>";

                if (!empty($annotations)) {
                    $matched_fields = []; // track fields already matched
                    $potential_dupes_remaining = [];

                    // Initialize remaining counts for potential dupes
                    foreach ($potential_dupes ?? [] as $pd_idx => $pd) {
                        $potential_dupes_remaining[$pd_idx] = $pd['chunkcount'] - 1; // initial subtraction
                    }

                    foreach ($annotations as $i => $ann) {
                        // Determine the "content" to match
                        $content_to_match = '';

                        if (isset($ann['content']) && $ann['content'] !== '') {
                            $content_to_match = $ann['content'];
                        } elseif (!empty($ann['raw'])) {
                            if (preg_match('/\/A\s*<<[^>]*\/URI\s*\((.*?)\)/', $ann['raw'], $uriMatch)) {
                                $content_to_match = $uriMatch[1];
                            }
                        }
                        // Gap D: also check /V (widget annotation display value).
                        // /Contents may be absent on widget annotations while /V holds
                        // the actual visible field value shown by PDF viewers.
                        if ($content_to_match === '' && !empty($ann['raw'])) {
                            if (preg_match('/\/V\s*\(([^)]*)\)/', $ann['raw'], $vMatch)) {
                                $content_to_match = $vMatch[1];
                            }
                        }

                        $content_to_match = trim($content_to_match);

                        // Track match type
                        $match_type = 'No'; // default
                        $matched_field = null;

                        if ($content_to_match !== '') {
                            // --- Sealdata match first ---
                            foreach ($seal_data['fields'] ?? [] as $idx => $field) {
                                if (in_array($idx, $matched_fields, true)) {
                                    continue;
                                }
                                $field_value = trim($field['value'] ?? '');
                                if ($field_value === '') {
                                    continue;
                                }

                                if (stripos($field_value, $content_to_match) !== false) {
                                    $matched_field = $field_value;
                                    $matched_fields[] = $idx;
                                    $match_type = 'Yes';
                                    break;
                                }
                            }

                            // --- Fallback to potential dupes ---
                            if ($match_type === 'No') {
                                foreach ($potential_dupes ?? [] as $pd_idx => $pd) {
                                    $seal_text = $pd['Sealdata'];
                                    if ($potential_dupes_remaining[$pd_idx] <= 0) {
                                        continue;
                                    }

                                    if (stripos($seal_text, $content_to_match) !== false) {
                                        $matched_field = $seal_text;
                                        $potential_dupes_remaining[$pd_idx]--;
                                        $match_type = 'Dupe Match';
                                        break;
                                    }
                                }
                            }
                        }

                        if ($match_type === 'No') {
                            $annotation_mismatch = true;
                            $annotation_fail_count++;
                        }

                        $matched_to_display = $matched_field ?? '';

                        $row_state = match ($match_type) {
                            'Yes'        => 'pass',
                            'Dupe Match' => 'warn',
                            default      => 'fail',
                        };
                        $pill_state = $row_state;
                        $pill_text  = match ($match_type) {
                            'Yes'        => __('MATCH', 'form-forge'),
                            'Dupe Match' => __('DUPE', 'form-forge'),
                            default      => __('UNMATCHED', 'form-forge'),
                        };

                        $ann_label = 'Annot #' . ($i + 1) . ' — ' . esc_html($ann['type']);
                        echo "<div class='forge-pdf-cmp-row forge-pdf-cmp-row--{$row_state}'>";
                        echo "<div class='forge-pdf-cmp-header'>"
                           . "<span class='forge-pdf-cmp-label'>{$ann_label}</span>"
                           . "<span class='forge-pdf-pill forge-pdf-pill--{$pill_state}'>{$pill_text}</span>"
                           . "</div>";
                        echo "<div class='forge-pdf-cmp-body'>";
                        echo "<div class='forge-pdf-cmp-col'>"
                           . "<div class='forge-pdf-cmp-col__label'>PDF content</div>"
                           . "<div class='forge-pdf-cmp-col__value'>" . esc_html($content_to_match) . "</div>"
                           . "</div>";
                        echo "<div class='forge-pdf-cmp-col'>"
                           . "<div class='forge-pdf-cmp-col__label'>Matched to seal</div>"
                           . "<div class='forge-pdf-cmp-col__value'>" . esc_html($matched_to_display) . "</div>"
                           . "</div>";
                        echo "</div></div>\n";
                    }
                } else {
                    echo "<p class='forge-pdf-empty-state'>No annotations found in PDF.</p>";
                }

                echo "</div>"; // forge-pdf-cmp-list
                echo "</div>"; // all_annots_id content
                echo "</div>"; // all_annots forge-pdf-detail-section
            }

            echo "</div>"; // close annotation section foldable content
            echo "</div>"; // close annotation section wrapper

            /* ─────────────────────────── OBJECT PROCESSING ────────────────────────── */

            if ($pdf_raw !== false) {
                // PAGE COUNT CHECK
                preg_match_all('/\/Type\s*\/Page\b/', $pdf_raw, $page_matches);
                $object_page_count = count($page_matches[0]);
                $expected_pages = $rebuilt_payload['expected_pages'] ?? $object_page_count; // fallback

                if ($object_page_count !== $expected_pages) {
                    $pagecount_mismatch = true;
                    $unexpected_detected = true;
                }

                self::setProgress(__('Checking page count…', 'form-forge'), 68);

                $page_box_id    = 'forge-pdf-content-pgcount-' . $uid_prefix;
                $pgcount_sec_id = 'forge-pdf-section-pgcount-' . esc_attr($uid_prefix);
                echo "<div class='forge-pdf-detail-section' id='{$pgcount_sec_id}'>";
                echo "<div class='forge-pdf-detail-hdr'>";
                echo "<button type='button' class='button button-small forge-pdf-toggle'"
                   . " data-target='" . esc_attr($page_box_id) . "'>Page Count</button>";
                $pgcount_badge = $pagecount_mismatch ? 'forge-pdf-badge-fail' : 'forge-pdf-badge-pass';
                $pgcount_label = $pagecount_mismatch ? 'FAIL' : 'PASS';
                echo "<span class='forge-pdf-detail-badge {$pgcount_badge}'>{$pgcount_label}</span>";
                echo "</div>";
                $pgcount_hidden = $pagecount_mismatch ? '' : ' forge-pdf-hidden';
                echo "<div id='" . esc_attr($page_box_id) . "' class='forge-pdf-detail-content{$pgcount_hidden}'>";
                echo "<div class='forge-pdf-stat-row'>";
                echo "<div class='forge-pdf-stat'><div class='forge-pdf-stat__label'>Seal</div>"
                   . "<div class='forge-pdf-stat__value'>{$expected_pages}</div></div>";
                echo "<div class='forge-pdf-stat-sep'>→</div>";
                echo "<div class='forge-pdf-stat'><div class='forge-pdf-stat__label'>PDF</div>"
                   . "<div class='forge-pdf-stat__value'>{$object_page_count}</div></div>";
                echo "</div>";
                if ($pagecount_mismatch) {
                    echo "<span class='forge-pdf-pill forge-pdf-pill--fail'>MISMATCH</span>";
                } else {
                    echo "<span class='forge-pdf-pill forge-pdf-pill--pass'>OK</span>";
                }
                echo "</div>";
                echo "</div>";

                // Use exact XObject stream hashes when available (new seals).
                // Fall back to thumbnail hashes from uploads/template for old seals.
                $exact_image_hashes = $rebuilt_payload['image_hashes'] ?? [];
                $use_exact_hashes   = !empty($exact_image_hashes);

                $allowed_template_hashes = array_column($rebuilt_payload['template'], 'sha256');
                $allowed_upload_hashes   = array_column($rebuilt_payload['uploads'], 'sha256');
                $allowed_hashes          = array_merge($allowed_template_hashes, $allowed_upload_hashes);

                // IMAGE CHECK
                self::setProgress(__('Checking images…', 'form-forge'), 75);

                $image_section_id  = 'forge-pdf-content-images-' . $uid_prefix;
                $image_section_sec = 'forge-pdf-section-images-' . esc_attr($uid_prefix);
                echo "<div class='forge-pdf-detail-section' id='{$image_section_sec}'>";
                echo "<div class='forge-pdf-detail-hdr'>";
                echo "<button type='button' class='button button-small forge-pdf-toggle'"
                   . " data-target='" . esc_attr($image_section_id) . "'>Image Hashes</button>";
                $img_badge_id = 'forge-pdf-badge-images-' . esc_attr($uid_prefix);
                echo "<span id='{$img_badge_id}' class='forge-pdf-detail-badge forge-pdf-badge-pass'>PASS</span>";
                echo "</div>";
                echo "<div id='" . esc_attr($image_section_id) . "'"
                   . " class='forge-pdf-hidden forge-pdf-detail-content'"
                   . " style='background:#f4f4f4; padding:10px; border:1px solid #ddd;'>";

                if (str_contains($pdf_raw, '/XObject')) {
                    // Pre-collect SMask object numbers so they can be skipped as
                    // standalone images (they are alpha channels, not content).
                    // SMask association per image is detected from $fullObj at scan time.
                    $smask_obj_nums = [];
                    if (preg_match_all('/\/SMask\s+(\d+)\s+\d+\s+R/', $pdf_raw, $_sm)) {
                        $smask_obj_nums = array_flip($_sm[1]);
                        unset($_sm);
                    }

                    $scanXObjects = function ($pdf_raw, $parentName = null, array $visited = [])
 use (&$scanXObjects, $allowed_hashes, $rebuilt_payload, $smask_obj_nums, $exact_image_hashes, $use_exact_hashes) {
                        $offset = 0;
                        $found  = false;

                        // --- Ensure image output directory exists (HTTP-blocked) ---
                        $upload_dir = wp_upload_dir();
                        $safe_dir   = $upload_dir['basedir'] . '/forge-secure-pdf';
                        $ver_dir    = $safe_dir . '/verimages';

                        foreach ([$safe_dir, $ver_dir] as $dir) {
                            if (!is_dir($dir)) {
                                wp_mkdir_p($dir);
                                chmod($dir, 0750);
                                file_put_contents($dir . '/index.php', "<?php // Silence is golden ?>");
                                chmod($dir . '/index.php', 0640);
                            }
                        }

                        // Ensure the parent .htaccess exists (Generator may not have run yet).
                        $htaccess = $safe_dir . '/.htaccess';
                        if (!file_exists($htaccess)) {
                            file_put_contents($htaccess, "Options -Indexes\nDeny from all\n");
                            chmod($htaccess, 0640);
                        }

                        while (($pos = strpos($pdf_raw, '/XObject', $offset)) !== false) {
                            $found = true;

                            $obj_start_line = strrpos(substr($pdf_raw, 0, $pos), "\n") ?: 0;
                            $obj_start      = $obj_start_line + 1;

                            // Resolve the PDF object number — look back up to 4 KB from
                            // /XObject so large image dictionaries are handled correctly.
                            $look_back = substr($pdf_raw, max(0, $pos - 4096), min($pos, 4096));
                            $obj_num   = null;
                            if (preg_match_all('/(\d+)\s+\d+\s+obj\b/', $look_back, $_all_nm)) {
                                $obj_num = end($_all_nm[1]); // last = closest to /XObject
                            }
                            unset($_all_nm);

                            if ($obj_num !== null && isset($smask_obj_nums[$obj_num])) {
                                $offset = $pos + 10;
                                continue;
                            }

                                    $obj_end = strpos($pdf_raw, 'endobj', $obj_start);
                            if ($obj_end === false) {
                                $offset = $pos + 10;
                                continue;
                            }

                                    $fullObj = substr($pdf_raw, $obj_start, $obj_end + 6 - $obj_start);
                                    $isImage = str_contains($fullObj, '/Subtype /Image');

                                    // Extract filters
                                    preg_match_all('/\/Filter\s*\/([A-Za-z0-9]+)/i', $fullObj, $fMatches);
                                    $filters = $fMatches[1] ?: [];

                                    // Extract width & height
                                    $width = $height = null;

                                    // Direct integer or float
                            if (preg_match('/\/Width\s+([0-9.]+)/', $fullObj, $m)) {
                                $width = (int)round($m[1]);
                            }
                            if (preg_match('/\/Height\s+([0-9.]+)/', $fullObj, $m)) {
                                $height = (int)round($m[1]);
                            }

                                    // Indirect reference (e.g. /Width 12 0 R)
                            $wh_pat = '/\/(Width|Height)\s+(\d+)\s+0\s+R/';
                            if ((!$width || !$height)
                                && preg_match_all($wh_pat, $fullObj, $refs, PREG_SET_ORDER)
                            ) {
                                foreach ($refs as $r) {
                                    $refNum  = $r[2];
                                    $ref_pat = '/' . $refNum . '\s+0\s+obj\s+(.*?)\s+endobj/s';
                                    if (preg_match($ref_pat, $pdf_raw, $refObj)) {
                                        if ($r[1] === 'Width') {
                                            $width  = (int)trim($refObj[1]);
                                        }
                                        if ($r[1] === 'Height') {
                                            $height = (int)trim($refObj[1]);
                                        }
                                    }
                                }
                            }

                                    // Reject implausible dimensions before they're used to size any
                                    // allocation or loop bound below — a crafted /Width, /Height in an
                                    // otherwise-tiny image object could otherwise trigger a multi-GB
                                    // str_repeat()/str_pad() or a CPU-burning pixel loop.
                                    // Cap is set well above real-world camera output (an 8000×8000/64MP
                                    // photo is ~64,000,000 px) so a legitimate high-resolution upload is
                                    // never misclassified as tampered — 100MP still bounds worst-case
                                    // allocation size to a sane multiple of real content.
                            $dims_over_cap = false;
                            if ($width !== null && $height !== null) {
                                if ($width < 0 || $height < 0 || $width > 20000 || $height > 20000
                                    || ($width * $height) > 100_000_000
                                ) {
                                    $dims_over_cap = true;
                                    $width = $height = null;
                                }
                            }

                                    // Extract stream
                                    $stream_pos = strpos($pdf_raw, 'stream', $obj_start);
                                    $endstream_pos = strpos($pdf_raw, 'endstream', $stream_pos);
                                    $stream_data = '';
                                    $decoded = null;

                            if ($stream_pos !== false && $endstream_pos !== false) {
                                $stream_data = substr($pdf_raw, $stream_pos + 6, $endstream_pos - ($stream_pos + 6));
                                $stream_data = ltrim($stream_data, "\r\n");

                                $decoded = $stream_data;
                                if (in_array('FlateDecode', $filters, true)) {
                                    // Bound both input and output size — same guard used for the
                                    // palette/SMask/content-stream decompressions elsewhere in this
                                    // file, against a crafted small stream that inflates to gigabytes.
                                    $try = (strlen($stream_data) <= 67108864) ? @gzuncompress($stream_data) : false;
                                    if ($try !== false && strlen($try) <= 67108864) {
                                        $decoded = $try;
                                    }
                                }
                            }

                                    $bytes    = strlen($decoded ?? '');
                                    $channels = 0;

                            if ($isImage) {
                                // Determine metadata
                                preg_match('/\/BitsPerComponent\s+(\d+)/', $fullObj, $bpcMatch);
                                $bpc = (int)($bpcMatch[1] ?? 8);

                                preg_match('/\/ColorSpace\s*(\/[A-Za-z0-9]+|\[.+?\])/s', $fullObj, $csMatch);
                                $csRaw = $csMatch[1] ?? '';
                                $colorspace = 'unknown';
                                $channels = 0;
                                $palette = null;

                                $isImageMask = str_contains($fullObj, '/ImageMask true');

                                // Standard color spaces
                                if (is_string($csRaw)) {
                                    switch ($csRaw) {
                                        case '/DeviceRGB':
                                                $colorspace = 'DeviceRGB';
                                            $channels = 3;
                                            break;
                                        case '/DeviceGray':
                                                $colorspace = 'DeviceGray';
                                            $channels = 1;
                                            break;
                                        case '/DeviceCMYK':
                                                $colorspace = 'DeviceCMYK';
                                            $channels = 4;
                                            break;
                                    }
                                }

                                // IMPORTANT:
                                // Indexed color spaces have TWO streams:
                                // 1) image index stream
                                // 2) palette lookup stream (may have its own filters)
                                // Both MUST be decoded, or colors will be wrong.

                                // Indexed color spaces
                                if (str_starts_with($csRaw, '[')
                                    && preg_match('/\/Indexed\s+\/DeviceRGB\s+(\d+)\s+(\d+)\s+0\s+R/', $csRaw, $m)
                                ) {
                                    $colorspace = 'IndexedRGB';
                                    $channels = 1; // IMPORTANT: index stream is 1 channel
                                    $hival = (int)$m[1];
                                    $paletteObjNum = (int)$m[2];

                                    // Per the PDF spec, hival for an 8-bit index stream can never
                                    // exceed 255 — reject anything outside that range before it
                                    // drives an allocation/loop bound below.
                                    if ($hival < 0 || $hival > 255) {
                                        $hival = null;
                                    }

                                    if ($hival !== null) {
                                        $pal_pat = '/' . $paletteObjNum . '\s+0\s+obj\s+(.*?)\s+endobj/s';
                                        if (preg_match($pal_pat, $pdf_raw, $palObj)) {
                                            // Extract palette filters
                                            preg_match_all('/\/Filter\s*\/([A-Za-z0-9]+)/', $palObj[1], $pf);
                                            $palFilters = $pf[1] ?? [];

                                            if (preg_match('/stream\s*(.*?)\s*endstream/s', $palObj[1], $palStream)) {
                                                $lookup = ltrim($palStream[1], "\r\n");

                                                // DECODE PALETTE STREAM — bounded input and output
                                                // size, matching the guard used elsewhere in this
                                                // file (e.g. the content-stream scan) against a
                                                // decompression-bomb crafted palette stream.
                                                if (in_array('FlateDecode', $palFilters, true)) {
                                                    $try = (strlen($lookup) <= 67108864) ? @gzuncompress($lookup) : false;
                                                    if ($try !== false && strlen($try) <= 67108864) {
                                                        $lookup = $try;
                                                    }
                                                }

                                                // Validate palette length
                                                $expectedLen = ($hival + 1) * 3;
                                                if (strlen($lookup) < $expectedLen) {
                                                    throw new \RuntimeException("Indexed palette too short");
                                                }

                                                // Build palette
                                                $palette = [];
                                                for ($i = 0; $i <= $hival; $i++) {
                                                    $off = $i * 3;
                                                    $palette[$i] = [
                                                        ord($lookup[$off]),
                                                        ord($lookup[$off + 1]),
                                                        ord($lookup[$off + 2]),
                                                    ];
                                                }
                                            }
                                        }
                                    }
                                }

                                // For JPEG (DCTDecode) the stream bytes ARE the raw JPEG data.
                                // Colorspace is embedded in the JPEG header and irrelevant to
                                // our hash, which is computed on the raw encoded bytes.
                                // Treat unknown colorspace as DeviceRGB so the pipeline continues.
                                if ($channels <= 0 && in_array('DCTDecode', $filters, true)) {
                                    $colorspace = 'DeviceRGB';
                                    $channels   = 3;
                                }

                                // --- Collect failure reasons ---
                                $failureReasons = [];
                                if ($decoded === null) {
                                    $failureReasons[] = 'Decoded stream is NULL';
                                }
                                if ($dims_over_cap) {
                                    $failureReasons[] = 'Image exceeds the 100-megapixel verification size '
                                        . 'limit and was skipped — this is a size cap, not evidence of tampering.';
                                } elseif (!$width || !$height) {
                                    $failureReasons[] = "Invalid dimensions ({$width}×{$height})";
                                }
                                if ($isImageMask) {
                                    $failureReasons[] = "Image is a mask";
                                }
                                if ($channels <= 0) {
                                    $failureReasons[] = "Unsupported ColorSpace: {$colorspace}";
                                }

                                // Always display metadata if anything is wrong
                                if ($failureReasons) {
                                    // An unreadable image XObject is suspicious — record it as a mismatch
                                    // so a tampered/corrupted image can't slip through by being undecodable.
                                    self::$image_slots[] = [
                                        'label'        => 'Unreadable XObject',
                                        'allowed'      => 0,
                                        'isBackground' => false,
                                        'hash'         => '',
                                        'check_hash'   => '',
                                        'colorspace'   => $colorspace,
                                        'width'        => $width,
                                        'height'       => $height,
                                    ];

                                    echo "<div style='margin:10px; padding:8px;"
                                       . " border:2px dashed #c00; background:#fff6f6;'>";
                                    echo "<b>Image could not be recreated from stream — counted as mismatch</b><br>";
                                    echo "<ul style='margin:5px 0; padding-left:18px;'>";
                                    foreach ($failureReasons as $r) {
                                        echo "<li>{$r}</li>";
                                    }
                                    echo "</ul>";

                                    echo "<div style='font-size:11px; color:#333;'>";
                                    echo "<b>Image metadata:</b><br>";
                                    echo "• Filters: " . implode(', ', $filters) . "<br>";
                                    echo "• Width × Height: {$width} × {$height}<br>";
                                    echo "• BitsPerComponent: {$bpc}<br>";
                                    echo "• ColorSpace: {$colorspace}<br>";
                                    echo "• Channels: {$channels}<br>";
                                    echo "• ImageMask: " . ($isImageMask ? 'true' : 'false') . "<br>";
                                    $dec_size = $decoded !== null ? strlen($decoded) . ' bytes' : 'n/a';
                                    echo "• Decoded size: {$dec_size}<br>";
                                    echo "</div></div>";

                                    $offset = $obj_end + 6;
                                    continue;
                                }
                            }

                            if ($isImage && $decoded !== null && $width && $height) {
                                // --- Image metadata ---
                                preg_match('/\/BitsPerComponent\s+(\d+)/', $fullObj, $bpcMatch);
                                $bpc = (int)($bpcMatch[1] ?? 8);

                                $palette    = [];
                                $colorspace = 'DeviceRGB'; // default
                                if (preg_match('/\/ColorSpace\s*\[\s*\/Indexed\s*\/([A-Za-z0-9]+)/', $fullObj, $m)) {
                                    $colorspace = 'IndexedRGB';
                                    $baseSpace = $m[1]; // usually DeviceRGB
                                } elseif (preg_match('/\/ColorSpace\s*\/([A-Za-z0-9]+)/', $fullObj, $m)) {
                                    $colorspace = $m[1];
                                }

                                $isImageMask = str_contains($fullObj, '/ImageMask true');

                                preg_match('/\/Decode\s*\[(.*?)\]/', $fullObj, $decodeMatch);
                                $invert = isset($decodeMatch[1]) && trim($decodeMatch[1]) === '1 0';

                                preg_match('/\/DecodeParms\s*<<(.+?)>>/s', $fullObj, $dpMatch);
                                $predictor = 1;
                                $colors = null;
                                if ($dpMatch) {
                                    if (preg_match('/\/Predictor\s+(\d+)/', $dpMatch[1], $m)) {
                                        $predictor = (int)$m[1];
                                    }
                                    if (preg_match('/\/Colors\s+(\d+)/', $dpMatch[1], $m)) {
                                        $colors = (int)$m[1];
                                    }
                                }

                                // --- Skip unsupported ---
                                if ($isImageMask || $bpc > 8 || $bpc < 1) {
                                    $offset = $obj_end + 6;
                                    continue;
                                }

                                // --- Determine channels ---
                                $channels = match ($colorspace) {
                                    'DeviceRGB' => 3,
                                    'DeviceGray' => 1,
                                    'DeviceCMYK' => 4,
                                    'IndexedRGB' => 1,
                                    default => 0
                                };

                                // JPEG: colorspace is embedded in the JPEG header; the PDF
                                // XObject entry may omit or encode it in a way our regex
                                // doesn't recognise. The hash is on raw DCT bytes so
                                // colorspace is irrelevant — treat as DeviceRGB and continue.
                                if ($channels === 0 && in_array('DCTDecode', $filters, true)) {
                                    $colorspace = 'DeviceRGB';
                                    $channels   = 3;
                                }

                                if ($channels === 0) {
                                    $failureReasons[] = "Unsupported ColorSpace: {$colorspace}";
                                    $offset = $obj_end + 6;
                                    continue;
                                }

                                if ($width === null || $height === null) {
                                    $failureReasons[] = 'Implausible or missing image dimensions';
                                    $offset = $obj_end + 6;
                                    continue;
                                }

                                        // --- PNG Predictor (skip for IndexedRGB) ---
                                if ($predictor >= 10 && $decoded !== null && $colorspace !== 'IndexedRGB') {
                                    $rowSize = $width * $channels;
                                    $out = '';
                                    $prev = str_repeat("\0", $rowSize);
                                    $i = 0;
                                    for ($y = 0; $y < $height; $y++) {
                                        if ($i >= strlen($decoded)) {
                                            break;
                                        }
                                        $filter = ord($decoded[$i++]);
                                        $row = substr($decoded, $i, $rowSize);
                                        $i += strlen($row);
                                        $row = str_pad($row, $rowSize, "\0");
                                        for ($j = 0; $j < $rowSize; $j++) {
                                            $cur = ord($row[$j]);
                                            $up  = ord($prev[$j]);
                                            $left = $j >= $channels ? ord($row[$j - $channels]) : 0;
                                            switch ($filter) {
                                                case 0:
                                                    $row[$j] = chr($cur);
                                                    break;
                                                case 1:
                                                    $row[$j] = chr(($cur + $left) & 0xFF);
                                                    break;
                                                case 2:
                                                    $row[$j] = chr(($cur + $up) & 0xFF);
                                                    break;
                                                case 3:
                                                    $row[$j] = chr(($cur + intdiv($left + $up, 2)) & 0xFF);
                                                    break;
                                                case 4:
                                                    $prev_c = $j >= $channels ? ord($prev[$j - $channels]) : 0;
                                                    $p  = $left + $up - $prev_c;
                                                    $pa = abs($p - $left);
                                                    $pb = abs($p - $up);
                                                    $pc = abs($p - $prev_c);
                                                    $paeth = ($pa <= $pb && $pa <= $pc)
                                                    ? $left
                                                    : (($pb <= $pc) ? $up : $prev_c);
                                                    $row[$j] = chr(($cur + $paeth) & 0xFF);
                                                    break;
                                                default:
                                                    $row[$j] = chr($cur);
                                            }
                                        }
                                        $out .= $row;
                                        $prev = $row;
                                    }
                                    $decoded = $out;
                                }

                                        // --- Handle IndexedRGB safely ---
                                $is_indexed = $colorspace === 'IndexedRGB'
                                    && $decoded !== null
                                    && is_array($palette)
                                    && count($palette) > 0;
                                if ($is_indexed) {
                                    if ($predictor >= 10) {
                                        $decoded = self::undoPngPredictorIndexed($decoded, $width, $bpc);
                                    }

                                    // --- Map indices to RGB and invert colors ---
                                    $expanded = '';
                                    $paletteSize = count($palette);
                                    for ($y = 0; $y < $height; $y++) {
                                        $rowStart = $y * $width;
                                        for ($x = 0; $x < $width; $x++) {
                                            $byte_pos = $rowStart + $x;
                                            $idx = ($byte_pos < strlen($decoded)) ? ord($decoded[$byte_pos]) : 0;
                                            if ($idx >= $paletteSize) {
                                                $idx = $paletteSize - 1;
                                            }

                                            $rgbEntry = $palette[$idx];
                                            if (is_string($rgbEntry)) {
                                                $rgbEntry = str_pad(substr($rgbEntry, 0, 3), 3, "\0");
                                                $rgb = array_map('ord', str_split($rgbEntry));
                                            } elseif (is_array($rgbEntry) && count($rgbEntry) >= 3) {
                                                $rgb = array_map(
                                                    fn($v) => is_string($v) ? ord($v) : (int) $v,
                                                    array_slice($rgbEntry, 0, 3)
                                                );
                                            } else {
                                                $rgb = [0,0,0];
                                            }

                                            $expanded .= chr($rgb[0]) . chr($rgb[1]) . chr($rgb[2]);
                                        }
                                    }

                                    $decoded = $expanded;
                                    $channels = 3;
                                }

                                        // --- Ensure full buffer ---
                                        $expected_len = $width * $height * $channels;
                                if (strlen($decoded) < $expected_len) {
                                    $decoded = str_pad($decoded, $expected_len, "\0");
                                }

                                        $is_jpeg_obj = in_array('DCTDecode', $filters, true);

                                        // --- Exact XObject hash (new seals) ---
                                        // Hash raw compressed stream bytes — identical between PASS 1 and PASS 2.
                                        // $stream_data is set before any decoding/predictor expansion.
                                if ($use_exact_hashes) {
                                    $check_hash        = hash('sha256', $stream_data);
                                    $hash_method_label = 'Exact XObject stream (sha256)';
                                    $img_id            = $check_hash;
                                } else {
                                    // Legacy seal: thumbnail 8×8 quantised comparison
                                    $gd_available = function_exists('imagecreatefromstring');
                                    $gd_src = ($is_jpeg_obj && $gd_available)
                                        ? @imagecreatefromstring($decoded)
                                        : null;
                                    if (!$is_jpeg_obj && $gd_available) {
                                        $gd_src = imagecreatetruecolor($width, $height);
                                        $idx = 0;
                                        for ($ty = 0; $ty < $height; $ty++) {
                                            for ($tx = 0; $tx < $width; $tx++) {
                                                if ($colorspace === 'IndexedRGB' || $colorspace === 'DeviceRGB') {
                                                    $tr = ord($decoded[$idx++] ?? "\x00");
                                                    $tg = ord($decoded[$idx++] ?? "\x00");
                                                    $tb = ord($decoded[$idx++] ?? "\x00");
                                                } elseif ($colorspace === 'DeviceGray') {
                                                    $tg = ord($decoded[$idx++] ?? "\x00");
                                                    $tr = $tb = $tg;
                                                } else {
                                                    $tc  = ord($decoded[$idx++] ?? "\x00") / 255;
                                                    $tm  = ord($decoded[$idx++] ?? "\x00") / 255;
                                                    $tyC = ord($decoded[$idx++] ?? "\x00") / 255;
                                                    $tk  = ord($decoded[$idx++] ?? "\x00") / 255;
                                                    $tr  = (int)(255 * (1 - min(1, $tc + $tk)));
                                                    $tg  = (int)(255 * (1 - min(1, $tm + $tk)));
                                                    $tb  = (int)(255 * (1 - min(1, $tyC + $tk)));
                                                }
                                                imagesetpixel(
                                                    $gd_src,
                                                    $tx,
                                                    $ty,
                                                    imagecolorallocate($gd_src, $tr, $tg, $tb)
                                                );
                                            }
                                        }
                                    }
                                    if ($gd_src !== false && $gd_src !== null) {
                                        $thumb = imagecreatetruecolor(8, 8);
                                        imagecopyresampled(
                                            $thumb,
                                            $gd_src,
                                            0,
                                            0,
                                            0,
                                            0,
                                            8,
                                            8,
                                            imagesx($gd_src),
                                            imagesy($gd_src)
                                        );
                                        if (!$is_jpeg_obj) {
                                            imagedestroy($gd_src);
                                        }
                                        $pixels = '';
                                        for ($ty = 0; $ty < 8; $ty++) {
                                            for ($tx = 0; $tx < 8; $tx++) {
                                                $tc = imagecolorat($thumb, $tx, $ty);
                                                $pixels .= chr((($tc >> 16) & 0xFF) & ~7)
                                                         . chr((($tc >> 8)  & 0xFF) & ~7)
                                                         . chr(($tc         & 0xFF) & ~7);
                                            }
                                        }
                                        imagedestroy($thumb);
                                        $img_id            = hash('sha256', $pixels);
                                        $hash_method_label = 'Thumbnail 8×8 quantised (thumbnail8x8q8)';
                                    } else {
                                        $dim_key           = $colorspace . '|' . $width . '|' . $height;
                                        $img_id            = hash('sha256', $dim_key);
                                        $hash_method_label = 'Dimension fingerprint (GD decode failed)';
                                    }
                                    $check_hash = $img_id;
                                }

                                        $uid = 'img_' . uniqid();

                                        // Determine file extension
                                        $ext = in_array('DCTDecode', $filters, true) ? 'jpg' : 'png';
                                        $imgFile = $ver_dir . "/xobject_{$check_hash}.{$ext}";

                                        // --- Emit empty slot FIRST (no logic) ---
                                // --- Prepare slot metadata ---
                                $is_allowed = $use_exact_hashes
                                    ? in_array($check_hash, $exact_image_hashes, true)
                                    : in_array($check_hash, $allowed_hashes, true);

                                        self::emitImageSlot(
                                            $uid,
                                            [
                                            'colorspace'  => $colorspace,
                                            'width'       => $width,
                                            'height'      => $height,
                                            'img_id'      => $img_id,
                                            'decoded_len' => strlen($decoded),
                                            'allowed'     => $is_allowed ? 1 : 0,
                                            'file_path'   => $imgFile,
                                            ]
                                        );
                                self::$image_slots[$uid] = [
                                    'allowed' => $is_allowed ? 1 : 0,
                                    'colorspace' => $colorspace,
                                    'width' => $width,
                                    'height' => $height,
                                    'isBackground' => false,
                                ];

                                // --- Decode SMask (alpha channel) for this image, if any ---
                                // Detect directly from the image's own dictionary (avoids the
                                // expensive full-PDF obj scan that caused catastrophic backtracking).
                                $smask_decoded = null;
                                $_sm_ref_num   = null;
                                if (preg_match('/\/SMask\s+(\d+)\s+\d+\s+R/', $fullObj, $_sm_ref)) {
                                    $_sm_ref_num = $_sm_ref[1];
                                    unset($_sm_ref);
                                }
                                if ($_sm_ref_num !== null) {
                                    $sm_num = $_sm_ref_num;
                                    if (preg_match(
                                        '/\b' . preg_quote($sm_num, '/') . '\s+\d+\s+obj\b(.*?)endobj/s',
                                        $pdf_raw,
                                        $_sm_obj
                                    )) {
                                        $sm_body = $_sm_obj[1];
                                        preg_match_all('/\/Filter\s*\/([A-Za-z0-9]+)/', $sm_body, $_sm_f);
                                        $sm_filters = $_sm_f[1] ?? [];
                                        if (preg_match('/stream\r?\n(.*?)\r?\nendstream/s', $sm_body, $_sm_s)) {
                                            $sm_raw  = $_sm_s[1];
                                            $sm_data = $sm_raw;
                                            if (in_array('FlateDecode', $sm_filters, true)) {
                                                $sm_try = (strlen($sm_raw) <= 67108864) ? @gzuncompress($sm_raw) : false;
                                                if ($sm_try !== false && strlen($sm_try) <= 67108864) {
                                                    $sm_data = $sm_try;
                                                }
                                            }
                                            // Undo PNG predictor for 1-channel (DeviceGray) SMask.
                                            $sm_predictor = 1;
                                            if (preg_match('/\/DecodeParms\s*<<(.*?)>>/s', $sm_body, $_sm_dp)
                                                && preg_match('/\/Predictor\s+(\d+)/', $_sm_dp[1], $_sm_pp)
                                            ) {
                                                $sm_predictor = (int)$_sm_pp[1];
                                            }
                                            if ($sm_predictor >= 10) {
                                                $sm_out  = '';
                                                $sm_prev = str_repeat("\0", $width);
                                                $sm_i    = 0;
                                                for ($sy = 0; $sy < $height; $sy++) {
                                                    if ($sm_i >= strlen($sm_data)) {
                                                        break;
                                                    }
                                                    $sm_ftype = ord($sm_data[$sm_i++]);
                                                    $sm_row   = str_pad(
                                                        substr($sm_data, $sm_i, $width),
                                                        $width,
                                                        "\0"
                                                    );
                                                    $sm_i += $width;
                                                    for ($sx = 0; $sx < $width; $sx++) {
                                                        $sc   = ord($sm_row[$sx]);
                                                        $sup  = ord($sm_prev[$sx]);
                                                        $slft = $sx > 0 ? ord($sm_row[$sx - 1]) : 0;
                                                        switch ($sm_ftype) {
                                                            case 1:
                                                                $sm_row[$sx] = chr(($sc + $slft) & 0xFF);
                                                                break;
                                                            case 2:
                                                                $sm_row[$sx] = chr(($sc + $sup) & 0xFF);
                                                                break;
                                                            case 3:
                                                                $sm_row[$sx] = chr(
                                                                    ($sc + intdiv($slft + $sup, 2)) & 0xFF
                                                                );
                                                                break;
                                                            case 4:
                                                                $spc   = $sx > 0 ? ord($sm_prev[$sx - 1]) : 0;
                                                                $sp    = $slft + $sup - $spc;
                                                                $spa   = abs($sp - $slft);
                                                                $spb   = abs($sp - $sup);
                                                                $spc_a = abs($sp - $spc);
                                                                $spaeth = ($spa <= $spb && $spa <= $spc_a)
                                                                    ? $slft : (($spb <= $spc_a) ? $sup : $spc);
                                                                $sm_row[$sx] = chr(($sc + $spaeth) & 0xFF);
                                                                break;
                                                            default:
                                                                $sm_row[$sx] = chr($sc);
                                                        }
                                                    }
                                                    $sm_out  .= $sm_row;
                                                    $sm_prev  = $sm_row;
                                                }
                                                $sm_data = $sm_out;
                                            }
                                            $smask_decoded = $sm_data;
                                        }
                                        unset($_sm_obj, $_sm_f, $_sm_s, $_sm_dp, $_sm_pp);
                                    }
                                }

                                // Create image file (disk cache) and build a data URI for inline display.
                                // Images are served as data URIs so the .htaccess-protected verimages/
                                // directory never needs to be HTTP-accessible.
                                $data_uri = '';
                                if (!file_exists($imgFile)) {
                                    if ($ext === 'jpg') {
                                        file_put_contents($imgFile, $decoded);
                                        $data_uri = 'data:image/jpeg;base64,' . base64_encode($decoded);
                                    } else {
                                        $im = imagecreatetruecolor($width, $height);
                                        // Enable alpha so the SMask can be written as transparency.
                                        imagealphablending($im, false);
                                        imagesavealpha($im, true);
                                        $idx = 0;

                                        for ($y = 0; $y < $height; $y++) {
                                            for ($x = 0; $x < $width; $x++) {
                                                if ($colorspace === 'IndexedRGB' || $colorspace === 'DeviceRGB') {
                                                    $r = ord($decoded[$idx++]);
                                                    $g = ord($decoded[$idx++]);
                                                    $b = ord($decoded[$idx++]);
                                                } elseif ($colorspace === 'DeviceGray') {
                                                    $g = ord($decoded[$idx++]);
                                                    $r = $b = $g;
                                                } else { // CMYK
                                                    $c = ord($decoded[$idx++]) / 255;
                                                    $m = ord($decoded[$idx++]) / 255;
                                                    $yC = ord($decoded[$idx++]) / 255;
                                                    $k = ord($decoded[$idx++]) / 255;
                                                    $r = (int)(255 * (1 - min(1, $c + $k)));
                                                    $g = (int)(255 * (1 - min(1, $m + $k)));
                                                    $b = (int)(255 * (1 - min(1, $yC + $k)));
                                                }
                                                if ($invert) {
                                                    $r = 255 - $r;
                                                    $g = 255 - $g;
                                                    $b = 255 - $b;
                                                }
                                                // SMask: 0=transparent, 255=opaque.
                                                // GD alpha: 0=opaque, 127=transparent.
                                                if ($smask_decoded !== null) {
                                                    $sm_byte  = ord($smask_decoded[$y * $width + $x] ?? "\xff");
                                                    $gd_alpha = (int)((255 - $sm_byte) / 2); // truncate: max 127 when sm_byte=0
                                                } else {
                                                    $gd_alpha = 0; // fully opaque
                                                }
                                                imagesetpixel(
                                                    $im,
                                                    $x,
                                                    $y,
                                                    imagecolorallocatealpha($im, $r, $g, $b, $gd_alpha)
                                                );
                                            }
                                        }
                                        imagepng($im, $imgFile, 9);
                                        imagedestroy($im);
                                        $raw_png = file_get_contents($imgFile);
                                        if ($raw_png !== false) {
                                            $data_uri = 'data:image/png;base64,' . base64_encode($raw_png);
                                        }
                                    }
                                } else {
                                    // Already cached — read from disk for the data URI.
                                    $cached = file_get_contents($imgFile);
                                    if ($cached !== false) {
                                        $mime_type = $ext === 'jpg' ? 'image/jpeg' : 'image/png';
                                        $data_uri  = "data:{$mime_type};base64," . base64_encode($cached);
                                    }
                                }

                                // --- Build HTML content ---
                                // Image is embedded as a data URI — the verimages/ directory
                                // is HTTP-blocked by .htaccess so no public URL is used.
                                $html  = "<div style='margin:10px 0; padding:8px; border:1px solid #ccc'>";

                                $html .= "<div style='font-size:10px;color:#666;margin-bottom:4px'>";
                                $html .= "{$colorspace} | {$width}×{$height} | {$channels}ch";
                                $html .= "</div>";

                                // --- SUSPECT FOUND LABEL (RESTORED) ---
                                $html .= "<div style='margin-bottom:4px'>";
                                $html .= "<b>Suspect Found:</b> " . esc_html((string)$check_hash);
                                $html .= "</div>";

                                // --- STATUS AREA ---
                                $html .= "<div class='img-status' style='margin-bottom:6px'>";
                                if ($is_allowed) {
                                    $html .= "<span style='color:green;font-weight:bold'>"
                                           . "Suspect is determined as usual.</span>";
                                } else {
                                    $html .= "<span style='color:red;font-weight:bold'>Visual mismatch detected</span>";
                                    $html .= "<div style='margin-top:6px;padding:6px;"
                                           . "background:#fff0f0;border:1px solid #f99;"
                                           . "font-size:11px;font-family:monospace'>";
                                    $html .= "<b>Why flagged:</b><br>";
                                    $html .= "Hash method: " . esc_html($hash_method_label ?? '') . "<br>";
                                    $html .= "Computed hash: <b>{$check_hash}</b><br>";
                                    $pool  = $use_exact_hashes ? $exact_image_hashes : $allowed_hashes;
                                    $html .= "Allowed hashes in seal (" . count($pool) . "):<br>";
                                    foreach ($pool as $ah) {
                                        $html .= "&nbsp;&nbsp;" . esc_html($ah) . "<br>";
                                    }
                                    $html .= "</div>";
                                }
                                $html .= "</div>";

                                // --- IMAGE ---
                                if ($data_uri !== '') {
                                    $html .= "<img src='" . esc_attr($data_uri) . "'"
                                           . " style='max-width:100%; height:auto;"
                                           . " border:1px solid #999; display:block; margin-bottom:6px'>";
                                } else {
                                    $html .= "<p style='color:orange'>[Image could not be rendered]</p>";
                                }

                                // --- IMAGE INFO (now BELOW image) ---
                                $html .= "<div style='margin:5px 0; padding:5px;"
                                       . " border:1px solid #666; background:#f9f9f9; font-size:10px'>";
                                $html .= "Image ID: <b>{$img_id}</b><br>";
                                $html .= "Colorspace: {$colorspace}<br>";
                                $html .= "Width × Height: {$width}×{$height}<br>";
                                $html .= "Decoded length: " . strlen($decoded) . " bytes";
                                $html .= "</div>";

                                $html .= "</div>";

                                // --- Fill slot ---
                                self::fillImageSlot($uid, $html);
                            }

                                    // --- Recurse if Form XObject ---
                            $xObjDict     = [];
                            $is_form_xobj = str_contains($fullObj, '/Subtype /Form')
                                && preg_match('/\/XObject\s*<<(.+?)>>/is', $fullObj, $xObjDict);
                            if ($is_form_xobj) {
                                preg_match_all('/\/[A-Za-z0-9]+\s+(\d+\s+0\s+R)/', $xObjDict[1], $refs);
                                if (!empty($refs[1])) {
                                    foreach ($refs[1] as $ref) {
                                        $objRefNum = preg_replace('/\s0 R/', '', $ref);
                                        // Guard against circular /XObject references (A -> B -> A),
                                        // which would otherwise recurse indefinitely and exhaust
                                        // the call stack / memory on a crafted PDF.
                                        if (isset($visited[$objRefNum])) {
                                            continue;
                                        }
                                        $pattern = '/' . preg_quote($objRefNum, '/') . '\s0\sobj(.*?)endobj/s';
                                        if (preg_match($pattern, $pdf_raw, $refObj)) {
                                            $scanXObjects($refObj[0], $objRefNum, $visited + [$objRefNum => true]);
                                        }
                                    }
                                }
                            }

                                    $offset = $obj_end + 6;
                        }

                        if (!$found && !$parentName) {
                            echo "[XObject Scan] No XObjects found.\n";
                        }
                    };

                    $scanXObjects($pdf_raw);

                    // --- Final visual classification ---
                    $contains_background_images = false; // retained for downstream verdict compat
                    $image_missmatch            = false;

                    foreach (self::$image_slots as $slot) {
                        if ((int)$slot['allowed'] !== 1) {
                            $image_missmatch = true;
                        }
                    }
                }

                echo "</div>"; // close image section foldable content
                echo "</div>"; // close image section wrapper


                /* ─────────────────────── CONTENT STREAM INTEGRITY ─────────────────────── */

                $allowed_content_hashes = $rebuilt_payload['content_streams'] ?? [];
                $content_stream_mismatch = false;

                self::setProgress(__('Checking content streams…', 'form-forge'), 87);

                $cs_section_id  = 'forge-pdf-content-streams-' . $uid_prefix;
                $cs_section_sec = 'forge-pdf-section-streams-' . esc_attr($uid_prefix);
                echo "<div class='forge-pdf-detail-section' id='{$cs_section_sec}'>";
                echo "<div class='forge-pdf-detail-hdr'>";
                echo "<button type='button' class='button button-small forge-pdf-toggle'"
                   . " data-target='" . esc_attr($cs_section_id) . "'>Content Streams</button>";
                $cs_badge_id = 'forge-pdf-badge-streams-' . esc_attr($uid_prefix);
                echo "<span id='{$cs_badge_id}' class='forge-pdf-detail-badge forge-pdf-badge-pass'>PASS</span>";
                echo "</div>";
                echo "<div id='" . esc_attr($cs_section_id) . "' class='forge-pdf-hidden forge-pdf-detail-content'>";

                if (empty($allowed_content_hashes)) {
                    echo "<p class='forge-pdf-empty-state'>No content stream hashes in seal "
                       . "(PDF generated before this feature was added).</p>";
                } else {
                    // Extract all page content streams from the PDF (exclude seal stream and binary streams)
                    $pdf_content_hashes = [];
                    $cs_offset = 0;
                    while (true) {
                        $cs_pos  = strpos($pdf_raw, "stream\r\n", $cs_offset);
                        $cs_pos2 = strpos($pdf_raw, "stream\n", $cs_offset);
                        if ($cs_pos === false && $cs_pos2 === false) {
                            break;
                        }
                        if ($cs_pos === false) {
                            $cs_pos = $cs_pos2;
                        } elseif ($cs_pos2 !== false && $cs_pos2 < $cs_pos) {
                            $cs_pos = $cs_pos2;
                        }

                        $cs_eol  = (substr($pdf_raw, $cs_pos + 6, 2) === "\r\n") ? 2 : 1;
                        $cs_bs   = $cs_pos + 6 + $cs_eol;
                        $cs_be   = strpos($pdf_raw, 'endstream', $cs_bs);
                        if ($cs_be === false) {
                            $cs_offset = $cs_pos + 7;
                            continue;
                        }

                        $cs_body = substr($pdf_raw, $cs_bs, $cs_be - $cs_bs);
                        $cs_dec  = (strlen($cs_body) <= 67108864) ? @gzuncompress($cs_body) : false;
                        if ($cs_dec === false) {
                            $cs_dec = (strlen($cs_body) <= 67108864) ? @gzinflate(substr($cs_body, 2)) : false;
                        }
                        if ($cs_dec !== false && strlen($cs_dec) > 67108864) {
                            $cs_dec = false; // decompression bomb: discard
                        }
                        // Fall back to raw bytes for uncompressed streams — prevents
                        // uncompressed injected streams from being silently skipped.
                        $cs_check = $cs_dec !== false ? $cs_dec : $cs_body;

                        if (self::isPageContentStream($cs_check)) {
                            // Skip the seal stream (differs between Pass 1 and Pass 2).
                            $is_seal = str_contains($cs_check, '---BEGIN-SEAL---')
                                    || str_contains($cs_check, "\x00-\x00-\x00-\x00B\x00E\x00G\x00I\x00N");
                            if (!$is_seal) {
                                $pdf_content_hashes[] = hash('sha256', $cs_check);
                            }
                        }

                        $cs_offset = $cs_be + 9;
                    }

                    $seal_hash_set = array_flip($allowed_content_hashes);

                    $n_seal = count($allowed_content_hashes);
                    $n_pdf  = count($pdf_content_hashes);
                    echo "<p class='forge-pdf-hash-summary'>"
                       . "{$n_seal} stream(s) in seal &nbsp;·&nbsp; {$n_pdf} verifiable in PDF</p>";
                    echo "<div class='forge-pdf-hash-list'>";
                    foreach ($pdf_content_hashes as $pdf_hash) {
                        $short = esc_html(substr($pdf_hash, 0, 20));
                        if (isset($seal_hash_set[$pdf_hash])) {
                            echo "<div class='forge-pdf-hash-row forge-pdf-hash-row--pass'>"
                               . "<span class='forge-pdf-pill forge-pdf-pill--pass'>MATCH</span>"
                               . "<code>{$short}…</code></div>";
                        } else {
                            $content_stream_mismatch = true;
                            echo "<div class='forge-pdf-hash-row forge-pdf-hash-row--fail'>"
                               . "<span class='forge-pdf-pill forge-pdf-pill--fail'>UNRECOGNISED</span>"
                               . "<code>{$short}…</code>"
                               . "<span style='font-size:11px;color:#721c24;'>not in seal</span></div>";
                        }
                    }
                    echo "</div>";
                    if (!$content_stream_mismatch) {
                        echo "<p style='margin-top:8px;'>"
                           . "<span class='forge-pdf-pill forge-pdf-pill--pass'>All streams accounted for</span></p>";
                    }
                }

                echo "</div>"; // close content streams foldable content
                echo "</div>"; // close content streams section wrapper

                /* ─────────────────────── GENERIC TYPE PROCESSING ──────────────────────── */

                self::setProgress(__('Checking fonts…', 'form-forge'), 94);

                // ── Fonts section ─────────────────────────────────────────────────────
                $fonts_section_id  = 'forge-pdf-content-fonts-' . $uid_prefix;
                $fonts_section_sec = 'forge-pdf-section-fonts-' . esc_attr($uid_prefix);
                echo "<div class='forge-pdf-detail-section' id='{$fonts_section_sec}'>";
                echo "<div class='forge-pdf-detail-hdr'>";
                echo "<button type='button' class='button button-small forge-pdf-toggle'"
                   . " data-target='" . esc_attr($fonts_section_id) . "'>Fonts</button>";
                $fonts_badge_id = 'forge-pdf-badge-fonts-' . esc_attr($uid_prefix);
                echo "<span id='{$fonts_badge_id}' class='forge-pdf-detail-badge forge-pdf-badge-pass'>PASS</span>";
                echo "</div>";
                echo "<div id='" . esc_attr($fonts_section_id) . "'"
                   . " class='forge-pdf-hidden forge-pdf-detail-content'>";

                if (preg_match_all('/\/Type\s*\/(\w+)/', $pdf_raw, $matches, PREG_SET_ORDER)) {
                    $verified_fonts = null;

                    foreach ($matches as $m) {
                        $type = $m[1];

                        if ($type !== 'Font') {
                            continue;
                        }

                        if ($verified_fonts === null) {
                            $verified_fonts = [
                                'used'       => [],
                                'missing'    => [],
                                'unexpected' => [],
                            ];

                            $allowed_fonts = $rebuilt_payload['fonts'] ?? [];

                            // --- 1a. Collect font references from /Resources ---
                            $font_refs = [];
                            if (preg_match_all('/\/Font\s*<<([\s\S]*?)>>/i', $pdf_raw, $blocks)) {
                                foreach ($blocks[1] as $block) {
                                    if (preg_match_all('/\/\w+\s+(\d+\s+\d+)\s+R/', $block, $m)) {
                                        foreach ($m[1] as $ref) {
                                            $font_refs[$ref] = true;
                                        }
                                    }
                                }
                            }

                            // --- 1b. Resolve font objects & extract BaseFont ---
                            foreach (array_keys($font_refs) as $ref) {
                                if (!preg_match('/' . preg_quote($ref, '/') . '\s+obj\s*<<(.*?)>>/is', $pdf_raw, $obj)) {
                                    continue;
                                }
                                if (!preg_match('/\/BaseFont\s*\/([^\s\/]+)/', $obj[1], $bf)) {
                                    continue;
                                }

                                // Normalize subset prefix
                                $font_name = preg_replace('/^[A-Z]{6}\+/', '', $bf[1]);
                                $verified_fonts['used'][$font_name] = true;
                            }

                            $used_fonts = array_keys($verified_fonts['used']);

                            // --- 1c. Compare ---
                            foreach ($used_fonts as $font) {
                                if (!in_array($font, $allowed_fonts, true)) {
                                    $verified_fonts['unexpected'][] = $font;
                                }
                            }

                            foreach ($allowed_fonts as $font) {
                                if (!in_array($font, $used_fonts, true)) {
                                    $verified_fonts['missing'][] = $font;
                                }
                            }

                            $all_font_names = array_unique(array_merge($allowed_fonts, $used_fonts));
                            sort($all_font_names);
                            echo "<div class='forge-pdf-hash-list'>";
                            foreach ($all_font_names as $font) {
                                $in_seal = in_array($font, $allowed_fonts, true);
                                $in_pdf  = in_array($font, $used_fonts, true);
                                if ($in_seal && $in_pdf) {
                                    $row_cls = 'forge-pdf-hash-row--pass';
                                    $pill    = "<span class='forge-pdf-pill forge-pdf-pill--pass'>OK</span>";
                                } elseif (!$in_seal && $in_pdf) {
                                    $row_cls = 'forge-pdf-hash-row--fail';
                                    $pill    = "<span class='forge-pdf-pill forge-pdf-pill--fail'>UNDECLARED</span>";
                                } else {
                                    $row_cls = 'forge-pdf-hash-row--warn';
                                    $pill    = "<span class='forge-pdf-pill forge-pdf-pill--warn'>UNUSED</span>";
                                }
                                echo "<div class='forge-pdf-hash-row {$row_cls}'>"
                                   . $pill
                                   . "<code>" . esc_html($font) . "</code>"
                                   . "</div>";
                            }
                            echo "</div>";

                            if ($verified_fonts['unexpected'] || $verified_fonts['missing']) {
                                $font_missmatch = true;
                            }
                        }
                    }
                }

                if (!$font_missmatch) {
                    echo "<p style='margin-top:8px;'>"
                       . "<span class='forge-pdf-pill forge-pdf-pill--pass'>All fonts match the seal</span></p>";
                }

                echo "</div>"; // close fonts foldable content
                echo "</div>"; // close fonts section wrapper

                // ── PDF Objects section ────────────────────────────────────────────────
                $objects_section_id  = 'forge-pdf-content-objects-' . $uid_prefix;
                $objects_section_sec = 'forge-pdf-section-objects-' . esc_attr($uid_prefix);
                echo "<div class='forge-pdf-detail-section' id='{$objects_section_sec}'>";
                echo "<div class='forge-pdf-detail-hdr'>";
                echo "<button type='button' class='button button-small forge-pdf-toggle'"
                   . " data-target='" . esc_attr($objects_section_id) . "'>PDF Objects</button>";
                $objects_badge_id = 'forge-pdf-badge-objects-' . esc_attr($uid_prefix);
                echo "<span id='{$objects_badge_id}' class='forge-pdf-detail-badge forge-pdf-badge-pass'>PASS</span>";
                echo "</div>";
                echo "<div id='" . esc_attr($objects_section_id) . "'"
                   . " class='forge-pdf-hidden forge-pdf-detail-content'>";

                $unexpected_types = [];
                if (!empty($matches)) {
                    // Whitelist
                    $safe_types = [
                        'Pages', 'Catalog', 'ExtGState', 'Pattern',
                        'FontDescriptor', 'Group', 'XRef', 'ObjStm', 'Trailer',
                    ];
                    foreach ($matches as $m) {
                        $type = $m[1];
                        if (in_array($type, $safe_types, true)) {
                            continue;
                        }
                        if ($type === 'Page' && $pagecount_mismatch === false) {
                            continue;
                        }
                        if ($type === 'Annot' && $annotation_mismatch === false) {
                            continue;
                        }
                        if ($type === 'Font' && $font_missmatch === false) {
                            continue;
                        }
                        if ($type === 'XObject' && $image_missmatch === false) {
                            if (preg_match('/\/Subtype\s*\/Image/i', $pdf_raw)) {
                                continue;
                            }
                        }
                        $unexpected_types[] = $type;
                        $unexpected_detected = true;
                    }
                }

                if ($unexpected_detected) {
                    echo "<div class='forge-pdf-tag-list'>";
                    foreach (array_unique($unexpected_types) as $utype) {
                        echo "<span class='forge-pdf-tag'>" . esc_html($utype) . "</span>";
                    }
                    echo "</div>";
                } else {
                    echo "<p class='forge-pdf-empty-state'>No unexpected PDF objects detected.</p>";
                }

                echo "</div>"; // close objects foldable content
                echo "</div>"; // close objects section wrapper
            }

            // --- Collect inner detail HTML ---
            $inner_html = ob_get_clean();

            // --- Post-process: update badge classes based on computed booleans ---
            $bdg_pass = "' class='forge-pdf-detail-badge forge-pdf-badge-pass'>PASS";
            $bdg_fail = "' class='forge-pdf-detail-badge forge-pdf-badge-fail'>FAIL";
            $bdg_flip = static function (
                string &$html,
                string $id,
                string $pass,
                string $fail
            ): void {
                $html = str_replace("id='" . $id . $pass, "id='" . $id . $fail, $html);
            };

            if ($field_mismatch_count > 0) {
                $bdg_flip(
                    $inner_html,
                    'forge-pdf-badge-fields-' . $uid_prefix,
                    $bdg_pass,
                    $bdg_fail
                );
            }
            if ($annotation_fail_count > 0) {
                $bdg_flip(
                    $inner_html,
                    'forge-pdf-badge-annots-' . $uid_prefix,
                    $bdg_pass,
                    $bdg_fail
                );
            }
            if ($image_missmatch) {
                $bdg_flip(
                    $inner_html,
                    'forge-pdf-badge-images-' . $uid_prefix,
                    $bdg_pass,
                    $bdg_fail
                );
            }
            if ($font_missmatch) {
                $bdg_flip(
                    $inner_html,
                    'forge-pdf-badge-fonts-' . $uid_prefix,
                    $bdg_pass,
                    $bdg_fail
                );
            }
            if ($unexpected_detected) {
                $bdg_flip(
                    $inner_html,
                    'forge-pdf-badge-objects-' . $uid_prefix,
                    $bdg_pass,
                    $bdg_fail
                );
            }
            if ($content_stream_mismatch) {
                $bdg_flip(
                    $inner_html,
                    'forge-pdf-badge-streams-' . $uid_prefix,
                    $bdg_pass,
                    $bdg_fail
                );
            }

            // --- PDF Metadata integrity check ---
            $meta_mismatch  = false;
            $sealed_meta    = $rebuilt_payload['pdf_meta'] ?? [];
            $meta_section_id = 'forge-pdf-content-meta-' . $uid_prefix;

            if (!empty($sealed_meta)) {
                // Helper: decode a PDF string value — plain ASCII or UTF-16BE (þÿ BOM).
                $decode_pdf_str = static function (string $raw): string {
                    // Strip surrounding parens if present
                    $raw = trim($raw);
                    if (str_starts_with($raw, '(') && str_ends_with($raw, ')')) {
                        $raw = substr($raw, 1, -1);
                    }
                    // UTF-16BE: starts with BOM \xFE\xFF
                    if (str_starts_with($raw, "\xFE\xFF")) {
                        $decoded = mb_convert_encoding(substr($raw, 2), 'UTF-8', 'UTF-16BE');
                        return $decoded !== false ? $decoded : $raw;
                    }
                    return $raw;
                };

                // Extract /Info dict — use the LAST definition of the referenced object
                // so that incremental updates (which append overriding object definitions)
                // are read the same way a PDF viewer would read them.
                $pdf_meta_found = ['title' => '', 'author' => '', 'creator' => ''];
                if (preg_match('/\/Info\s+(\d+)\s+\d+\s+R/', $pdf_raw, $info_ref)) {
                    $obj_num      = $info_ref[1];
                    $info_pattern = '/' . preg_quote($obj_num, '/') . '\s+\d+\s+obj\s*<<(.*?)>>/s';
                    // preg_match_all + take last match = "last definition wins"
                    if (preg_match_all($info_pattern, $pdf_raw, $info_matches) && !empty($info_matches[1])) {
                        $info_dict = end($info_matches[1]);
                        foreach (['Title' => 'title', 'Author' => 'author', 'Creator' => 'creator'] as $key => $slot) {
                            if (preg_match('/\/' . $key . '\s*\(([^)]*)\)/', $info_dict, $vm)) {
                                $pdf_meta_found[$slot] = $decode_pdf_str('(' . $vm[1] . ')');
                            } elseif (preg_match('/\/' . $key . '\s*<([^>]*)>/', $info_dict, $vm)) {
                                $hex       = preg_replace('/\s+/', '', $vm[1]);
                                $raw_bytes = hex2bin($hex);
                                $pdf_meta_found[$slot] = $raw_bytes !== false
                                    ? $decode_pdf_str($raw_bytes) : '';
                            }
                        }
                    }
                }

                ob_start();
                echo "<div class='forge-pdf-detail-section' id='forge-pdf-section-meta-" . esc_attr($uid_prefix) . "'>";
                echo "<div class='forge-pdf-detail-hdr'>";
                echo "<button type='button' class='button button-small forge-pdf-toggle'"
                   . " data-target='" . esc_attr($meta_section_id) . "'>PDF Metadata</button>";
                echo "<span id='forge-pdf-badge-meta-" . esc_attr($uid_prefix) . "'"
                   . " class='forge-pdf-detail-badge forge-pdf-badge-pass'>PASS</span>";
                echo "</div>";
                echo "<div id='" . esc_attr($meta_section_id) . "' class='forge-pdf-hidden forge-pdf-detail-content'>";
                echo "<div class='forge-pdf-hash-list'>";

                $meta_labels = ['title' => 'Title', 'author' => 'Author', 'creator' => 'Creator'];
                foreach ($meta_labels as $slot => $label) {
                    $expected = trim((string)($sealed_meta[$slot] ?? ''));
                    $actual   = trim((string)($pdf_meta_found[$slot] ?? ''));
                    $match    = ($expected === $actual);
                    if (!$match) {
                        $meta_mismatch = true;
                    }
                    $row_cls    = $match ? 'forge-pdf-hash-row--pass' : 'forge-pdf-hash-row--fail';
                    $pill_cls   = $match ? 'forge-pdf-pill--pass' : 'forge-pdf-pill--fail';
                    $pill_text  = $match ? __('MATCH', 'form-forge') : __('MISMATCH', 'form-forge');
                    echo "<div class='forge-pdf-hash-row {$row_cls}'>"
                       . "<span class='forge-pdf-pill {$pill_cls}'>{$pill_text}</span>"
                       . "<code>" . esc_html($label) . "</code>"
                       . "<span style='color:#50575e;font-size:11px;margin-left:4px;'>"
                       . esc_html($actual !== '' ? $actual : '(leer)')
                       . ($match ? '' : ' <em style="color:#d63638;">erwartet: ' . esc_html($expected) . '</em>')
                       . "</span>"
                       . "</div>";
                }

                echo "</div>"; // forge-pdf-hash-list
                echo "</div>"; // meta section content
                echo "</div>"; // meta section wrapper
                $meta_html = ob_get_clean();
                $inner_html = ($meta_html ?: '') . ($inner_html ?? '');
            }

            if ($meta_mismatch) {
                $meta_badge_pass = "id='forge-pdf-badge-meta-{$uid_prefix}'"
                    . " class='forge-pdf-detail-badge forge-pdf-badge-pass'>PASS";
                $meta_badge_fail = "id='forge-pdf-badge-meta-{$uid_prefix}'"
                    . " class='forge-pdf-detail-badge forge-pdf-badge-fail'>FAIL";
                $inner_html = str_replace($meta_badge_pass, $meta_badge_fail, $inner_html ?? '');
            }

            // --- Font program integrity check ---
            $font_prog_mismatch = false;
            if ($pdf_raw !== false) {
                $sealed_fp = array_values(array_map('strval', (array) ($seal_data['font_prog_hashes'] ?? [])));
                $live_fp   = self::hashFontProgramStreams((string) $pdf_raw);
                sort($sealed_fp);
                sort($live_fp);
                $font_prog_mismatch = ($live_fp !== $sealed_fp);
                if ($font_prog_mismatch) {
                    $font_missmatch = true;
                }
            }

            // --- All-stream fingerprint check (Gap B catch-all) ---
            // Subset model (same as content_streams): flag when a live stream has no
            // matching sealed hash — that means an unknown stream was injected.
            // A sealed hash absent from the live PDF is NOT flagged; stream removal
            // doesn't inject content, and PASS 1 / PASS 2 may differ in stream count.
            $all_stream_mismatch = false;
            if ($pdf_raw !== false) {
                $sealed_as_index = array_flip(
                    array_map('strval', (array) ($seal_data['all_stream_hashes'] ?? []))
                );
                foreach (self::hashAllCompressedStreams((string) $pdf_raw) as $h) {
                    if (!isset($sealed_as_index[$h])) {
                        $all_stream_mismatch = true;
                        break;
                    }
                }
            }

            // --- Final verdict computation ---
            // $seal_matches comes from HashSeal::verify() using hash_equals() (constant-time)
            // against a key resolved server-side by key_id lookup — never derived from the
            // uploaded PDF's own seal payload, so a forged seal can't supply its own "valid" key.
            // Structural-tamper flags are OR'd in so the document can still fail even if the
            // seal hash alone were somehow satisfied.
            $visual_modified   = $visual_mismatch_found === true;
            $contains_background_images = (bool)($contains_background_images ?? false);
            $any_pdf_issue     = $incremental_update_detected || $multiple_seals_detected
                || $unexpected_detected || $annotation_mismatch || $pagecount_mismatch
                || $image_missmatch || $font_missmatch || $content_stream_mismatch
                || $meta_mismatch || $all_stream_mismatch;
            $document_modified = !$seal_matches || $visual_modified || $any_pdf_issue;

            // --- Summary panel ---
            echo self::renderSummaryPanel(
                [
                'seal_matches'               => $seal_matches,
                'seal_key_status'            => $seal_key_status  ?? 'active',
                'seal_compromised'           => $seal_compromised ?? false,
                'visual_modified'            => $visual_modified,
                'field_mismatch_count'       => $field_mismatch_count,
                'annotation_fail_count'      => $annotation_fail_count,
                'pagecount_mismatch'         => $pagecount_mismatch,
                'image_missmatch'            => $image_missmatch,
                'font_missmatch'             => $font_missmatch,
                'unexpected_detected'        => $unexpected_detected,
                'content_stream_mismatch'    => $content_stream_mismatch,
                'meta_mismatch'                  => $meta_mismatch,
                'incremental_update_detected'    => $incremental_update_detected,
                'incremental_update_eof_count'   => $incremental_update_eof_count ?? $eof_count ?? 1,
                'multiple_seals_detected'        => $multiple_seals_detected ?? false,
                'all_stream_mismatch'            => $all_stream_mismatch ?? false,
                'contains_background_images'     => $contains_background_images,
                'document_modified'          => $document_modified,
                'file_name'                  => $file_name,
                'uid_prefix'                 => $uid_prefix,
                'doc_nonce'                  => (string) ($seal_data['nonce'] ?? ''),
                ]
            );

            echo $inner_html;
        } catch (\Throwable $e) {
            while (ob_get_level() > $outer_ob_level) {
                ob_end_clean();
            }
            $raw_msg = $e->getMessage();
            error_log('ForgeForms Verificationpage: ' . $raw_msg);
            // Map technical exception messages to user-friendly German.
            $fn           = esc_html($file_name);
            $friendly_msg = match (true) {
                str_contains($raw_msg, 'Multiple seal blocks')
                    => $fn . ': ' . __('The document contains multiple seal blocks — possibly tampered.', 'form-forge'),
                str_contains($raw_msg, 'Seal not found')
                    => $fn . ': ' . __('No FormForge seal found. Please only upload original documents.', 'form-forge'),
                str_contains($raw_msg, 'Base64 decode')
                    => $fn . ': ' . __('The seal in the document is corrupted and cannot be read.', 'form-forge'),
                str_contains($raw_msg, 'Seal is implausibly large')
                    => $fn . ': ' . __('The seal in the document is unusually large — file rejected.', 'form-forge'),
                str_contains($raw_msg, 'Object list not found')
                    => $fn . ': ' . __('The PDF structure is invalid or the document is encrypted.', 'form-forge'),
                str_contains($raw_msg, 'not a valid PDF')
                    => $fn . ': ' . __('The file is not a valid PDF document.', 'form-forge'),
                default
                    => $fn . ': ' . __('The document could not be processed.', 'form-forge'),
            };
            echo self::noticeHtml($friendly_msg, 'error');
        } finally {
            $pdf_content = ob_get_clean();
        }

        $segment_id      = sanitize_html_class($uid_prefix . '-segment');
        $legacy_statuses = ['rotated-legacy', 'compromised-legacy'];
        $is_legacy       = in_array($seal_key_status ?? '', $legacy_statuses, true);
        $is_compromised  = ($seal_compromised ?? false)
            || ($seal_key_status ?? '') === 'compromised-legacy';

        if ($document_modified === null) {
            $verdict_label = __('Error', 'form-forge');
            $border_color  = '#b32d2e';
            $badge_bg      = '#b32d2e';
            $badge_text    = '#fff';
            $hdr_bg        = '#fff';
        } elseif ($document_modified) {
            $verdict_label = '⚠ ' . __('Modified', 'form-forge');
            $border_color  = '#d63638';
            $badge_bg      = '#d63638';
            $badge_text    = '#fff';
            $hdr_bg        = '#fff';
        } elseif ($is_compromised) {
            $verdict_label = '⚠ ' . __('Compromised Key', 'form-forge');
            $border_color  = '#d97706';
            $badge_bg      = '#d97706';
            $badge_text    = '#fff';
            $hdr_bg        = '#fff';
        } elseif (($seal_key_status ?? 'active') !== 'active') {
            $verdict_label = '↺ ' . __('Rotated Key', 'form-forge');
            $border_color  = '#65a30d';
            $badge_bg      = '#65a30d';
            $badge_text    = '#fff';
            $hdr_bg        = '#fff';
        } else {
            $verdict_label = '✓ ' . __('Authentic', 'form-forge');
            $border_color  = '#00a32a';
            $badge_bg      = '#00a32a';
            $badge_text    = '#fff';
            $hdr_bg        = '#fff';
        }

        $hdr_wrap_style = 'border:2px solid ' . esc_attr($border_color)
            . ';border-left-width:5px;margin:15px 0;border-radius:4px;overflow:hidden;';
        $hdr_btn_style  = 'display:grid;grid-template-columns:1fr auto 1fr;align-items:center;'
            . 'width:100%;padding:10px 14px;background:' . esc_attr($hdr_bg)
            . ';border:none;border-bottom:1px solid #dcdcde;cursor:pointer;font-size:13px;'
            . 'gap:12px;box-sizing:border-box;';
        $hdr_name_style = 'grid-column:2;font-weight:600;color:#1d2327;overflow:hidden;'
            . 'text-overflow:ellipsis;white-space:nowrap;text-align:center;';

        $badge_base_style = 'font-weight:700;font-size:12px;padding:3px 10px;border-radius:3px;'
            . 'letter-spacing:.5px;white-space:nowrap;';
        $verdict_badge = '<span class="forge-pdf-pdf-hdr-verdict" style="background:'
            . esc_attr($badge_bg) . ';color:' . esc_attr($badge_text) . ';' . $badge_base_style . '">'
            . esc_html($verdict_label) . '</span>';

        if ($is_legacy) {
            $legacy_badge = '<span class="forge-pdf-verdict-legacy" style="background:#1a56db;color:#fff;'
                . $badge_base_style . '">Legacy</span>';
            $hdr_right = '<span style="grid-column:3;justify-self:end;display:flex;gap:6px;'
                . 'align-items:center;">' . $verdict_badge . $legacy_badge . '</span>';
        } else {
            $hdr_right = '<span style="grid-column:3;justify-self:end;flex-shrink:0;">'
                . $verdict_badge . '</span>';
        }

        echo "<div style='" . $hdr_wrap_style . "'>";
        echo "<button type='button' class='forge-pdf-toggle forge-pdf-pdf-hdr'"
            . " data-target='" . esc_attr($segment_id) . "'"
            . " style='" . $hdr_btn_style . "'>";
        echo "<span></span>";
        echo "<span class='forge-pdf-pdf-hdr-name' style='" . $hdr_name_style . "'>"
            . esc_html($file_name) . "</span>";
        echo $hdr_right;
        echo "</button>";
        echo "<div id='" . esc_attr($segment_id) . "' class='forge-pdf-hidden' style='padding:10px;'>";
        echo $pdf_content;
        echo "</div>";
        echo "</div>";
    }


    /**
     * Scans raw PDF bytes for the FF seal marker without loading the full object graph.
     *
     * Reads each FlateDecode stream, decompresses it, and checks both byte
     * alignments of the mPDF 2-byte Unicode encoding for "---BEGIN-SEAL---".
     *
     * @param string $path Absolute filesystem path to the PDF file.
     *
     * @return bool True if the seal marker is found, false otherwise.
     */
    private static function rawPdfHasSeal(string $path): bool
    {
        // Must read the FULL file — large embedded images push page content streams
        // to the beginning of the file, well outside any 2MB tail window.
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return false;
        }

        // Find every stream…endstream block.
        preg_match_all('/<<([^>]*)>>\s*stream\r?\n([\s\S]*?)\nendstream/m', $raw, $blocks, PREG_SET_ORDER);

        foreach ($blocks as $block) {
            $dict   = $block[1];
            $stream = $block[2];

            // Only bother decompressing FlateDecode streams.
            if (!preg_match('/\/Filter\s*\/FlateDecode/', $dict)) {
                continue;
            }

            // Refuse to decompress streams that would expand beyond 64 MB —
            // a crafted FlateDecode bomb could expand 50 MB of compressed data to GBs.
            if (strlen($stream) > 67108864) {
                continue;
            }
            $dec = @gzuncompress($stream);
            if ($dec === false) {
                $dec = @gzinflate($stream);
            }
            if ($dec === false || strlen($dec) < 32 || strlen($dec) > 67108864) {
                continue;
            }

            // Try even and odd byte alignments of the 2-byte Unicode pairs.
            for ($start = 0; $start <= 1; $start++) {
                $ascii = '';
                for ($i = $start; $i + 1 < strlen($dec); $i += 2) {
                    if ($dec[$i] === "\x00") {
                        $ascii .= $dec[$i + 1];
                    }
                }
                if (str_contains($ascii, '---BEGIN-SEAL---')) {
                    return true;
                }
            }

            // Also check plain ASCII (some streams are not Unicode-paired).
            if (str_contains($dec, '---BEGIN-SEAL---')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determines whether a decoded stream body is a PDF page content stream.
     *
     * Checks for control-character-free leading bytes and the presence of
     * standard PDF content-stream operators (BT, q, Q, cm, Tf, Tj, Td).
     *
     * @param string $decoded Decompressed stream bytes.
     *
     * @return bool True if the stream looks like a page content stream.
     */
    private static function isPageContentStream(string $decoded): bool
    {
        $head = substr($decoded, 0, 16);
        for ($i = 0; $i < strlen($head); $i++) {
            $b = ord($head[$i]);
            if ($b < 9 || ($b > 13 && $b < 32 && $b !== 27)) {
                return false;
            }
        }
        return (bool) preg_match('/\bBT\b|\bq\b|\bQ\b|\bcm\b|\bTf\b|\bTj\b|\bTd\b/', $decoded);
    }

    /**
     * Renders the verification summary panel HTML for a single PDF.
     *
     * Builds a verdict banner and a check-row table from the supplied
     * result flags, then returns the complete HTML string.
     *
     * @param array $d Associative array of verification result flags and
     *                 metadata (seal_matches, document_modified, uid_prefix,
     *                 file_name, etc.).
     *
     * @return string HTML for the summary panel.
     */
    private static function renderSummaryPanel(array $d): string
    {
        $pass     = '<span class="forge-pdf-chk-pass">&#10003;</span>';
        $fail     = '<span class="forge-pdf-chk-fail">&#10007;</span>';
        $warn     = '<span class="forge-pdf-chk-warn">&#9888;</span>';
        $rotated  = '<span class="forge-pdf-chk-rotated">&#8634;</span>';
        $caret    = '<span class="forge-pdf-row-caret">&#8250;</span>';

        $row = function (bool $ok, string $label, string $detail, string $target_id)
 use ($pass, $fail, $caret): string {
            $icon   = $ok ? $pass : $fail;
            $status = $ok
                ? '<span class="forge-pdf-row-ok">OK</span>'
                : '<span class="forge-pdf-row-fail">' . esc_html($detail) . '</span>';
            return "<tr class='forge-pdf-toggle forge-pdf-summary-row' data-target='"
                . esc_attr($target_id) . "' title='Details anzeigen'>"
                . "<td>{$icon}</td>"
                . '<td>' . esc_html($label) . '</td>'
                . "<td class='forge-pdf-row-detail'>{$status}</td>"
                . "<td class='forge-pdf-row-caret-cell'>{$caret}</td>"
                . "</tr>\n";
        };

        $uid  = $d['uid_prefix'];
        $rows = '';

        if (!empty($d['incremental_update_detected'])) {
            $rows .= $row(
                false,
                'PDF structure',
                'Incremental update detected',
                'forge-pdf-content-structure-' . $uid
            );
        }

        if (!empty($d['multiple_seals_detected'])) {
            $rows .= $row(
                false,
                __('Seal integrity', 'form-forge'),
                __('Extra seal block(s) injected — document has been tampered with', 'form-forge'),
                'forge-pdf-content-seals-' . $uid
            );
        }

        $seal_detail = __('MISMATCH — document may have been tampered with', 'form-forge');
        $rows .= $row($d['seal_matches'], __('Cryptographic seal (HMAC)', 'form-forge'), $seal_detail, $uid . '-raw-content');

        if ($d['seal_matches']) {
            $key_status       = (string)($d['seal_key_status'] ?? 'active');
            $key_compromised  = !empty($d['seal_compromised']);
            if ($key_compromised) {
                $rows .= "<tr class='forge-pdf-summary-row'>"
                    . "<td>{$warn}</td>"
                    . "<td>" . esc_html__('Seal key', 'form-forge') . "</td>"
                    . "<td class='forge-pdf-row-detail' colspan='2'>"
                    . "<span class='forge-pdf-row-warn'>"
                    . esc_html__('COMPROMISED — manual verification strongly recommended', 'form-forge')
                    . "</span></td></tr>\n";
            } elseif (in_array($key_status, ['rotated-legacy', 'compromised-legacy'], true)) {
                $rows .= "<tr class='forge-pdf-summary-row'>"
                    . "<td>&#10003;</td>"
                    . "<td>" . esc_html__('Seal key', 'form-forge') . "</td>"
                    . "<td class='forge-pdf-row-detail' colspan='2'>"
                    . "<span style='background:#1a56db;color:#fff;font-size:11px;font-weight:700;"
                    . "padding:2px 8px;border-radius:3px;letter-spacing:.4px;'>" . esc_html__('Legacy', 'form-forge') . "</span>"
                    . "&nbsp;" . esc_html__('Manually imported key', 'form-forge')
                    . "</td></tr>\n";
            } elseif ($key_status !== 'active') {
                $rows .= "<tr class='forge-pdf-summary-row'>"
                    . "<td>{$rotated}</td>"
                    . "<td>" . esc_html__('Seal key', 'form-forge') . "</td>"
                    . "<td class='forge-pdf-row-detail' colspan='2'>"
                    . "<span class='forge-pdf-row-rotated'>"
                    . esc_html__('Signed with a rotated (older) key', 'form-forge')
                    . "</span></td></tr>\n";
            }
        }

        $fields_ok = $d['field_mismatch_count'] === 0;
        $rows .= $row(
            $fields_ok,
            __('Visual field content', 'form-forge'),
            sprintf(__('%d field(s) do not match the seal', 'form-forge'), $d['field_mismatch_count']),
            'forge-pdf-content-fields-' . $uid
        );

        $annots_ok = $d['annotation_fail_count'] === 0;
        $rows .= $row(
            $annots_ok,
            __('Annotations', 'form-forge'),
            sprintf(__('%d annotation(s) unmatched', 'form-forge'), $d['annotation_fail_count']),
            'forge-pdf-content-annots-' . $uid
        );

        $rows .= $row(!$d['pagecount_mismatch'], __('Page count', 'form-forge'), __('MISMATCH', 'form-forge'), 'forge-pdf-content-pgcount-' . $uid);

        $rows .= $row(
            !$d['image_missmatch'],
            __('Image hashes', 'form-forge'),
            __('MISMATCH — image content changed', 'form-forge'),
            'forge-pdf-content-images-' . $uid
        );

        $cs_ok = !($d['content_stream_mismatch'] ?? false);
        $rows .= $row(
            $cs_ok,
            __('Page content integrity', 'form-forge'),
            __('Page content was modified or added', 'form-forge'),
            'forge-pdf-content-streams-' . $uid
        );

        $rows .= $row(
            !$d['font_missmatch'],
            __('Fonts', 'form-forge'),
            __('Undeclared or missing fonts detected', 'form-forge'),
            'forge-pdf-content-fonts-' . $uid
        );

        $rows .= $row(
            !$d['unexpected_detected'],
            __('PDF Objects', 'form-forge'),
            __('Unexpected object types detected', 'form-forge'),
            'forge-pdf-content-objects-' . $uid
        );

        if (isset($d['meta_mismatch'])) {
            $rows .= $row(
                !$d['meta_mismatch'],
                __('PDF Metadata', 'form-forge'),
                __('Title/Author/Creator tampered', 'form-forge'),
                'forge-pdf-content-meta-' . $uid
            );
        }

        if (!empty($d['all_stream_mismatch'])) {
            $rows .= $row(
                false,
                __('Stream fingerprint', 'form-forge'),
                __('A compressed stream was added or modified', 'form-forge'),
                'forge-pdf-content-streams-' . $uid
            );
        }

        $verdict_class = $d['document_modified'] ? 'forge-pdf-verdict-fail' : 'forge-pdf-verdict-pass';
        $verdict_text  = $d['document_modified']
            ? '&#10007; ' . esc_html($d['file_name']) . ' — ' . esc_html__('MODIFIED or INVALID', 'form-forge')
            : '&#10003; ' . esc_html($d['file_name']) . ' — ' . esc_html__('Authentic', 'form-forge');

        $nonce_html = '';
        if (!empty($d['doc_nonce'])) {
            $nonce_disp = esc_html((string) $d['doc_nonce']);
            $nonce_html = "<div class='forge-pdf-doc-id'>" . esc_html__('Document ID:', 'form-forge') . " <code>{$nonce_disp}</code></div>";
        }

        return "<div class='forge-pdf-summary-panel'>"
            . "<div class='forge-pdf-summary-verdict {$verdict_class}'>{$verdict_text}</div>"
            . $nonce_html
            . "<table class='forge-pdf-summary-table'>"
            . "<thead><tr><th></th><th>Check</th><th></th><th></th></tr></thead>"
            . "<tbody>{$rows}</tbody>"
            . "</table>"
            . "</div>";
    }


    /**
     * Reconstructs the canonical HMAC payload array from raw seal data.
     *
     * Produces a payload whose key order and value types exactly match
     * those used by the generator, so the HMAC can be re-verified.
     *
     * @param array $seal_data Decoded seal JSON as an associative array.
     *
     * @return array Canonical payload ready for HMAC verification.
     */
    private static function rebuildPayload(array $seal_data): array
    {
        // Key order must exactly match Generator::$seal_data construction order.
        // key_id is only present in PDFs generated after UUID support was added;
        // omitting it for older PDFs preserves the original HMAC input.
        $rebuilt = ['generated' => (string) ($seal_data['generated'] ?? '')];
        if (!empty($seal_data['key_id'])) {
            $rebuilt['key_id'] = (string) $seal_data['key_id'];
        }
        $rebuilt += [
            'nonce'            => (string) ($seal_data['nonce'] ?? ''),
            'form_id'          => (int) ($seal_data['form_id'] ?? 0),
            'form_name'        => trim((string) ($seal_data['form_name'] ?? '')),
            'fields'           => [],
            'uploads'          => [],
            'template'         => [],
            'fonts'            => [],
            'expected_pages'   => (int) ($seal_data['expected_pages'] ?? 0),
            'content_streams'  => [],
            'image_hashes'     => [],
            'font_prog_hashes'  => array_values(
                array_map('strval', (array) ($seal_data['font_prog_hashes'] ?? []))
            ),
            'all_stream_hashes' => array_values(
                array_map('strval', (array) ($seal_data['all_stream_hashes'] ?? []))
            ),
            'pdf_meta'          => [
                'title'   => (string)($seal_data['pdf_meta']['title']   ?? ''),
                'author'  => (string)($seal_data['pdf_meta']['author']  ?? ''),
                'creator' => (string)($seal_data['pdf_meta']['creator'] ?? ''),
            ],
        ];

        foreach ((array) ($seal_data['fields'] ?? []) as $field) {
            $rebuilt['fields'][] = [
                'label' => trim((string) ($field['label'] ?? '')),
                'value' => self::normalizeValue($field['value'] ?? ''),
            ];
        }

        if (is_array($seal_data['uploads'] ?? null)) {
            foreach ($seal_data['uploads'] as $u) {
                $rebuilt['uploads'][] = [
                    'name'   => (string) ($u['name'] ?? ''),
                    'mime'   => (string) ($u['mime'] ?? ''),
                    'sha256' => (string) ($u['sha256'] ?? ''),
                ];
            }
        }

        if (is_array($seal_data['template'] ?? null)) {
            foreach ($seal_data['template'] as $t) {
                $rebuilt['template'][] = [
                    'name'   => (string) ($t['name'] ?? ''),
                    'mime'   => (string) ($t['mime'] ?? ''),
                    'sha256' => (string) ($t['sha256'] ?? ''),
                ];
            }
        }

        if (is_array($seal_data['fonts'] ?? null)) {
            $rebuilt['fonts'] = array_values(array_map('strval', $seal_data['fonts']));
        }

        if (is_array($seal_data['content_streams'] ?? null)) {
            $rebuilt['content_streams'] = array_values(array_map('strval', $seal_data['content_streams']));
        }

        if (is_array($seal_data['image_hashes'] ?? null)) {
            $rebuilt['image_hashes'] = array_values(array_map('strval', $seal_data['image_hashes']));
        }

        return $rebuilt;
    }

    /**
     * Hashes every compressed (non-page-content) stream in a raw PDF.
     *
     * Decompresses each stream with gzuncompress/gzinflate, skips page
     * content streams (handled separately), and returns sorted SHA-256
     * hashes for catch-all stream-injection detection.
     *
     * @param string $pdf_raw Raw PDF file bytes.
     *
     * @return array Sorted array of SHA-256 hex strings.
     */
    private static function hashAllCompressedStreams(string $pdf_raw): array
    {
        $hashes = [];
        $offset = 0;
        while (true) {
            $pos  = strpos($pdf_raw, "stream\r\n", $offset);
            $pos2 = strpos($pdf_raw, "stream\n", $offset);
            if ($pos === false && $pos2 === false) {
                break;
            }
            if ($pos === false) {
                $pos = $pos2;
            } elseif ($pos2 !== false && $pos2 < $pos) {
                $pos = $pos2;
            }
            $eol = (substr($pdf_raw, $pos + 6, 2) === "\r\n") ? 2 : 1;
            $bs  = $pos + 6 + $eol;
            $be  = strpos($pdf_raw, 'endstream', $bs);
            if ($be === false) {
                $offset = $bs;
                continue;
            }
            $body = substr($pdf_raw, $bs, $be - $bs);
            if (strlen($body) > 67108864) {
                $offset = $be + 9;
                continue;
            }
            $dec = @gzuncompress($body) ?: @gzinflate($body);
            if ($dec === false || strlen($dec) > 67108864) {
                $offset = $be + 9;
                continue;
            }
            // Exclude page content streams — handled by content_streams and unstable
            // between PASS 1 (seal) and PASS 2 (final PDF) due to seal embedding.
            if (!self::isPageContentStream($dec)) {
                $hashes[] = hash('sha256', $dec);
            }
            $offset = $be + 9;
        }
        sort($hashes);
        return $hashes;
    }

    /**
     * Extracts and hashes font program streams from FontDescriptor objects.
     *
     * Locates /FontFile, /FontFile2, and /FontFile3 references, decompresses
     * each referenced stream, and returns a sorted list of SHA-256 hashes
     * for font-program integrity verification.
     *
     * @param string $pdf_raw Raw PDF file bytes.
     *
     * @return array Sorted array of SHA-256 hex strings.
     */
    private static function hashFontProgramStreams(string $pdf_raw): array
    {
        $hashes   = [];
        $pat_desc = '/\d+\s+\d+\s+obj\s*<<([\s\S]*?\/Type\s*\/FontDescriptor[\s\S]*?)>>\s*endobj/m';
        if (!preg_match_all($pat_desc, $pdf_raw, $descs, PREG_SET_ORDER)) {
            return $hashes;
        }

        $seen = [];
        foreach ($descs as $desc) {
            if (!preg_match('/\/FontFile[23]?\s+(\d+)\s+\d+\s+R/', $desc[1], $ref)) {
                continue;
            }
            $obj_num = (int) $ref[1];
            if (isset($seen[$obj_num])) {
                continue;
            }
            $seen[$obj_num] = true;

            $pat_obj = '/' . $obj_num . '\s+\d+\s+obj[\s\S]*?stream\r?\n([\s\S]*?)\r?\nendstream/m';
            if (!preg_match($pat_obj, $pdf_raw, $so)) {
                continue;
            }
            $body = $so[1];
            if (strlen($body) > 67108864) {
                continue;
            }
            $dec = @gzuncompress($body) ?: @gzinflate($body);
            if ($dec === false || strlen($dec) > 67108864) {
                $dec = $body;
            }
            $hashes[] = hash('sha256', $dec);
        }

        sort($hashes);
        return $hashes;
    }

    /**
     * Normalizes a seal field value for comparison against PDF text.
     *
     * Decodes HTML entities, strips all tags, and collapses whitespace
     * to a single space so seal values and extracted PDF text can be
     * compared with a consistent baseline.
     *
     * @param string $value Raw field value from the seal payload.
     *
     * @return string Normalized plain-text value.
     */
    private static function normalizeValue(string $value): string
    {
        // Decode HTML entities
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Strip HTML tags
        $value = wp_strip_all_tags($value);

        // Normalize whitespace
        $value = preg_replace('/\s+/u', ' ', $value);

        return trim($value);
    }

    /**
     * Recursively computes the differences between two associative arrays.
     *
     * Returns a flat list of human-readable mismatch descriptions, including
     * the dot-notation path of each differing key and the values from each side.
     *
     * @param array  $a    First array (seal payload).
     * @param array  $b    Second array (rebuilt payload).
     * @param string $path Dot-notation key path prefix for nested calls.
     *
     * @return array List of difference description strings.
     */
    private static function diffArrays(array $a, array $b, string $path = ''): array
    {
        $diffs = [];
        $keys = array_unique(array_merge(array_keys($a), array_keys($b)));

        foreach ($keys as $key) {
            $currentPath = $path === '' ? $key : $path . '.' . $key;

            if (!array_key_exists($key, $a)) {
                $diffs[] = "Missing in A: {$currentPath}";
                continue;
            }

            if (!array_key_exists($key, $b)) {
                $diffs[] = "Missing in B: {$currentPath}";
                continue;
            }

            if (is_array($a[$key]) && is_array($b[$key])) {
                $diffs = array_merge($diffs, self::diffArrays($a[$key], $b[$key], $currentPath));
            } else {
                if ((string)$a[$key] !== (string)$b[$key]) {
                    $diffs[] = "Mismatch at {$currentPath}\n"
                        . 'A: ' . var_export($a[$key], true)
                        . "\nB: " . var_export($b[$key], true);
                }
            }
        }

        return $diffs;
    }

    /**
     * Builds a styled notice card HTML string for pre-flight rejections.
     *
     * Matches the forge-vpc error card visual so all server-side rejections
     * (MIME, %%EOF, no seal, etc.) share a single consistent card design.
     *
     * @param string $message Notice text (may contain safe HTML).
     * @param string $type    Card type: 'error', 'warning', 'success', or 'info'.
     *
     * @return string HTML notice card markup.
     */
    private static function noticeHtml(string $message, string $type = 'error'): string
    {
        if ($type === 'error') {
            return sprintf(
                '<div class="forge-vpc forge-vpc--error">'
                . '<div class="forge-vpc__header">'
                . '<span class="forge-vpc__icon"><span class="dashicons dashicons-warning"></span></span>'
                . '</div>'
                . '<div class="forge-vpc__step">%s</div>'
                . '</div>',
                wp_kses_post($message)
            );
        }

        $class = match ($type) {
            'success' => 'notice notice-success',
            'warning' => 'notice notice-warning',
            'info'    => 'notice notice-info',
            default   => 'notice notice-error',
        };
        return sprintf(
            '<div class="%s is-dismissible"><p><strong>%s</strong></p></div>',
            esc_attr($class),
            wp_kses_post($message)
        );
    }

    /**
     * Reverses PNG predictor filtering on an indexed-color image stream.
     *
     * Applies None (0), Sub (1), and Up (2) PNG row filters byte-by-byte,
     * restoring raw palette-index bytes from a FlateDecode+Predictor stream.
     *
     * @param string $data  Raw (still-filtered) indexed image stream bytes.
     * @param int    $width Image width in pixels.
     * @param int    $bpc   Bits per component (typically 8 for indexed images).
     *
     * @return string Decoded index-stream bytes with predictor removed.
     */
    private static function undoPngPredictorIndexed(
        string $data,
        int $width,
        int $bpc
    ): string {
        $rowBytes = (int)ceil($width * $bpc / 8);
        $out = '';
        $prev = str_repeat("\0", $rowBytes);
        $i = 0;

        while ($i < strlen($data)) {
            $filter = ord($data[$i++]);
            $row = substr($data, $i, $rowBytes);
            $i += $rowBytes;
            $row = str_pad($row, $rowBytes, "\0");

            for ($j = 0; $j < $rowBytes; $j++) {
                $cur = ord($row[$j]);
                $up  = ord($prev[$j]);

                switch ($filter) {
                    case 0: // None
                        break;
                    case 1: // Sub (byte-wise!)
                        $left = $j > 0 ? ord($row[$j - 1]) : 0;
                        $cur = ($cur + $left) & 0xFF;
                        break;
                    case 2: // Up
                        $cur = ($cur + $up) & 0xFF;
                        break;
                    default:
                        // Other PNG filters are illegal for PDF predictors
                        break;
                }

                $row[$j] = chr($cur);
            }

            $out .= $row;
            $prev = $row;
        }

        return $out;
    }

    /**
     * Emits an empty placeholder div for a PDF image XObject.
     *
     * Registers the image file for deferred deletion via a shutdown function
     * and outputs a data-attribute slot that JavaScript later populates with
     * the fully rendered image card.
     *
     * @param string $uid  Unique slot identifier used as the element ID.
     * @param array  $meta Image metadata (colorspace, width, height, img_id,
     *                     decoded_len, allowed, file_path).
     *
     * @return void
     */
    private static function emitImageSlot(string $uid, array $meta): void
    {

        self::$files_to_delete[] = $meta['file_path'];

        if (!self::$image_cleanup_registered) {
            self::$image_cleanup_registered = true;

            register_shutdown_function(
                static function () {
                    if (function_exists('fastcgi_finish_request')) {
                        fastcgi_finish_request();
                    }
                    // Give the browser time to fetch the rendered images before
                    // they're removed, without blocking this PHP-FPM worker for
                    // the whole delay — hand the actual unlink off to WP-Cron.
                    wp_schedule_single_event(time() + 120, 'forge_verifier_cleanup_files', [self::$files_to_delete]);
                }
            );
        }

        echo "<div id='" . esc_attr($uid) . "' class='img-slot'
            data-colorspace='" . esc_attr((string) ($meta['colorspace'] ?? '')) . "'
            data-width='" . (int) ($meta['width'] ?? 0) . "'
            data-height='" . (int) ($meta['height'] ?? 0) . "'
            data-imgid='" . esc_attr((string) ($meta['img_id'] ?? '')) . "'
            data-decodedlen='" . (int) ($meta['decoded_len'] ?? 0) . "'
            data-allowed='" . (int) ($meta['allowed'] ?? 0) . "'
        ></div>";
    }

    /**
     * Schedules a temporary PDF file for deletion after the request ends.
     *
     * Registers a shutdown function (once) that waits until the same 600s
     * (10 minute) window as the forge_pdf_{token} transient set alongside
     * this file, then retries unlink up to five times per file — the file
     * must outlive every request that can legitimately still read it via
     * that token (forge_verify_push_lines/forge_serve_pdf), which can run
     * up to set_time_limit(300) seconds after the initial upload response.
     * A shorter window here previously raced those follow-up requests.
     *
     * @param string $file_path Absolute path to the PDF file to delete.
     *
     * @return void
     */
    private static function scheduleDeletion(string $file_path): void
    {
        self::$pdfs_to_delete[] = $file_path;

        if (!self::$pdf_cleanup_registered) {
            self::$pdf_cleanup_registered = true;

            register_shutdown_function(
                static function () {
                    if (function_exists('fastcgi_finish_request')) {
                        fastcgi_finish_request();
                    }
                    // Same rationale as emitImageSlot() above — offload the
                    // delayed unlink to WP-Cron instead of sleeping in-worker.
                    wp_schedule_single_event(time() + 600, 'forge_verifier_cleanup_files', [self::$pdfs_to_delete]);
                }
            );
        }
    }

    /**
     * Fills a previously emitted image slot with rendered image card HTML.
     *
     * Wraps the HTML in a relocatable container carrying the slot UID so
     * the client-side image-slot JS can move it into the correct placeholder.
     *
     * @param string $uid  Slot identifier matching the placeholder element ID.
     * @param string $html Rendered image card HTML to inject into the slot.
     *
     * @return void
     */
    private static function fillImageSlot(string $uid, string $html): void
    {
        // Defensive: never emit empty content
        if ($html === '') {
            echo "<!-- FF: empty image slot content for {$uid} -->";
            return;
        }

        // Wrap content so JS can relocate it safely
        echo sprintf(
            '<div class="img-slot-content" data-slot="%s">%s</div>',
            esc_attr($uid),
            $html
        );
    }
}
