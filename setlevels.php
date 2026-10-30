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
 * Set intervention levels from state test scores (NTstatetesting).
 *
 * Logic:
 *   ELA level 1  — student has a High School ELA2 score below passing threshold
 *   ELA level 2  — no ELA2 found, but Eighth Grade ELA score below passing threshold
 *   ELA level 3  — passes or no data found (unchanged / default)
 *
 *   Math level 1 — student has a High School ALG1 score below passing threshold
 *   Math level 2 — no ALG1 found, but Eighth Grade M score below passing threshold
 *   Math level 3 — passes or no data found (unchanged / default)
 *
 * Students are matched via:
 *   mdl_user.idnumber -> mdl_contacts.StudentNumber
 *                     -> mdl_contacts.StateStudentID -> mdl_NTstatetesting.ssid
 *
 * Always uses the highest score found for the relevant subject/grade combination.
 *
 * @package    local_roomscheduler
 * @copyright  2026 Brian Pool
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once(__DIR__ . '/lib.php');

require_login();

// ---------------------------------------------------------------------------
// Configuration — passing score default and subject/grade constants.
// ---------------------------------------------------------------------------
// *** NTLS DEFAULT: Score at or above this value is considered passing. ***
define('SETLEVELS_DEFAULT_PASSING_SCORE', 684);

// High school subjects to check first (level 1 candidates).
define('SETLEVELS_HS_ELA_SUBJECT',  'ELA2');
define('SETLEVELS_HS_ELA_GRADE',    'High School');
define('SETLEVELS_HS_MATH_SUBJECT', 'ALG1');
define('SETLEVELS_HS_MATH_GRADE',   'High School');

// Eighth grade fallback subjects (level 2 candidates).
define('SETLEVELS_8_ELA_SUBJECT',   'ELA');
define('SETLEVELS_8_ELA_GRADE',     'Eighth Grade');
define('SETLEVELS_8_MATH_SUBJECT',  'M');
define('SETLEVELS_8_MATH_GRADE',    'Eighth Grade');

// ---------------------------------------------------------------------------
// Access control.
// ---------------------------------------------------------------------------
$context           = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/roomscheduler/setlevels.php'));
$PAGE->set_pagelayout('standard');
$PAGE->set_title('Set Intervention Levels from State Tests');
$PAGE->set_heading('Set Intervention Levels from State Tests');

$teachercourseid   = (int) get_config('local_roomscheduler', 'teachercourseid');
$counselorcourseid = (int) get_config('local_roomscheduler', 'counselorcourseid');

if (empty($teachercourseid) || empty($counselorcourseid)) {
    echo $OUTPUT->header();
    echo $OUTPUT->notification(get_string('notconfigured', 'local_roomscheduler'), 'notifyproblem');
    echo $OUTPUT->footer();
    die;
}

$isadmin          = is_siteadmin();
$counselorcontext = context_course::instance($counselorcourseid);
$managerroleid    = $DB->get_field('role', 'id', ['shortname' => 'manager']);
$ismanager        = !empty($managerroleid) && user_has_role_assignment(
                        $USER->id, $managerroleid, $counselorcontext->id);
$canmanage        = ($isadmin || $ismanager);

if (!$canmanage) {
    echo $OUTPUT->header();
    echo $OUTPUT->notification(get_string('nopermission', 'local_roomscheduler'), 'notifyproblem');
    echo $OUTPUT->footer();
    die;
}

// ---------------------------------------------------------------------------
// Build raw table names using the Moodle prefix.
// NTstatetesting and contacts are external tables not managed by Moodle XMLDB,
// so they must be referenced by their full prefixed name in raw SQL, not {}.
// ---------------------------------------------------------------------------
$prefix         = $DB->get_prefix();
$tbl_state      = $prefix . 'NTstatetesting';
$tbl_contacts   = $prefix . 'contacts';
$tbl_students   = '{local_roomscheduler_students}';  // Moodle-managed — use {}.
$tbl_user       = '{user}';

// ---------------------------------------------------------------------------
// Verify NTstatetesting table exists and has data.
// ---------------------------------------------------------------------------
$dbman      = $DB->get_manager();
$statetable = new xmldb_table('NTstatetesting');
if (!$dbman->table_exists($statetable)) {
    echo $OUTPUT->header();
    echo $OUTPUT->notification(
        'The NTstatetesting table does not exist. Cannot set levels.',
        'notifyproblem'
    );
    echo $OUTPUT->footer();
    die;
}

