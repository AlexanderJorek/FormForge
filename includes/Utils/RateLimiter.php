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
 * letting an attacker with parallel connections exceed the intended cap.
 *
 * The count and its window-expiry are stored together as one "count|expiry" value
 * in a single row, and the reset-or-increment decision is made inside one atomic
 * INSERT ... ON DUPLICATE KEY UPDATE statement — not as a separate read-then-write
 * followed by a separate atomic increment. Two separate atomic statements (reset,
 * then increment) would still race with each other: a concurrent request could
 * observe "expired" and unconditionally zero a sibling request's already-incremented
 * count. Folding both decisions into one statement, executed under InnoDB's
 * per-row lock, closes that gap.
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

        $opt        = 'forge_rl_' . $key;
        $now        = time();
        $new_expiry = $now + $window_seconds;

        // Single atomic upsert: inserts the first-ever row for this key, or — on the
        // existing row — atomically resets (if its stored expiry has passed) or
        // increments (otherwise), all within one statement under one row lock, so no
        // concurrent request can observe or overwrite an intermediate state.
        //
        // The new count is wrapped in LAST_INSERT_ID() (a general-purpose way to smuggle
        // a computed value out of an INSERT/UPDATE, not related to auto_increment here)
        // instead of using this codebase's earlier VALUES(option_value) draft — that
        // form is deprecated since MySQL 8.0.20, and more importantly would still need a
        // *separate* SELECT to read the new count back, and that separate read is its
        // own small race: a sibling request's increment could land between this
        // statement and that read, so the count returned to the caller could reflect
        // more than this call's own increment (harmless — it can only over-block, never
        // bypass the cap — but avoidable). LAST_INSERT_ID() is connection-scoped, so
        // reading it back after this statement, on this same connection, is race-free
        // by construction: no other request's connection can affect what >this< session
        // sees for its own LAST_INSERT_ID().
        $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO {$wpdb->options} (option_name, option_value, autoload)
                 VALUES (%s, CONCAT(LAST_INSERT_ID(1), '|', %d), 'no')
                 ON DUPLICATE KEY UPDATE option_value = IF(
                     CAST(SUBSTRING_INDEX(option_value, '|', -1) AS UNSIGNED) <= %d,
                     CONCAT(LAST_INSERT_ID(1), '|', %d),
                     CONCAT(
                         LAST_INSERT_ID(CAST(SUBSTRING_INDEX(option_value, '|', 1) AS UNSIGNED) + 1),
                         '|',
                         SUBSTRING_INDEX(option_value, '|', -1)
                     )
                 )",
                $opt,
                $new_expiry,
                $now,
                $new_expiry
            )
        );
        // The direct query above bypasses WP's own cache invalidation, so the object
        // cache (if any, e.g. Redis/Memcached) must be explicitly told to drop its
        // stale copy or every subsequent get_option() on this key would return it.
        wp_cache_delete($opt, 'options');

        return (int) $wpdb->get_var('SELECT LAST_INSERT_ID()');
    }

    /**
     * WP-Cron callback (hourly): deletes any forge_rl_* option row whose window
     * has expired. Each distinct rate-limit bucket (IP+form combination) leaves
     * a permanent wp_options row once written, since increment() only ever
     * resets/increments a row in place and never deletes it — without this
     * sweep, buckets accumulate forever.
     *
     * @return void
     */
    public static function cronSweepExpired(): void
    {
        global $wpdb;

        $now = time();
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options}
                 WHERE option_name LIKE %s
                   AND CAST(SUBSTRING_INDEX(option_value, '|', -1) AS UNSIGNED) <= %d",
                $wpdb->esc_like('forge_rl_') . '%',
                $now
            )
        );
        wp_cache_delete('alloptions', 'options');
    }
}
