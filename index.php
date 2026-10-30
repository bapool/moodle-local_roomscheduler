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
 * Main page for local_roomscheduler — inline table editor.
 *
 * @package    local_roomscheduler
 * @copyright  2026 Brian Pool
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

require_login();

$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/roomscheduler/index.php'));
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('pluginname', 'local_roomscheduler'));
$PAGE->set_heading(get_string('pluginname_heading', 'local_roomscheduler'));

// Check plugin is configured.
$teachercourseid   = (int) get_config('local_roomscheduler', 'teachercourseid');
$counselorcourseid = (int) get_config('local_roomscheduler', 'counselorcourseid');

if (empty($teachercourseid) || empty($counselorcourseid)) {
    echo $OUTPUT->header();
    if (is_siteadmin()) {
        echo $OUTPUT->notification(get_string('noteachercourseid', 'local_roomscheduler'), 'notifyproblem');
        $settingsurl = new moodle_url('/admin/settings.php', ['section' => 'local_roomscheduler']);
        echo html_writer::div(
            html_writer::link($settingsurl, get_string('settings', 'local_roomscheduler')),
            'mt-3'
        );
    } else {
        echo $OUTPUT->notification(get_string('notconfigured', 'local_roomscheduler'), 'notifyproblem');
    }
    echo $OUTPUT->footer();
    die;
}

// --- Determine access level ---
// Site admin always gets manage.
$isadmin = is_siteadmin();

// Course manager of the counselor course gets manage.
$counselorcontext = context_course::instance($counselorcourseid);
// Check for manager role specifically — teachers also have course:update.
$managerroleid = $DB->get_field('role', 'id', ['shortname' => 'manager']);
$ismanager     = !empty($managerroleid) && user_has_role_assignment($USER->id, $managerroleid, $counselorcontext->id);

// Teachers in either the teacher course (5513) or the counselor course (53) get read-only.
$isteacher = $DB->record_exists_sql(
    "SELECT 1 FROM {user_enrolments} ue
       JOIN {enrol} e ON e.id = ue.enrolid
      WHERE e.courseid IN (:teachercourseid, :counselorcourseid)
        AND ue.userid = :userid",
    ['teachercourseid' => $teachercourseid, 'counselorcourseid' => $counselorcourseid, 'userid' => $USER->id]
);

$canmanage = ($isadmin || $ismanager);

if (!$canmanage && !$isteacher) {
    echo $OUTPUT->header();
    echo $OUTPUT->notification(get_string('nopermission', 'local_roomscheduler'), 'notifyproblem');
    echo $OUTPUT->footer();
    die;
}

// Intervention type options.
$typeoptions = [
    'ELA1P' => get_string('type_ELA1P', 'local_roomscheduler'),
    'ELA1'  => get_string('type_ELA1',  'local_roomscheduler'),
    'ELA2'  => get_string('type_ELA2',  'local_roomscheduler'),
    'MTH1P' => get_string('type_MTH1P', 'local_roomscheduler'),
    'MTH1'  => get_string('type_MTH1',  'local_roomscheduler'),
    'MTH2'  => get_string('type_MTH2',  'local_roomscheduler'),
    'GEN'   => get_string('type_GEN',   'local_roomscheduler'),
    'IEP'   => get_string('type_IEP',   'local_roomscheduler'),
    'NA'    => get_string('type_NA',    'local_roomscheduler'),
];

// Grade preference options.
$gradeoptions = [
    '9'    => '9',
    '10'   => '10',
    '11'   => '11',
    '12'   => '12',
    'Open' => get_string('gradeopen', 'local_roomscheduler'),
];

// Get all teachers using direct SQL keyed by user ID.
$sql = "SELECT u.id, u.firstname, u.lastname
          FROM {user} u
          JOIN {user_enrolments} ue ON ue.userid = u.id
          JOIN {enrol} e ON e.id = ue.enrolid
         WHERE e.courseid = :courseid
           AND u.deleted = 0
           AND u.suspended = 0
         ORDER BY u.lastname, u.firstname";
$enrolledteachers = $DB->get_records_sql($sql, ['courseid' => $teachercourseid]);

// Build valid teacher ID list as integers.
$validteacherids = array_map('intval', array_keys($enrolledteachers));

