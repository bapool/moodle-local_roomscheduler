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
 * Event observer for local_roomscheduler.
 *
 * @package    local_roomscheduler
 * @copyright  2026 Brian Pool
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_roomscheduler;

defined('MOODLE_INTERNAL') || die();

/**
 * Observer for local_roomscheduler events.
 */
class observer {

    /**
     * Handle the course_reset_started event.
     *
     * When the counselor course is reset, clear all student intervention data
     * (scores, requests, archive, attendance, behavior). Rooms, teacher preferences,
     * and plugin settings are preserved.
     *
     * @param \core\event\course_reset_started $event
     */
    public static function course_reset_started(\core\event\course_reset_started $event) {
        global $DB;

        $counselorcourseid = (int) get_config('local_roomscheduler', 'counselorcourseid');
        if (empty($counselorcourseid)) {
            return;
        }

        // Only act on the counselor course.
        if ((int)$event->courseid !== $counselorcourseid) {
            return;
        }

        $DB->delete_records('local_roomscheduler_students');
        $DB->delete_records('local_roomscheduler_requests');
        $DB->delete_records('local_roomscheduler_request_archive');
        $DB->delete_records('local_roomscheduler_attendance');
        $DB->delete_records('local_roomscheduler_behavior');

        // Log to Moodle for audit trail.
        mtrace('local_roomscheduler: Cleared student intervention data due to course reset.');
    }

    /**
     * Handle the user_deleted event.
     *
     * Removes the deleted user's own student data (scores and room assignments,
     * requests about them, archived requests, attendance, and behavior flags) so
     * no orphaned records are left behind. Rooms and teacher preferences are not
     * touched.
     *
     * @param \core\event\user_deleted $event
     */
    public static function user_deleted(\core\event\user_deleted $event) {
        global $DB;

        $userid = (int)$event->objectid;
        if (empty($userid)) {
            return;
        }

        $DB->delete_records('local_roomscheduler_students', ['userid' => $userid]);
        $DB->delete_records('local_roomscheduler_requests', ['userid' => $userid]);
        $DB->delete_records('local_roomscheduler_request_archive', ['userid' => $userid]);
        $DB->delete_records('local_roomscheduler_attendance', ['studentid' => $userid]);
        $DB->delete_records('local_roomscheduler_behavior', ['userid' => $userid]);
    }
}
