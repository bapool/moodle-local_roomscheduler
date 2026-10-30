echo html_writer::tag('th', get_string('intervention_room', 'local_roomscheduler'), ['style' => 'width:15%']);
echo html_writer::tag('th', get_string('requested', 'local_roomscheduler'), ['style' => 'width:8%']);<?php
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
 * Intervention requests page for local_roomscheduler.
 *
 * @package    local_roomscheduler
 * @copyright  2026 Brian Pool
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once(__DIR__ . '/lib.php');

require_login();

// Get course ID from URL.
$courseid = required_param('courseid', PARAM_INT);
$course   = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);

$coursecontext = context_course::instance($courseid);
$PAGE->set_context($coursecontext);
$PAGE->set_url(new moodle_url('/local/roomscheduler/requests.php', ['courseid' => $courseid]));
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('requests', 'local_roomscheduler'));
$PAGE->set_heading(get_string('requests_heading', 'local_roomscheduler'));

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

// Check user is a teacher in this course, site admin, or manager.
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

// Load teacher's persistent preference.
$teacherpref = $DB->get_record('local_roomscheduler_teacher_prefs', ['teacherid' => $USER->id]);
$ispersistent = $teacherpref ? (int)$teacherpref->persistent : 0;

// Get selected group filter.
$selectedgroup = optional_param('groupid', 0, PARAM_INT);
$groups        = groups_get_all_groups($courseid);

// Get students in this course (student role only).
$studentroleid = $DB->get_field('role', 'id', ['shortname' => 'student']);

if ($selectedgroup > 0) {
    $sql = "SELECT DISTINCT u.id, u.firstname, u.lastname, u.username
              FROM {user} u
              JOIN {user_enrolments} ue ON ue.userid = u.id
              JOIN {enrol} e ON e.id = ue.enrolid
              JOIN {role_assignments} ra ON ra.userid = u.id
              JOIN {groups_members} gm ON gm.userid = u.id
             WHERE e.courseid = :courseid
               AND ra.contextid = :contextid
               AND ra.roleid = :roleid
               AND gm.groupid = :groupid
               AND u.deleted = 0
               AND u.suspended = 0
             ORDER BY u.lastname, u.firstname";
    $params = [
        'courseid'  => $courseid,
        'contextid' => $coursecontext->id,
        'roleid'    => $studentroleid,
        'groupid'   => $selectedgroup,
    ];
} else {
    $sql = "SELECT DISTINCT u.id, u.firstname, u.lastname, u.username
              FROM {user} u
              JOIN {user_enrolments} ue ON ue.userid = u.id
              JOIN {enrol} e ON e.id = ue.enrolid
              JOIN {role_assignments} ra ON ra.userid = u.id
             WHERE e.courseid = :courseid
               AND ra.contextid = :contextid
               AND ra.roleid = :roleid
               AND u.deleted = 0
               AND u.suspended = 0
             ORDER BY u.lastname, u.firstname";
    $params = [
        'courseid'  => $courseid,
        'contextid' => $coursecontext->id,
        'roleid'    => $studentroleid,
    ];
}
$students = $DB->get_records_sql($sql, $params);

// Get all existing requests by this teacher (keyed by userid).
$myrequests = $DB->get_records(
    'local_roomscheduler_requests',
    ['teacherid' => $USER->id],
    '',
    '*'
);
$requestedbyMe = [];
foreach ($myrequests as $req) {
    $requestedbyMe[$req->userid] = $req;
}

// Load last 4 weeks of archive data for this teacher.
$archiverows = $DB->get_records_select(
    'local_roomscheduler_request_archive',
    'teacherid = :teacherid',
    ['teacherid' => $USER->id],
    'weekof DESC',
    '*'
);

// Get the last 4 distinct weeks.
$archiveweeks = [];
foreach ($archiverows as $arc) {
    if (!in_array($arc->weekof, $archiveweeks) && count($archiveweeks) < 4) {
        $archiveweeks[] = $arc->weekof;
    }
}
sort($archiveweeks); // Oldest first for display.

// Build archive lookup: userid => [weekof => true]
$archivebystudent = [];
foreach ($archiverows as $arc) {
    $archivebystudent[$arc->userid][$arc->weekof] = true;
}

// Get student scores from counselor course (keyed by userid).
$scores = $DB->get_records(
    'local_roomscheduler_students',
    ['courseid' => $counselorcourseid],
    '',
    '*'
);
$scorebyuser = [];
foreach ($scores as $score) {
    $scorebyuser[$score->userid] = $score;
}

