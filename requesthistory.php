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
 * Request history page for local_roomscheduler.
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
$PAGE->set_url(new moodle_url('/local/roomscheduler/requesthistory.php', ['courseid' => $courseid]));
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('requesthistory', 'local_roomscheduler'));
$PAGE->set_heading(get_string('requesthistory_heading', 'local_roomscheduler'));

require_login($course);

$hscategoryid      = (int) get_config('local_roomscheduler', 'hscategoryid');
$counselorcourseid = (int) get_config('local_roomscheduler', 'counselorcourseid');

if (empty($hscategoryid) || empty($counselorcourseid)) {
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

// Load this teacher's archive records.
$archiverows = $DB->get_records_select(
    'local_roomscheduler_request_archive',
    'teacherid = :teacherid',
    ['teacherid' => $USER->id],
    'weekof DESC',
    '*'
);

// Group by week.
$archiveweeks = []; // weekof => [userid => true]
foreach ($archiverows as $arc) {
    $archiveweeks[$arc->weekof][$arc->userid] = true;
}

// Get current requests too (not yet archived).
$currentrequests = $DB->get_records(
    'local_roomscheduler_requests',
    ['teacherid' => $USER->id, 'requested' => 1],
    '',
    '*'
);
$currentuserids = [];
foreach ($currentrequests as $req) {
    $currentuserids[$req->userid] = true;
}

// Collect all unique student IDs.
$allstudentids = [];
foreach ($archiveweeks as $uids) {
    foreach (array_keys($uids) as $uid) {
        $allstudentids[$uid] = true;
    }
}
foreach (array_keys($currentuserids) as $uid) {
    $allstudentids[$uid] = true;
}

// Load student info and scores.
$students = [];
if (!empty($allstudentids)) {
    list($insql, $inparams) = $DB->get_in_or_equal(array_keys($allstudentids), SQL_PARAMS_NAMED, 'st');
    $sql = "SELECT u.id, u.firstname, u.lastname, u.idnumber,
                   s.homeroom, s.intervention_room, s.ela_score, s.math_score, s.assignment_code
              FROM {user} u
         LEFT JOIN {local_roomscheduler_students} s ON s.userid = u.id AND s.courseid = :courseid
             WHERE u.id $insql
             ORDER BY u.lastname, u.firstname";
    $params = array_merge(['courseid' => $counselorcourseid], $inparams);
    $students = $DB->get_records_sql($sql, $params);
}

// --- Render page ---
echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('requesthistory_heading', 'local_roomscheduler')
    . ': ' . fullname($USER));

// Navigation tabs (standard set for all four Intervention pages).
$showscheduler = ($isadmin || $ismanager) && (int)$courseid === $counselorcourseid;
local_roomscheduler_print_intervention_tabs('requesthistory', $courseid, $showscheduler, $showscheduler);

if (empty($students)) {
    echo $OUTPUT->notification(get_string('norequesthistory', 'local_roomscheduler'), 'info');
    echo $OUTPUT->footer();
    die;
}

// Get the last 4 distinct week timestamps for columns.
$weeks = array_keys($archiveweeks);
sort($weeks); // Oldest first.
$weeks = array_slice($weeks, -4); // Last 4.

// Inline styles.
echo html_writer::tag('style', '
    .history-table th { background-color: #f8f9fa; white-space: nowrap; }
    .history-table td { vertical-align: middle !important; text-align: center; }
    .history-table td.namecell { text-align: left; }
    .history-table .score-1 { background-color: #ffe0e0 !important; }
    .history-table .score-2 { background-color: #fff3cd !important; }
    .history-table .score-3 { background-color: #f0fff0 !important; }
    .check-yes { color: green; font-weight: bold; font-size: 1.2em; }
');

echo html_writer::tag('p',
    'Showing the last 4 weeks of intervention requests you made.',
    ['class' => 'text-muted small mb-2']
);

echo html_writer::start_tag('div', ['class' => 'table-responsive']);
echo html_writer::start_tag('table', [
    'class' => 'table table-bordered table-hover table-sm history-table',
]);

echo html_writer::start_tag('thead', ['class' => 'thead-light']);
echo html_writer::start_tag('tr');
echo html_writer::tag('th', get_string('lastname',   'local_roomscheduler'), ['style' => 'width:18%; text-align:left;']);
echo html_writer::tag('th', get_string('firstname',  'local_roomscheduler'), ['style' => 'width:15%; text-align:left;']);
echo html_writer::tag('th', get_string('ela_score',  'local_roomscheduler'), ['style' => 'width:8%']);
echo html_writer::tag('th', get_string('math_score', 'local_roomscheduler'), ['style' => 'width:8%']);
echo html_writer::tag('th', 'Current',                                       ['style' => 'width:10%']);
foreach ($weeks as $weekof) {
    echo html_writer::tag('th',
        userdate($weekof, '%-m/%-d'),
        ['style' => 'width:10%']
    );
}
// Pad to 4 week columns if we have fewer.
for ($w = count($weeks); $w < 4; $w++) {
    echo html_writer::tag('th', '—', ['style' => 'width:10%; color:#ccc;']);
}
echo html_writer::end_tag('tr');
echo html_writer::end_tag('thead');

echo html_writer::start_tag('tbody');

foreach ($students as $student) {
    $uid      = (int)$student->id;
    $elascore = (int)($student->ela_score ?? 3);
    $mathscore = (int)($student->math_score ?? 3);
    $minscore = min($elascore, $mathscore);
    $rowclass = 'score-' . $minscore;

    echo html_writer::start_tag('tr', ['class' => $rowclass]);
    echo html_writer::tag('td', s($student->lastname),  ['class' => 'namecell']);
    echo html_writer::tag('td', s($student->firstname), ['class' => 'namecell']);
    echo html_writer::tag('td', $elascore);
    echo html_writer::tag('td', $mathscore);
    // Current.
    echo html_writer::tag('td',
        isset($currentuserids[$uid]) ? html_writer::span('✓', 'check-yes') : ''
    );
    // Archive weeks.
    foreach ($weeks as $weekof) {
        $wasrequested = isset($archiveweeks[$weekof][$uid]);
        echo html_writer::tag('td',
            $wasrequested ? html_writer::span('✓', 'check-yes') : ''
        );
    }
    for ($w = count($weeks); $w < 4; $w++) {
        echo html_writer::tag('td', '');
    }
    echo html_writer::end_tag('tr');
}

echo html_writer::end_tag('tbody');
echo html_writer::end_tag('table');
echo html_writer::end_div();

echo $OUTPUT->footer();
