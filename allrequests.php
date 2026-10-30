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
 * All intervention requests view for local_roomscheduler.
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
$PAGE->set_url(new moodle_url('/local/roomscheduler/allrequests.php'));
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('allrequests', 'local_roomscheduler'));
$PAGE->set_heading(get_string('allrequests_heading', 'local_roomscheduler'));

// Get plugin settings.
$counselorcourseid = (int) get_config('local_roomscheduler', 'counselorcourseid');

if (empty($counselorcourseid)) {
    echo $OUTPUT->header();
    echo $OUTPUT->notification(get_string('notconfigured', 'local_roomscheduler'), 'notifyproblem');
    echo $OUTPUT->footer();
    die;
}

// Managers and admins only.
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

// Get all requested students with teacher info and student scores.
$sql = "SELECT r.id, r.userid, r.teacherid, r.requested,
               u.firstname, u.lastname, u.idnumber,
               t.firstname AS teacherfirst, t.lastname AS teacherlast,
               s.ela_score, s.math_score, s.homeroom, s.intervention_room
          FROM {local_roomscheduler_requests} r
          JOIN {user} u ON u.id = r.userid
          JOIN {user} t ON t.id = r.teacherid
     LEFT JOIN {local_roomscheduler_students} s ON s.userid = r.userid
                AND s.courseid = :counselorcourseid
         WHERE r.requested = 1
         ORDER BY u.lastname, u.firstname, t.lastname, t.firstname";

$requests = $DB->get_records_sql($sql, ['counselorcourseid' => $counselorcourseid]);

// Navigation URLs.

// Handle Clear All Requests.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && optional_param('clearall', 0, PARAM_INT)) {
    require_sesskey();
    $DB->delete_records('local_roomscheduler_requests');
    $DB->delete_records('local_roomscheduler_teacher_prefs');
    redirect(
        new moodle_url('/local/roomscheduler/allrequests.php'),
        'All requests cleared successfully.',
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}
// --- Render page ---
echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('allrequests_heading', 'local_roomscheduler'));

// Navigation tabs.
local_roomscheduler_print_admin_tabs('allrequests', $counselorcourseid, $canmanage);

echo html_writer::start_div('mb-3');
// Clear All Requests button.
$clearurl = new moodle_url('/local/roomscheduler/allrequests.php');
echo html_writer::start_tag('form', [
    'method' => 'post',
    'action' => $clearurl->out(false),
    'class'  => 'd-inline-block',
]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey',  'value' => sesskey()]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'clearall', 'value' => '1']);
echo html_writer::empty_tag('input', [
    'type'    => 'submit',
    'value'   => 'Clear All Requests',
    'class'   => 'btn btn-danger btn-sm',
    'onclick' => 'return confirm("Are you sure you want to clear ALL requests? This cannot be undone.");',
]);
echo html_writer::end_tag('form');
echo html_writer::end_div();

if (empty($requests)) {
    echo $OUTPUT->notification(get_string('norequests', 'local_roomscheduler'), 'info');
    echo $OUTPUT->footer();
    die;
}

// Inline styles.
echo html_writer::tag('style', '
    .allrequests-table th { background-color: #f8f9fa; white-space: nowrap; }
    .allrequests-table td { vertical-align: middle !important; }
    .allrequests-table .score-1 { background-color: #ffe0e0 !important; }
    .allrequests-table .score-2 { background-color: #fff3cd !important; }
    .allrequests-table .score-3 { background-color: #f0fff0 !important; }
');

echo html_writer::tag('p',
    count($requests) . ' total intervention request(s).',
    ['class' => 'text-muted mb-2']
);

echo html_writer::start_tag('div', ['class' => 'table-responsive']);
echo html_writer::start_tag('table', [
    'class' => 'table table-bordered table-hover table-sm allrequests-table',
]);

// Header.
echo html_writer::start_tag('thead', ['class' => 'thead-light']);
echo html_writer::start_tag('tr');
echo html_writer::tag('th', '#',                                                        ['style' => 'width:4%']);
echo html_writer::tag('th', get_string('lastname',          'local_roomscheduler'),     ['style' => 'width:14%']);
echo html_writer::tag('th', get_string('firstname',         'local_roomscheduler'),     ['style' => 'width:12%']);
echo html_writer::tag('th', get_string('sisid',             'local_roomscheduler'),     ['style' => 'width:9%']);
echo html_writer::tag('th', get_string('ela_score',         'local_roomscheduler'),     ['style' => 'width:7%']);
echo html_writer::tag('th', get_string('math_score',        'local_roomscheduler'),     ['style' => 'width:7%']);
echo html_writer::tag('th', get_string('homeroom',          'local_roomscheduler'),     ['style' => 'width:10%']);
echo html_writer::tag('th', get_string('intervention_room', 'local_roomscheduler'),     ['style' => 'width:10%']);
echo html_writer::tag('th', get_string('teachercolumn',     'local_roomscheduler'),     ['style' => 'width:17%']);
echo html_writer::end_tag('tr');
echo html_writer::end_tag('thead');

echo html_writer::start_tag('tbody');

$i = 0;
foreach ($requests as $req) {
    $i++;
    $elaval   = $req->ela_score        ? (int)$req->ela_score        : 3;
    $mathval  = $req->math_score       ? (int)$req->math_score       : 3;
    $homeroom = $req->homeroom         ? s($req->homeroom)           : '—';
    $introom  = $req->intervention_room ? s($req->intervention_room) : '—';
    $sisid    = $req->idnumber         ? s($req->idnumber)           : '—';

    $minscore = min($elaval, $mathval);
    $rowclass = 'score-' . $minscore;

    echo html_writer::start_tag('tr', ['class' => $rowclass]);
    echo html_writer::tag('td', $i, ['style' => 'color:#999;font-size:0.85em;']);
    echo html_writer::tag('td', s($req->lastname));
    echo html_writer::tag('td', s($req->firstname));
    echo html_writer::tag('td', $sisid);
    echo html_writer::tag('td', $elaval);
    echo html_writer::tag('td', $mathval);
    echo html_writer::tag('td', $homeroom);
    echo html_writer::tag('td', $introom);
    echo html_writer::tag('td', s($req->teacherlast . ', ' . $req->teacherfirst));
    echo html_writer::end_tag('tr');
}

echo html_writer::end_tag('tbody');
echo html_writer::end_tag('table');
echo html_writer::end_div();

echo $OUTPUT->footer();
