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
 * Import Intervention Homeroom and Thur/Fri rooms (and ELA/Math levels) from CSV.
 *
 * Three stages:
 *   1. Upload form.
 *   2. Preview — the file is read and every change (old -> new) is listed.
 *      Nothing is saved yet; the parsed rows are kept in the user's session.
 *   3. Confirm Import — the changes are written in one transaction.
 *
 * Columns are matched by header name, so the Export Assignments file from the
 * Students page (on production or the sandbox) imports as-is. See
 * local_roomscheduler_import_parse_csv() in lib.php for the accepted headers.
 *
 * @package    local_roomscheduler
 * @copyright  2026 Brian Pool
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once(__DIR__ . '/lib.php');

require_login();

$action = optional_param('action', '', PARAM_ALPHA);

$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/roomscheduler/import.php'));
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('importtitle', 'local_roomscheduler'));
$PAGE->set_heading(get_string('importtitle', 'local_roomscheduler'));

$teachercourseid   = (int) get_config('local_roomscheduler', 'teachercourseid');
$counselorcourseid = (int) get_config('local_roomscheduler', 'counselorcourseid');

if (empty($teachercourseid) || empty($counselorcourseid)) {
    echo $OUTPUT->header();
    echo $OUTPUT->notification(get_string('notconfigured', 'local_roomscheduler'), 'notifyproblem');
    echo $OUTPUT->footer();
    die;
}

// Managers only.
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

$importurl = new moodle_url('/local/roomscheduler/import.php');

// Every student in the counselor course, keyed by SIS ID.
$studentroleid = $DB->get_field('role', 'id', ['shortname' => 'student']);
$sql = "SELECT DISTINCT u.id, u.firstname, u.lastname, u.idnumber
          FROM {user} u
          JOIN {user_enrolments} ue ON ue.userid = u.id
          JOIN {enrol} e ON e.id = ue.enrolid
          JOIN {role_assignments} ra ON ra.userid = u.id
         WHERE e.courseid = :courseid
           AND ra.contextid = :contextid
           AND ra.roleid = :roleid
           AND u.deleted = 0
           AND u.suspended = 0
           AND u.idnumber != ''";
$studentrows = $DB->get_records_sql($sql, [
    'courseid'  => $counselorcourseid,
    'contextid' => $counselorcontext->id,
    'roleid'    => $studentroleid,
]);
$studentbysisid = [];
foreach ($studentrows as $s) {
    $studentbysisid[trim($s->idnumber)] = $s;
}

$errors = [];

// --- Cancel: forget the preview ---
if ($action === 'cancel') {
    require_sesskey();
    unset($SESSION->local_roomscheduler_import);
    redirect($importurl);
}

// --- Confirm: write the previewed rows ---
if ($action === 'confirm') {
    require_sesskey();
    $pending = $SESSION->local_roomscheduler_import ?? null;
    unset($SESSION->local_roomscheduler_import);

    if (empty($pending) || empty($pending['rows'])) {
        redirect($importurl, get_string('importexpired', 'local_roomscheduler'), null,
            \core\output\notification::NOTIFY_WARNING);
    }

    $updated = local_roomscheduler_import_apply($pending['rows'], $counselorcourseid);
    redirect($importurl, get_string('importsuccess', 'local_roomscheduler', $updated), null,
        \core\output\notification::NOTIFY_SUCCESS);
}

// --- Preview: read the uploaded file, show the changes, save nothing ---
$preview = null;
if ($action === 'preview') {
    require_sesskey();

    if (empty($_FILES['csvfile']['tmp_name']) || !is_uploaded_file($_FILES['csvfile']['tmp_name'])) {
        $errors[] = get_string('importnofile', 'local_roomscheduler');
    } else {
        $parsed = local_roomscheduler_import_parse_csv($_FILES['csvfile']['tmp_name'], $studentbysisid);
        if (!empty($parsed['error'])) {
            $errors[] = $parsed['error'];
        } else {
            $preview = $parsed;
            $preview['changes'] = local_roomscheduler_import_changes($parsed['rows'], $counselorcourseid);
            $SESSION->local_roomscheduler_import = ['rows' => $parsed['rows']];
        }
    }
}

// --- Render ---
echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('importmaintitle', 'local_roomscheduler'));
local_roomscheduler_print_admin_tabs('import', $counselorcourseid, $canmanage);

foreach ($errors as $error) {
    echo $OUTPUT->notification($error, 'notifyproblem');
}

