<?php

// platform_check.php — PHP 8.1+ uyumlu (override)
// PhpSpreadsheet 2.4.8 PHP 8.1+ destekler

$issues = array();

if (!(PHP_VERSION_ID >= 80100)) {
    $issues[] = 'Your Composer dependencies require a PHP version ">= 8.1.0". You are running ' . PHP_VERSION . '.';
}

if ($issues) {
    if (!headers_sent()) {
        header('HTTP/1.1 500 Internal Server Error');
    }
    echo 'Composer detected issues in your platform:' . PHP_EOL . implode(PHP_EOL, $issues);
    throw new \RuntimeException(implode(' ', $issues));
}
