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
 * Intervention attendance page for local_roomscheduler.
 *
 * @package    local_roomscheduler
 * @copyright  2026 Brian Pool
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once(__DIR__ . '/lib.php');

require_login();

$courseid = required_param('courseid', PARAM_INT);
$course   = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);

$coursecontext = context_course::instance($courseid);
$PAGE->set_context($coursecontext);
$PAGE->set_url(new moodle_url('/local/roomscheduler/intattendance.php', ['courseid' => $courseid]));
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('intattendance_heading', 'local_roomscheduler'));
$PAGE->set_heading(get_string('intattendance_heading', 'local_roomscheduler'));

require_login($course);

// Get plugin settings.
$counselorcourseid = (int) get_config('local_roomscheduler', 'counselorcourseid');
$hscategoryid      = (int) get_config('local_roomscheduler', 'hscategoryid');

if (empty($counselorcourseid) || empty($hscategoryid)) {
    echo $OUTPUT->header();
    echo $OUTPUT->notification(get_string('notconfigured', 'local_roomscheduler'), 'notifyproblem');
    echo $OUTPUT->footer();
    die;
}

// Verify this course is in the HS category.
if (!local_roomscheduler_is_in_category((int)$course->category, $hscategoryid) && !is_siteadmin()) {
    echo $OUTPUT->header();
    echo $OUTPUT->notification(get_string('nopermission', 'local_roomscheduler'), 'notifyproblem');
    echo $OUTPUT->footer();
    die;
}

// Check user is a teacher in this course or site admin.
$isadmin        = is_siteadmin();
$managerroleid  = $DB->get_field('role', 'id', ['shortname' => 'manager']);
$ismanager      = !empty($managerroleid) && user_has_role_assignment(
                      $USER->id, $managerroleid, $coursecontext->id);
$teacherroleid  = $DB->get_field('role', 'id', ['shortname' => 'editingteacher']);
$teacherroleid2 = $DB->get_field('role', 'id', ['shortname' => 'teacher']);
$isteacher      = user_has_role_assignment($USER->id, $teacherroleid,  $coursecontext->id)
               || user_has_role_assignment($USER->id, $teacherroleid2, $coursecontext->id);

if (!$isadmin && !$isteacher) {
    echo $OUTPUT->header();
    echo $OUTPUT->notification(get_string('nopermission', 'local_roomscheduler'), 'notifyproblem');
    echo $OUTPUT->footer();
    die;
}

// Get this teacher's room assignment.
$myroom = $DB->get_record('local_roomscheduler_rooms', ['teacherid' => $USER->id]);

// Determine if this teacher tracks attendance (ELA, Math, or IEP rooms).
// IEP rooms track both ELA and Math, so the teacher marks which one each student received.
$tracksattendance = false;
$isela            = false;
$ismath           = false;
$attcolumns       = [];
if ($myroom) {
    $isela            = in_array($myroom->interventiontype, ['ELA1P', 'ELA1', 'ELA2']);
    $ismath           = in_array($myroom->interventiontype, ['MTH1P', 'MTH1', 'MTH2']);
    $attcolumns       = local_roomscheduler_attendance_columns($myroom);
    $tracksattendance = !empty($attcolumns);
}

// Get selected date (default today).
$selecteddate  = optional_param('attendancedate', date('Y-m-d'), PARAM_TEXT);
$dateparts     = explode('-', $selecteddate);
$datetimestamp = mktime(0, 0, 0, $dateparts[1], $dateparts[2], $dateparts[0]);

// Get ALL students assigned to this teacher's intervention room.
// Checkboxes will only appear for ELA/Math score 1/2 students.
$students = [];
if ($myroom) {
    $sql = "SELECT DISTINCT u.id, u.firstname, u.lastname, u.idnumber,
                   s.homeroom, s.intervention_room, s.assignment_code,
                   s.ela_score, s.math_score, c.fullname AS dfcoursename
              FROM {user} u
              JOIN {local_roomscheduler_students} s ON s.userid = u.id
         LEFT JOIN {course} c ON c.id = s.dfcourseid
             WHERE s.intervention_room = :introom
               AND s.courseid = :courseid
               AND u.deleted = 0
             ORDER BY u.lastname, u.firstname";
    $students = $DB->get_records_sql($sql, [
        'introom'  => $myroom->roomnumber,
        'courseid' => $counselorcourseid,
    ]);
}

// Work out which attendance types (E/M) each student can be marked for in this room.
$studenttypes = [];
if ($tracksattendance) {
    foreach ($students as $uid => $student) {
        $types = local_roomscheduler_attendance_types($myroom, $student, true);
        if (!empty($types)) {
            $studenttypes[(int)$uid] = $types;
        }
    }
}

// Load existing attendance for this teacher/date.
$existingattendance = local_roomscheduler_load_attendance($USER->id, array_keys($studenttypes), $datetimestamp);