$hasdata = $DB->count_records_sql("SELECT COUNT(*) FROM `$tbl_state`");
if (empty($hasdata)) {
    echo $OUTPUT->header();
    echo $OUTPUT->notification(
        'The NTstatetesting table is empty. Cannot set levels.',
        'notifyproblem'
    );
    echo $OUTPUT->footer();
    die;
}

// ---------------------------------------------------------------------------
// Handle form submission.
// ---------------------------------------------------------------------------
$confirmed          = optional_param('confirmed',          0, PARAM_INT);
$clearintervention  = optional_param('clearintervention',  0, PARAM_INT);
$clearlevels        = optional_param('clearlevels',        0, PARAM_INT);
$passingscore     = optional_param('passingscore', SETLEVELS_DEFAULT_PASSING_SCORE, PARAM_INT);
$studentsurl      = new moodle_url('/local/roomscheduler/students.php');
$setlevelsurl     = new moodle_url('/local/roomscheduler/setlevels.php');


if ($clearintervention && confirm_sesskey()) {
    $clearedcount = local_roomscheduler_clear_intervention_rooms();

    echo $OUTPUT->header();
    echo $OUTPUT->heading('Clear Intervention Rooms — Complete');
    local_roomscheduler_print_admin_tabs('setlevels', $counselorcourseid, $canmanage);
    echo $OUTPUT->notification(
        "<strong>{$clearedcount}</strong> student(s) had their Intervention room cleared.",
        'notifysuccess'
    );
    echo html_writer::link(
        $setlevelsurl,
        '← Return to Set Levels',
        ['class' => 'btn btn-secondary mt-3']
    );
    echo $OUTPUT->footer();
    die;
}

if ($clearlevels && confirm_sesskey()) {
    $clearedlevelscount = local_roomscheduler_clear_intervention_levels();

    echo $OUTPUT->header();
    echo $OUTPUT->heading('Clear Intervention Levels — Complete');
    local_roomscheduler_print_admin_tabs('setlevels', $counselorcourseid, $canmanage);
    echo $OUTPUT->notification(
        "<strong>{$clearedlevelscount}</strong> student(s) had their ELA/Math intervention level reset " .
        "back to 3 (no intervention). Intervention room assignments were not touched.",
        'notifysuccess'
    );
    echo html_writer::link(
        $setlevelsurl,
        '← Return to Set Levels',
        ['class' => 'btn btn-secondary mt-3']
    );
    echo $OUTPUT->footer();
    die;
}

