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
 * Room assignments roster page for local_roomscheduler.
 *
 * Lets a manager pick a room and an intervention type (the Mon/Tue/Wed
 * "Intervention" homeroom, or the Thu/Fri intervention room) and see every
 * student currently assigned to that room for that type — the same
 * information as the Students tab, filtered down to one room.
 *
 * @package    local_roomscheduler
 * @copyright  2026 Brian Pool
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once(__DIR__ . '/lib.php');

require_login();

$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/roomscheduler/assignments.php'));
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('assignments', 'local_roomscheduler'));
$PAGE->set_heading(get_string('assignments_heading', 'local_roomscheduler'));

// Managers and admins only.
$isadmin           = is_siteadmin();
$counselorcourseid = (int) get_config('local_roomscheduler', 'counselorcourseid');

if (empty($counselorcourseid)) {
    echo $OUTPUT->header();
    echo $OUTPUT->notification(get_string('notconfigured', 'local_roomscheduler'), 'notifyproblem');
    echo $OUTPUT->footer();
    die;
}

$counselorcontext = context_course::instance($counselorcourseid);
$managerroleid     = $DB->get_field('role', 'id', ['shortname' => 'manager']);
$ismanager         = !empty($managerroleid) && user_has_role_assignment(
                         $USER->id, $managerroleid, $counselorcontext->id);
$canmanage         = ($isadmin || $ismanager);

if (!$canmanage) {
    echo $OUTPUT->header();
    echo $OUTPUT->notification(get_string('nopermission', 'local_roomscheduler'), 'notifyproblem');
    echo $OUTPUT->footer();
    die;
}

// --- Selected room and intervention-type field ---
$selectedroom = optional_param('roomnumber', '', PARAM_TEXT);

// Only two fields are ever valid here — whitelist to keep this injection-safe.
$field = optional_param('field', 'intervention_room', PARAM_ALPHA);
if (!in_array($field, ['homeroom', 'intervention_room'])) {
    $field = 'intervention_room';
}

// --- Room dropdown: every room on file, listed by teacher (Last, First) so
// staff can find a room by the person's name rather than the room number. ---
$roomsql = "SELECT r.id, r.roomnumber, u.lastname, u.firstname
              FROM {local_roomscheduler_rooms} r
              JOIN {user} u ON u.id = r.teacherid
             WHERE r.roomnumber != ''
             ORDER BY u.lastname ASC, u.firstname ASC, r.roomnumber ASC";
$roomrows = $DB->get_records_sql($roomsql);

$roomoptions = ['' => get_string('assignmentsselectroom', 'local_roomscheduler')];
foreach ($roomrows as $row) {
    $name = $row->lastname . ', ' . $row->firstname;
    if (isset($roomoptions[$row->roomnumber])) {
        // Shared room (two teachers, one room number) — show both names in one entry.
        $roomoptions[$row->roomnumber] = str_replace(' — ' . $row->roomnumber, '', $roomoptions[$row->roomnumber])
            . ' / ' . $name . ' — ' . $row->roomnumber;
    } else {
        $roomoptions[$row->roomnumber] = $name . ' — ' . $row->roomnumber;
    }
}

// --- Render page ---
echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('assignments_heading', 'local_roomscheduler'));

// Navigation tabs (hidden when printing).
echo html_writer::start_div('no-print');
local_roomscheduler_print_admin_tabs('assignments', $counselorcourseid, $canmanage);

// Filter form (GET — this page only displays, it never saves anything).
$formurl = new moodle_url('/local/roomscheduler/assignments.php');

echo html_writer::start_tag('form', [
    'method' => 'get',
    'action' => $formurl->out(false),
    'class'  => 'd-flex align-items-center flex-wrap mb-3',
]);

echo html_writer::tag('label', get_string('assignmentsroomlabel', 'local_roomscheduler'),
    ['for' => 'roomnumber', 'class' => 'mr-2 mb-0']);
echo html_writer::select(
    $roomoptions,
    'roomnumber',
    $selectedroom,
    false,
    ['id' => 'roomnumber', 'class' => 'form-control mr-4', 'onchange' => 'this.form.submit()']
);

echo html_writer::start_div('d-flex align-items-center', ['style' => 'gap: 20px;']);

echo html_writer::start_span();
echo html_writer::empty_tag('input', array_merge(
    ['type' => 'radio', 'name' => 'field', 'value' => 'homeroom', 'id' => 'field_homeroom',
     'onchange' => 'this.form.submit()', 'class' => 'mr-1'],
    ($field === 'homeroom') ? ['checked' => 'checked'] : []
));
echo html_writer::tag('label', get_string('hrattendance', 'local_roomscheduler'),
    ['for' => 'field_homeroom', 'class' => 'mb-0']);
echo html_writer::end_span();

echo html_writer::start_span();
echo html_writer::empty_tag('input', array_merge(
    ['type' => 'radio', 'name' => 'field', 'value' => 'intervention_room', 'id' => 'field_intervention',
     'onchange' => 'this.form.submit()', 'class' => 'mr-1'],
    ($field === 'intervention_room') ? ['checked' => 'checked'] : []
));
echo html_writer::tag('label', get_string('intervention_room', 'local_roomscheduler'),
    ['for' => 'field_intervention', 'class' => 'mb-0']);
echo html_writer::end_span();

echo html_writer::end_div();