// --- Handle form submission ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();
// Checkboxes are submitted as requested_USERID => 1 when checked.
    // All userids are submitted as allstudents[] hidden fields.
    $allstudents  = optional_param_array('allstudents', [], PARAM_INT);
    $newpersistent = optional_param('persistent', 0, PARAM_INT);
    $now          = time();

    // Save persistent preference.
    if ($teacherpref) {
        $rec             = new stdClass();
        $rec->id         = $teacherpref->id;
        $rec->persistent = $newpersistent;
        $DB->update_record('local_roomscheduler_teacher_prefs', $rec);
    } else {
        $rec             = new stdClass();
        $rec->teacherid  = $USER->id;
        $rec->persistent = $newpersistent;
        $DB->insert_record('local_roomscheduler_teacher_prefs', $rec);
    }
    $ispersistent = $newpersistent;
    
    foreach ($allstudents as $userid) {
        $userid    = (int)$userid;
        if (!$userid) {
            continue;
        }

        // Check if this student's checkbox was submitted.
        $ischecked = optional_param('requested_' . $userid, 0, PARAM_INT);

        // Get existing record.
        $existing = $DB->get_record('local_roomscheduler_requests', [
            'userid'    => $userid,
            'teacherid' => $USER->id,
        ]);

        if ($existing) {
            $rec               = new stdClass();
            $rec->id           = $existing->id;
            $rec->requested    = $ischecked;
            $rec->timemodified = $now;
            $DB->update_record('local_roomscheduler_requests', $rec);
        } else {
            $rec               = new stdClass();
            $rec->userid       = $userid;
            $rec->teacherid    = $USER->id;
            $rec->requested    = $ischecked;
            $rec->timecreated  = $now;
            $rec->timemodified = $now;
            $DB->insert_record('local_roomscheduler_requests', $rec);
        }
    }
    $redirecturl = new moodle_url('/local/roomscheduler/requests.php',
        array_merge(['courseid' => $courseid],
        $selectedgroup ? ['groupid' => $selectedgroup] : [])
    );
    redirect(
        $redirecturl,
        get_string('requestsaved', 'local_roomscheduler'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

// --- Render page ---
echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('requests_heading', 'local_roomscheduler') . ': ' . $course->fullname);

// Navigation tabs (standard set for all four Intervention pages).
$showscheduler = ($isadmin || $ismanager) && (int)$courseid === $counselorcourseid;
local_roomscheduler_print_intervention_tabs('requests', $courseid, $showscheduler, $showscheduler);

// Group filter.
if (!empty($groups)) {
    $groupoptions = [0 => get_string('allgroups', 'local_roomscheduler')];
    foreach ($groups as $group) {
        $groupoptions[$group->id] = $group->name;
    }
    $filterurl = new moodle_url('/local/roomscheduler/requests.php', ['courseid' => $courseid]);
    echo html_writer::start_tag('form', [
        'method' => 'get',
        'action' => $filterurl->out(false),
        'class'  => 'mb-2',
    ]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'courseid', 'value' => $courseid]);
    echo html_writer::tag('label',
        get_string('selectgroup', 'local_roomscheduler') . ': ',
        ['for' => 'groupid', 'class' => 'mr-2 font-weight-bold']
    );
    echo html_writer::select(
        $groupoptions, 'groupid', $selectedgroup, false,
        ['id' => 'groupid', 'class' => 'form-control d-inline-block w-auto mr-2',
         'onchange' => 'this.form.submit()']
    );
    echo html_writer::end_tag('form');
}

if (empty($students)) {
    echo $OUTPUT->notification(get_string('nostudentscourse', 'local_roomscheduler'), 'info');
    echo $OUTPUT->footer();
    die;
}

