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
 * Upgrade steps for local_roomscheduler.
 *
 * @package    local_roomscheduler
 * @copyright  2026 Brian Pool
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

function xmldb_local_roomscheduler_upgrade($oldversion) {
    global $DB;
    $dbman = $DB->get_manager();

    if ($oldversion < 2026041701) {
        $table = new xmldb_table('local_roomscheduler_students');
        $table->add_field('id',                XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('userid',            XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('courseid',          XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('ela_score',         XMLDB_TYPE_INTEGER, '1',  null, XMLDB_NOTNULL, null, '3');
        $table->add_field('math_score',        XMLDB_TYPE_INTEGER, '1',  null, XMLDB_NOTNULL, null, '3');
        $table->add_field('homeroom',          XMLDB_TYPE_CHAR,    '20', null, XMLDB_NOTNULL, null, '');
        $table->add_field('intervention_room', XMLDB_TYPE_CHAR,    '20', null, XMLDB_NOTNULL, null, '');
        $table->add_field('timecreated',       XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified',      XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary',  XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('userid',   XMLDB_KEY_FOREIGN, ['userid'],   'user',   ['id']);
        $table->add_key('courseid', XMLDB_KEY_FOREIGN, ['courseid'], 'course', ['id']);
        $table->add_index('userid-courseid', XMLDB_INDEX_UNIQUE, ['userid', 'courseid']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }
        upgrade_plugin_savepoint(true, 2026041701, 'local', 'roomscheduler');
    }

    if ($oldversion < 2026041800) {
        $table = new xmldb_table('local_roomscheduler_requests');
        $table->add_field('id',           XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('userid',       XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('teacherid',    XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('requested',    XMLDB_TYPE_INTEGER, '1',  null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated',  XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary',   XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('userid',    XMLDB_KEY_FOREIGN, ['userid'],    'user', ['id']);
        $table->add_key('teacherid', XMLDB_KEY_FOREIGN, ['teacherid'], 'user', ['id']);
        $table->add_index('userid-teacherid', XMLDB_INDEX_UNIQUE, ['userid', 'teacherid']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }
        upgrade_plugin_savepoint(true, 2026041800, 'local', 'roomscheduler');
    }

    if ($oldversion < 2026041900) {
        $table = new xmldb_table('local_roomscheduler_students');
        $field = new xmldb_field('assignment_code', XMLDB_TYPE_CHAR, '1', null, XMLDB_NOTNULL, null, '');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_plugin_savepoint(true, 2026041900, 'local', 'roomscheduler');
    }

    if ($oldversion < 2026042200) {
        $table = new xmldb_table('local_roomscheduler_attendance');
        $table->add_field('id',             XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('teacherid',      XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('studentid',      XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('attendancetype', XMLDB_TYPE_CHAR,    '2',  null, XMLDB_NOTNULL, null, 'E');
        $table->add_field('attendancedate', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('present',        XMLDB_TYPE_INTEGER, '1',  null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timesaved',      XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary',   XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('teacherid', XMLDB_KEY_FOREIGN, ['teacherid'], 'user', ['id']);
        $table->add_key('studentid', XMLDB_KEY_FOREIGN, ['studentid'], 'user', ['id']);
        $table->add_index('teacher-student-type-date',
            XMLDB_INDEX_UNIQUE,
            ['teacherid', 'studentid', 'attendancetype', 'attendancedate']
        );
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }
        upgrade_plugin_savepoint(true, 2026042200, 'local', 'roomscheduler');
    }

    if ($oldversion < 2026042201) {

        // Create local_roomscheduler_teacher_prefs table.
        $table = new xmldb_table('local_roomscheduler_teacher_prefs');
        $table->add_field('id',         XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('teacherid',  XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('persistent', XMLDB_TYPE_INTEGER, '1',  null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary',   XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('teacherid', XMLDB_KEY_FOREIGN, ['teacherid'], 'user', ['id']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Create local_roomscheduler_request_archive table.
        $table = new xmldb_table('local_roomscheduler_request_archive');
        $table->add_field('id',           XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('userid',       XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('teacherid',    XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('weekof',       XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timearchived', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary',   XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('userid',    XMLDB_KEY_FOREIGN, ['userid'],    'user', ['id']);
        $table->add_key('teacherid', XMLDB_KEY_FOREIGN, ['teacherid'], 'user', ['id']);
        $table->add_index('userid-teacherid-weekof', XMLDB_INDEX_NOTUNIQUE, ['userid', 'teacherid', 'weekof']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026042201, 'local', 'roomscheduler');
    }

    if ($oldversion < 2026042202) {

        // Create local_roomscheduler_behavior table.
        $table = new xmldb_table('local_roomscheduler_behavior');

        $table->add_field('id',           XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('userid',       XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('sisid',        XMLDB_TYPE_CHAR,    '20', null, XMLDB_NOTNULL, null, '');
        $table->add_field('denied',       XMLDB_TYPE_INTEGER, '1',  null, XMLDB_NOTNULL, null, '0');
        $table->add_field('incidents',    XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('modifiedby',   XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('userid',  XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);


        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026042202, 'local', 'roomscheduler');
    }

    if ($oldversion < 2026042203) {

        // Expand assignment_code from CHAR(1) to CHAR(2) to hold denial suffixes.
        $table = new xmldb_table('local_roomscheduler_students');
        $field = new xmldb_field('assignment_code', XMLDB_TYPE_CHAR, '2', null, XMLDB_NOTNULL, null, '');

        if ($dbman->field_exists($table, $field)) {
            $dbman->change_field_precision($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026042203, 'local', 'roomscheduler');
    }

    if ($oldversion < 2026072401) {

        // Add isrewardroom flag to local_roomscheduler_rooms.
        $table = new xmldb_table('local_roomscheduler_rooms');
        $field = new xmldb_field('isrewardroom', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');

        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026072401, 'local', 'roomscheduler');
    }

    if ($oldversion < 2026072404) {

        // Add gradelevel preference to local_roomscheduler_rooms.
        $table = new xmldb_table('local_roomscheduler_rooms');
        $field = new xmldb_field('gradelevel', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, 'Open');

        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026072404, 'local', 'roomscheduler');
    }

    if ($oldversion < 2026080500) {

        // Add blazerroom (imported Blazer Room number) to local_roomscheduler_students.
        $table = new xmldb_table('local_roomscheduler_students');
        $field = new xmldb_field('blazerroom', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, '');

        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026080500, 'local', 'roomscheduler');
    }

    if ($oldversion < 2026102902) {

        // Drop blazerroom. The Intervention Homeroom (homeroom field) is what
        // used to be called the Blazer Room, so this separate field is no
        // longer imported or used.
        $table = new xmldb_table('local_roomscheduler_students');
        $field = new xmldb_field('blazerroom');

        if ($dbman->field_exists($table, $field)) {
            $dbman->drop_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026102902, 'local', 'roomscheduler');
    }

    if ($oldversion < 2026103000) {

        // Add dfcourseid: the course whose D/F grade placed the student in their
        // Thur/Fri room, shown as "Class" on the teacher attendance pages.
        $table = new xmldb_table('local_roomscheduler_students');
        $field = new xmldb_field('dfcourseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0',
            'assignment_code');

        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026103000, 'local', 'roomscheduler');
    }

    return true;
}