echo html_writer::end_tag('form');
echo html_writer::end_div(); // .no-print

// Print-only header (hidden on screen, shown only in the printout).
echo html_writer::start_div('print-header');
echo html_writer::tag('h2', get_string('assignments_heading', 'local_roomscheduler') .
    ($selectedroom ? ': Room ' . s($selectedroom) : ''));
echo html_writer::end_div();

if (empty($selectedroom)) {
    echo $OUTPUT->notification(get_string('assignmentsselectroom', 'local_roomscheduler'), 'info');
    echo $OUTPUT->footer();
    die;
}

// --- Students assigned to the selected room for the selected field ---
$sql = "SELECT s.*, u.firstname, u.lastname, u.idnumber, c.fullname AS dfcoursename
          FROM {local_roomscheduler_students} s
          JOIN {user} u ON u.id = s.userid
     LEFT JOIN {course} c ON c.id = s.dfcourseid
         WHERE s.courseid = :courseid
           AND s.$field = :roomnumber
         ORDER BY u.lastname, u.firstname";
$rows = $DB->get_records_sql($sql, ['courseid' => $counselorcourseid, 'roomnumber' => $selectedroom]);

if (empty($rows)) {
    echo $OUTPUT->notification(get_string('assignmentsnorooms', 'local_roomscheduler'), 'info');
    echo $OUTPUT->footer();
    die;
}

echo html_writer::tag('style', '
    .assignments-table { table-layout: fixed; }
    .assignments-table th {
        background-color: #f8f9fa;
        white-space: normal;
        word-wrap: break-word;
    }
    .assignments-table td { vertical-align: middle !important; }
    .print-header { display: none; }
    @media print {
        .no-print { display: none !important; }
        .print-header { display: block !important; margin-bottom: 10px; page-break-after: avoid; }
        body { background: white !important; }
        header, nav, footer, .navbar, #page-header, #page-footer { display: none !important; }
        .assignments-table th { background: #eee !important; -webkit-print-color-adjust: exact; }
        .assignments-table tr { page-break-inside: avoid; }
        /* Boost wraps the page in flex/min-height containers for a sticky footer;
           printed, that forces a full viewport-tall page 1 and spills a blank
           page 2. Collapse them back down to their actual content height. */
        html, body,
        #page, #page-content, #region-main-box, #region-main,
        .container-fluid, #topofscroll {
            height: auto !important;
            min-height: 0 !important;
        }
        @page { margin: 0.4in; size: portrait; }
    }
');

echo html_writer::start_tag('div', ['class' => 'table-responsive']);
echo html_writer::start_tag('table', [
    'class' => 'table table-bordered table-hover table-sm assignments-table',
]);

echo html_writer::start_tag('thead', ['class' => 'thead-light']);
echo html_writer::start_tag('tr');
echo html_writer::tag('th', get_string('lastname',          'local_roomscheduler'), ['style' => 'width:14%']);
echo html_writer::tag('th', get_string('firstname',         'local_roomscheduler'), ['style' => 'width:12%']);
echo html_writer::tag('th', get_string('sisid',              'local_roomscheduler'), ['style' => 'width:9%']);
echo html_writer::tag('th', get_string('ela_score',          'local_roomscheduler'), ['style' => 'width:6%']);
echo html_writer::tag('th', get_string('math_score',         'local_roomscheduler'), ['style' => 'width:6%']);
echo html_writer::tag('th', get_string('col_monwed',         'local_roomscheduler'), ['style' => 'width:9%; text-align:center;']);
echo html_writer::tag('th', get_string('col_thurfri',        'local_roomscheduler'), ['style' => 'width:9%; text-align:center;']);
echo html_writer::tag('th', 'Code', ['style' => 'width:7%; white-space:nowrap;']);
echo html_writer::tag('th', get_string('col_class',          'local_roomscheduler'), ['style' => 'width:28%']);
echo html_writer::end_tag('tr');
echo html_writer::end_tag('thead');

echo html_writer::start_tag('tbody');
foreach ($rows as $row) {
    echo html_writer::start_tag('tr');
    echo html_writer::tag('td', s($row->lastname));
    echo html_writer::tag('td', s($row->firstname));
    echo html_writer::tag('td', !empty($row->idnumber) ? s($row->idnumber) : '—');
    echo html_writer::tag('td', (int)$row->ela_score);
    echo html_writer::tag('td', (int)$row->math_score);
    echo html_writer::tag('td', s($row->homeroom),          ['style' => 'text-align:center;']);
    echo html_writer::tag('td', s($row->intervention_room), ['style' => 'text-align:center;']);
    echo html_writer::tag('td',
        html_writer::span(s($row->assignment_code), 'badge badge-secondary'),
        ['style' => 'text-align:center;']
    );
    // Class: the course whose D/F grade placed the student here (D/F codes only).
    echo local_roomscheduler_class_cell($row);
    echo html_writer::end_tag('tr');
}
echo html_writer::end_tag('tbody');

echo html_writer::end_tag('table');
echo html_writer::end_tag('div');

echo local_roomscheduler_code_legend();

echo html_writer::start_div('mt-3 no-print');
echo html_writer::tag('button', 'Print',
    ['class' => 'btn btn-secondary', 'onclick' => 'window.print(); return false;']
);
echo html_writer::end_div();

echo $OUTPUT->footer();
