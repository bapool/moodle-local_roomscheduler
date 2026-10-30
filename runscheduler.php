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
 * Manual scheduler trigger page for local_roomscheduler.
 *
 * @package    local_roomscheduler
 * @copyright  2026 Brian Pool
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once(__DIR__ . '/scheduler.php');

require_login();

$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/roomscheduler/runscheduler.php'));
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('scheduler', 'local_roomscheduler'));
$PAGE->set_heading(get_string('scheduler_heading', 'local_roomscheduler'));

// Managers and admins only.
$isadmin          = is_siteadmin();
$counselorcourseid = (int) get_config('local_roomscheduler', 'counselorcourseid');
$counselorcontext  = context_course::instance($counselorcourseid);
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

$results  = null;
$ran      = false;
$action   = optional_param('action', '', PARAM_ALPHA);

// --- Handle form submission ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();

    if (optional_param('togglerewards', 0, PARAM_INT)) {
        // Just toggling the reward-room / lock-IEP checkboxes — don't run the scheduler.
        // These settings only affect the NEXT run; current assignments are never touched.
        $rewardsenabled = optional_param('rewardsenabled', 0, PARAM_INT);
        $lockiep        = optional_param('lockiep',        0, PARAM_INT);
        $lockall        = optional_param('lockall',        0, PARAM_INT);
        set_config('rewardsenabled', $rewardsenabled, 'local_roomscheduler');
        set_config('lockiep',        $lockiep,        'local_roomscheduler');
        set_config('lockall',        $lockall,        'local_roomscheduler');
        // Leveling gap for General rooms: 0 turns leveling off, capped at 20.
        $levelgap = optional_param('levelgap', 4, PARAM_INT);
        $levelgap = max(0, min(20, $levelgap));
        set_config('levelgap',       $levelgap,       'local_roomscheduler');
        redirect(new moodle_url('/local/roomscheduler/runscheduler.php'));
    }

    // Only the Run button on the confirmation screen (which posts action=run)
    // can start a run. Any other POST to this page is ignored.
    if ($action !== 'run') {
        redirect(new moodle_url('/local/roomscheduler/runscheduler.php'));
    }

    $results = local_roomscheduler_run_scheduler();
    $ran     = true;
} else {
    // Not a fresh run — load the results from the last time it was run, if any.
    $storedresults = get_config('local_roomscheduler', 'lastrunresults');
    if ($storedresults) {
        $decoded = json_decode($storedresults, true);
        if (is_array($decoded)) {
            $results = $decoded;
        }
    }
}

// Current reward-room scheduling state (defaults to on if never saved).
$rewardsenabled = get_config('local_roomscheduler', 'rewardsenabled');
if ($rewardsenabled === false || $rewardsenabled === '') {
    $rewardsenabled = 1;
}

// Current lock-IEP state (defaults to off if never saved).
$lockiep = get_config('local_roomscheduler', 'lockiep');
if ($lockiep === false || $lockiep === '') {
    $lockiep = 0;
}

// Current lock-all state (defaults to off if never saved).
$lockall = get_config('local_roomscheduler', 'lockall');
if ($lockall === false || $lockall === '') {
    $lockall = 0;
}

// Current General-room leveling gap (defaults to 4 if never saved; 0 = off).
$levelgap = get_config('local_roomscheduler', 'levelgap');
if ($levelgap === false || $levelgap === '') {
    $levelgap = 4;
}
$levelgap = (int)$levelgap;

// --- Confirmation screen ---
// The main page's Run button only brings you here; it never runs the scheduler
// by itself. The scheduler runs only if the Run button on THIS screen is clicked.
if ($action === 'confirm') {
    $on  = get_string('settingon',  'local_roomscheduler');
    $off = get_string('settingoff', 'local_roomscheduler');

    $a          = new stdClass();
    $a->rewards = $rewardsenabled ? $on : $off;
    $a->lockiep = $lockiep        ? $on : $off;
    $a->lockall = $lockall        ? $on : $off;
    $a->levelgap = $levelgap > 0 ? $levelgap
                                 : get_string('levelgap_off', 'local_roomscheduler');

    $continue = new single_button(
        new moodle_url('/local/roomscheduler/runscheduler.php', ['action' => 'run']),
        get_string('schedulerrun', 'local_roomscheduler'),
        'post',
        single_button::BUTTON_DANGER
    );
    $cancel = new single_button(
        new moodle_url('/local/roomscheduler/runscheduler.php'),
        get_string('cancel'),
        'get'
    );

    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('scheduler_heading', 'local_roomscheduler'));
    echo $OUTPUT->confirm(
        get_string('schedulerconfirm', 'local_roomscheduler', $a),
        $continue,
        $cancel,
        ['confirmtitle' => get_string('schedulerconfirm_title', 'local_roomscheduler')]
    );
    echo $OUTPUT->footer();
    die;
}

