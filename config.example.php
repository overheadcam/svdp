<?php
/**
 * Vincentian Resource Center — configuration
 *
 * COPY this file to  config.php  and fill in your real values.
 * config.php lives OUTSIDE the web-served "public/" folder, so it is never
 * downloadable by visitors. Keep it readable only by the web server user:
 *
 *     sudo chown root:www-data config.php
 *     sudo chmod 640 config.php
 *
 * Never commit the real config.php to version control.
 */

return [

    // ---- PostgreSQL connection ----
    'db' => [
        'host'     => '127.0.0.1',
        'port'     => 5432,
        'dbname'   => 'svdp',
        'user'     => 'svdp',
        'password' => 'CHANGE_ME_db_password',
    ],

    /**
     * Password required to make edits (viewing is always public).
     * Store a HASH here, not the plain password. Generate one with:
     *
     *     php -r "echo password_hash('your-edit-password', PASSWORD_DEFAULT), PHP_EOL;"
     *
     * Paste the resulting string (it starts with $2y$...) below.
     */
    'edit_password_hash' => '$2y$10$REPLACE_WITH_OUTPUT_OF_password_hash',

    /**
     * Set to true only after you have HTTPS working. It forces the login
     * cookie to be sent over HTTPS only. Leave false while testing on plain
     * HTTP, then switch to true once Let's Encrypt is installed.
     */
    'secure_cookies' => false,

    // ---- ServWare integration (placeholder for the future) ----
    // Left empty for now. When ServWare access is arranged, fill these in and
    // build out lib/servware.php. Nothing here is used until then.
    'servware' => [
        'enabled'  => false,
        'base_url' => '',
        'api_key'  => '',
    ],
];
