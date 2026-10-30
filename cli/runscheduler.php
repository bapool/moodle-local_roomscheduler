<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * CLI script to run the intervention scheduler.
 *
 * Usage: php /var/www/html/local/roomscheduler/cli/runscheduler.php
 *
 * This script is CLI-only and will refuse to run from a web browser.
 * Intended to be called from cron:
 *   0 10 * * 1 /usr/bin/php /var/www/html/local/roomscheduler/cli/runscheduler.php
 *
 * Logs to /var/www/html/local/roomscheduler/cli/logs/
 * Keeps the last 4 log files, older ones are automatically deleted.
 *
 * @package    local_roomscheduler
 * @copyright  2026 Brian Pool
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once(__DIR__ . '/../scheduler.php');

if (PHP_SAPI !== 'cli') {
    die("This script can only be run from the command line.\n");
}

// Setup log directory.
$logdir = __DIR__ . '/logs';
if (!is_dir($logdir)) {
    mkdir($logdir, 0755, true);
}

// Create log file with timestamp.
$logfile = $logdir . '/scheduler-' . date('Y-m-d-His') . '.log';
$logfp   = fopen($logfile, 'w');

// Helper to write to both stdout and log file.
$log = function($msg) use ($logfp) {
    mtrace($msg);
    fwrite($logfp, $msg . "\n");
};

$log('== Intervention Room Scheduler ==');
$starttime = microtime(true);
$log('Starting scheduler run at ' . date('Y-m-d H:i:s'));

$results  = local_roomscheduler_run_scheduler();
$duration = round(microtime(true) - $starttime, 2);

$log('');
$log('=== Scheduler Results ===');
$log('Step 1 (Cross-subject swaps): ' . $results['step1']);
$log('  - placed in ELA 1+/Math 1+:  ' . ($results['step1plus'] ?? 0));
$log('  - extra + rooms this run:   ' . ($results['plusadded'] ?? 0));
$log('  - ELA/Math rooms as General: ' . ($results['subjecttogen'] ?? 0));
$log('Step 2 (Teacher requests):    ' . $results['step2']);
$log('Step 3 (D/F grades):          ' . $results['step3']);
$log('  - moved off a + teacher:     ' . ($results['step3plus'] ?? 0));
$log('Step 4 (Reward room):         ' . $results['step4']);
$log('Step 4.5 (Denied-attendance): ' . $results['step4absent']);
$log('Step 4.6 (Denied-behavior):   ' . $results['step4behavior']);
$log('Step 5 (Other moves S):       ' . $results['displaced']);
$log('Step 6 (Homeroom default):    ' . $results['step6']);
$log('');
$log('Total students assigned: ' . $results['total']);
$log('Total skipped (no HR):   ' . $results['skipped']);
$log('');
$log('Completed in ' . $duration . ' seconds.');
$log('Log written to: ' . $logfile);

fclose($logfp);

// Keep only the last 4 log files.
$logs = glob($logdir . '/scheduler-*.log');
if (!empty($logs)) {
    rsort($logs); // Newest first.
    $toremove = array_slice($logs, 4);
    foreach ($toremove as $oldlog) {
        unlink($oldlog);
    }
}

exit(0);
