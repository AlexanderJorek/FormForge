<?php

/**
 * Resolves the client IP address used for rate limiting.
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

namespace ForgeForms\Utils;

defined('ABSPATH') || exit;

/**
 * Centralizes trusted-proxy-aware client IP resolution so every rate-limit call site
 * behaves consistently instead of reading $_SERVER['REMOTE_ADDR'] directly.
 */
class ClientIp
{
    /**
     * Returns the IP address to key rate limiting on. By default this is REMOTE_ADDR — the only value that
     * can't be spoofed by the client. X-Forwarded-For is only consulted when REMOTE_ADDR is in the site's
     * FORGE_TRUSTED_PROXIES allowlist (a comma-separated constant defined in wp-config.php), since otherwise
     * any client could forge that header to dodge the limit or to frame another visitor's IP.
     *
     * @return string Client IP address, or '' if unavailable.
     */
    public static function resolve(): string
    {
        $remote = isset($_SERVER['REMOTE_ADDR'])
            ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR']))
            : '';

        if ($remote === '' || !self::isTrustedProxy($remote)) {
            return $remote;
        }

        $forwarded = isset($_SERVER['HTTP_X_FORWARDED_FOR'])
            ? sanitize_text_field(wp_unslash($_SERVER['HTTP_X_FORWARDED_FOR']))
            : '';
        if ($forwarded === '') {
            return $remote;
        }

        // Trusting the left-most entry outright assumes the proxy chain always
        // *overwrites* X-Forwarded-For rather than appending to whatever the
        // client already sent — if it appends, a client can still prepend
        // arbitrary IPs and have the left-most one trusted. Walk from the
        // right instead: skip any entry that is itself a known trusted proxy
        // (a multi-hop chain of our own proxies) and take the first one that
        // isn't — that's the actual, unspoofable client-facing hop.
        $parts = array_map('trim', explode(',', $forwarded));
        for ($i = count($parts) - 1; $i >= 0; $i--) {
            if (!filter_var($parts[$i], FILTER_VALIDATE_IP)) {
                continue;
            }
            if (!self::isTrustedProxy($parts[$i])) {
                return $parts[$i];
            }
        }
        return $remote;
    }

    /**
     * Checks whether an address is listed in FORGE_TRUSTED_PROXIES.
     *
     * @param string $ip Address to check.
     * @return bool True when the site has explicitly marked $ip as a trusted proxy.
     */
    private static function isTrustedProxy(string $ip): bool
    {
        if (!defined('FORGE_TRUSTED_PROXIES') || (string) FORGE_TRUSTED_PROXIES === '') {
            return false;
        }
        $trusted = array_map('trim', explode(',', (string) FORGE_TRUSTED_PROXIES));
        return in_array($ip, $trusted, true);
    }
}
