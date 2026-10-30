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
 * Privacy provider for local_roomscheduler.
 *
 * @package    local_roomscheduler
 * @copyright  2026 Brian Pool
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_roomscheduler\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\transform;
use core_privacy\local\request\writer;

defined('MOODLE_INTERNAL') || die();

class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {

    public static function get_metadata(collection $collection): collection {

        $collection->add_database_table(
            'local_roomscheduler_rooms',
            [
                'teacherid'        => 'privacy:metadata:rooms:teacherid',
                'roomnumber'       => 'privacy:metadata:rooms:roomnumber',
                'maxstudents'      => 'privacy:metadata:rooms:maxstudents',
                'interventiontype' => 'privacy:metadata:rooms:interventiontype',
            ],
            'privacy:metadata:rooms'
        );

        $collection->add_database_table(
            'local_roomscheduler_students',
            [
                'userid'            => 'privacy:metadata:students:userid',
                'ela_score'         => 'privacy:metadata:students:ela_score',
                'math_score'        => 'privacy:metadata:students:math_score',
                'homeroom'          => 'privacy:metadata:students:homeroom',
                'intervention_room' => 'privacy:metadata:students:intervention_room',
                'assignment_code'   => 'privacy:metadata:students:assignment_code',
                'dfcourseid'        => 'privacy:metadata:students:dfcourseid',
            ],
            'privacy:metadata:students'
        );

        $collection->add_database_table(
            'local_roomscheduler_requests',
            [
                'userid'    => 'privacy:metadata:requests:userid',
                'teacherid' => 'privacy:metadata:requests:teacherid',
                'requested' => 'privacy:metadata:requests:requested',
            ],
            'privacy:metadata:requests'
        );

        $collection->add_database_table(
            'local_roomscheduler_teacher_prefs',
            [
                'teacherid'  => 'privacy:metadata:teacher_prefs:teacherid',
                'persistent' => 'privacy:metadata:teacher_prefs:persistent',
            ],
            'privacy:metadata:teacher_prefs'
        );

        $collection->add_database_table(
            'local_roomscheduler_request_archive',
            [
                'userid'    => 'privacy:metadata:request_archive:userid',
                'teacherid' => 'privacy:metadata:request_archive:teacherid',
                'weekof'    => 'privacy:metadata:request_archive:weekof',
            ],
            'privacy:metadata:request_archive'
        );

        $collection->add_database_table(
            'local_roomscheduler_attendance',
            [
                'teacherid'      => 'privacy:metadata:attendance:teacherid',
                'studentid'      => 'privacy:metadata:attendance:studentid',
                'attendancetype' => 'privacy:metadata:attendance:attendancetype',
                'attendancedate' => 'privacy:metadata:attendance:attendancedate',
                'present'        => 'privacy:metadata:attendance:present',
            ],
            'privacy:metadata:attendance'
        );

        return $collection;
    }

    public static function get_contexts_for_userid(int $userid): contextlist {
        global $DB;
        $contextlist = new contextlist();

        $hasdata = $DB->record_exists_sql(
            "SELECT 1 FROM {local_roomscheduler_rooms} WHERE teacherid = ?
             UNION SELECT 1 FROM {local_roomscheduler_students} WHERE userid = ?
             UNION SELECT 1 FROM {local_roomscheduler_requests} WHERE userid = ? OR teacherid = ?
             UNION SELECT 1 FROM {local_roomscheduler_teacher_prefs} WHERE teacherid = ?
             UNION SELECT 1 FROM {local_roomscheduler_request_archive} WHERE userid = ? OR teacherid = ?
             UNION SELECT 1 FROM {local_roomscheduler_attendance} WHERE teacherid = ? OR studentid = ?",
            [$userid, $userid, $userid, $userid, $userid, $userid, $userid, $userid, $userid]
        );

        if ($hasdata) {
            $contextlist->add_system_context();
        }

        return $contextlist;
    }

    public static function get_users_in_context(userlist $userlist) {
        $context = $userlist->get_context();
        if (!$context instanceof \context_system) {
            return;
        }

        $userlist->add_from_sql('userid',
            "SELECT teacherid AS userid FROM {local_roomscheduler_rooms}", []);
        $userlist->add_from_sql('userid',
            "SELECT userid FROM {local_roomscheduler_students}", []);
        $userlist->add_from_sql('userid',
            "SELECT userid FROM {local_roomscheduler_requests}", []);
        $userlist->add_from_sql('userid',
            "SELECT teacherid AS userid FROM {local_roomscheduler_requests}", []);
        $userlist->add_from_sql('userid',
            "SELECT teacherid AS userid FROM {local_roomscheduler_teacher_prefs}", []);
        $userlist->add_from_sql('userid',
            "SELECT userid FROM {local_roomscheduler_request_archive}", []);
        $userlist->add_from_sql('userid',
            "SELECT teacherid AS userid FROM {local_roomscheduler_request_archive}", []);
        $userlist->add_from_sql('userid',
            "SELECT teacherid AS userid FROM {local_roomscheduler_attendance}", []);
        $userlist->add_from_sql('userid',
            "SELECT studentid AS userid FROM {local_roomscheduler_attendance}", []);
    }

