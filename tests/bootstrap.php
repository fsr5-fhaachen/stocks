<?php

/**
 * Bootstrap of PHPUnit (phpunit.xml.dist).
 *
 * The tests use a sqlite file instead of ":memory:", so RefreshDatabase migrates once per run and not
 * before every test. SQLite does not create the file itself; database/.gitignore keeps it out of git.
 */

require __DIR__.'/../vendor/autoload.php';

$database = __DIR__.'/../database/testing.sqlite';

if (! file_exists($database)) {
    touch($database);
}