if ($confirmed && confirm_sesskey()) {

    // For each relevant subject/grade, find the highest score per student ssid.
    // All four queries use raw table names because NTstatetesting and contacts
    // are external tables not registered with Moodle's XMLDB.

    // ELA level 1: High School ELA2.
    $sql_hs_ela = "SELECT c.StateStudentID AS ssid,
                          MAX(CAST(t.assessment_score AS UNSIGNED)) AS bestscore
                     FROM `$tbl_state` t
                     JOIN `$tbl_contacts` c ON c.StateStudentID COLLATE utf8mb4_unicode_ci = t.ssid COLLATE utf8mb4_unicode_ci
                    WHERE t.grade_level  = :grade
                      AND t.subject_code = :subject
                      AND t.assessment_score REGEXP '^[0-9]+\$'
                      AND t.assessment_score != '0'
                 GROUP BY c.StateStudentID";
    $hs_ela_scores = $DB->get_records_sql($sql_hs_ela, [
        'grade'   => SETLEVELS_HS_ELA_GRADE,
        'subject' => SETLEVELS_HS_ELA_SUBJECT,
    ]);

    // ELA level 2 fallback: Eighth Grade ELA.
    $sql_8_ela = "SELECT c.StateStudentID AS ssid,
                         MAX(CAST(t.assessment_score AS UNSIGNED)) AS bestscore
                    FROM `$tbl_state` t
                    JOIN `$tbl_contacts` c ON c.StateStudentID COLLATE utf8mb4_unicode_ci = t.ssid COLLATE utf8mb4_unicode_ci
                   WHERE t.grade_level  = :grade
                     AND t.subject_code = :subject
                     AND t.assessment_score REGEXP '^[0-9]+\$'
                     AND t.assessment_score != '0'
                GROUP BY c.StateStudentID";
    $grade8_ela_scores = $DB->get_records_sql($sql_8_ela, [
        'grade'   => SETLEVELS_8_ELA_GRADE,
        'subject' => SETLEVELS_8_ELA_SUBJECT,
    ]);

    // Math level 1: High School ALG1.
    $sql_hs_math = "SELECT c.StateStudentID AS ssid,
                           MAX(CAST(t.assessment_score AS UNSIGNED)) AS bestscore
                      FROM `$tbl_state` t
                      JOIN `$tbl_contacts` c ON c.StateStudentID COLLATE utf8mb4_unicode_ci = t.ssid COLLATE utf8mb4_unicode_ci
                     WHERE t.grade_level  = :grade
                       AND t.subject_code = :subject
                       AND t.assessment_score REGEXP '^[0-9]+\$'
                       AND t.assessment_score != '0'
                  GROUP BY c.StateStudentID";
    $hs_math_scores = $DB->get_records_sql($sql_hs_math, [
        'grade'   => SETLEVELS_HS_MATH_GRADE,
        'subject' => SETLEVELS_HS_MATH_SUBJECT,
    ]);

    // Math level 2 fallback: Eighth Grade M.
    $sql_8_math = "SELECT c.StateStudentID AS ssid,
                          MAX(CAST(t.assessment_score AS UNSIGNED)) AS bestscore
                     FROM `$tbl_state` t
                     JOIN `$tbl_contacts` c ON c.StateStudentID COLLATE utf8mb4_unicode_ci = t.ssid COLLATE utf8mb4_unicode_ci
                    WHERE t.grade_level  = :grade
                      AND t.subject_code = :subject
                      AND t.assessment_score REGEXP '^[0-9]+\$'
                      AND t.assessment_score != '0'
                 GROUP BY c.StateStudentID";
    $grade8_math_scores = $DB->get_records_sql($sql_8_math, [
        'grade'   => SETLEVELS_8_MATH_GRADE,
        'subject' => SETLEVELS_8_MATH_SUBJECT,
    ]);

    // Get all students enrolled in the counselor course (student role),
    // joined to contacts to get their StateStudentID (ssid).
    // This is the same join chain MTSS uses:
    //   mdl_user.idnumber → contacts.StudentNumber → contacts.StateStudentID
    // We left-join local_roomscheduler_students so we get the existing
    // record id/scores if present, or nulls if not yet in the table.
    $counselorcontext = context_course::instance($counselorcourseid);
    $studentroleid    = $DB->get_field('role', 'id', ['shortname' => 'student']);

    $sql_students = "SELECT DISTINCT
                            u.id        AS userid,
                            u.idnumber,
                            c.StateStudentID AS ssid,
                            c.GradeLevelCode AS grade,
                            s.id        AS recordid,
                            s.ela_score,
                            s.math_score
                       FROM $tbl_user u
                       JOIN {user_enrolments} ue  ON ue.userid  = u.id
                       JOIN {enrol}           e   ON e.id       = ue.enrolid
                                                 AND e.courseid = :courseid
                       JOIN {role_assignments} ra ON ra.userid  = u.id
                                                 AND ra.contextid = :contextid
                       JOIN {role}             r  ON r.id       = ra.roleid
                                                 AND r.shortname = 'student'
                       JOIN `$tbl_contacts`    c  ON c.StudentNumber = CAST(u.idnumber AS UNSIGNED)
                  LEFT JOIN $tbl_students      s  ON s.userid   = u.id
                                                 AND s.courseid = :courseid2
                      WHERE u.deleted   = 0
                        AND u.suspended = 0
                        AND u.idnumber != ''
                        AND c.StateStudentID IS NOT NULL
                        AND c.StateStudentID != ''
                   ORDER BY u.lastname, u.firstname";

    $students = $DB->get_records_sql($sql_students, [
        'courseid'  => $counselorcourseid,
        'contextid' => $counselorcontext->id,
        'courseid2' => $counselorcourseid,
    ]);

    $updated   = 0;
    $unchanged = 0;

    $now = time();
    foreach ($students as $stu) {
        $ssid     = $stu->ssid;
        $new_ela  = !empty($stu->ela_score)  ? (int)$stu->ela_score  : 3;
        $new_math = !empty($stu->math_score) ? (int)$stu->math_score : 3;

        // --- Determine ELA level ---
        if (isset($hs_ela_scores[$ssid])) {
            // Has High School ELA2 score.
            $new_ela = ((int)$hs_ela_scores[$ssid]->bestscore < $passingscore) ? 1 : 3;
        } else if (isset($grade8_ela_scores[$ssid])) {
            // Has Eighth Grade ELA score (fallback).
            $new_ela = ((int)$grade8_ela_scores[$ssid]->bestscore < $passingscore) ? 2 : 3;
        }
        // else: no state test data — leave ela_score unchanged.

        // --- Determine Math level ---
        if (isset($hs_math_scores[$ssid])) {
            // Has High School ALG1 score.
            $new_math = ((int)$hs_math_scores[$ssid]->bestscore < $passingscore) ? 1 : 3;
        } else if (isset($grade8_math_scores[$ssid])) {
            // Has Eighth Grade M score (fallback).
            $new_math = ((int)$grade8_math_scores[$ssid]->bestscore < $passingscore) ? 2 : 3;
        }
        // else: no state test data — leave math_score unchanged.

        // Seniors are never assigned level 1 — cap them at level 2 instead.
        if ((int)trim((string)$stu->grade) === 12) {
            if ($new_ela  === 1) $new_ela  = 2;
            if ($new_math === 1) $new_math = 2;
        }

        $old_ela  = !empty($stu->ela_score)  ? (int)$stu->ela_score  : 3;
        $old_math = !empty($stu->math_score) ? (int)$stu->math_score : 3;

        if ($new_ela !== $old_ela || $new_math !== $old_math || empty($stu->recordid)) {
            if (!empty($stu->recordid)) {
                // Update existing record.
                $rec               = new stdClass();
                $rec->id           = (int)$stu->recordid;
                $rec->ela_score    = $new_ela;
                $rec->math_score   = $new_math;
                $rec->timemodified = $now;
                $DB->update_record('local_roomscheduler_students', $rec);
            } else {
                // Insert new record — student not yet in scheduler table.
                $rec                    = new stdClass();
                $rec->userid            = (int)$stu->userid;
                $rec->courseid          = $counselorcourseid;
                $rec->ela_score         = $new_ela;
                $rec->math_score        = $new_math;
                $rec->homeroom          = '';
                $rec->intervention_room = '';
                $rec->timecreated       = $now;
                $rec->timemodified      = $now;
                $DB->insert_record('local_roomscheduler_students', $rec);
            }
            $updated++;
        } else {
            $unchanged++;
        }
    }

    $skipped = count($students) - $updated - $unchanged;

    echo $OUTPUT->header();
    echo $OUTPUT->heading('Set Intervention Levels — Complete');
    local_roomscheduler_print_admin_tabs('setlevels', $counselorcourseid, $canmanage);
    echo $OUTPUT->notification(
        "Processing complete using passing score of <strong>{$passingscore}</strong>.<br>" .
        "<strong>{$updated}</strong> student(s) updated &nbsp;|&nbsp; " .
        "<strong>{$unchanged}</strong> unchanged &nbsp;|&nbsp; " .
        "<strong>{$skipped}</strong> skipped (no state test data found).",
        'notifysuccess'
    );
    echo html_writer::link(
        $studentsurl,
        '← Return to Student Settings',
        ['class' => 'btn btn-secondary mt-3']
    );
    echo $OUTPUT->footer();
    die;
}