// --- Handle form submission ---
if ($tracksattendance && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();

    $submitdate    = required_param('attendancedate', PARAM_TEXT);
    $dateparts     = explode('-', $submitdate);
    $datetimestamp = mktime(0, 0, 0, $dateparts[1], $dateparts[2], $dateparts[0]);

    $skipped = local_roomscheduler_save_attendance_form($USER->id, $studenttypes, $datetimestamp);

    redirect(
        new moodle_url('/local/roomscheduler/intattendance.php', [
            'courseid'       => $courseid,
            'attendancedate' => $submitdate,
        ]),
        $skipped ? get_string('attendancebothskipped', 'local_roomscheduler', $skipped)
                 : get_string('attendancesaved', 'local_roomscheduler'),
        null,
        $skipped ? \core\output\notification::NOTIFY_WARNING : \core\output\notification::NOTIFY_SUCCESS
    );
}

// --- Render page ---
echo $OUTPUT->header();
echo html_writer::start_div('no-print');
echo $OUTPUT->heading(get_string('intattendance_heading', 'local_roomscheduler')
    . ': ' . ($myroom ? 'Room ' . $myroom->roomnumber : ''));
echo html_writer::end_div();

// Navigation tabs (standard set for all four Intervention pages).
$showscheduler = ($isadmin || $ismanager) && (int)$courseid === $counselorcourseid;
local_roomscheduler_print_intervention_tabs('intattendance', $courseid, $showscheduler, $showscheduler);

if (!$myroom) {
    echo $OUTPUT->notification('You do not have a room assignment in the scheduler.', 'notifyinfo');
    echo $OUTPUT->footer();
    die;
}

// Room type info.
$roomtypelabel = $isela ? get_string('roomtype_ela', 'local_roomscheduler')
                        : ($ismath ? get_string('roomtype_math', 'local_roomscheduler')
                                   : $myroom->interventiontype);
echo html_writer::tag('p',
    'Room: <strong>' . s($myroom->roomnumber) . '</strong> &nbsp;|&nbsp; '
    . 'Type: <strong>' . s($roomtypelabel) . '</strong>',
    ['class' => 'mb-2']
);

if (empty($students)) {
    echo $OUTPUT->notification(get_string('noattendancestudents', 'local_roomscheduler'), 'info');
    echo $OUTPUT->footer();
    die;
}

// Date picker (hidden when printing).
echo html_writer::start_div('no-print');
echo html_writer::start_tag('form', [
    'method' => 'get',
    'action' => '',
    'class'  => 'mb-3 d-inline-block',
]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'courseid', 'value' => $courseid]);
echo html_writer::tag('label',
    get_string('selectdate', 'local_roomscheduler') . ': ',
    ['class' => 'mr-2 font-weight-bold']
);
echo html_writer::empty_tag('input', [
    'type'     => 'date',
    'name'     => 'attendancedate',
    'value'    => $selecteddate,
    'class'    => 'form-control d-inline-block w-auto mr-2',
    'onchange' => 'this.form.submit()',
]);
echo html_writer::end_tag('form');
echo html_writer::end_div(); // .no-print

