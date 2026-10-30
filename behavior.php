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
 * Student behavior page for local_roomscheduler.
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
$PAGE->set_url(new moodle_url('/local/roomscheduler/behavior.php'));
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('behavior', 'local_roomscheduler'));
$PAGE->set_heading(get_string('behavior_heading', 'local_roomscheduler'));

// Settings.
$counselorcourseid = (int) get_config('local_roomscheduler', 'counselorcourseid');

if (empty($counselorcourseid)) {
    echo $OUTPUT->header();
    echo $OUTPUT->notification(get_string('notconfigured', 'local_roomscheduler'), 'notifyproblem');
    echo $OUTPUT->footer();
    die;
}

// Managers of course 53 and site admin only.
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

// Get selected group filter.
$selectedgroup = optional_param('groupid', 0, PARAM_INT);
$groups        = groups_get_all_groups($counselorcourseid);

// Get all students in course 53.
$studentroleid = $DB->get_field('role', 'id', ['shortname' => 'student']);

if ($selectedgroup > 0) {
    $sql = "SELECT DISTINCT u.id, u.firstname, u.lastname, u.idnumber
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
             ORDER BY u.lastname, u.firstname";
    $params = [
        'courseid'  => $counselorcourseid,
        'contextid' => $counselorcontext->id,
        'roleid'    => $studentroleid,
    ];
}
$students = $DB->get_records_sql($sql, $params);

// Load existing behavior records keyed by userid.
$behaviorrecords = $DB->get_records('local_roomscheduler_behavior', null, '', '*');
$behaviorByUser  = [];
foreach ($behaviorrecords as $b) {
    $behaviorByUser[$b->userid] = $b;
}

// --- Handle form submission ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();

    $allstudents = optional_param_array('allstudents', [], PARAM_INT);
    $now         = time();

    foreach ($allstudents as $userid) {
        $userid = (int)$userid;
        if (!$userid) {
            continue;
        }

        $denied    = optional_param('denied_'    . $userid, 0, PARAM_INT);
        $incidents = optional_param('incidents_' . $userid, 0, PARAM_INT);

        // Get SIS ID for storage.
        $user = $DB->get_record('user', ['id' => $userid], 'id, idnumber');
        $sisid = $user ? $user->idnumber : '';

        $existing = $DB->get_record('local_roomscheduler_behavior', ['userid' => $userid]);

        if ($existing) {
            $rec               = new stdClass();
            $rec->id           = $existing->id;
            $rec->sisid        = $sisid;
            $rec->denied       = $denied;
            $rec->incidents    = $incidents;
            $rec->timemodified = $now;
            $rec->modifiedby   = $USER->id;
            $DB->update_record('local_roomscheduler_behavior', $rec);
        } else {
            // Only insert if denied=1 or incidents>0 to avoid empty rows.
            if ($denied || $incidents > 0) {
                $rec               = new stdClass();
                $rec->userid       = $userid;
                $rec->sisid        = $sisid;
                $rec->denied       = $denied;
                $rec->incidents    = $incidents;
                $rec->timemodified = $now;
                $rec->modifiedby   = $USER->id;
                $DB->insert_record('local_roomscheduler_behavior', $rec);
            }
        }
    }

    $redirecturl = new moodle_url('/local/roomscheduler/behavior.php',
        $selectedgroup ? ['groupid' => $selectedgroup] : []
    );
    redirect(
        $redirecturl,
        get_string('behaviorsaved', 'local_roomscheduler'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

// --- Render page ---
echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('behavior_heading', 'local_roomscheduler'));

// Navigation tabs.
local_roomscheduler_print_intervention_tabs('behavior', $counselorcourseid, $canmanage, $canmanage);

// Instructions.
echo html_writer::tag('p',
    get_string('behaviorinstructions', 'local_roomscheduler'),
    ['class' => 'alert alert-info']
);

// Group filter.
if (!empty($groups)) {
    $groupoptions = [0 => get_string('allgroups', 'local_roomscheduler')];
    foreach ($groups as $group) {
        $groupoptions[$group->id] = $group->name;
    }
    $filterurl = new moodle_url('/local/roomscheduler/behavior.php');
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
        $groupoptions, 'groupid', $selectedgroup, false,
        ['id' => 'groupid', 'class' => 'form-control d-inline-block w-auto mr-2',
         'onchange' => 'this.form.submit()']
    );
    echo html_writer::end_tag('form');
}

