<?php
// Browser access stays blocked until you change CRON_KEY in config.php.
// Sends appointment reminders. Run every hour, e.g.:
//   Windows Task Scheduler:  C:\xampp\php\php.exe C:\xampp\htdocs\salon\reminders.php
//   Or from a browser/cron:   reminders.php?key=<CRON_KEY from config.php>
require 'config.php';
if (PHP_SAPI !== 'cli' && (CRON_KEY === 'change-this-key' || !hash_equals(CRON_KEY, (string)($_GET['key'] ?? '')))) {
    http_response_code(403);
    exit('Forbidden');
}
echo 'Reminders sent: ' . send_due_reminders($db) . "\n";
