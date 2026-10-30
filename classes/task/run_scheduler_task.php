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

namespace local_roomscheduler\task;

defined('MOODLE_INTERNAL') || die();

require_once($GLOBALS['CFG']->dirroot . '/local/roomscheduler/scheduler.php');

/**
 * Scheduled task that runs the weekly intervention room scheduler automatically.
 *
 * Default schedule is Monday at 8:00 AM. The day/time and whether this task
 * runs at all can be changed under Site administration > Server >
 * Scheduled tasks (search for this task's name) — that screen's own
 * "Disabled" checkbox is what turns automatic running on and off.
 *
 * Whether reward rooms are used on a given run is controlled separately,
 * from the "Enable reward room scheduling" checkbox on the Run Scheduler page.
 *
 * @package    local_roomscheduler
 * @copyright  2026 Brian Pool
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class run_scheduler_task extends \core\task\scheduled_task {

    /**
     * Get a descriptive name for this task, shown on the Scheduled tasks screen.
     *
     * @return string
     */
    public function get_name() {
        return get_string('runschedulertask', 'local_roomscheduler');
    }

    /**
     * Run the intervention room scheduler.
     */
    public function execute() {
        mtrace('Running local_roomscheduler automatic weekly scheduler...');
        $results = local_roomscheduler_run_scheduler();
        mtrace('Scheduler complete. Total assigned: ' . $results['total'] .
               ', skipped: ' . $results['skipped']);
    }
}
