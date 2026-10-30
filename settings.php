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
 * Admin settings for local_roomscheduler.
 *
 * @package    local_roomscheduler
 * @copyright  2026 Brian Pool
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {

    $settings = new admin_settingpage(
        'local_roomscheduler',
        get_string('pluginname', 'local_roomscheduler')
    );

    $ADMIN->add('localplugins', $settings);

    // Teacher source course ID.
    // *** NTLS DEFAULT: Course ID of the "HS Teachers" course. Change if course ID differs on target server. ***
    $settings->add(new admin_setting_configtext(
        'local_roomscheduler/teachercourseid',
        get_string('teachercourseid', 'local_roomscheduler'),
        get_string('teachercourseid_desc', 'local_roomscheduler'),
        '5513',
        PARAM_INT
    ));

    // Counselor course ID.
    // *** NTLS DEFAULT: Course ID of the "HS Counseling" course. Change if course ID differs on target server. ***
    $settings->add(new admin_setting_configtext(
        'local_roomscheduler/counselorcourseid',
        get_string('counselorcourseid', 'local_roomscheduler'),
        get_string('counselorcourseid_desc', 'local_roomscheduler'),
        '53',
        PARAM_INT
    ));

    // HS category ID.
    // *** NTLS DEFAULT: Course category ID for the high school. Change if category ID differs on target server. ***
    $settings->add(new admin_setting_configtext(
        'local_roomscheduler/hscategoryid',
        get_string('hscategoryid', 'local_roomscheduler'),
        get_string('hscategoryid_desc', 'local_roomscheduler'),
        '2',
        PARAM_INT
    ));

    // D/F grade threshold.
    // *** NTLS DEFAULT: Grades below this % are considered D or F. ***
    $settings->add(new admin_setting_configtext(
        'local_roomscheduler/dfthreshold',
        get_string('dfthreshold', 'local_roomscheduler'),
        get_string('dfthreshold_desc', 'local_roomscheduler'),
        '70',
        PARAM_FLOAT
    ));

    // A/B grade threshold.
    // *** NTLS DEFAULT: Grades at or above this % are considered A or B. ***
    $settings->add(new admin_setting_configtext(
        'local_roomscheduler/abthreshold',
        get_string('abthreshold', 'local_roomscheduler'),
        get_string('abthreshold_desc', 'local_roomscheduler'),
        '79.5',
        PARAM_FLOAT
    ));

    // Attendance threshold for reward room.
    // *** NTLS DEFAULT: Students below this attendance % are denied the reward room. ***
    $settings->add(new admin_setting_configtext(
        'local_roomscheduler/attendancethreshold',
        get_string('attendancethreshold', 'local_roomscheduler'),
        get_string('attendancethreshold_desc', 'local_roomscheduler'),
        '90',
        PARAM_FLOAT
    ));

    // Minutes per intervention session.
    // *** NTLS DEFAULT: Instruction credit minutes per attendance record. ***
    $settings->add(new admin_setting_configtext(
        'local_roomscheduler/minutespersession',
        get_string('minutespersession', 'local_roomscheduler'),
        get_string('minutespersession_desc', 'local_roomscheduler'),
        '30',
        PARAM_INT
    ));
}