if ($preview !== null) {
    $changes   = $preview['changes'];
    $filecount = count($preview['rows']);

    echo $OUTPUT->notification(get_string('importpreview', 'local_roomscheduler'), 'warning');

    $a = (object)[
        'rows'      => $filecount,
        'changed'   => count($changes),
        'unchanged' => $filecount - count($changes),
        'columns'   => implode(', ', $preview['columns']),
    ];
    echo html_writer::tag('p', get_string('importpreviewsummary', 'local_roomscheduler', $a));
    echo html_writer::tag('p', get_string('importcodenote', 'local_roomscheduler'), ['class' => 'text-muted']);

    // SIS IDs in the file that are not students in the counselor course.
    if (!empty($preview['notfound'])) {
        echo html_writer::tag('h5',
            get_string('importnotfound', 'local_roomscheduler') . ' (' . count($preview['notfound']) . ')',
            ['class' => 'mt-3 text-danger']);
        echo html_writer::tag('p', s(implode(', ', $preview['notfound'])));
    }

    // Values that were not valid and will be skipped.
    if (!empty($preview['invalid'])) {
        echo html_writer::tag('h5',
            get_string('importinvalid', 'local_roomscheduler') . ' (' . count($preview['invalid']) . ')',
            ['class' => 'mt-3 text-danger']);
        echo html_writer::start_tag('ul');
        foreach ($preview['invalid'] as $bad) {
            echo html_writer::tag('li', s($bad['sisid'] . ' — ' . $bad['field'] . ': "' . $bad['value'] . '"'));
        }
        echo html_writer::end_tag('ul');
    }

    // Changes, one row per changed field.
    echo html_writer::tag('h5',
        get_string('importchanged', 'local_roomscheduler') . ' (' . count($changes) . ')',
        ['class' => 'mt-3']);

    if (empty($changes)) {
        echo $OUTPUT->notification(get_string('importnochanges', 'local_roomscheduler'), 'info');
    } else {
        echo html_writer::start_tag('table', ['class' => 'table table-bordered table-sm table-hover']);
        echo html_writer::start_tag('thead', ['class' => 'thead-light']);
        echo html_writer::start_tag('tr');
        echo html_writer::tag('th', get_string('importstudent', 'local_roomscheduler'));
        echo html_writer::tag('th', get_string('sisid', 'local_roomscheduler'));
        echo html_writer::tag('th', get_string('importfield', 'local_roomscheduler'));
        echo html_writer::tag('th', get_string('importold', 'local_roomscheduler'));
        echo html_writer::tag('th', get_string('importnew', 'local_roomscheduler'));
        echo html_writer::end_tag('tr');
        echo html_writer::end_tag('thead');
        echo html_writer::start_tag('tbody');

        foreach ($changes as $userid => $change) {
            $student = $studentrows[$userid];
            $first   = true;
            $span    = count($change['fields']);
            foreach ($change['fields'] as $field => $vals) {
                echo html_writer::start_tag('tr');
                if ($first) {
                    $name = $student->lastname . ', ' . $student->firstname;
                    if ($change['isnew']) {
                        $name .= ' ' . get_string('importnewrecord', 'local_roomscheduler');
                    }
                    echo html_writer::tag('td', s($name), ['rowspan' => $span]);
                    echo html_writer::tag('td', s(trim($student->idnumber)), ['rowspan' => $span]);
                    $first = false;
                }
                $old = ($vals['old'] === '' || $vals['old'] === null) ? '—' : $vals['old'];
                $new = ($vals['new'] === '') ? get_string('importblank', 'local_roomscheduler') : $vals['new'];
                echo html_writer::tag('td', get_string($field, 'local_roomscheduler'));
                echo html_writer::tag('td', s($old), ['class' => 'text-danger']);
                echo html_writer::tag('td', s($new), ['class' => 'text-success']);
                echo html_writer::end_tag('tr');
            }
        }

        echo html_writer::end_tag('tbody');
        echo html_writer::end_tag('table');
    }

    // Confirm / Cancel.
    echo html_writer::start_div('d-flex mt-3 mb-4');
    if (!empty($changes)) {
        echo html_writer::start_tag('form', ['method' => 'post', 'action' => $importurl->out(false), 'class' => 'mr-2']);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'confirm']);
        echo html_writer::empty_tag('input', [
            'type'  => 'submit',
            'value' => get_string('importconfirm', 'local_roomscheduler'),
            'class' => 'btn btn-danger',
        ]);
        echo html_writer::end_tag('form');
    }
    echo html_writer::start_tag('form', ['method' => 'post', 'action' => $importurl->out(false)]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'cancel']);
    echo html_writer::empty_tag('input', [
        'type'  => 'submit',
        'value' => get_string('importcancel', 'local_roomscheduler'),
        'class' => 'btn btn-secondary',
    ]);
    echo html_writer::end_tag('form');
    echo html_writer::end_div();

} else {
    // --- Upload form ---
    echo html_writer::div(get_string('importinstructions', 'local_roomscheduler'), 'alert alert-info');

    echo html_writer::start_tag('form', [
        'method'  => 'post',
        'action'  => $importurl->out(false),
        'enctype' => 'multipart/form-data',
    ]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'preview']);

    echo html_writer::start_div('form-group');
    echo html_writer::tag('label', get_string('importfile', 'local_roomscheduler'),
        ['for' => 'csvfile', 'class' => 'font-weight-bold']);
    echo html_writer::empty_tag('input', [
        'type'   => 'file',
        'name'   => 'csvfile',
        'id'     => 'csvfile',
        'accept' => '.csv',
        'class'  => 'form-control-file',
    ]);
    echo html_writer::end_div();

    echo html_writer::start_div('mt-3');
    echo html_writer::empty_tag('input', [
        'type'  => 'submit',
        'value' => get_string('importsubmit', 'local_roomscheduler'),
        'class' => 'btn btn-primary',
    ]);
    echo html_writer::end_div();

    echo html_writer::end_tag('form');
}

echo $OUTPUT->footer();
