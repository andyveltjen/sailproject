<?php
/**
 * Grav Scheduler trigger voor Combell URL-based cron.
 * Gebruik: https://sailproject.ai/cron.php?token=TOKEN
 */

define('CRON_TOKEN', '51fb1f17e737276085fac2af51132f48b759a26b8d86e9c6');

// Beveilig met token
if (!isset($_GET['token']) || !hash_equals(CRON_TOKEN, $_GET['token'])) {
    http_response_code(403);
    exit('Forbidden');
}

// PHP-binary op Combell server
$php = '/data/jail/usr/local/php-8.4/bin/php';
$grav = __DIR__ . '/bin/grav';

// Backup direct triggeren of scheduler uitvoeren
if (isset($_GET['backup']) && $_GET['backup'] === '1') {
    $cmd = escapeshellarg($php) . ' ' . escapeshellarg($grav) . ' backup 2>&1';
} else {
    $force = isset($_GET['force']) && $_GET['force'] === '1' ? ' --force' : '';
    $cmd   = escapeshellarg($php) . ' ' . escapeshellarg($grav) . ' scheduler' . $force . ' 2>&1';
}
$output = [];
$code   = 0;
exec($cmd, $output, $code);

header('Content-Type: text/plain');
echo 'Exit: ' . $code . "\n";
echo implode("\n", $output) . "\n";