if (empty($students)) {
    echo $OUTPUT->notification('No students found.', 'info');
    echo $OUTPUT->footer();
    die;
}

// Inline styles.
echo html_writer::tag('style', '
    .behavior-table th { background-color: #f8f9fa; white-space: nowrap; }
    .behavior-table td { vertical-align: middle !important; }
    .behavior-table tr.denied-row { background-color: #ffe0e0 !important; }
    .deny-cb { width: 22px; height: 22px; cursor: pointer; }
    .incident-input { width: 80px; text-align: center; }
');

// Form.
$formurl = new moodle_url('/local/roomscheduler/behavior.php',
    $selectedgroup ? ['groupid' => $selectedgroup] : []
);

echo html_writer::start_tag('form', [
    'method' => 'post',
    'action' => $formurl->out(false),
]);
echo html_writer::empty_tag('input', [
    'type'  => 'hidden',
    'name'  => 'sesskey',
    'value' => sesskey(),
]);

// Top save button.
echo html_writer::start_div('mb-2');
echo html_writer::empty_tag('input', [
    'type'  => 'submit',
    'value' => 'Save All Changes',
    'class' => 'btn btn-primary btn-sm',
]);
echo html_writer::end_div();

echo html_writer::start_tag('div', ['class' => 'table-responsive']);
echo html_writer::start_tag('table', [
    'class' => 'table table-bordered table-hover table-sm behavior-table',
]);

// Header.
echo html_writer::start_tag('thead', ['class' => 'thead-light']);
echo html_writer::start_tag('tr');
echo html_writer::tag('th', get_string('lastname',  'local_roomscheduler'), ['style' => 'width:28%']);
echo html_writer::tag('th', get_string('firstname', 'local_roomscheduler'), ['style' => 'width:22%']);
echo html_writer::tag('th', get_string('denyreward', 'local_roomscheduler'), ['style' => 'width:20%; text-align:center;']);
echo html_writer::end_tag('tr');
echo html_writer::end_tag('thead');

echo html_writer::start_tag('tbody');

foreach ($students as $student) {
    $uid       = (int)$student->id;
    $bhv       = $behaviorByUser[$uid] ?? null;
    $denied    = $bhv ? (int)$bhv->denied    : 0;
    $incidents = $bhv ? (int)$bhv->incidents : 0;

    $rowclass = $denied ? 'denied-row' : '';

    echo html_writer::start_tag('tr', ['class' => $rowclass]);
    echo '<td>'
        . '<input type="hidden" name="allstudents[]" value="' . $uid . '">'
        . s($student->lastname)
        . '</td>';
    echo html_writer::tag('td', s($student->firstname));

    // Deny checkbox.
    $checkedattr = $denied ? 'checked' : '';
    echo '<td style="text-align:center;">'
        . '<input type="checkbox" name="denied_' . $uid . '" value="1" '
        . 'class="deny-cb" ' . $checkedattr . '>'
        . '</td>';

    echo html_writer::end_tag('tr');
}

echo html_writer::end_tag('tbody');
echo html_writer::end_tag('table');
echo html_writer::end_div();

// Bottom save button.
echo html_writer::start_div('mt-2 mb-3');
echo html_writer::empty_tag('input', [
    'type'  => 'submit',
    'value' => 'Save All Changes',
    'class' => 'btn btn-primary btn-sm',
]);
echo html_writer::end_div();

echo html_writer::end_tag('form');
echo $OUTPUT->footer();