// Inline styles.
echo html_writer::tag('style', '
    .requests-table th { background-color: #f8f9fa; white-space: normal; word-wrap: break-word; }
    .requests-table th.nowrap-header { white-space: nowrap; }
    .requests-table td { vertical-align: middle !important; }
    .requests-table .score-1 { background-color: #ffe0e0 !important; }
    .requests-table .score-2 { background-color: #fff3cd !important; }
    .requests-table .score-3 { background-color: #f0fff0 !important; }
    .requests-table .requested-cb { width: 20px; height: 20px; cursor: pointer; }
');

// Build form.
$formurl = new moodle_url('/local/roomscheduler/requests.php',
    array_merge(['courseid' => $courseid],
    $selectedgroup ? ['groupid' => $selectedgroup] : [])
);

echo html_writer::start_tag('form', [
    'method' => 'post',
    'action' => $formurl->out(false),
    'id'     => 'requestsform',
]);
echo html_writer::empty_tag('input', [
    'type'  => 'hidden',
    'name'  => 'sesskey',
    'value' => sesskey(),
]);

// Persistent checkbox.
$persistchecked = $ispersistent ? 'checked' : '';
echo html_writer::start_div('mb-3 p-2 border rounded bg-light');
echo '<input type="checkbox" name="persistent" value="1" id="persistent" '
    . $persistchecked . ' class="mr-2">';
echo html_writer::tag('label',
    '<strong>Keep these requests for next week?</strong> '
    . '<span class="text-muted small">(unchecked = requests will be cleared)</span>',
    ['for' => 'persistent']
);
echo html_writer::end_div();

// Top save button.
echo html_writer::start_div('mb-2 d-flex align-items-center');
echo html_writer::empty_tag('input', [
    'type'  => 'submit',
    'value' => 'Save All Changes',
    'class' => 'btn btn-primary btn-sm mr-3',
]);
echo html_writer::span(
    'Check the box to request a student for intervention.',
    'text-muted small'
);
echo html_writer::end_div();

echo html_writer::start_tag('div', ['class' => 'table-responsive']);
echo html_writer::start_tag('table', [
    'class' => 'table table-bordered table-hover table-sm requests-table',
]);

// Header.
echo html_writer::start_tag('thead', ['class' => 'thead-light']);
echo html_writer::start_tag('tr');
echo html_writer::tag('th', get_string('lastname',          'local_roomscheduler'), ['style' => 'width:20%']);
echo html_writer::tag('th', get_string('firstname',         'local_roomscheduler'), ['style' => 'width:17%', 'class' => 'nowrap-header']);
echo html_writer::tag('th', get_string('ela_score',         'local_roomscheduler'), ['style' => 'width:10%']);
echo html_writer::tag('th', get_string('math_score',        'local_roomscheduler'), ['style' => 'width:10%']);
echo html_writer::tag('th', get_string('homeroom',          'local_roomscheduler'), ['style' => 'width:13%']);
echo html_writer::tag('th', get_string('intervention_room', 'local_roomscheduler'), ['style' => 'width:15%']);
echo html_writer::tag('th', 'Code', ['style' => 'width:6%; white-space:nowrap;']);
echo html_writer::tag('th', get_string('requested', 'local_roomscheduler'), ['style' => 'width:8%']);
foreach ($archiveweeks as $weekof) {
    echo html_writer::tag('th',
        userdate($weekof, '%m/%d'),
        ['style' => 'width:5%; text-align:center; font-size:0.8em;']
    );
}
// Pad to 4 columns if less than 4 weeks.
for ($w = count($archiveweeks); $w < 4; $w++) {
    echo html_writer::tag('th', '—', ['style' => 'width:5%; text-align:center; font-size:0.8em;']);
}
echo html_writer::end_tag('tr');
echo html_writer::end_tag('thead');

echo html_writer::start_tag('tbody');

foreach ($students as $userid => $student) {
    $uid      = (int)$userid;
    $req      = isset($requestedbyMe[$uid]) ? $requestedbyMe[$uid] : null;
    $recordid = $req ? (int)$req->id        : 0;
    $ischecked = $req ? (int)$req->requested : 0;
    $score    = isset($scorebyuser[$uid])   ? $scorebyuser[$uid]   : null;

    $elaval   = $score ? (int)$score->ela_score        : 3;
    $mathval  = $score ? (int)$score->math_score       : 3;
    $homeroom = $score ? s($score->homeroom)           : '—';
    $introom  = $score ? s($score->intervention_room)  : '—';

    $minscore = min($elaval, $mathval);
    $rowclass = 'score-' . $minscore;

    echo html_writer::start_tag('tr', ['class' => $rowclass]);

    // Last name cell.
    echo '<td>' . s($student->lastname) . '</td>';

    echo html_writer::tag('td', s($student->firstname));
    echo html_writer::tag('td', $elaval);
    echo html_writer::tag('td', $mathval);
    echo html_writer::tag('td', $homeroom ?: '—');
    echo html_writer::tag('td', $introom  ?: '—');

    // Assignment code — why the student landed in this room.
    $acode = ($score && !empty($score->assignment_code)) ? s($score->assignment_code) : '';
    echo html_writer::tag('td',
        $acode !== '' ? html_writer::span($acode, 'badge badge-secondary') : '—',
        ['style' => 'text-align:center;']
    );

    // Checkbox + hidden value trick for unchecked state.
    $checkedattr = $ischecked ? 'checked' : '';
    echo '<td style="text-align:center;">'
        . '<input type="hidden" name="allstudents[]" value="' . $uid . '">'
        . '<input type="checkbox" name="requested_' . $uid . '" value="1" class="requested-cb" ' . $checkedattr . '>'
        . '</td>';

    // Archive columns — last 4 weeks.
    foreach ($archiveweeks as $weekof) {
        $wasrequested = isset($archivebystudent[$uid][$weekof]);
        echo html_writer::tag('td',
            $wasrequested ? '✓' : '',
            ['style' => 'text-align:center; color:green; font-weight:bold;']
        );
    }
    // Pad empty columns.
    for ($w = count($archiveweeks); $w < 4; $w++) {
        echo html_writer::tag('td', '', ['style' => 'text-align:center;']);
    }
    echo html_writer::end_tag('tr');
}

echo html_writer::end_tag('tbody');
echo html_writer::end_tag('table');
echo html_writer::end_tag('div');

echo local_roomscheduler_code_legend();

// Bottom save button.
echo html_writer::start_div('mt-2 mb-4');
echo html_writer::empty_tag('input', [
    'type'  => 'submit',
    'value' => 'Save All Changes',
    'class' => 'btn btn-primary btn-sm',
]);
echo html_writer::end_div();

echo html_writer::end_tag('form');
echo $OUTPUT->footer();
