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
 * Add / edit a room assignment for local_roomscheduler.
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
$PAGE->set_url(new moodle_url('/local/roomscheduler/edit.php'));
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('assign_rooms', 'local_roomscheduler'));
$PAGE->set_heading(get_string('assign_rooms', 'local_roomscheduler'));

// Managers only.
require_capability('local/roomscheduler:manage', $context);

// Check plugin is configured.
$teachercourseid = get_config('local_roomscheduler', 'teachercourseid');
if (empty($teachercourseid)) {
    redirect(
        new moodle_url('/local/roomscheduler/index.php'),
        get_string('noteachercourseid', 'local_roomscheduler'),
        null,
        \core\output\notification::NOTIFY_ERROR
    );
}

// Optional record ID for editing an existing record.
$id = optional_param('id', 0, PARAM_INT);

// Load existing record if editing.
$record = null;
if ($id) {
    $record = $DB->get_record('local_roomscheduler_rooms', ['id' => $id], '*', MUST_EXIST);
}

// Get all teachers from configured course for the dropdown.
$enrolledteachers = get_enrolled_users(context_course::instance($teachercourseid));
$teacheroptions = [];
foreach ($enrolledteachers as $t) {
    $teacheroptions[$t->id] = fullname($t);
}
asort($teacheroptions); // Alphabetical order.

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

// --- Handle form submission ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    require_sesskey();

    $teacherid        = required_param('teacherid',        PARAM_INT);
    $roomnumber       = required_param('roomnumber',       PARAM_TEXT);
    $maxstudents      = required_param('maxstudents',      PARAM_INT);
    $interventiontype = required_param('interventiontype', PARAM_ALPHANUMEXT);

    // Validate intervention type is one of the allowed values.
    if (!array_key_exists($interventiontype, $typeoptions)) {
        $interventiontype = 'NA';
    }

    // Validate teacher is in the configured course.
    if (!array_key_exists($teacherid, $teacheroptions)) {
        redirect(
            new moodle_url('/local/roomscheduler/index.php'),
            get_string('nopermission', 'local_roomscheduler'),
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }

    $now = time();

    if ($id && $record) {
        // Update existing record.
        $record->teacherid        = $teacherid;
        $record->roomnumber       = $roomnumber;
        $record->maxstudents      = $maxstudents;
        $record->interventiontype = $interventiontype;
        $record->timemodified     = $now;
        $DB->update_record('local_roomscheduler_rooms', $record);
    } else {
        // Insert new record.
        $newrecord = new stdClass();
        $newrecord->teacherid        = $teacherid;
        $newrecord->roomnumber       = $roomnumber;
        $newrecord->maxstudents      = $maxstudents;
        $newrecord->interventiontype = $interventiontype;
        $newrecord->timecreated      = $now;
        $newrecord->timemodified     = $now;
        $DB->insert_record('local_roomscheduler_rooms', $newrecord);
    }

    redirect(
        new moodle_url('/local/roomscheduler/index.php'),
        get_string('savedsuccess', 'local_roomscheduler'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

// --- Render the form ---
echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('assign_rooms', 'local_roomscheduler'));

// Set current values (for edit) or defaults (for add).
$currentteacher  = $record ? $record->teacherid        : 0;
$currentroom     = $record ? $record->roomnumber        : '';
$currentmax      = $record ? $record->maxstudents       : 0;
$currenttype     = $record ? $record->interventiontype  : 'NA';

$formurl = new moodle_url('/local/roomscheduler/edit.php', $id ? ['id' => $id] : []);

echo html_writer::start_tag('form', [
    'method' => 'post',
    'action' => $formurl->out(false),
    'class'  => 'mform',
]);

echo html_writer::input_hidden_params($formurl);
echo html_writer::empty_tag('input', [
    'type'  => 'hidden',
    'name'  => 'sesskey',
    'value' => sesskey(),
]);

// Teacher dropdown.
echo html_writer::start_div('form-group row');
echo html_writer::tag('label',
    get_string('teacher', 'local_roomscheduler'),
    ['class' => 'col-md-3 col-form-label', 'for' => 'teacherid']
);
echo html_writer::start_div('col-md-9');
echo html_writer::select($teacheroptions, 'teacherid', $currentteacher, ['' => 'choosedots'], ['id' => 'teacherid', 'class' => 'form-control']);
echo html_writer::end_div();
echo html_writer::end_div();

// Room number input.
echo html_writer::start_div('form-group row');
echo html_writer::tag('label',
    get_string('roomnumber', 'local_roomscheduler'),
    ['class' => 'col-md-3 col-form-label', 'for' => 'roomnumber']
);
echo html_writer::start_div('col-md-9');
echo html_writer::empty_tag('input', [
    'type'      => 'text',
    'name'      => 'roomnumber',
    'id'        => 'roomnumber',
    'value'     => s($currentroom),
    'maxlength' => 20,
    'class'     => 'form-control',
    'required'  => 'required',
]);
echo html_writer::end_div();
echo html_writer::end_div();

// Intervention type dropdown.
echo html_writer::start_div('form-group row');
echo html_writer::tag('label',
    get_string('interventiontype', 'local_roomscheduler'),
    ['class' => 'col-md-3 col-form-label', 'for' => 'interventiontype']
);
echo html_writer::start_div('col-md-9');
echo html_writer::select($typeoptions, 'interventiontype', $currenttype, false, ['id' => 'interventiontype', 'class' => 'form-control']);
echo html_writer::end_div();
echo html_writer::end_div();

// Max students input.
echo html_writer::start_div('form-group row');
echo html_writer::tag('label',
    get_string('maxstudents', 'local_roomscheduler'),
    ['class' => 'col-md-3 col-form-label', 'for' => 'maxstudents']
);
echo html_writer::start_div('col-md-9');
echo html_writer::empty_tag('input', [
    'type'  => 'number',
    'name'  => 'maxstudents',
    'id'    => 'maxstudents',
    'value' => $currentmax,
    'min'   => 0,
    'max'   => 999,
    'class' => 'form-control',
]);
echo html_writer::end_div();
echo html_writer::end_div();

// Buttons.
echo html_writer::start_div('form-group row');
echo html_writer::start_div('col-md-9 offset-md-3');
echo html_writer::empty_tag('input', [
    'type'  => 'submit',
    'value' => get_string('save', 'local_roomscheduler'),
    'class' => 'btn btn-primary mr-2',
]);
$cancelurl = new moodle_url('/local/roomscheduler/index.php');
echo html_writer::link($cancelurl, get_string('cancel', 'local_roomscheduler'), ['class' => 'btn btn-secondary']);
echo html_writer::end_div();
echo html_writer::end_div();

echo html_writer::end_tag('form');

echo $OUTPUT->footer();