    public static function export_user_data(approved_contextlist $contextlist) {
        global $DB;

        $userid  = $contextlist->get_user()->id;
        $context = \context_system::instance();
        $plugin  = get_string('pluginname', 'local_roomscheduler');

        $rooms = $DB->get_records('local_roomscheduler_rooms', ['teacherid' => $userid]);
        if (!empty($rooms)) {
            $data = [];
            foreach ($rooms as $r) {
                $data[] = (object)[
                    'roomnumber'       => $r->roomnumber,
                    'maxstudents'      => $r->maxstudents,
                    'interventiontype' => $r->interventiontype,
                ];
            }
            writer::with_context($context)->export_data(
                [$plugin, 'Rooms'], (object)['records' => $data]);
        }

        $students = $DB->get_records('local_roomscheduler_students', ['userid' => $userid]);
        if (!empty($students)) {
            $data = [];
            foreach ($students as $s) {
                $data[] = (object)[
                    'ela_score'         => $s->ela_score,
                    'math_score'        => $s->math_score,
                    'homeroom'          => $s->homeroom,
                    'intervention_room' => $s->intervention_room,
                    'assignment_code'   => $s->assignment_code,
                    'dfcourseid'        => $s->dfcourseid,
                ];
            }
            writer::with_context($context)->export_data(
                [$plugin, 'Student Scores'], (object)['records' => $data]);
        }

        $requests = $DB->get_records_sql(
            "SELECT * FROM {local_roomscheduler_requests}
              WHERE userid = ? OR teacherid = ?", [$userid, $userid]);
        if (!empty($requests)) {
            $data = [];
            foreach ($requests as $r) {
                $data[] = (object)[
                    'student_userid' => $r->userid,
                    'teacher_userid' => $r->teacherid,
                    'requested'      => $r->requested,
                    'timecreated'    => transform::datetime($r->timecreated),
                ];
            }
            writer::with_context($context)->export_data(
                [$plugin, 'Intervention Requests'], (object)['records' => $data]);
        }

        $prefs = $DB->get_records('local_roomscheduler_teacher_prefs', ['teacherid' => $userid]);
        if (!empty($prefs)) {
            $data = [];
            foreach ($prefs as $p) {
                $data[] = (object)['persistent' => $p->persistent];
            }
            writer::with_context($context)->export_data(
                [$plugin, 'Teacher Preferences'], (object)['records' => $data]);
        }

        $archive = $DB->get_records_sql(
            "SELECT * FROM {local_roomscheduler_request_archive}
              WHERE userid = ? OR teacherid = ?", [$userid, $userid]);
        if (!empty($archive)) {
            $data = [];
            foreach ($archive as $a) {
                $data[] = (object)[
                    'student_userid' => $a->userid,
                    'teacher_userid' => $a->teacherid,
                    'weekof'         => transform::datetime($a->weekof),
                ];
            }
            writer::with_context($context)->export_data(
                [$plugin, 'Request Archive'], (object)['records' => $data]);
        }

        $attendance = $DB->get_records_sql(
            "SELECT * FROM {local_roomscheduler_attendance}
              WHERE teacherid = ? OR studentid = ?", [$userid, $userid]);
        if (!empty($attendance)) {
            $data = [];
            foreach ($attendance as $a) {
                $data[] = (object)[
                    'teacher_userid' => $a->teacherid,
                    'student_userid' => $a->studentid,
                    'attendancetype' => $a->attendancetype,
                    'attendancedate' => transform::datetime($a->attendancedate),
                    'present'        => $a->present,
                ];
            }
            writer::with_context($context)->export_data(
                [$plugin, 'Attendance'], (object)['records' => $data]);
        }
    }

    public static function delete_data_for_all_users_in_context(\context $context) {
        global $DB;
        if (!$context instanceof \context_system) {
            return;
        }

        $DB->delete_records('local_roomscheduler_rooms');
        $DB->delete_records('local_roomscheduler_students');
        $DB->delete_records('local_roomscheduler_requests');
        $DB->delete_records('local_roomscheduler_teacher_prefs');
        $DB->delete_records('local_roomscheduler_request_archive');
        $DB->delete_records('local_roomscheduler_attendance');
    }

    public static function delete_data_for_user(approved_contextlist $contextlist) {
        global $DB;

        $userid = $contextlist->get_user()->id;

        $DB->delete_records('local_roomscheduler_rooms',            ['teacherid' => $userid]);
        $DB->delete_records('local_roomscheduler_students',         ['userid'    => $userid]);
        $DB->delete_records('local_roomscheduler_teacher_prefs',    ['teacherid' => $userid]);
        $DB->delete_records_select('local_roomscheduler_requests',
            'userid = :uid OR teacherid = :tid', ['uid' => $userid, 'tid' => $userid]);
        $DB->delete_records_select('local_roomscheduler_request_archive',
            'userid = :uid OR teacherid = :tid', ['uid' => $userid, 'tid' => $userid]);
        $DB->delete_records_select('local_roomscheduler_attendance',
            'teacherid = :tid OR studentid = :sid', ['tid' => $userid, 'sid' => $userid]);
    }

    public static function delete_data_for_users(approved_userlist $userlist) {
        global $DB;
        $context = $userlist->get_context();
        if (!$context instanceof \context_system) {
            return;
        }

        $userids = $userlist->get_userids();
        if (empty($userids)) {
            return;
        }

        list($insql1, $inparams1) = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'u1');
        list($insql2, $inparams2) = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'u2');

        $DB->delete_records_select('local_roomscheduler_rooms',
            "teacherid $insql1", $inparams1);
        $DB->delete_records_select('local_roomscheduler_students',
            "userid $insql1", $inparams1);
        $DB->delete_records_select('local_roomscheduler_teacher_prefs',
            "teacherid $insql1", $inparams1);
        $DB->delete_records_select('local_roomscheduler_requests',
            "userid $insql1 OR teacherid $insql2",
            array_merge($inparams1, $inparams2));
        $DB->delete_records_select('local_roomscheduler_request_archive',
            "userid $insql1 OR teacherid $insql2",
            array_merge($inparams1, $inparams2));
        $DB->delete_records_select('local_roomscheduler_attendance',
            "teacherid $insql1 OR studentid $insql2",
            array_merge($inparams1, $inparams2));
    }
}
