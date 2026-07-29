<?php

declare(strict_types=1);

/**
 * Wrapper — preferred: php spark inbox:test
 *
 * Run: php tests/InboxStatusFeatureTest.php
 * (delegates to spark so DB tenant boot works)
 */
$php = PHP_BINARY;
$root = dirname(__DIR__);
$spark = $root . DIRECTORY_SEPARATOR . 'spark';

passthru(escapeshellarg($php) . ' ' . escapeshellarg($spark) . ' inbox:test', $code);
exit($code);
