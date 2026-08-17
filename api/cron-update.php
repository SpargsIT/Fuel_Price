<?php
declare(strict_types=1);

// Optional cPanel cron helper. Example command:
// php /home/USERNAME/public_html/Fuel/api/cron-update.php
$_GET['force'] = '1';
ob_start();
require __DIR__ . '/live-data.php';
$output = ob_get_clean();
if (PHP_SAPI === 'cli') {
    echo $output . PHP_EOL;
}