// ---------------------------------------------------------------------------
// Show confirmation form.
// ---------------------------------------------------------------------------
echo $OUTPUT->header();
echo $OUTPUT->heading('Set Intervention Levels from State Tests');
local_roomscheduler_print_admin_tabs('setlevels', $counselorcourseid, $canmanage);

echo html_writer::start_div('alert alert-warning');
echo html_writer::tag('h5', '⚠ Warning', ['class' => 'alert-heading']);
echo html_writer::tag('p',
    'This process will update the <strong>ELA</strong> and <strong>Math</strong> intervention ' .
    'levels for all students based on their Ohio State Test scores in the NTstatetesting table.'
);
echo html_writer::tag('p',
    '<strong>Rules applied:</strong><br>' .
    '&bull; If a student has a <strong>High School ELA2</strong> score below the passing ' .
    'score &rarr; ELA level set to <strong>1</strong><br>' .
    '&bull; If no ELA2 found, but <strong>Eighth Grade ELA</strong> score below passing ' .
    'score &rarr; ELA level set to <strong>2</strong><br>' .
    '&bull; If a student has a <strong>High School ALG1</strong> score below the passing ' .
    'score &rarr; Math level set to <strong>1</strong><br>' .
    '&bull; If no ALG1 found, but <strong>Eighth Grade Math</strong> score below passing ' .
    'score &rarr; Math level set to <strong>2</strong><br>' .
    '&bull; Students who pass, or have no matching test data, are <strong>not changed</strong>.<br>' .
    '&bull; Always uses the <strong>highest score</strong> found for each subject.'
);
echo html_writer::tag('p',
    '<strong>This is intended to be run once at the beginning of the year.</strong> ' .
    'Running it again will overwrite any manual adjustments made since the last run.'
);
echo html_writer::end_div();

