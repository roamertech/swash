<?php

/**
 * Strip inherited environment variables before anything boots.
 *
 * PHPUnit's <env force="true"> sets getenv() and $_ENV, but it does not touch
 * $_SERVER. This host runs variables_order=GPCS, so a variable exported by the
 * shell also lands in $_SERVER, and Laravel's Env repository reads $_SERVER
 * before $_ENV. The forced value therefore loses to the shell.
 *
 * Measured on 2026-08-28 with DB_DATABASE=swash exported:
 *
 *     getenv:   'swash_test'   <- forced, correct
 *     $_ENV:    'swash_test'   <- forced, correct
 *     $_SERVER: 'swash'        <- inherited, and the one Laravel used
 *
 * The suite runs migrate:fresh, so that difference is the gap between a test
 * run and an erased production database. Removing the inherited copies here
 * closes it; Tests\TestCase still refuses to run as a second line of defence.
 *
 * OPENAI_* is stripped for the same reason: a real key inherited from the
 * shell would let a test reach the paid image API.
 */
foreach (['DB_', 'OPENAI_', 'IMAGE_'] as $prefix) {
    foreach (array_keys($_SERVER) as $key) {
        if (is_string($key) && str_starts_with($key, $prefix)) {
            unset($_SERVER[$key]);
        }
    }
}

require __DIR__.'/../vendor/autoload.php';
