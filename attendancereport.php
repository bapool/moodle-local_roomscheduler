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
 * Attendance report page for local_roomscheduler.
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
$PAGE->set_url(new moodle_url('/local/roomscheduler/attendancereport.php'));
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('attendancereport', 'local_roomscheduler'));
$PAGE->set_heading(get_string('attendancereport_heading', 'local_roomscheduler'));

// Get plugin settings.
$counselorcourseid = (int) get_config('local_roomscheduler', 'counselorcourseid');
$minutespersession = (int) get_config('local_roomscheduler', 'minutespersession');
if (empty($minutespersession)) {
    $minutespersession = 30;
}

if (empty($counselorcourseid)) {
    echo $OUTPUT->header();
    echo $OUTPUT->notification(get_string('notconfigured', 'local_roomscheduler'), 'notifyproblem');
    echo $OUTPUT->footer();
    die;
}

// Any teacher in course 53 or site admin can view.
$counselorcontext = context_course::instance($counselorcourseid);
$isadmin          = is_siteadmin();
$teacherroleid    = $DB->get_field('role', 'id', ['shortname' => 'editingteacher']);
$teacherroleid2   = $DB->get_field('role', 'id', ['shortname' => 'teacher']);
$managerroleid    = $DB->get_field('role', 'id', ['shortname' => 'manager']);

$canview = $isadmin
    || user_has_role_assignment($USER->id, $teacherroleid,  $counselorcontext->id)
    || user_has_role_assignment($USER->id, $teacherroleid2, $counselorcontext->id)
    || (!empty($managerroleid) && user_has_role_assignment($USER->id, $managerroleid, $counselorcontext->id));

if (!$canview) {
    echo $OUTPUT->header();
    echo $OUTPUT->notification(get_string('nopermission', 'local_roomscheduler'), 'notifyproblem');
    echo $OUTPUT->footer();
    die;
}

$canmanage = $isadmin
    || (!empty($managerroleid) && user_has_role_assignment($USER->id, $managerroleid, $counselorcontext->id));

// Calculate current school year date range (Aug 1 to June 1).
$now       = time();
$curmonth  = (int)date('n', $now);
$curyear   = (int)date('Y', $now);
$startyear = ($curmonth >= 8) ? $curyear : $curyear - 1;
$endyear   = $startyear + 1;
$yearstart = mktime(0, 0, 0, 8, 1, $startyear); // Aug 1.
$yearend   = mktime(23, 59, 59, 6, 1, $endyear); // June 1.

// Which view to show.
$view         = optional_param('view', 'ela', PARAM_ALPHA);
$selecteduser = optional_param('userid', 0, PARAM_INT);
$selectedgroup = optional_param('groupid', 0, PARAM_INT);
$groups        = groups_get_all_groups($counselorcourseid);

// --- Render page ---
echo $OUTPUT->header();
echo html_writer::start_div('no-print');
echo $OUTPUT->heading(get_string('attendancereport_heading', 'local_roomscheduler'));
echo html_writer::end_div();

// Navigation tabs (hidden on print).
echo html_writer::start_div('no-print');
local_roomscheduler_print_admin_tabs('attendancereport', $counselorcourseid, $canmanage);
echo html_writer::end_div();

// Print header (only visible when printing).
$viewtitle = 'Intervention Attendance Report';
if ($view === 'ela')        $viewtitle .= ' — ELA Summary';
else if ($view === 'math')  $viewtitle .= ' — Math Summary';
else                        $viewtitle .= ' — Individual Student';

echo html_writer::start_div('print-header');
echo html_writer::tag('h2', $viewtitle);
echo html_writer::tag('div',
    'Report period: ' . userdate($yearstart, '%m/%d/%Y') . ' – ' . userdate($yearend, '%m/%d/%Y')
    . ' &nbsp;|&nbsp; Printed: ' . userdate(time(), '%m/%d/%Y %I:%M %p')
    . ' &nbsp;|&nbsp; ' . $minutespersession . ' minutes per session',
    ['class' => 'print-meta']
);
echo html_writer::end_div();

// School year range info (on-screen only).
echo html_writer::start_div('no-print');
echo html_writer::tag('p',
    'Report period: ' . userdate($yearstart, '%m/%d/%Y') . ' – ' . userdate($yearend, '%m/%d/%Y')
    . ' &nbsp;|&nbsp; ' . $minutespersession . ' minutes per session',
    ['class' => 'text-muted small mb-2']
);
echo html_writer::tag('button', '🖨 Print Report',
    [
        'class'   => 'btn btn-sm btn-outline-secondary mb-3',
        'onclick' => 'window.print(); return false;',
        'type'    => 'button',
    ]
);
echo html_writer::end_div();

