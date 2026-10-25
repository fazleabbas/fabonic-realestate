<?php
/**
 * Secrets: access keys and passwords that must never be committed.
 *
 * They live in config/secrets.php, one per site, outside git (see
 * config/secrets.example.php; deploy/make-secrets.php creates it). A secret
 * that is not set is an empty string, and appSecretMatches() never accepts an
 * empty key — so a site without the file denies web access instead of
 * opening it. Command-line runs (cron) do not use these keys at all.
 */

function appSecret($name) {
    static $loaded = false;
    if (!$loaded) {
        $loaded = true;
        $file = __DIR__ . '/../config/secrets.php';
        if (is_file($file)) require_once $file;
    }
    return defined($name) ? (string) constant($name) : '';
}

/** Constant-time check of a key given in a URL against a configured secret. */
function appSecretMatches($name, $given) {
    $expected = appSecret($name);
    return $expected !== '' && is_string($given) && hash_equals($expected, $given);
}
