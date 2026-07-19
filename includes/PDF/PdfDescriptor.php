<?php

/**
 * Fluent builder that field classes use to describe their PDF output.
 *
 * PHP Version 8.1
 *
 * @category  FormForge
 * @package   FormForge
 * @author    Alexander Jorek
 * @copyright 2026 Alexander Jorek
 * @license   https://www.gnu.org/licenses/gpl-2.0.html GPL-2.0-or-later
 * @version   1.0.0
 * @link      https://github.com/AlexanderJorek/form-forge
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2
 * of the License, or (at your option) any later version.
 */

namespace ForgeForms\PDF;

defined('ABSPATH') || exit;

/**
 * Fluent builder that field classes use to describe their PDF output.
 *
 * Start from BaseField::pdf($field), chain methods for anything non-default,
 * then call build(). Generator consumes the resulting array.
 *
 * Usage:
 *   return $this->pdf($field)->attachImage($binary, 'sig.png')->build();
 */
class PdfDescriptor
{
    /**
     * Cell HTML content.
     *
     * @var string
     */
    private $cellHtml;

    /**
     * Whether to render with a label row.
     *
     * @var bool
     */
    private $labeled = true;

    /**
     * Keyed binary image data for mPDF imageVars.
     *
     * @var array
     */
    private $imageVars = [];

    /**
     * File descriptors for the HMAC seal.
     *
     * @var array
     */
    private $sealedUploads = [];

    /**
     * @param string $defaultCellHtml Escaped value text — the starting cell content.
     */
    public function __construct(string $defaultCellHtml)
    {
        $this->cellHtml = $defaultCellHtml;
    }

    /**
     * Replaces the cell text with an already-escaped string.
     *
     * @param string $escaped Pre-escaped HTML or empty string.
     *
     * @return static
     */
    public function text(string $escaped): static
    {
        $this->cellHtml = $escaped;
        return $this;
    }

    /**
     * Sets the cell content to raw HTML (no escaping applied).
     *
     * @param string $html Raw HTML string.
     *
     * @return static
     */
    public function rawHtml(string $html): static
    {
        $this->cellHtml = $html;
        return $this;
    }

    /**
     * Renders without a label row — just a plain block.
     *
     * @return static
     */
    public function unlabeled(): static
    {
        $this->labeled = false;
        return $this;
    }

    /**
     * Embeds an image in the PDF and records its fingerprint in the seal.
     * TIFF images are converted to PNG first — mPDF does not support TIFF natively.
     *
     * @param string $binary   Raw binary image data.
     * @param string $filename Display name used in the seal.
     * @param string $mime     MIME type (default 'image/png').
     *
     * @return static
     */
    public function attachImage(
        string $binary,
        string $filename,
        string $mime = 'image/png'
    ): static {
        if ($mime === 'image/tiff') {
            $gd = @imagecreatefromstring($binary);
            if ($gd !== false) {
                ob_start();
                imagepng($gd);
                $binary   = (string) ob_get_clean();
                $mime     = 'image/png';
                $filename = preg_replace('/\.tiff?$/i', '.png', $filename);
            }
        }

        $key = 'img' . bin2hex(random_bytes(8));
        $this->imageVars[$key] = $binary;
        $this->sealedUploads[] = [
            'name'   => $filename,
            'mime'   => $mime,
            'sha256' => PdfUtils::thumbnailHash($binary) ?? hash('sha256', $binary),
        ];
        return $this;
    }

    /**
     * Returns the array that Generator::generate() consumes.
     *
     * @return array PDF render descriptor.
     */
    public function build(): array
    {
        return [
            'cell_html'      => $this->cellHtml,
            'labeled'        => $this->labeled,
            'image_vars'     => $this->imageVars,
            'sealed_uploads' => $this->sealedUploads,
        ];
    }
}