// View switcher buttons.
$groupparam = $selectedgroup ? ['groupid' => $selectedgroup] : [];
$elaurl  = new moodle_url('/local/roomscheduler/attendancereport.php', ['view' => 'ela']    + $groupparam);
$mathurl = new moodle_url('/local/roomscheduler/attendancereport.php', ['view' => 'math']   + $groupparam);
$indurl  = new moodle_url('/local/roomscheduler/attendancereport.php', ['view' => 'individual'] + $groupparam);

$btnela  = ($view === 'ela')        ? 'btn-primary' : 'btn-outline-primary';
$btnmath = ($view === 'math')       ? 'btn-primary' : 'btn-outline-primary';
$btnind  = ($view === 'individual') ? 'btn-primary' : 'btn-outline-primary';

echo html_writer::start_div('mb-3 btn-group no-print');
echo html_writer::link($elaurl,  get_string('elasummary',        'local_roomscheduler'), ['class' => "btn $btnela"]);
echo html_writer::link($mathurl, get_string('mathsummary',       'local_roomscheduler'), ['class' => "btn $btnmath"]);
echo html_writer::link($indurl,  get_string('individualreport',  'local_roomscheduler'), ['class' => "btn $btnind"]);
echo html_writer::end_div();

// Group filter dropdown.
if (!empty($groups)) {
    $groupoptions = [0 => get_string('allgroups', 'local_roomscheduler')];
    foreach ($groups as $group) {
        $groupoptions[$group->id] = $group->name;
    }
    $filterurl = new moodle_url('/local/roomscheduler/attendancereport.php');
    echo html_writer::start_tag('form', [
        'method' => 'get',
        'action' => $filterurl->out(false),
        'class'  => 'mb-3 no-print',
    ]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'view',   'value' => $view]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'userid', 'value' => $selecteduser]);
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

