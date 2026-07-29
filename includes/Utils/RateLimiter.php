<?php

/**
 * Atomic, window-based rate-limit counter.
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
 * Atomic, window-based rate-limit counter backed directly by wp_options.
 *
 * Replaces the get_transient()-then-set_transient() read-modify-write pattern used
 * previously, which is a classic TOCTOU race: concurrent requests can all read the
 * same pre-increment count before any of them writes the incremented value back,
 * letting an attacker with parallel connections exceed the intended cap. The counter
 * itself is incremented with a single atomic SQL statement, closing that race.
 */
class RateLimiter
{
    /**
     * Atomically increments the counter for $key and returns the new count. The
     * window resets automatically once $window_seconds have elapsed since the
     * counter was first created for this key.
     *
     * @param string $key            Unique rate-limit bucket identifier (already
     *                                hashed/sanitized by the caller — used verbatim
     *                                as part of an option name).
     * @param int    $window_seconds Window duration in seconds.
     *
     * @return int New count after incrementing.
     */
    public static function increment(string $key, int $window_seconds): int
    {
        global $wpdb;

        $value_opt  = 'forge_rl_' . $key;
        $expiry_opt = $value_opt . '_exp';
        $now        = time();

        $expires = (int) get_option($expiry_opt, 0);
        if ($expires <= $now) {
            // (Re)start the window. A rare concurrent double-reset here only shortens
            // the effective window slightly — unlike the read-modify-write race this
            // replaces, it can't be used to bypass the cap, because the increment
            // below is still a single atomic statement either way.
            update_option($value_opt, 0, false);
            update_option($expiry_opt, $now + $window_seconds, false);
            wp_cache_delete($value_opt, 'options');
            wp_cache_delete($expiry_opt, 'options');
        }

        // Atomic increment: a single SQL statement, not a read-then-write — concurrent
        // requests can't all observe the same pre-increment value.
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$wpdb->options} SET option_value = option_value + 1 WHERE option_name = %s",
                $value_opt
            )
        );
        // The direct query above bypasses WP's own cache invalidation, so the object
        // cache (if any, e.g. Redis/Memcached) must be explicitly told to drop its
        // stale copy or every subsequent get_option() on this key would return it.
        wp_cache_delete($value_opt, 'options');

        return (int) get_option($value_opt, 0);
    }
}