echo html_writer::start_tag('form', ['method' => 'post', 'action' => '']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'confirmed', 'value' => '1']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey',   'value' => sesskey()]);

echo html_writer::start_div('form-group mb-3');
echo html_writer::tag('label',
    'Passing Score (students scoring below this will be assigned level 1 or 2):',
    ['for' => 'passingscore', 'class' => 'font-weight-bold']
);
echo html_writer::empty_tag('input', [
    'type'  => 'number',
    'id'    => 'passingscore',
    'name'  => 'passingscore',
    'value' => SETLEVELS_DEFAULT_PASSING_SCORE,
    'min'   => '1',
    'max'   => '999',
    'class' => 'form-control',
    'style' => 'width: 120px;',
]);
echo html_writer::end_div();

echo html_writer::empty_tag('input', [
    'type'  => 'submit',
    'value' => 'Yes, Set Levels Now',
    'class' => 'btn btn-danger mr-2',
]);
echo html_writer::link(
    $studentsurl,
    'Cancel — Return to Student Settings',
    ['class' => 'btn btn-secondary']
);

echo html_writer::end_tag('form');

// --- Clear Intervention Levels button (separate from the form above) ---
echo html_writer::start_tag('form', ['method' => 'post', 'action' => '', 'class' => 'mt-2']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'clearlevels', 'value' => '1']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
echo html_writer::empty_tag('input', [
    'type'    => 'submit',
    'value'   => get_string('clearlevelsbutton', 'local_roomscheduler'),
    'class'   => 'btn btn-secondary',
    'onclick' => 'return confirm(' .
        '"This will reset EVERY student\'s ELA and Math intervention level back to 3 (no intervention ' .
        'needed). It does NOT touch Intervention room assignments. Use this before importing a fresh ' .
        'priorities CSV. This cannot be undone. Continue?");',
]);
echo html_writer::end_tag('form');

// ---------------------------------------------------------------------------
// Intervention Scheduler — first step is just a table of how many students
// currently need ELA/Math intervention, broken out by level AND by grade,
// before any assignment logic is built.
// ---------------------------------------------------------------------------
echo html_writer::tag('hr', '', ['class' => 'mt-4']);
echo html_writer::tag('h4', get_string('intschedulerheading', 'local_roomscheduler'), ['class' => 'mt-3']);

// Pull each student's grade level from the contacts table (GradeLevelCode),
// matched the same way the rest of this page matches students.
$sql_gradecounts = "SELECT s.userid, s.ela_score, s.math_score, c.GradeLevelCode AS grade
                      FROM {local_roomscheduler_students} s
                      JOIN {user} u ON u.id = s.userid
                      JOIN `$tbl_contacts` c ON c.StudentNumber = CAST(u.idnumber AS UNSIGNED)
                     WHERE s.courseid = :courseid";
$graderows = $DB->get_records_sql($sql_gradecounts, ['courseid' => $counselorcourseid]);

// Tally counts per grade, plus an overall total row.
$counts = [];
foreach ($graderows as $row) {
    $grade = trim((string)$row->grade);
    if ($grade === '') {
        $grade = get_string('intschedulerunknowngrade', 'local_roomscheduler');
    }
    if (!isset($counts[$grade])) {
        $counts[$grade] = ['ela1' => 0, 'ela2' => 0, 'math1' => 0, 'math2' => 0, 'both' => 0];
    }
    if ((int)$row->ela_score  === 1) $counts[$grade]['ela1']++;
    if ((int)$row->ela_score  === 2) $counts[$grade]['ela2']++;
    if ((int)$row->math_score === 1) $counts[$grade]['math1']++;
    if ((int)$row->math_score === 2) $counts[$grade]['math2']++;
    if (in_array((int)$row->ela_score, [1, 2], true) && in_array((int)$row->math_score, [1, 2], true)) {
        $counts[$grade]['both']++;
    }
}
ksort($counts, SORT_NATURAL);