// --- Render page ---
echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('scheduler_heading', 'local_roomscheduler'));

// Navigation tabs.
local_roomscheduler_print_admin_tabs('scheduler', $counselorcourseid, $canmanage);

// Last run info.
$lastrun = get_config('local_roomscheduler', 'lastrun');
echo html_writer::tag('p',
    get_string('schedulerlastrun', 'local_roomscheduler') . ': ' .
    ($lastrun ? userdate($lastrun) : get_string('schedulerneverrun', 'local_roomscheduler')),
    ['class' => 'text-muted']
);

// Reward-room scheduling toggle.
echo html_writer::start_tag('form', [
    'method' => 'post',
    'action' => '',
    'class'  => 'mb-3',
]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey',        'value' => sesskey()]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'togglerewards',  'value' => '1']);
echo html_writer::start_tag('label', ['class' => 'font-weight-bold']);
echo html_writer::empty_tag('input', array_merge(
    ['type' => 'checkbox', 'name' => 'rewardsenabled', 'value' => '1',
     'onchange' => 'this.form.submit()', 'class' => 'mr-2'],
    $rewardsenabled ? ['checked' => 'checked'] : []
));
echo get_string('rewardsenabled', 'local_roomscheduler');
echo html_writer::end_tag('label');
echo html_writer::tag('p',
    $rewardsenabled
        ? get_string('rewardsenabled_on', 'local_roomscheduler')
        : get_string('rewardsenabled_off', 'local_roomscheduler'),
    ['class' => 'text-muted small mb-0']
);

echo html_writer::start_tag('label', ['class' => 'font-weight-bold']);
echo html_writer::empty_tag('input', array_merge(
    ['type' => 'checkbox', 'name' => 'lockiep', 'value' => '1',
     'onchange' => 'this.form.submit()', 'class' => 'mr-2'],
    $lockiep ? ['checked' => 'checked'] : []
));
echo get_string('lockiep', 'local_roomscheduler');
echo html_writer::end_tag('label');
echo html_writer::tag('p',
    $lockiep
        ? get_string('lockiep_on', 'local_roomscheduler')
        : get_string('lockiep_off', 'local_roomscheduler'),
    ['class' => 'text-muted small mb-0']
);

echo html_writer::start_tag('label', ['class' => 'font-weight-bold']);
echo html_writer::empty_tag('input', array_merge(
    ['type' => 'checkbox', 'name' => 'lockall', 'value' => '1',
     'onchange' => 'this.form.submit()', 'class' => 'mr-2'],
    $lockall ? ['checked' => 'checked'] : []
));
echo get_string('lockall', 'local_roomscheduler');
echo html_writer::end_tag('label');
echo html_writer::tag('p',
    $lockall
        ? get_string('lockall_on', 'local_roomscheduler')
        : get_string('lockall_off', 'local_roomscheduler'),
    ['class' => 'text-muted small mb-0']
);

// General-room leveling gap. A number box with its own Save button, since
// it should not submit on every arrow click the way the checkboxes do.
echo html_writer::start_tag('label', ['class' => 'font-weight-bold mt-2', 'for' => 'id_levelgap']);
echo get_string('levelgap', 'local_roomscheduler');
echo html_writer::end_tag('label');
echo html_writer::start_div('d-flex align-items-center');
echo html_writer::empty_tag('input', [
    'type'  => 'number',
    'name'  => 'levelgap',
    'id'    => 'id_levelgap',
    'value' => $levelgap,
    'min'   => 0,
    'max'   => 20,
    'step'  => 1,
    'class' => 'form-control mr-2',
    'style' => 'width: 5em;',
]);
echo html_writer::empty_tag('input', [
    'type'  => 'submit',
    'value' => get_string('savechanges'),
    'class' => 'btn btn-secondary btn-sm',
]);
echo html_writer::end_div();
echo html_writer::tag('p',
    get_string('levelgap_desc', 'local_roomscheduler'),
    ['class' => 'text-muted small mb-0']
);
echo html_writer::end_tag('form');

// Warning box.
echo html_writer::tag('div',
    html_writer::tag('strong', '⚠ Warning: ') .
    'Running the scheduler will wipe and completely reassign ALL student intervention rooms. ' .
    'This cannot be undone. Make sure room assignments and student scores are up to date before running.',
    ['class' => 'alert alert-warning']
);

// Run button — opens the confirmation screen only. It cannot run the
// scheduler by itself (it is a GET to action=confirm, not a POST).
$runbutton = new single_button(
    new moodle_url('/local/roomscheduler/runscheduler.php', ['action' => 'confirm']),
    get_string('schedulerrun', 'local_roomscheduler'),
    'get',
    single_button::BUTTON_DANGER
);
$runbutton->class = 'mb-4';
echo $OUTPUT->render($runbutton);

// --- Show results (from this run, or the most recent stored run) ---
if ($results !== null) {
    if ($ran) {
        echo $OUTPUT->notification(get_string('schedulersuccess', 'local_roomscheduler'), 'notifysuccess');
    }

    echo html_writer::tag('h4', get_string('scheduleresults', 'local_roomscheduler'), ['class' => 'mt-3']);

    echo html_writer::start_tag('table', ['class' => 'table table-bordered table-sm w-auto']);
    echo html_writer::start_tag('tbody');

    $steps = [
        'step0iep'    => get_string('step0iep', 'local_roomscheduler'),
        'lockall'     => get_string('step0lockall', 'local_roomscheduler'),
        'step1'       => get_string('step1assigned', 'local_roomscheduler'),
        'step1plus'   => get_string('step1plus', 'local_roomscheduler'),
        'plusadded'   => get_string('plusadded', 'local_roomscheduler'),
        'subjecttogen' => get_string('subjecttogen', 'local_roomscheduler'),
        'step2'       => get_string('step2assigned', 'local_roomscheduler'),
        'step3'       => get_string('step3assigned', 'local_roomscheduler'),
        'step3swap'   => get_string('step3swap', 'local_roomscheduler'),
        'step3plus'   => get_string('step3plus', 'local_roomscheduler'),
        'step4'       => get_string('step4assigned', 'local_roomscheduler'),
        'step4absent'   => get_string('step4absent',   'local_roomscheduler'),
        'step4behavior' => get_string('step4behavior', 'local_roomscheduler'),
        'displaced'   => get_string('step5assigned', 'local_roomscheduler'),
        'step6'       => get_string('step6assigned', 'local_roomscheduler'),
    ];
    foreach ($steps as $key => $label) {
        echo html_writer::start_tag('tr');
        echo html_writer::tag('td', $label, ['class' => 'font-weight-bold']);
        echo html_writer::tag('td', $results[$key] ?? 0, ['class' => 'text-right']);
        echo html_writer::end_tag('tr');
    }

    echo html_writer::start_tag('tr', ['class' => 'table-dark']);
    echo html_writer::tag('td', get_string('totalassigned', 'local_roomscheduler'), ['class' => 'font-weight-bold']);
    echo html_writer::tag('td', $results['total'], ['class' => 'text-right font-weight-bold']);
    echo html_writer::end_tag('tr');

    echo html_writer::start_tag('tr', ['class' => 'table-warning']);
    echo html_writer::tag('td', get_string('totalskipped', 'local_roomscheduler'));
    echo html_writer::tag('td', $results['skipped'], ['class' => 'text-right']);
    echo html_writer::end_tag('tr');

    echo html_writer::end_tag('tbody');
    echo html_writer::end_tag('table');

    // Link to view student results.
    $studentsurl = new moodle_url('/local/roomscheduler/students.php');
    echo html_writer::div(
        html_writer::link($studentsurl,
            'View Student Assignments →',
            ['class' => 'btn btn-primary mt-2']
        ),
        'mt-2'
    );
}

echo $OUTPUT->footer();
