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
 * Student intervention scores page for local_roomscheduler.
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
$PAGE->set_url(new moodle_url('/local/roomscheduler/students.php'));
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('studentscores', 'local_roomscheduler'));
$PAGE->set_heading(get_string('studentscores_heading', 'local_roomscheduler'));

// Get plugin settings.
$teachercourseid   = (int) get_config('local_roomscheduler', 'teachercourseid');
$counselorcourseid = (int) get_config('local_roomscheduler', 'counselorcourseid');

if (empty($teachercourseid) || empty($counselorcourseid)) {
    echo $OUTPUT->header();
    echo $OUTPUT->notification(get_string('notconfigured', 'local_roomscheduler'), 'notifyproblem');
    echo $OUTPUT->footer();
    die;
}

// --- Determine access level ---
$isadmin          = is_siteadmin();
$counselorcontext = context_course::instance($counselorcourseid);
$managerroleid    = $DB->get_field('role', 'id', ['shortname' => 'manager']);
$ismanager        = !empty($managerroleid) && user_has_role_assignment(
                        $USER->id, $managerroleid, $counselorcontext->id);

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

// Get selected group filter.
$selectedgroup = optional_param('groupid', 0, PARAM_INT);

// Get all groups in the counselor course.
$groups = groups_get_all_groups($counselorcourseid);

// --- Get students from counselor course (student role only) ---
$studentroleid = $DB->get_field('role', 'id', ['shortname' => 'student']);

if ($selectedgroup > 0) {
    $sql = "SELECT DISTINCT u.id, u.firstname, u.lastname, u.username, u.idnumber
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
        'courseid'  => $counselorcourseid,
        'contextid' => $counselorcontext->id,
        'roleid'    => $studentroleid,
        'groupid'   => $selectedgroup,
    ];
} else {
    $sql = "SELECT DISTINCT u.id, u.firstname, u.lastname, u.username, u.idnumber
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
        'courseid'  => $counselorcourseid,
        'contextid' => $counselorcontext->id,
        'roleid'    => $studentroleid,
    ];
}
$students = $DB->get_records_sql($sql, $params);

// Get existing student score records keyed by userid.
$existingscores = $DB->get_records(
    'local_roomscheduler_students',
    ['courseid' => $counselorcourseid],
    '',
    '*'
);
$bystudent = [];
foreach ($existingscores as $score) {
    $bystudent[$score->userid] = $score;
}

// --- CSV export: First/Last name, Grade, SSID, Intervention room ---
if ($canmanage && optional_param('exportassignments', 0, PARAM_INT)) {
    $prefix       = $DB->get_prefix();
    $tbl_contacts = $prefix . 'contacts';
    $gradebyuserid = [];
    if (!empty($students)) {
        list($insql, $inparams) = $DB->get_in_or_equal(array_keys($students), SQL_PARAMS_NAMED, 'uid');
        $graderows = $DB->get_records_sql(
            "SELECT u.id AS userid, c.GradeLevelCode AS grade
               FROM {user} u
               JOIN `$tbl_contacts` c ON c.StudentNumber = CAST(u.idnumber AS UNSIGNED)
              WHERE u.id $insql",
            $inparams
        );
        foreach ($graderows as $row) {
            $gradebyuserid[$row->userid] = $row->grade;
        }
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="intervention_room_assignments_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, [
        'First Name', 'Last Name', 'Grade', 'SSID',
        'ELA Level', 'Math Level', 'Intervention', 'Thur/Fri Intervention',
    ]);
    foreach ($students as $student) {
        $rec = $bystudent[$student->id] ?? null;
        fputcsv($out, [
            $student->firstname,
            $student->lastname,
            $gradebyuserid[$student->id] ?? '',
            trim($student->idnumber),
            $rec ? $rec->ela_score : '',
            $rec ? $rec->math_score : '',
            $rec ? $rec->homeroom : '',
            $rec ? $rec->intervention_room : '',
        ]);
    }
    fclose($out);
    exit;
}

// --- CSV export (short headers; the Import Rooms page reads these too) ---
if ($canmanage && optional_param('export', 0, PARAM_INT)) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="roomscheduler_students_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['sisid', 'ela', 'math', 'homeroom', 'thurfri']);
    foreach ($students as $student) {
        $sisid = trim($student->idnumber);
        if ($sisid === '') {
            // Import matches students by SIS ID, so a row with none can't be re-imported anyway.
            continue;
        }
        $rec = $bystudent[$student->id] ?? null;
        fputcsv($out, [
            $sisid,
            $rec ? $rec->ela_score  : '',
            $rec ? $rec->math_score : '',
            $rec ? $rec->homeroom   : '',
            $rec ? $rec->intervention_room : '',
        ]);
    }
    fclose($out);
    exit;
}