// Inline styles.
echo html_writer::tag('style', '
    .attendance-table { table-layout: fixed; }
    .attendance-table th {
        background-color: #f8f9fa;
        white-space: normal;
        word-wrap: normal;
        overflow-wrap: normal;
    }
    .attendance-table td { vertical-align: middle !important; }
    .attendance-table .score-1 { background-color: #ffe0e0 !important; }
    .attendance-table .score-2 { background-color: #fff3cd !important; }
    .attendance-table .score-3 { background-color: #f0fff0 !important; }
    .attendance-cb { width: 22px; height: 22px; cursor: pointer; }
    .print-header { display: none; }
    @media print {
        .no-print { display: none !important; }
        .print-header { display: block !important; margin-bottom: 10px; page-break-after: avoid; }
        body { background: white !important; }
        header, nav, footer, .navbar, #page-header, #page-footer { display: none !important; }
        .attendance-table th { background: #eee !important; -webkit-print-color-adjust: exact; }
        .attendance-table tr { page-break-inside: avoid; }
        html, body,
        #page, #page-content, #region-main-box, #region-main,
        .container-fluid, #topofscroll {
            height: auto !important;
            min-height: 0 !important;
        }
        @page { margin: 0.4in; size: portrait; }
    }
');

if ($tracksattendance) {
    echo html_writer::tag('p',
        get_string(count($attcolumns) > 1 ? 'attendanceinstructions_iep' : 'attendanceinstructions',
            'local_roomscheduler'),
        ['class' => 'alert alert-info mb-2 no-print']
    );
}

// Main form.
if ($tracksattendance) {
    $formurl = new moodle_url('/local/roomscheduler/intattendance.php', ['courseid' => $courseid]);
    echo html_writer::start_tag('form', [
        'method' => 'post',
        'action' => $formurl->out(false),
        'id'     => 'attendanceform',
    ]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey',        'value' => sesskey()]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'attendancedate', 'value' => $selecteddate]);

    echo html_writer::start_div('mb-2 no-print');
    echo local_roomscheduler_attendance_checkall_buttons($attcolumns);
    echo html_writer::empty_tag('input', [
        'type'  => 'submit',
        'value' => get_string('saveattendance', 'local_roomscheduler'),
        'class' => 'btn btn-primary btn-sm',
    ]);
    echo html_writer::end_div();
}

echo html_writer::start_tag('div', ['class' => 'table-responsive']);
echo html_writer::start_tag('table', [
    'class' => 'table table-bordered table-hover table-sm attendance-table',
]);

echo html_writer::start_tag('thead', ['class' => 'thead-light']);
echo html_writer::start_tag('tr');
echo html_writer::tag('th', get_string('lastname',          'local_roomscheduler'), ['style' => 'width:13%']);
echo html_writer::tag('th', get_string('firstname',         'local_roomscheduler'), ['style' => 'width:13%']);
echo html_writer::tag('th', get_string('ela_score',         'local_roomscheduler'), ['style' => 'width:5%']);
echo html_writer::tag('th', get_string('math_score',        'local_roomscheduler'), ['style' => 'width:5%']);
echo html_writer::tag('th', get_string('col_monwed',        'local_roomscheduler'), ['style' => 'width:8%; text-align:center;']);
echo html_writer::tag('th', get_string('col_thurfri',       'local_roomscheduler'), ['style' => 'width:8%; text-align:center;']);
echo html_writer::tag('th', 'Code',                                                 ['style' => 'width:5%']);
if ($tracksattendance) {
    echo local_roomscheduler_attendance_header_cells($attcolumns);
}
echo html_writer::tag('th', get_string('col_class',         'local_roomscheduler'), ['style' => 'width:20%']);
echo html_writer::end_tag('tr');
echo html_writer::end_tag('thead');

echo html_writer::start_tag('tbody');

foreach ($students as $uid => $student) {
    $uid       = (int)$uid;
    $elascore  = (int)$student->ela_score;
    $mathscore = (int)$student->math_score;

    $minscore  = min($elascore, $mathscore);
    $rowclass  = 'score-' . $minscore;

    echo html_writer::start_tag('tr', ['class' => $rowclass]);
    echo html_writer::tag('td', s($student->lastname));
    echo html_writer::tag('td', s($student->firstname));
    echo html_writer::tag('td', $elascore,  ['style' => 'text-align:center;']);
    echo html_writer::tag('td', $mathscore, ['style' => 'text-align:center;']);
    echo html_writer::tag('td', s($student->homeroom),          ['style' => 'text-align:center;']);
    echo html_writer::tag('td', s($student->intervention_room), ['style' => 'text-align:center;']);
    echo html_writer::tag('td',
        html_writer::span(s($student->assignment_code), 'badge badge-secondary'),
        ['style' => 'text-align:center;']
    );

    if ($tracksattendance) {
        echo local_roomscheduler_attendance_cells($uid, $attcolumns, $studenttypes[$uid] ?? [],
            $existingattendance);
    }

    // Class: the course whose D/F grade placed the student here (D/F codes only).
    echo local_roomscheduler_class_cell($student);

    echo html_writer::end_tag('tr');
}

echo html_writer::end_tag('tbody');
echo html_writer::end_tag('table');
echo html_writer::end_tag('div');

if ($tracksattendance) {
    echo html_writer::start_div('mt-2 mb-3 no-print');
    echo html_writer::empty_tag('input', [
        'type'  => 'submit',
        'value' => get_string('saveattendance', 'local_roomscheduler'),
        'class' => 'btn btn-primary btn-sm',
    ]);
    echo html_writer::end_div();
    echo html_writer::end_tag('form');
} else {
    echo $OUTPUT->notification(get_string('attendancereadonly', 'local_roomscheduler'), 'info');
}

// Code legend.
echo html_writer::start_div('mt-3 p-3 border rounded bg-light');
echo html_writer::tag('strong', 'Assignment Code Key: ', ['class' => 'mr-2']);
$codes = ['E'=>'ELA Move','M'=>'Math Move','Q'=>'Requested','F'=>'F Grade',
          'D'=>'D Grade','R'=>'Reward','S'=>'Displaced','H'=>'Homeroom'];
$items = [];
foreach ($codes as $c => $d) {
    $items[] = html_writer::tag('strong', $c) . '=' . $d;
}
echo html_writer::tag('small', implode(' | ', $items), ['class' => 'text-muted']);
echo html_writer::empty_tag('br');
echo html_writer::tag('strong', 'Suffix: ', ['class' => 'mr-2']);
$suffixes = ['A'=>'Attendance denied reward','B'=>'Behavior denied reward'];
$sitems = [];
foreach ($suffixes as $c => $d) {
    $sitems[] = html_writer::tag('strong', $c) . '=' . $d;
}
echo html_writer::tag('small', implode(' | ', $sitems), ['class' => 'text-muted']);
echo html_writer::end_div();

echo html_writer::start_div('mt-3 no-print');
echo html_writer::tag('button', 'Print',
    ['class' => 'btn btn-secondary', 'onclick' => 'window.print(); return false;']
);
echo html_writer::end_div();

echo $OUTPUT->footer();