// --- Handle Reset Student Data (admins only) ---
if ($isadmin && $_SERVER['REQUEST_METHOD'] === 'POST' && optional_param('resetstudentdata', 0, PARAM_INT)) {
    require_sesskey();
    $DB->delete_records('local_roomscheduler_students');
    $DB->delete_records('local_roomscheduler_requests');
    $DB->delete_records('local_roomscheduler_request_archive');
    $DB->delete_records('local_roomscheduler_attendance');
    $DB->delete_records('local_roomscheduler_behavior');
    redirect(
        new moodle_url('/local/roomscheduler/index.php'),
        'Student intervention data has been reset successfully.',
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

// --- Handle form submission (managers only) ---
if ($canmanage && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();

    $teacherids        = optional_param_array('teacherid',        [], PARAM_INT);
    $roomnumbers       = optional_param_array('roomnumber',       [], PARAM_TEXT);
    $maxstudentsarr    = optional_param_array('maxstudents',      [], PARAM_INT);
    $interventiontypes = optional_param_array('interventiontype', [], PARAM_ALPHANUMEXT);
    $gradelevels       = optional_param_array('gradelevel',       [], PARAM_ALPHANUMEXT);
    $recordids         = optional_param_array('recordid',         [], PARAM_INT);

    $now   = time();
    $count = count($teacherids);

    for ($i = 0; $i < $count; $i++) {
        $teacherid        = (int) ($teacherids[$i] ?? 0);
        $roomnumber       = trim(clean_param($roomnumbers[$i] ?? '', PARAM_TEXT));
        $maxstudents      = (int) ($maxstudentsarr[$i] ?? 0);
        $interventiontype = clean_param($interventiontypes[$i] ?? 'NA', PARAM_ALPHANUMEXT);
        $gradelevel       = clean_param($gradelevels[$i] ?? 'Open', PARAM_ALPHANUMEXT);
        $recordid         = (int) ($recordids[$i] ?? 0);

        // Skip if teacher ID is zero or not in enrolled list.
        if (!$teacherid || !in_array($teacherid, $validteacherids)) {
            continue;
        }

        if (!array_key_exists($interventiontype, $typeoptions)) {
            $interventiontype = 'NA';
        }

        if (!array_key_exists($gradelevel, $gradeoptions)) {
            $gradelevel = 'Open';
        }

        $isrewardroom = optional_param('isrewardroom_' . $teacherid, 0, PARAM_INT);

        if ($recordid) {
            // Update existing record.
            $rec                   = new stdClass();
            $rec->id               = $recordid;
            $rec->teacherid        = $teacherid;
            $rec->roomnumber       = $roomnumber;
            $rec->maxstudents      = $maxstudents;
            $rec->interventiontype = $interventiontype;
            $rec->gradelevel       = $gradelevel;
            $rec->isrewardroom     = $isrewardroom;
            $rec->timemodified     = $now;
            $DB->update_record('local_roomscheduler_rooms', $rec);
        } else if (!empty($roomnumber)) {
            // Only insert new records that have a room number entered.
            $rec                   = new stdClass();
            $rec->teacherid        = $teacherid;
            $rec->roomnumber       = $roomnumber;
            $rec->maxstudents      = $maxstudents;
            $rec->interventiontype = $interventiontype;
            $rec->gradelevel       = $gradelevel;
            $rec->isrewardroom     = $isrewardroom;
            $rec->timecreated      = $now;
            $rec->timemodified     = $now;
            $DB->insert_record('local_roomscheduler_rooms', $rec);
        }
    }

    // DATA INTEGRITY: Remove records for teachers no longer enrolled.
    if (!empty($validteacherids)) {
        $allrecords = $DB->get_records('local_roomscheduler_rooms', null, '', 'id, teacherid');
        foreach ($allrecords as $allrec) {
            if (!in_array((int)$allrec->teacherid, $validteacherids)) {
                $DB->delete_records('local_roomscheduler_rooms', ['id' => $allrec->id]);
            }
        }
    }

    redirect(
        new moodle_url('/local/roomscheduler/index.php'),
        get_string('savedsuccess', 'local_roomscheduler'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

// Get existing room assignment records keyed by teacherid.
$existingrecords = $DB->get_records('local_roomscheduler_rooms', null, '', '*');
$byteacher = [];
foreach ($existingrecords as $rec) {
    $byteacher[$rec->teacherid] = $rec;
}

// --- Render page ---
echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('pluginname_heading', 'local_roomscheduler'));

// Navigation tabs.
local_roomscheduler_print_admin_tabs('rooms', $counselorcourseid, $canmanage);

if (empty($enrolledteachers)) {
    echo $OUTPUT->notification(get_string('norecords', 'local_roomscheduler'), 'info');
    echo $OUTPUT->footer();
    die;
}

// Inline styles.
echo html_writer::tag('style', '
    .roomscheduler-table th {
        background-color: #f8f9fa;
        white-space: nowrap;
    }
    .roomscheduler-table td {
        vertical-align: middle !important;
    }
    .roomscheduler-table input[type="text"],
    .roomscheduler-table input[type="number"],
    .roomscheduler-table select {
        width: 100%;
    }
    .roomscheduler-table .teacher-name {
        white-space: nowrap;
        font-weight: 500;
    }
    .roomscheduler-table tr.has-data {
        background-color: #f0fff0 !important;
    }
    .lastupdated-cell {
        font-size: 0.82em;
        color: #555;
        white-space: nowrap;
    }
    .lastupdated-cell.never {
        color: #bbb;
        font-style: italic;
    }
');

if ($canmanage) {
    // --- MANAGER VIEW: inline editable table ---
    $formurl = new moodle_url('/local/roomscheduler/index.php');

    echo html_writer::start_tag('form', [
        'method' => 'post',
        'action' => $formurl->out(false),
    ]);
    echo html_writer::empty_tag('input', [
        'type'  => 'hidden',
        'name'  => 'sesskey',
        'value' => sesskey(),
    ]);

    echo html_writer::tag('p',
        'Enter a room number to save a row. Green rows have saved data.',
        ['class' => 'text-muted mb-2']
    );

    echo html_writer::start_tag('div', ['class' => 'table-responsive']);
    echo html_writer::start_tag('table', [
        'class' => 'table table-bordered table-hover table-sm roomscheduler-table',
    ]);

    // Header.
    echo html_writer::start_tag('thead', ['class' => 'thead-light']);
    echo html_writer::start_tag('tr');
    echo html_writer::tag('th', get_string('teacher',          'local_roomscheduler'), ['style' => 'width:22%']);
    echo html_writer::tag('th', 'Room',                                                ['style' => 'width:5%']);
    echo html_writer::tag('th', get_string('interventiontype', 'local_roomscheduler'), ['style' => 'width:15%']);
    echo html_writer::tag('th', get_string('gradelevel',        'local_roomscheduler'), ['style' => 'width:10%']);
    echo html_writer::tag('th', 'Max #',                                            ['style' => 'width:8%']);
    echo html_writer::tag('th', get_string('homeroomshort', 'local_roomscheduler'),    ['style' => 'width:8%']);
    echo html_writer::tag('th', get_string('thurfrishort', 'local_roomscheduler'),     ['style' => 'width:8%']);
    echo html_writer::tag('th', 'Reward',                                              ['style' => 'width:6%; text-align:center;']);
    echo html_writer::end_tag('tr');
    echo html_writer::end_tag('thead');

    // Load current Th/Fri (intervention_room) assignment counts per room number.
    $assigncounts = [];
    $assignrows = $DB->get_records_sql(
        "SELECT intervention_room, COUNT(*) as cnt
           FROM {local_roomscheduler_students}
          WHERE intervention_room != ''
            AND courseid = :courseid
          GROUP BY intervention_room",
        ['courseid' => $counselorcourseid]
    );
    foreach ($assignrows as $arow) {
        $assigncounts[$arow->intervention_room] = (int)$arow->cnt;
    }

    // Load current Homeroom (Mon/Tue/Wed) assignment counts per room number.
    $homeroomcounts = [];
    $homeroomrows = $DB->get_records_sql(
        "SELECT homeroom, COUNT(*) as cnt
           FROM {local_roomscheduler_students}
          WHERE homeroom != ''
            AND courseid = :courseid
          GROUP BY homeroom",
        ['courseid' => $counselorcourseid]
    );
    foreach ($homeroomrows as $hrow) {
        $homeroomcounts[$hrow->homeroom] = (int)$hrow->cnt;
    }

    // Most recent save across all rooms, shown once next to the Save button
    // instead of a per-row "Updated" column.
    $lastsavedoverall = $DB->get_field_sql(
        "SELECT MAX(timemodified) FROM {local_roomscheduler_rooms}"
    );

    echo html_writer::start_tag('tbody');

    echo html_writer::start_tag('tbody');

    $i = 0;
    foreach ($enrolledteachers as $teacherid => $teacher) {
        $i++;
        $tid      = (int)$teacherid;
        $rec      = isset($byteacher[$tid]) ? $byteacher[$tid] : null;
        $recordid = $rec ? (int)$rec->id            : 0;
        $roomval  = $rec ? s($rec->roomnumber)       : '';
        $maxval   = $rec ? (int)$rec->maxstudents    : 0;
        $typeval  = $rec ? $rec->interventiontype    : 'NA';
        $gradeval = $rec ? $rec->gradelevel          : 'Open';
        $hasdata  = ($rec && !empty(trim($rec->roomnumber)));
        $isreward = $rec ? (int)$rec->isrewardroom : 0;

        $rowclass = $hasdata ? 'has-data' : '';
        $fullname = s($teacher->lastname . ', ' . $teacher->firstname);

        echo html_writer::start_tag('tr', ['class' => $rowclass]);


        // Teacher name cell with hidden inputs inside the td.
        echo '<td>'
            . '<input type="hidden" name="recordid[]" value="' . $recordid . '">'
            . '<input type="hidden" name="teacherid[]" value="' . $tid . '">'
            . '<span class="teacher-name">' . $fullname . '</span>'
            . '</td>';

        // Room number.
        echo html_writer::tag('td',
            html_writer::empty_tag('input', [
                'type'        => 'text',
                'name'        => 'roomnumber[]',
                'value'       => $roomval,
                'maxlength'   => 20,
                'class'       => 'form-control form-control-sm',
                'placeholder' => 'e.g. 101',
            ])
        );

        // Intervention type.
        echo html_writer::tag('td',
            html_writer::select(
                $typeoptions,
                'interventiontype[]',
                $typeval,
                false,
                ['class' => 'form-control form-control-sm']
            )
        );

        // Grade preference.
        echo html_writer::tag('td',
            html_writer::select(
                $gradeoptions,
                'gradelevel[]',
                $gradeval,
                false,
                ['class' => 'form-control form-control-sm']
            )
        );

        // Max students.
        echo html_writer::tag('td',
            html_writer::empty_tag('input', [
                'type'  => 'number',
                'name'  => 'maxstudents[]',
                'value' => $maxval,
                'min'   => 0,
                'max'   => 999,
                'class' => 'form-control form-control-sm',
            ])
        );

        // Room number for count lookups.
        $roomnum  = $rec ? $rec->roomnumber : '';
        $capacity = $rec ? (int)$rec->maxstudents : 0;

        // Color-code an assigned count against the room's capacity.
        $countcolorfor = function($assigned, $capacity) {
            if ($capacity <= 0) {
                return '';
            }
            if ($assigned >= $capacity) {
                return 'color:red;font-weight:bold;';
            } else if ($assigned >= $capacity * 0.8) {
                return 'color:orange;font-weight:bold;';
            }
            return 'color:green;';
        };

        // Homeroom (Mon/Tue/Wed) count.
        $homeroomassigned = $homeroomcounts[$roomnum] ?? 0;
        $homeroomcolor    = $countcolorfor($homeroomassigned, $capacity);
        $homeroomtext     = $homeroomassigned > 0 ? $homeroomassigned : '—';
        echo html_writer::tag('td', $homeroomtext, ['style' => $homeroomcolor . 'text-align:center;font-weight:bold;']);

        // Th/Fri (intervention_room) count.
        $assigned   = $assigncounts[$roomnum] ?? 0;
        $countcolor = $countcolorfor($assigned, $capacity);
        $counttext = $assigned > 0 ? $assigned : '—';
        echo html_writer::tag('td', $counttext, ['style' => $countcolor . 'text-align:center;font-weight:bold;']);

        // Reward room checkbox.
        $checkedattr = $isreward ? 'checked' : '';
        echo '<td style="text-align:center;">'
            . '<div style="display:flex;justify-content:center;">'
            . '<input type="checkbox" name="isrewardroom_' . $tid . '" value="1" style="width:auto;" ' . $checkedattr . '>'
            . '</div></td>';

        echo html_writer::end_tag('tr');
    }

    echo html_writer::end_tag('tbody');
    echo html_writer::end_tag('table');
    echo html_writer::end_tag('div');

    echo html_writer::start_div('mt-3 mb-4 d-flex align-items-center');
    echo html_writer::empty_tag('input', [
        'type'  => 'submit',
        'value' => 'Save — Save All Changes',
        'class' => 'btn btn-primary btn-lg mr-3',
    ]);
    echo html_writer::span(
        $lastsavedoverall
            ? 'Last Saved: ' . userdate($lastsavedoverall, '%m/%d %H:%M')
            : 'Last Saved: Never',
        'text-muted'
    );
    echo html_writer::end_div();
    echo html_writer::end_tag('form');

    // August new-school-year reminder banner (admin only). Placed below the
    // save button/form on purpose — this is a once-a-year destructive action
    // and should not sit next to routine save clicks.
    $currentmonth = (int)date('n');
    if ($currentmonth === 8 && $isadmin) {
        $selfurl = new moodle_url('/local/roomscheduler/index.php');
        echo html_writer::start_div('alert alert-warning mt-4');
        echo html_writer::tag('h5', '⚠️ New School Year Reminder', ['class' => 'alert-heading']);
        echo html_writer::tag('p',
            html_writer::tag('strong', 'WARNING: This should only be done ONCE per school year. '
            . 'All student data will be permanently deleted. '
            . 'Rooms, teacher preferences, and plugin settings will be preserved.')
        );
        echo html_writer::start_tag('form', [
            'method'   => 'post',
            'action'   => $selfurl->out(false),
            'class'    => 'd-inline-block',
            'onsubmit' => 'return confirm("⚠️ WARNING: This will permanently delete ALL student scores, room assignments, requests, attendance records, and behavior denials.\n\nThis should only be done ONCE at the start of each school year.\n\nAre you absolutely sure?");',
        ]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey',          'value' => sesskey()]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'resetstudentdata', 'value' => '1']);
        echo html_writer::empty_tag('input', [
            'type'  => 'submit',
            'value' => '🗑 Reset Student Data Now',
            'class' => 'btn btn-danger btn-sm',
        ]);
        echo html_writer::end_tag('form');
        echo html_writer::end_div();
    }

} else {
    // --- TEACHER READ-ONLY VIEW ---
    echo html_writer::start_tag('div', ['class' => 'table-responsive']);
    echo html_writer::start_tag('table', [
        'class' => 'table table-bordered table-hover table-sm roomscheduler-table',
    ]);

    echo html_writer::start_tag('thead', ['class' => 'thead-light']);
    echo html_writer::start_tag('tr');
    echo html_writer::tag('th', '#');
    echo html_writer::tag('th', get_string('teacher',          'local_roomscheduler'));
    echo html_writer::tag('th', get_string('roomnumber',       'local_roomscheduler'));
    echo html_writer::tag('th', get_string('interventiontype', 'local_roomscheduler'));
    echo html_writer::tag('th', get_string('maxstudents',      'local_roomscheduler'));
    echo html_writer::end_tag('tr');
    echo html_writer::end_tag('thead');

    echo html_writer::start_tag('tbody');

    $i = 0;
    foreach ($enrolledteachers as $teacherid => $teacher) {
        $tid = (int)$teacherid;
        $rec = isset($byteacher[$tid]) ? $byteacher[$tid] : null;
        if (!$rec || empty(trim($rec->roomnumber))) {
            continue;
        }
        $i++;
        $typelabel = isset($typeoptions[$rec->interventiontype])
            ? $typeoptions[$rec->interventiontype]
            : $rec->interventiontype;
        $fullname = s($teacher->lastname . ', ' . $teacher->firstname);

        echo html_writer::start_tag('tr');
        echo html_writer::tag('td', $i);
        echo html_writer::tag('td', '<span class="teacher-name">' . $fullname . '</span>');
        echo html_writer::tag('td', s($rec->roomnumber));
        echo html_writer::tag('td', $typelabel);
        echo html_writer::tag('td', (int)$rec->maxstudents);
        echo html_writer::end_tag('tr');
    }

    if ($i === 0) {
        echo html_writer::start_tag('tr');
        echo html_writer::tag('td',
            get_string('norecords', 'local_roomscheduler'),
            ['colspan' => 5, 'class' => 'text-center text-muted']
        );
        echo html_writer::end_tag('tr');
    }

    echo html_writer::end_tag('tbody');
    echo html_writer::end_tag('table');
    echo html_writer::end_tag('div');
}

echo $OUTPUT->footer();
