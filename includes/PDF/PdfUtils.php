<?php

/**
 * Static image and storage helpers used internally by Generator and PdfDescriptor.
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

namespace ForgeForms\PDF;

defined('ABSPATH') || exit;

/**
 * Static image and storage helpers used internally by Generator and PdfDescriptor.
 */
class PdfUtils
{
    /**
     * Computes a perceptual thumbnail hash of an image for stable cross-pass comparison.
     *
     * Downscales the image to an 8×8 pixel grid and hashes the quantised RGB
     * values, producing a hash that is stable across PDF rendering passes.
     * Returns null when the GD extension is unavailable or the image cannot
     * be decoded.
     *
     * @param string $binary Raw binary image data.
     *
     * @return string|null SHA-256 hash of the thumbnail pixels, or null on failure.
     */
    public static function thumbnailHash(string $binary): ?string
    {
        if (!function_exists('imagecreatefromstring')) {
            \ForgeForms\forge_log(
                'ForgeForms: GD extension unavailable — '
                . 'image verification uses raw hash (degraded mode).'
            );
            return null;
        }
        $gd = @imagecreatefromstring($binary);
        if ($gd === false) {
            return null;
        }
        $thumb = imagecreatetruecolor(8, 8);
        imagealphablending($thumb, false);
        imagecopyresampled(
            $thumb,
            $gd,
            0,
            0,
            0,
            0,
            8,
            8,
            imagesx($gd),
            imagesy($gd)
        );
        $pixels = '';
        // Masking off the low 3 bits (& ~7) coarsens each channel to 32 levels, so tiny
        // pixel-value jitter from GD re-encoding the same image between the seal's two
        // render passes doesn't flip the hash — only a real visual change should
        for ($ty = 0; $ty < 8; $ty++) {
            for ($tx = 0; $tx < 8; $tx++) {
                $c       = imagecolorat($thumb, $tx, $ty);
                $pixels .= chr((($c >> 16) & 0xFF) & ~7)
                         . chr((($c >> 8)  & 0xFF) & ~7)
                         . chr(($c         & 0xFF) & ~7);
            }
        }
        return hash('sha256', $pixels);
    }

    /**
     * Returns the absolute path to the embed storage directory, creating it if needed.
     *
     * @return string Absolute filesystem path.
     */
    public static function getEmbedStorageDir(): string
    {
        $dir = wp_upload_dir()['basedir'] . '/forge-secure-pdf/embed';
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
            file_put_contents($dir . '/index.php', '<?php // Silence is golden ?>');
        }
        return $dir;
    }
}