$totals = ['ela1' => 0, 'ela2' => 0, 'math1' => 0, 'math2' => 0, 'both' => 0];
foreach ($counts as $row) {
    foreach ($row as $key => $val) {
        $totals[$key] += $val;
    }
}

echo html_writer::start_tag('table', ['class' => 'table table-bordered table-sm w-auto']);
echo html_writer::start_tag('thead', ['class' => 'thead-light']);
echo html_writer::start_tag('tr');
echo html_writer::tag('th', get_string('intschedulergrade', 'local_roomscheduler'));
echo html_writer::tag('th', get_string('intschedulerelalevel1',  'local_roomscheduler'), ['class' => 'text-center']);
echo html_writer::tag('th', get_string('intschedulerelalevel2',  'local_roomscheduler'), ['class' => 'text-center']);
echo html_writer::tag('th', get_string('intschedulermathlevel1', 'local_roomscheduler'), ['class' => 'text-center']);
echo html_writer::tag('th', get_string('intschedulermathlevel2', 'local_roomscheduler'), ['class' => 'text-center']);
echo html_writer::tag('th', get_string('intschedulerboth', 'local_roomscheduler'), ['class' => 'text-center']);
echo html_writer::end_tag('tr');
echo html_writer::end_tag('thead');
echo html_writer::start_tag('tbody');

foreach ($counts as $grade => $row) {
    echo html_writer::start_tag('tr');
    echo html_writer::tag('td', s($grade), ['class' => 'font-weight-bold']);
    echo html_writer::tag('td', $row['ela1'],  ['class' => 'text-center']);
    echo html_writer::tag('td', $row['ela2'],  ['class' => 'text-center']);
    echo html_writer::tag('td', $row['math1'], ['class' => 'text-center']);
    echo html_writer::tag('td', $row['math2'], ['class' => 'text-center']);
    echo html_writer::tag('td', $row['both'],  ['class' => 'text-center']);
    echo html_writer::end_tag('tr');
}

echo html_writer::start_tag('tr', ['class' => 'table-dark']);
echo html_writer::tag('td', get_string('intschedulertotal', 'local_roomscheduler'), ['class' => 'font-weight-bold']);
echo html_writer::tag('td', $totals['ela1'],  ['class' => 'text-center font-weight-bold']);
echo html_writer::tag('td', $totals['ela2'],  ['class' => 'text-center font-weight-bold']);
echo html_writer::tag('td', $totals['math1'], ['class' => 'text-center font-weight-bold']);
echo html_writer::tag('td', $totals['math2'], ['class' => 'text-center font-weight-bold']);
echo html_writer::tag('td', $totals['both'],  ['class' => 'text-center font-weight-bold']);
echo html_writer::end_tag('tr');

echo html_writer::end_tag('tbody');
echo html_writer::end_tag('table');

// --- Export / Clear buttons ---
echo html_writer::start_div('d-flex mt-3');

echo html_writer::start_tag('form', ['method' => 'get', 'action' => (new moodle_url('/local/roomscheduler/students.php'))->out(false), 'class' => 'mr-2']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'exportassignments', 'value' => '1']);
echo html_writer::empty_tag('input', [
    'type'  => 'submit',
    'value' => get_string('exportassignmentsbutton', 'local_roomscheduler'),
    'class' => 'btn btn-secondary',
]);
echo html_writer::end_tag('form');

echo html_writer::start_tag('form', ['method' => 'post', 'action' => '']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'clearintervention', 'value' => '1']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
echo html_writer::empty_tag('input', [
    'type'    => 'submit',
    'value'   => get_string('clearinterventionbutton', 'local_roomscheduler'),
    'class'   => 'btn btn-secondary',
    'onclick' => 'return confirm(' .
        '"This will clear both the Intervention Homeroom and Intervention Thur/Fri rooms for every ' .
        'student in this course, without assigning anything new. This cannot be undone. Continue?");',
]);
echo html_writer::end_tag('form');

echo html_writer::end_div();

echo $OUTPUT->footer();
