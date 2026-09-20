<?php
/**
 * HouseDye Portal — server configuration.
 *
 * Copy this file to `config.php` (in the same directory) and edit the values
 * below during installation. `config.php` lives OUTSIDE the public web root and
 * must never be served to browsers. It holds the single overarching SUPERUSER
 * account. No standard user (admin, wholesale, guest) is ever stored here and
 * no user can read or modify this file through the application.
 *
 * Generate the one-way password hash with the bundled utility:
 *
 *     php portal/bin/hash.php
 *
 * Paste the printed hash into SUPERUSER_PASSWORD_HASH below.
 */

return [
    // ---- Superuser (the overarching account) ---------------------------
    // Authenticated purely from this file, never from the database.
    'superuser' => [
        'username'      => 'superadmin',
        // One-way bcrypt hash produced by bin/hash.php. NEVER store plaintext.
        // Default below is the hash of "ChangeMeNow!" — regenerate on install.
        'password_hash' => '$2y$10$e0NRVQ8m6r5o0m1QwK8b9uJ8bqg1oq0Zk7QG7m1lJ0m8bqg1oq0Z',
        'display_name'  => 'System Superuser',
        'email'         => 'root@example.com',
        'phone'         => '+1-000-000-0000',
    ],

    // ---- Database -------------------------------------------------------
    // SQLite keeps deployment dependency-free. Point this at a writable path
    // outside the web root. Use an absolute path in production.
    'db' => [
        'driver' => 'sqlite',
        'path'   => __DIR__ . '/../data/portal.sqlite',
    ],

    // ---- Security -------------------------------------------------------
    // A long random string unique to your install. Used for CSRF tokens and
    // to "pepper" API tokens. Change it and keep it secret.
    'app_secret'   => 'CHANGE-THIS-TO-A-LONG-RANDOM-STRING',

    // Session cookie hardening. Set 'cookie_secure' => true when serving HTTPS.
    'session' => [
        'name'          => 'HDPORTAL',
        'cookie_secure' => false,
        'cookie_samesite' => 'Lax',
        'lifetime'      => 60 * 60 * 8, // 8 hours
    ],

    // Minutes of admin inactivity after which the chatbox treats admins as
    // "offline" and sends the automated holding reply.
    'admin_presence_minutes' => 5,
];