// --- Handle form submission (managers only) ---
if ($canmanage && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();

    $userids           = optional_param_array('userid',            [], PARAM_INT);
    $elascores         = optional_param_array('ela_score',         [], PARAM_INT);
    $mathscores        = optional_param_array('math_score',        [], PARAM_INT);
    $homerooms         = optional_param_array('homeroom',          [], PARAM_TEXT);
    $interventionrooms = optional_param_array('intervention_room', [], PARAM_TEXT);
    $recordids         = optional_param_array('recordid',          [], PARAM_INT);

    $now   = time();
    $count = count($userids);

    for ($i = 0; $i < $count; $i++) {
        $userid           = (int) ($userids[$i]    ?? 0);
        $elascore         = (int) ($elascores[$i]  ?? 3);
        $mathscore        = (int) ($mathscores[$i] ?? 3);
        $homeroom         = trim(clean_param($homerooms[$i]         ?? '', PARAM_TEXT));
        $interventionroom = trim(clean_param($interventionrooms[$i] ?? '', PARAM_TEXT));
        $recordid         = (int) ($recordids[$i]  ?? 0);

        if (!$userid) {
            continue;
        }

        // Validate scores.
        if (!in_array($elascore,  [1, 2, 3])) {
            $elascore  = 3;
        }
        if (!in_array($mathscore, [1, 2, 3])) {
            $mathscore = 3;
        }

        if ($recordid) {
            $rec                    = new stdClass();
            $rec->id                = $recordid;
            $rec->userid            = $userid;
            $rec->courseid          = $counselorcourseid;
            $rec->ela_score         = $elascore;
            $rec->math_score        = $mathscore;
            $rec->homeroom          = $homeroom;
            $rec->intervention_room = $interventionroom;
            $rec->timemodified      = $now;
            $DB->update_record('local_roomscheduler_students', $rec);
        } else {
            $rec                    = new stdClass();
            $rec->userid            = $userid;
            $rec->courseid          = $counselorcourseid;
            $rec->ela_score         = $elascore;
            $rec->math_score        = $mathscore;
            $rec->homeroom          = $homeroom;
            $rec->intervention_room = $interventionroom;
            $rec->timecreated       = $now;
            $rec->timemodified      = $now;
            $DB->insert_record('local_roomscheduler_students', $rec);
        }
    }

    $redirecturl = new moodle_url('/local/roomscheduler/students.php',
        $selectedgroup ? ['groupid' => $selectedgroup] : []
    );
    redirect(
        $redirecturl,
        get_string('studentssavedsuccess', 'local_roomscheduler'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

// --- Render page ---
echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('studentscores_heading', 'local_roomscheduler'));

// Navigation tabs.
local_roomscheduler_print_admin_tabs('students', $counselorcourseid, $canmanage);

// Group filter dropdown.
if (!empty($groups)) {
    $groupoptions = [0 => get_string('allgroups', 'local_roomscheduler')];
    foreach ($groups as $group) {
        $groupoptions[$group->id] = $group->name;
    }
    $filterurl = new moodle_url('/local/roomscheduler/students.php');
    echo html_writer::start_tag('form', [
        'method' => 'get',
        'action' => $filterurl->out(false),
        'class'  => 'mb-2',
    ]);
    echo html_writer::tag('label',
        get_string('selectgroup', 'local_roomscheduler') . ': ',
        ['for' => 'groupid', 'class' => 'mr-2 font-weight-bold']
    );
    echo html_writer::select(
        $groupoptions,
        'groupid',
        $selectedgroup,
        false,
        ['id'       => 'groupid',
         'class'    => 'form-control d-inline-block w-auto mr-2',
         'onchange' => 'this.form.submit()']
    );
    echo html_writer::end_tag('form');
}

if (empty($students)) {
    echo $OUTPUT->notification(get_string('nostudents', 'local_roomscheduler'), 'info');
    echo $OUTPUT->footer();
    die;
}

// Inline styles.
echo html_writer::tag('style', '
    .students-table {
        table-layout: fixed;
    }
    .students-table th {
        background-color: #f8f9fa;
        white-space: normal;
        word-wrap: break-word;
    }
    .students-table td {
        vertical-align: middle !important;
    }
    .students-table select,
    .students-table input[type="text"],
    .students-table input[type="number"] {
        width: 100%;
        box-sizing: border-box;
    }
    .students-table .score-1 { background-color: #ffe0e0 !important; }
    .students-table .score-2 { background-color: #fff3cd !important; }
    .students-table .score-3 { background-color: #f0fff0 !important; }
');

if ($canmanage) {
    // --- MANAGER VIEW: inline editable table ---
    $formurl = new moodle_url('/local/roomscheduler/students.php',
        $selectedgroup ? ['groupid' => $selectedgroup] : []
    );

    echo html_writer::start_tag('form', [
        'method' => 'post',
        'action' => $formurl->out(false),
        'id'     => 'studentform',
    ]);
    echo html_writer::empty_tag('input', [
        'type'  => 'hidden',
        'name'  => 'sesskey',
        'value' => sesskey(),
    ]);

    // Top save button with inline hint text.
    echo html_writer::start_div('mb-2 d-flex align-items-center');
    echo html_writer::empty_tag('input', [
        'type'  => 'submit',
        'value' => 'Save All Changes',
        'class' => 'btn btn-primary btn-sm mr-3',
    ]);
    echo html_writer::span(
        '1 = High Priority (red) &nbsp;|&nbsp; 2 = Identified (yellow) &nbsp;|&nbsp; 3 = Low Priority (green)',
        'text-muted small'
    );
    echo html_writer::end_div();

    echo html_writer::start_tag('div', ['class' => 'table-responsive']);
    echo html_writer::start_tag('table', [
        'class' => 'table table-bordered table-hover table-sm students-table',
    ]);

    // Header.
    echo html_writer::start_tag('thead', ['class' => 'thead-light']);
    echo html_writer::start_tag('tr');
    echo html_writer::tag('th', get_string('lastname',          'local_roomscheduler'), ['style' => 'width:15%']);
    echo html_writer::tag('th', get_string('firstname',         'local_roomscheduler'), ['style' => 'width:13%']);
    echo html_writer::tag('th', get_string('sisid',             'local_roomscheduler'), ['style' => 'width:9%']);
    echo html_writer::tag('th', get_string('ela_score',         'local_roomscheduler'), ['style' => 'width:7%']);
    echo html_writer::tag('th', get_string('math_score',        'local_roomscheduler'), ['style' => 'width:7%']);
    echo html_writer::tag('th', 'Mon-Wed', ['style' => 'width:16%']);
    echo html_writer::tag('th', 'Thur/Fri', ['style' => 'width:16%']);
    echo html_writer::tag('th', 'Code', ['style' => 'width:7%; white-space:nowrap;']);
    echo html_writer::end_tag('tr');
    echo html_writer::end_tag('thead');

    echo html_writer::start_tag('tbody');

    foreach ($students as $userid => $student) {
        $uid             = (int)$userid;
        $rec             = isset($bystudent[$uid]) ? $bystudent[$uid] : null;
        $recordid        = $rec ? (int)$rec->id             : 0;
        $elaval          = $rec ? (int)$rec->ela_score       : 3;
        $mathval         = $rec ? (int)$rec->math_score      : 3;
        $homeroomval     = $rec ? s($rec->homeroom)          : '';
        $interventionval = $rec ? s($rec->intervention_room) : '';
        $sisid           = !empty($student->idnumber) ? s($student->idnumber) : '—';

        $minscore = min($elaval, $mathval);
        $rowclass = 'score-' . $minscore;

        echo html_writer::start_tag('tr', ['class' => $rowclass]);

        echo '<td>'
            . '<input type="hidden" name="recordid[]" value="' . $recordid . '">'
            . '<input type="hidden" name="userid[]" value="' . $uid . '">'
            . s($student->lastname)
            . '</td>';

        echo html_writer::tag('td', s($student->firstname));
        echo html_writer::tag('td', $sisid);

        echo html_writer::tag('td',
            html_writer::empty_tag('input', [
                'type'  => 'number',
                'name'  => 'ela_score[]',
                'value' => $elaval,
                'min'   => 1,
                'max'   => 3,
                'class' => 'form-control form-control-sm',
            ])
        );

        echo html_writer::tag('td',
            html_writer::empty_tag('input', [
                'type'  => 'number',
                'name'  => 'math_score[]',
                'value' => $mathval,
                'min'   => 1,
                'max'   => 3,
                'class' => 'form-control form-control-sm',
            ])
        );

        echo html_writer::tag('td',
            html_writer::empty_tag('input', [
                'type'        => 'text',
                'name'        => 'homeroom[]',
                'value'       => $homeroomval,
                'maxlength'   => 20,
                'class'       => 'form-control form-control-sm',
                'placeholder' => 'e.g. 101',
            ])
        );

        echo html_writer::tag('td',
            html_writer::empty_tag('input', [
                'type'        => 'text',
                'name'        => 'intervention_room[]',
                'value'       => $interventionval,
                'maxlength'   => 20,
                'class'       => 'form-control form-control-sm',
                'placeholder' => 'Auto-assigned',
            ])
        );

        // Assignment code (read-only).
        $acode = $rec ? s($rec->assignment_code) : '';
        echo html_writer::tag('td',
            html_writer::span($acode, 'badge badge-secondary'),
            ['style' => 'text-align:center;']
        );

        echo html_writer::end_tag('tr');
    }

    echo html_writer::end_tag('tbody');
    echo html_writer::end_tag('table');
    echo html_writer::end_tag('div');

    // Bottom save button.
    echo html_writer::start_div('mt-2 mb-3');
    echo html_writer::empty_tag('input', [
        'type'  => 'submit',
        'value' => 'Save All Changes',
        'class' => 'btn btn-primary btn-sm',
    ]);
    echo html_writer::end_div();

    echo html_writer::end_tag('form');

    // Assignment code legend.
    echo local_roomscheduler_code_legend();

} else {
    // --- TEACHER READ-ONLY VIEW ---
    echo html_writer::start_tag('div', ['class' => 'table-responsive']);
    echo html_writer::start_tag('table', [
        'class' => 'table table-bordered table-hover table-sm students-table',
    ]);

    echo html_writer::start_tag('thead', ['class' => 'thead-light']);
    echo html_writer::start_tag('tr');
    echo html_writer::tag('th', get_string('lastname',          'local_roomscheduler'));
    echo html_writer::tag('th', get_string('firstname',         'local_roomscheduler'));
    echo html_writer::tag('th', get_string('sisid',             'local_roomscheduler'));
    echo html_writer::tag('th', get_string('ela_score',         'local_roomscheduler'));
    echo html_writer::tag('th', get_string('math_score',        'local_roomscheduler'));
    echo html_writer::tag('th', 'Mon-Wed');
    echo html_writer::tag('th', 'Thur/Fri');
    echo html_writer::tag('th', 'Code');
    echo html_writer::end_tag('tr');
    echo html_writer::end_tag('thead');

    echo html_writer::start_tag('tbody');

    $i = 0;
    foreach ($students as $userid => $student) {
        $i++;
        $uid             = (int)$userid;
        $rec             = isset($bystudent[$uid]) ? $bystudent[$uid] : null;
        $elaval          = $rec ? (int)$rec->ela_score        : 3;
        $mathval         = $rec ? (int)$rec->math_score       : 3;
        $homeroomval     = $rec ? s($rec->homeroom)           : '—';
        $interventionval = $rec ? s($rec->intervention_room)  : '—';
        $sisid           = !empty($student->idnumber) ? s($student->idnumber) : '—';

        $minscore = min($elaval, $mathval);
        $rowclass = 'score-' . $minscore;

        echo html_writer::start_tag('tr', ['class' => $rowclass]);
        echo html_writer::tag('td', s($student->lastname));
        echo html_writer::tag('td', s($student->firstname));
        echo html_writer::tag('td', $sisid);
        echo html_writer::tag('td', $elaval);
        echo html_writer::tag('td', $mathval);
        echo html_writer::tag('td', $homeroomval ?: '—');
        echo html_writer::tag('td', $interventionval ?: '—');
        $acode = $rec ? s($rec->assignment_code) : '—';
        echo html_writer::tag('td',
            html_writer::span($acode, 'badge badge-secondary'),
            ['style' => 'text-align:center;']
        );
        echo html_writer::end_tag('tr');
    }

    if ($i === 0) {
        echo html_writer::start_tag('tr');
        echo html_writer::tag('td',
            get_string('nostudents', 'local_roomscheduler'),
            ['colspan' => 7, 'class' => 'text-center text-muted']
        );
        echo html_writer::end_tag('tr');
    }

    echo html_writer::end_tag('tbody');
    echo html_writer::end_tag('table');
    echo html_writer::end_div();

    // Assignment code legend.
    echo local_roomscheduler_code_legend();
}

if ($canmanage && !empty($students)) {
    $exporturl = new moodle_url('/local/roomscheduler/students.php',
        array_merge(['export' => 1], $selectedgroup ? ['groupid' => $selectedgroup] : [])
    );
    $exportassignmentsurl = new moodle_url('/local/roomscheduler/students.php',
        array_merge(['exportassignments' => 1], $selectedgroup ? ['groupid' => $selectedgroup] : [])
    );
    echo html_writer::div(
        html_writer::link($exporturl, get_string('exportcsv', 'local_roomscheduler'),
            ['class' => 'btn btn-secondary btn-sm mr-2']
        ) .
        html_writer::link($exportassignmentsurl, get_string('exportassignmentsbutton', 'local_roomscheduler'),
            ['class' => 'btn btn-secondary btn-sm']
        ),
        'mt-3'
    );
}

echo $OUTPUT->footer();