// Inline styles.
echo html_writer::tag('style', '
    .attrep-table th { background-color: #f8f9fa; white-space: nowrap; }
    .attrep-table td { vertical-align: middle !important; }
    .date-cell { display: inline-block; background: #e9ecef; padding: 2px 6px;
                 margin: 2px; border-radius: 3px; font-size: 0.85em; }
    .print-header { display: none; }
    @media print {
        .no-print { display: none !important; }
        .print-header { display: block !important; margin-bottom: 10px; page-break-after: avoid; }
        .print-header h2 { margin: 0 0 5px 0; page-break-after: avoid; }
        .print-header .print-meta { font-size: 0.9em; color: #555; page-break-after: avoid; }
        body { background: white !important; }
        header, nav, footer, .navbar, #page-header, #page-footer { display: none !important; }
        h4, h5, h6 { page-break-after: avoid !important; }
        .attrep-table { font-size: 0.9em; }
        .attrep-table thead { display: table-header-group; }
        .attrep-table th { background: #eee !important; -webkit-print-color-adjust: exact; page-break-after: avoid; }
        .attrep-table tr { page-break-inside: avoid; }
        .date-cell { background: #f0f0f0 !important; -webkit-print-color-adjust: exact; }
        @page { margin: 0.4in; size: portrait; }
    }
');

// Helper: format minutes as "Xh Ym"
$formatHours = function($totalminutes) {
    $h = intdiv($totalminutes, 60);
    $m = $totalminutes % 60;
    return sprintf('%d:%02d', $h, $m);
};

// =====================================================
// ELA SUMMARY
// =====================================================
if ($view === 'ela' || $view === 'math') {
    $type      = ($view === 'ela') ? 'E' : 'M';
    $scorefield = ($view === 'ela') ? 'ela_score' : 'math_score';
    $heading   = ($view === 'ela') ? get_string('elasummary', 'local_roomscheduler')
                                   : get_string('mathsummary', 'local_roomscheduler');

    echo html_writer::tag('h4', $heading, ['class' => 'mt-3 mb-2', 'style' => 'page-break-after: avoid;']);

    // Students with a score of 1 or 2 in this area, plus anyone with a session of this type
    // recorded this year (e.g. a Level 3 student in an IEP room).
    $groupjoin   = $selectedgroup ? 'JOIN {groups_members} gm ON gm.userid = u.id' : '';
    $groupwhere  = $selectedgroup ? 'AND gm.groupid = :groupid' : '';
    $sql = "SELECT u.id, u.firstname, u.lastname, u.idnumber,
                   s.ela_score, s.math_score
              FROM {user} u
              JOIN {local_roomscheduler_students} s ON s.userid = u.id
              $groupjoin
             WHERE s.courseid = :courseid
               AND (s.{$scorefield} IN (1, 2)
                    OR EXISTS (SELECT 1
                                 FROM {local_roomscheduler_attendance} a
                                WHERE a.studentid = u.id
                                  AND a.present = 1
                                  AND a.attendancetype = :atttype
                                  AND a.attendancedate >= :yearstart
                                  AND a.attendancedate <= :yearend))
               AND u.deleted = 0
               $groupwhere
             ORDER BY u.lastname, u.firstname";
    $sqlparams = [
        'courseid'  => $counselorcourseid,
        'atttype'   => $type,
        'yearstart' => $yearstart,
        'yearend'   => $yearend,
    ];
    if ($selectedgroup) {
        $sqlparams['groupid'] = $selectedgroup;
    }
    $students = $DB->get_records_sql($sql, $sqlparams);

    if (empty($students)) {
        echo $OUTPUT->notification(get_string('nosessions', 'local_roomscheduler'), 'info');
    } else {
        // Get session counts for all students at once.
        $studentids = array_keys($students);
        list($insql, $inparams) = $DB->get_in_or_equal($studentids, SQL_PARAMS_QM);
        $countsql = "SELECT studentid, COUNT(DISTINCT attendancedate) AS sessions
                       FROM {local_roomscheduler_attendance}
                      WHERE attendancetype = ?
                        AND present = 1
                        AND attendancedate >= ?
                        AND attendancedate <= ?
                        AND studentid $insql
                   GROUP BY studentid";
        $params = array_merge([$type, $yearstart, $yearend], $inparams);
        $counts = $DB->get_records_sql($countsql, $params);

        $sessionsByUser = [];
        foreach ($counts as $c) {
            $sessionsByUser[$c->studentid] = (int)$c->sessions;
        }

        echo html_writer::start_tag('table', ['class' => 'table table-bordered table-hover table-sm attrep-table']);
        echo html_writer::start_tag('thead', ['class' => 'thead-light']);
        echo html_writer::start_tag('tr');
        echo html_writer::tag('th', get_string('lastname',  'local_roomscheduler'), ['style' => 'width:22%']);
        echo html_writer::tag('th', get_string('firstname', 'local_roomscheduler'), ['style' => 'width:18%']);
        echo html_writer::tag('th', get_string('sisid',     'local_roomscheduler'), ['style' => 'width:15%']);
        echo html_writer::tag('th', get_string('sessions',  'local_roomscheduler'), ['style' => 'width:15%; text-align:center;']);
        echo html_writer::tag('th', get_string('hours',     'local_roomscheduler'), ['style' => 'width:15%; text-align:center;']);
        echo html_writer::end_tag('tr');
        echo html_writer::end_tag('thead');

        echo html_writer::start_tag('tbody');
        foreach ($students as $student) {
            $sessions = $sessionsByUser[$student->id] ?? 0;
            $minutes  = $sessions * $minutespersession;
            $hours    = $formatHours($minutes);

            echo html_writer::start_tag('tr');
            echo html_writer::tag('td', s($student->lastname));
            echo html_writer::tag('td', s($student->firstname));
            echo html_writer::tag('td', s($student->idnumber));
            echo html_writer::tag('td', $sessions, ['style' => 'text-align:center;']);
            echo html_writer::tag('td', $hours,    ['style' => 'text-align:center;']);
            echo html_writer::end_tag('tr');
        }
        echo html_writer::end_tag('tbody');
        echo html_writer::end_tag('table');
    }
}

// =====================================================
// INDIVIDUAL STUDENT REPORT
// =====================================================
if ($view === 'individual') {
    echo html_writer::tag('h4', get_string('individualreport', 'local_roomscheduler'),
        ['class' => 'mt-3 mb-2', 'style' => 'page-break-after: avoid;']);

    // Only list students who have at least one ELA or Math session recorded this school year.
    $groupjoin2  = $selectedgroup ? 'JOIN {groups_members} gm ON gm.userid = u.id' : '';
    $groupwhere2 = $selectedgroup ? 'AND gm.groupid = :groupid' : '';
    $sql = "SELECT u.id, u.firstname, u.lastname, u.idnumber
              FROM {user} u
              $groupjoin2
             WHERE u.deleted = 0
               AND EXISTS (SELECT 1
                             FROM {local_roomscheduler_attendance} a
                            WHERE a.studentid = u.id
                              AND a.present = 1
                              AND a.attendancetype IN (:typeela, :typemath)
                              AND a.attendancedate >= :yearstart
                              AND a.attendancedate <= :yearend)
               $groupwhere2
          ORDER BY u.lastname, u.firstname";
    $sqlparams2 = [
        'typeela'   => 'E',
        'typemath'  => 'M',
        'yearstart' => $yearstart,
        'yearend'   => $yearend,
    ];
    if ($selectedgroup) {
        $sqlparams2['groupid'] = $selectedgroup;
    }
    $allstudents = $DB->get_records_sql($sql, $sqlparams2);

    // Dropdown.
    $options = [0 => '-- ' . get_string('selectstudent', 'local_roomscheduler') . ' --'];
    foreach ($allstudents as $s) {
        $options[$s->id] = $s->lastname . ', ' . $s->firstname . ' (' . $s->idnumber . ')';
    }

    if (empty($allstudents)) {
        echo $OUTPUT->notification(get_string('nostudentswithsessions', 'local_roomscheduler'), 'info');
    }

    $formurl = new moodle_url('/local/roomscheduler/attendancereport.php');
    echo html_writer::start_tag('form', [
        'method' => 'get',
        'action' => $formurl->out(false),
        'class'  => 'mb-3 no-print',
    ]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'view', 'value' => 'individual']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'groupid', 'value' => $selectedgroup]);
    echo html_writer::tag('label',
        get_string('selectstudent', 'local_roomscheduler') . ': ',
        ['class' => 'mr-2 font-weight-bold']
    );
    echo html_writer::select(
        $options, 'userid', $selecteduser, false,
        ['class' => 'form-control d-inline-block w-auto mr-2',
         'onchange' => 'this.form.submit()']
    );
    echo html_writer::end_tag('form');

    if ($selecteduser && isset($allstudents[$selecteduser])) {
        $student = $allstudents[$selecteduser];

        // Load ELA and Math session dates for this student.
        $sql = "SELECT id, attendancetype, attendancedate
                  FROM {local_roomscheduler_attendance}
                 WHERE studentid = ?
                   AND present = 1
                   AND attendancedate >= ?
                   AND attendancedate <= ?
              ORDER BY attendancedate ASC";
        $rows = $DB->get_records_sql($sql, [$selecteduser, $yearstart, $yearend]);

        $eladates  = [];
        $mathdates = [];
        foreach ($rows as $r) {
            // Dedupe by date since multiple teachers might save same type/date.
            $datestr = userdate($r->attendancedate, '%-m/%-d');
            if ($r->attendancetype === 'E') {
                $eladates[$r->attendancedate] = $datestr;
            } else if ($r->attendancetype === 'M') {
                $mathdates[$r->attendancedate] = $datestr;
            }
        }

        $elacount    = count($eladates);
        $mathcount   = count($mathdates);
        $elaminutes  = $elacount * $minutespersession;
        $mathminutes = $mathcount * $minutespersession;

        echo html_writer::tag('h5',
            s($student->firstname . ' ' . $student->lastname) . ' (' . s($student->idnumber) . ')',
            ['class' => 'mt-3']
        );

        echo html_writer::start_div('row');

        // ELA column.
        echo html_writer::start_div('col-md-6');
        echo html_writer::tag('h6',
            get_string('eladates', 'local_roomscheduler'),
            ['class' => 'mt-2 mb-0']
        );
        echo html_writer::tag('p',
            'Total of ' . $elacount . ' Sessions for ' . $formatHours($elaminutes) . ' total time.',
            ['class' => 'text-muted small mb-2']
        );
        if (empty($eladates)) {
            echo html_writer::tag('p', get_string('nosessions', 'local_roomscheduler'),
                ['class' => 'text-muted']);
        } else {
            echo html_writer::start_div('p-2 border rounded bg-light');
            foreach ($eladates as $d) {
                echo html_writer::span($d, 'date-cell');
            }
            echo html_writer::end_div();
        }
        echo html_writer::end_div();

        // Math column.
        echo html_writer::start_div('col-md-6');
        echo html_writer::tag('h6',
            get_string('mathdates', 'local_roomscheduler'),
            ['class' => 'mt-2 mb-0']
        );
        echo html_writer::tag('p',
            'Total of ' . $mathcount . ' Sessions for ' . $formatHours($mathminutes) . ' total time.',
            ['class' => 'text-muted small mb-2']
        );
        if (empty($mathdates)) {
            echo html_writer::tag('p', get_string('nosessions', 'local_roomscheduler'),
                ['class' => 'text-muted']);
        } else {
            echo html_writer::start_div('p-2 border rounded bg-light');
            foreach ($mathdates as $d) {
                echo html_writer::span($d, 'date-cell');
            }
            echo html_writer::end_div();
        }
        echo html_writer::end_div();

        echo html_writer::end_div();
    }
}

echo $OUTPUT->footer();
