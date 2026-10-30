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
 * Library functions for local_roomscheduler.
 *
 * @package    local_roomscheduler
 * @copyright  2026 Brian Pool
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Returns HTML for the assignment code legend.
 */
function local_roomscheduler_code_legend() {
    $codes = [
        'E' => 'Assigned ELA room',
        'M' => 'Assigned Math room',
        'Q' => 'Teacher requested',
        'F' => 'Assigned due to F',
        'D' => 'Assigned due to D',
        'R' => 'Reward room',
        'S' => 'Displaced to make space',
        'H' => 'Homeroom default',
        'A' => 'Attendance denied reward (suffix)',
        'B' => 'Behavior denied reward (suffix)',
    ];

    $html  = html_writer::start_div('mt-3 p-3 border rounded bg-light');
    $html .= html_writer::tag('strong', 'Assignment Code Key:', ['class' => 'mr-2']);
    $items = [];
    foreach ($codes as $code => $desc) {
        $items[] = html_writer::tag('strong', $code) . ' = ' . $desc;
    }
    $html .= html_writer::tag('small', implode(' &nbsp;|&nbsp; ', $items), ['class' => 'text-muted']);
    $html .= html_writer::end_div();
    return $html;
}

/**
 * Helper function to check if a course belongs to a category
 * or any of its subcategories.
 *
 * @param int $coursecategoryid The category ID of the course.
 * @param int $targetcategoryid The target parent category ID.
 * @return bool
 */
function local_roomscheduler_is_in_category($coursecategoryid, $targetcategoryid) {
    global $DB;

    if (empty($targetcategoryid)) {
        return false;
    }

    // Walk up the category tree until we find the target or hit the top.
    $categoryid = (int)$coursecategoryid;
    $visited    = [];

    while ($categoryid > 0) {
        if ($categoryid === (int)$targetcategoryid) {
            return true;
        }
        if (isset($visited[$categoryid])) {
            break; // Prevent infinite loop.
        }
        $visited[$categoryid] = true;
        $cat = $DB->get_record('course_categories', ['id' => $categoryid], 'id, parent');
        if (!$cat) {
            break;
        }
        $categoryid = (int)$cat->parent;
    }

    return false;
}

/**
 * Adds the "Intervention" link to the course More-menu (admin dropdown only)
 * for the configured counselor course. The primary-tab-bar placement is
 * handled by local_roomscheduler_extend_navigation() below.
 *
 * Note: the old "NTHS Intervention Scheduler" dropdown entry has been removed;
 * access to the scheduler is now via the button on the Intervention Requests page.
 *
 * @param navigation_node $parentnode
 * @param stdClass $course
 * @param context_course $context
 */
function local_roomscheduler_extend_navigation_course($parentnode, $course, $context) {
    global $USER, $DB;

    $counselorcourseid = (int) get_config('local_roomscheduler', 'counselorcourseid');
    $hscategoryid      = (int) get_config('local_roomscheduler', 'hscategoryid');

    $isadmin       = is_siteadmin();
    $managerroleid = $DB->get_field('role', 'id', ['shortname' => 'manager']);
    $ismanager     = !empty($managerroleid) && user_has_role_assignment(
                         $USER->id, $managerroleid, $context->id);

    // --- Single "Intervention" link (HS category courses, teachers/admins) ---
    if (!empty($hscategoryid)) {
        $coursecatid = (int)$course->category;
        if (local_roomscheduler_is_in_category($coursecatid, $hscategoryid)) {
            $teacherroleid  = $DB->get_field('role', 'id', ['shortname' => 'editingteacher']);
            $teacherroleid2 = $DB->get_field('role', 'id', ['shortname' => 'teacher']);
            $isteacher      = user_has_role_assignment($USER->id, $teacherroleid,  $context->id)
                           || user_has_role_assignment($USER->id, $teacherroleid2, $context->id)
                           || $isadmin || $ismanager;

            if ($isteacher) {
                $dow = (int)date('N');
                if ($dow <= 3) {
                    $interventionurl = new moodle_url('/local/roomscheduler/hrattendance.php',
                        ['courseid' => $course->id]);
                } else {
                    $interventionurl = new moodle_url('/local/roomscheduler/intattendance.php',
                        ['courseid' => $course->id]);
                }

                $node = navigation_node::create(
                    get_string('gotointervention', 'local_roomscheduler'),
                    $interventionurl,
                    navigation_node::TYPE_SETTING,
                    null,
                    'local_roomscheduler_intervention',
                    new pix_icon('i/checkedcircle', '')
                );
                $parentnode->add_node($node, 'reports');
            }
        }
    }
}

/**
 * Injects the "Intervention" link into the primary course tab bar
 * (between Grades and Participants) for all courses in the HS category.
 *
 * The link target is day-of-week smart:
 *   Mon/Tue/Wed  → Home Room Attendance page
 *   Thu/Fri      → Intervention Attendance page
 *
 * @param global_navigation $navigation
 */
function local_roomscheduler_extend_navigation(global_navigation $navigation) {
    global $USER, $DB, $COURSE, $PAGE;

    // Only inject on course pages.
    if (!isset($COURSE->id) || $COURSE->id <= 1) {
        return;
    }

    $hscategoryid = (int) get_config('local_roomscheduler', 'hscategoryid');
    if (empty($hscategoryid)) {
        return;
    }

    $course = $COURSE;
    $coursecatid = (int)$course->category;
    if (!local_roomscheduler_is_in_category($coursecatid, $hscategoryid)) {
        return;
    }

    $coursecontext = context_course::instance($course->id);

    $isadmin       = is_siteadmin();
    $managerroleid = $DB->get_field('role', 'id', ['shortname' => 'manager']);
    $ismanager     = !empty($managerroleid) && user_has_role_assignment(
                         $USER->id, $managerroleid, $coursecontext->id);
    $teacherroleid  = $DB->get_field('role', 'id', ['shortname' => 'editingteacher']);
    $teacherroleid2 = $DB->get_field('role', 'id', ['shortname' => 'teacher']);
    $isteacher      = user_has_role_assignment($USER->id, $teacherroleid,  $coursecontext->id)
                   || user_has_role_assignment($USER->id, $teacherroleid2, $coursecontext->id)
                   || $isadmin || $ismanager;

    if (!$isteacher) {
        return;
    }

    // Day-of-week routing: Mon(1)/Tue(2)/Wed(3) → hrattendance,
    // Thu(4)/Fri(5)/Sat(6)/Sun(7) → intattendance.
    $dow = (int)date('N');
    if ($dow <= 3) {
        $interventionurl = new moodle_url('/local/roomscheduler/hrattendance.php',
            ['courseid' => $course->id]);
    } else {
        $interventionurl = new moodle_url('/local/roomscheduler/intattendance.php',
            ['courseid' => $course->id]);
    }

    // Find the course node in the global navigation tree.
    $coursenode = $navigation->find($course->id, navigation_node::TYPE_COURSE);
    if (!$coursenode) {
        return;
    }

    $node = navigation_node::create(
        get_string('gotointervention', 'local_roomscheduler'),
        $interventionurl,
        navigation_node::TYPE_CUSTOM,
        null,
        'local_roomscheduler_intervention',
        new pix_icon('i/checkedcircle', '')
    );
    $node->showinflatnavigation = true;

    // Insert before the 'participants' node so it sits between Grades and Participants.
    // The Moodle secondary nav renders course nodes in order; we find the participants
    // node and splice before it.
    $participantsnode = $coursenode->find('participants', navigation_node::TYPE_CONTAINER);
    if ($participantsnode) {
        $coursenode->add_node($node, 'participants');
    } else {
        $coursenode->add($node);
    }
}

/**
 * Renders the shared tab bar for the four teacher-facing Intervention pages:
 * Home Room Attendance, Intervention Attendance, Intervention Requests, and
 * Request History — plus an optional Intervention Scheduler tab.
 *
 * @param string $current       one of: hrattendance, intattendance, requests, requesthistory
 * @param int    $courseid      current course id, passed through to each tab's URL
 * @param bool   $showscheduler whether to include the Intervention Scheduler tab
 */
function local_roomscheduler_print_intervention_tabs($current, $courseid, $showscheduler, $showbehavior = false) {

    $tabs = [
        'hrattendance' => [
            'label' => get_string('hrattendance', 'local_roomscheduler'),
            'url'   => new moodle_url('/local/roomscheduler/hrattendance.php', ['courseid' => $courseid]),
        ],
        'intattendance' => [
            'label' => get_string('intattendance', 'local_roomscheduler'),
            'url'   => new moodle_url('/local/roomscheduler/intattendance.php', ['courseid' => $courseid]),
        ],
        'requests' => [
            'label' => get_string('gotorequests', 'local_roomscheduler'),
            'url'   => new moodle_url('/local/roomscheduler/requests.php', ['courseid' => $courseid]),
        ],
        'requesthistory' => [
            'label' => get_string('gorequesthistory', 'local_roomscheduler'),
            'url'   => new moodle_url('/local/roomscheduler/requesthistory.php', ['courseid' => $courseid]),
        ],
    ];

    if ($showbehavior) {
        $tabs['behavior'] = [
            'label' => get_string('gobehavior', 'local_roomscheduler'),
            'url'   => new moodle_url('/local/roomscheduler/behavior.php'),
        ];
    }

    if ($showscheduler) {
        $tabs['intscheduler'] = [
            'label' => get_string('gotointscheduler', 'local_roomscheduler'),
            'url'   => new moodle_url('/local/roomscheduler/students.php'),
        ];
    }

    echo html_writer::start_tag('ul', ['class' => 'nav nav-tabs mb-3']);
    foreach ($tabs as $key => $tab) {
        echo html_writer::start_tag('li', ['class' => 'nav-item']);
        if ($key === $current) {
            echo html_writer::tag('span',
                $tab['label'],
                ['class' => 'nav-link active', 'aria-current' => 'page']
            );
        } else {
            echo html_writer::link($tab['url'], $tab['label'], ['class' => 'nav-link']);
        }
        echo html_writer::end_tag('li');
    }
    echo html_writer::end_tag('ul');
}

/**
 * Renders the shared tab bar for the admin/manager "hub" pages: Room Settings,
 * Intervention, Student Settings, View All Requests, Run Scheduler, Attendance
 * Report, Set Levels, Import CSV, Behavior, and Return to Course.
 *
 * @param string $current           one of: rooms, intervention, students, allrequests,
 *                                  scheduler, attendancereport, setlevels, import, behavior, course
 * @param int    $counselorcourseid the counselor course id
 * @param bool   $canmanage         whether the current user can manage (site admin or manager)
 */
function local_roomscheduler_print_admin_tabs($current, $counselorcourseid, $canmanage) {
    global $DB;

    $tabs = [];

    $tabs['intervention'] = [
        'label' => get_string('gotointervention', 'local_roomscheduler'),
        'url'   => new moodle_url('/local/roomscheduler/requests.php', ['courseid' => $counselorcourseid]),
    ];
    $tabs['students'] = [
        'label' => get_string('gotostudents', 'local_roomscheduler'),
        'url'   => new moodle_url('/local/roomscheduler/students.php'),
    ];

    if ($canmanage) {
        $tabs['assignments'] = [
            'label' => get_string('assignments', 'local_roomscheduler'),
            'url'   => new moodle_url('/local/roomscheduler/assignments.php'),
        ];
    }

    $tabs['rooms'] = [
        'label' => get_string('gotorooms', 'local_roomscheduler'),
        'url'   => new moodle_url('/local/roomscheduler/index.php'),
    ];

    if ($canmanage) {
        $tabs['allrequests'] = [
            'label' => get_string('allrequests', 'local_roomscheduler'),
            'url'   => new moodle_url('/local/roomscheduler/allrequests.php'),
        ];
        $tabs['attendancereport'] = [
            'label' => get_string('goattendancereport', 'local_roomscheduler'),
            'url'   => new moodle_url('/local/roomscheduler/attendancereport.php'),
        ];
        $tabs['scheduler'] = [
            'label' => get_string('goscheduler', 'local_roomscheduler'),
            'url'   => new moodle_url('/local/roomscheduler/runscheduler.php'),
        ];
        $tabs['import'] = [
            'label' => get_string('importbutton', 'local_roomscheduler'),
            'url'   => new moodle_url('/local/roomscheduler/import.php'),
        ];

        // Set Levels — only when the NTstatetesting table exists and has data.
        $dbman = $DB->get_manager();
        $slt   = new xmldb_table('NTstatetesting');
        if ($dbman->table_exists($slt)) {
            $tbl = $DB->get_prefix() . 'NTstatetesting';
            if ($DB->count_records_sql("SELECT COUNT(*) FROM `$tbl`") > 0) {
                $tabs['setlevels'] = [
                    'label' => 'Set Levels',
                    'url'   => new moodle_url('/local/roomscheduler/setlevels.php'),
                ];
            }
        }
    }

    $tabs['course'] = [
        'label' => 'Return to Course',
        'url'   => new moodle_url('/course/view.php', ['id' => $counselorcourseid]),
    ];

    echo html_writer::start_tag('ul', ['class' => 'nav nav-tabs mb-3']);
    foreach ($tabs as $key => $tab) {
        echo html_writer::start_tag('li', ['class' => 'nav-item']);
        if ($key === $current) {
            echo html_writer::tag('span',
                $tab['label'],
                ['class' => 'nav-link active', 'aria-current' => 'page']
            );
        } else {
            echo html_writer::link($tab['url'], $tab['label'], ['class' => 'nav-link']);
        }
        echo html_writer::end_tag('li');
    }
    echo html_writer::end_tag('ul');
}

/**
 * Clear every student's Intervention (Mon/Tue/Wed homeroom) AND Th/Fri
 * Intervention assignment for the counselor course, without reassigning
 * anything.
 *
 * @return int the number of students cleared
 */
function local_roomscheduler_clear_intervention_rooms() {
    global $DB;

    $counselorcourseid = (int) get_config('local_roomscheduler', 'counselorcourseid');

    $cleared = $DB->count_records_select(
        'local_roomscheduler_students',
        "courseid = :courseid AND (homeroom != '' OR intervention_room != '')",
        ['courseid' => $counselorcourseid]
    );

    $DB->execute(
        "UPDATE {local_roomscheduler_students}
            SET homeroom = '', intervention_room = '', assignment_code = ''
          WHERE courseid = :courseid",
        ['courseid' => $counselorcourseid]
    );

    return $cleared;
}

/**
 * Reset every student's ELA and Math intervention level back to the
 * default (3 = no intervention needed) for the counselor course. Does NOT
 * touch Intervention room assignments. Intended to be run right before
 * importing a fresh priorities CSV, so stale levels from an earlier
 * import don't linger on students the new file no longer lists.
 *
 * @return int the number of students reset
 */
function local_roomscheduler_clear_intervention_levels() {
    global $DB;

    $counselorcourseid = (int) get_config('local_roomscheduler', 'counselorcourseid');

    $cleared = $DB->count_records_select(
        'local_roomscheduler_students',
        'courseid = :courseid AND (ela_score != 3 OR math_score != 3)',
        ['courseid' => $counselorcourseid]
    );

    $DB->execute(
        "UPDATE {local_roomscheduler_students}
            SET ela_score = 3, math_score = 3, timemodified = :now
          WHERE courseid = :courseid",
        ['now' => time(), 'courseid' => $counselorcourseid]
    );

    return $cleared;
}

/**
 * Reads an Intervention room CSV for the Import Rooms page.
 *
 * Columns are matched by header name (case and spacing ignored), so the
 * Export Assignments file from the Students page imports as-is, and a
 * counselor's file only needs SSID plus the columns being changed:
 *   SSID / SISID                                  -> required, matches u.idnumber
 *   ELA Level / ELA                               -> ela_score (1-3)
 *   Math Level / Math                             -> math_score (1-3)
 *   Intervention Homeroom / Intervention / Homeroom / Mon-Wed -> homeroom
 *   Intervention Thur/Fri / Thur/Fri Intervention / Thur/Fri  -> intervention_room
 * Any other column (name, grade, old Blazer Room) is ignored.
 *
 * A blank room cell means "clear this room". A blank ELA/Math Level is left
 * unchanged. Invalid values are listed and skipped.
 *
 * @param string $filepath       Uploaded file path.
 * @param array  $studentbysisid SIS ID => user record, for counselor-course students.
 * @return array ['rows' => [userid => [field => value]], 'columns' => string[],
 *                'notfound' => string[], 'invalid' => array[], 'error' => string|null]
 */
function local_roomscheduler_import_parse_csv(string $filepath, array $studentbysisid): array {
    $result = ['rows' => [], 'columns' => [], 'notfound' => [], 'invalid' => [], 'error' => null];

    $headermap = [
        'ssid'                  => 'sisid',
        'sisid'                 => 'sisid',
        'sis id'                => 'sisid',
        'ela level'             => 'ela_score',
        'ela'                   => 'ela_score',
        'math level'            => 'math_score',
        'math'                  => 'math_score',
        'intervention homeroom' => 'homeroom',
        'intervention'          => 'homeroom',
        'homeroom'              => 'homeroom',
        'mon-wed'               => 'homeroom',
        'intervention thur/fri' => 'intervention_room',
        'thur/fri intervention' => 'intervention_room',
        'thur/fri'              => 'intervention_room',
        'thurfri'               => 'intervention_room',
    ];

    $handle = fopen($filepath, 'r');
    if ($handle === false) {
        $result['error'] = get_string('importinvalidfile', 'local_roomscheduler');
        return $result;
    }

    // Header row -> column index for each field we know.
    $header = fgetcsv($handle);
    $colfor = [];
    if (is_array($header)) {
        foreach ($header as $i => $name) {
            $name = preg_replace('/^\xEF\xBB\xBF/', '', (string)$name); // Excel BOM.
            $name = strtolower(trim(preg_replace('/\s+/', ' ', $name)));
            if (isset($headermap[$name]) && !isset($colfor[$headermap[$name]])) {
                $colfor[$headermap[$name]] = $i;
            }
        }
    }
    if (!isset($colfor['sisid'])) {
        fclose($handle);
        $result['error'] = get_string('importnosisidcol', 'local_roomscheduler');
        return $result;
    }
    $datafields = array_values(array_diff(array_keys($colfor), ['sisid']));
    if (empty($datafields)) {
        fclose($handle);
        $result['error'] = get_string('importnodatacols', 'local_roomscheduler');
        return $result;
    }
    foreach ($datafields as $field) {
        $result['columns'][] = get_string($field, 'local_roomscheduler');
    }

    while (($row = fgetcsv($handle)) !== false) {
        if (empty(array_filter($row, function($v) {
            return trim((string)$v) !== '';
        }))) {
            continue;
        }
        $sisid = trim((string)($row[$colfor['sisid']] ?? ''));
        if ($sisid === '') {
            continue;
        }
        if (!isset($studentbysisid[$sisid])) {
            $result['notfound'][] = $sisid;
            continue;
        }
        $userid = (int)$studentbysisid[$sisid]->id;

        $values = [];
        foreach ($datafields as $field) {
            $raw = trim((string)($row[$colfor[$field]] ?? ''));
            if ($field === 'ela_score' || $field === 'math_score') {
                if ($raw === '') {
                    continue; // Blank level: leave unchanged.
                }
                if (ctype_digit($raw) && in_array((int)$raw, [1, 2, 3], true)) {
                    $values[$field] = (int)$raw;
                    continue;
                }
            } else {
                if ($raw === '') {
                    $values[$field] = ''; // Blank room: clear it.
                    continue;
                }
                if (ctype_digit($raw) && (int)$raw >= 100 && (int)$raw <= 299) {
                    $values[$field] = (string)(int)$raw;
                    continue;
                }
            }
            $result['invalid'][] = [
                'sisid' => $sisid,
                'field' => get_string($field, 'local_roomscheduler'),
                'value' => $raw,
            ];
        }
        $result['rows'][$userid] = $values;
    }
    fclose($handle);

    return $result;
}

/**
 * Works out what an import would change, compared with what is saved now.
 *
 * @param array $rows     userid => [field => value], from local_roomscheduler_import_parse_csv().
 * @param int   $courseid Counselor course id.
 * @return array userid => ['isnew' => bool, 'id' => int|null, 'fields' => [field => ['old' => mixed, 'new' => mixed]]]
 *               Only students with at least one change are returned.
 */
function local_roomscheduler_import_changes(array $rows, int $courseid): array {
    global $DB;

    $existing = [];
    foreach ($DB->get_records('local_roomscheduler_students', ['courseid' => $courseid]) as $rec) {
        $existing[(int)$rec->userid] = $rec;
    }
    // Values a brand-new record starts with.
    $defaults = ['ela_score' => 3, 'math_score' => 3, 'homeroom' => '', 'intervention_room' => ''];

    $changes = [];
    foreach ($rows as $userid => $values) {
        $rec    = $existing[$userid] ?? null;
        $fields = [];
        foreach ($values as $field => $new) {
            $old = $rec ? $rec->$field : null;
            $cmp = $rec ? $old : $defaults[$field];
            if ((string)$cmp !== (string)$new) {
                $fields[$field] = ['old' => $old, 'new' => $new];
            }
        }
        // A student with no record whose file values match the defaults
        // needs nothing saved, so they are skipped like an unchanged student.
        if (empty($fields)) {
            continue;
        }
        $changes[$userid] = [
            'isnew'  => ($rec === null),
            'id'     => $rec ? (int)$rec->id : null,
            'fields' => $fields,
        ];
    }
    return $changes;
}

/**
 * Saves an import. Changes are worked out again against the database at the
 * moment of saving, and written in one transaction. When a student's
 * Intervention Thur/Fri room changes, their assignment code is cleared,
 * because the old code belonged to the old room. The scheduler sets it again.
 *
 * @param array $rows     userid => [field => value], from local_roomscheduler_import_parse_csv().
 * @param int   $courseid Counselor course id.
 * @return int Number of students added or changed.
 */
function local_roomscheduler_import_apply(array $rows, int $courseid): int {
    global $DB;

    $changes     = local_roomscheduler_import_changes($rows, $courseid);
    $now         = time();
    $transaction = $DB->start_delegated_transaction();

    foreach ($changes as $userid => $change) {
        $rec = new stdClass();
        foreach ($change['fields'] as $field => $vals) {
            $rec->$field = $vals['new'];
        }
        if (isset($change['fields']['intervention_room'])) {
            $rec->assignment_code = '';
        }
        $rec->timemodified = $now;

        if ($change['isnew']) {
            $rec->userid      = $userid;
            $rec->courseid    = $courseid;
            $rec->ela_score         = $rec->ela_score ?? 3;
            $rec->math_score        = $rec->math_score ?? 3;
            $rec->homeroom          = $rec->homeroom ?? '';
            $rec->intervention_room = $rec->intervention_room ?? '';
            $rec->assignment_code   = '';
            $rec->timecreated = $now;
            $DB->insert_record('local_roomscheduler_students', $rec);
        } else {
            $rec->id = $change['id'];
            $DB->update_record('local_roomscheduler_students', $rec);
        }
    }

    $transaction->allow_commit();
    return count($changes);
}

/**
 * Returns the attendance types a teacher's room can record for one student.
 *
 * ELA rooms record E and Math rooms record M. IEP rooms can record either subject
 * for every student in the room, whatever their levels, because everyone in an IEP
 * room is receiving intervention. Only one subject per student per day is saved.
 *
 * On the Mon-Wed (homeroom) page, ELA/Math rooms use the student's score.
 * On the Thur/Fri page, ELA/Math rooms use the assignment code (E or M) instead.
 *
 * @param stdClass $room    The teacher's local_roomscheduler_rooms record.
 * @param stdClass $student Student row with ela_score, math_score and assignment_code.
 * @param bool     $bycode  True on the Thur/Fri page.
 * @return string[] Zero or more of 'E' and 'M'.
 */
function local_roomscheduler_attendance_types(stdClass $room, stdClass $student, bool $bycode): array {
    $elalow  = in_array((int)$student->ela_score, [1, 2], true);
    $mathlow = in_array((int)$student->math_score, [1, 2], true);
    $code    = (string)$student->assignment_code;
    $type    = $room->interventiontype;

    $types = [];
    if ($type === 'IEP') {
        $types = ['E', 'M'];
    } else if (in_array($type, ['ELA1P', 'ELA1', 'ELA2'], true)) {
        if ($bycode ? $code === 'E' : $elalow) {
            $types[] = 'E';
        }
    } else if (in_array($type, ['MTH1P', 'MTH1', 'MTH2'], true)) {
        if ($bycode ? $code === 'M' : $mathlow) {
            $types[] = 'M';
        }
    }
    return $types;
}

/**
 * Loads one teacher's saved attendance for a date.
 *
 * @param int   $teacherid  Teacher user id.
 * @param int[] $studentids Student user ids.
 * @param int   $date       Midnight timestamp of the session date.
 * @return array [studentid][type] => present (0 or 1).
 */
function local_roomscheduler_load_attendance(int $teacherid, array $studentids, int $date): array {
    global $DB;

    if (empty($studentids)) {
        return [];
    }
    list($insql, $params) = $DB->get_in_or_equal($studentids, SQL_PARAMS_NAMED, 'st');
    $params['teacherid']      = $teacherid;
    $params['attendancedate'] = $date;
    $rows = $DB->get_records_select('local_roomscheduler_attendance',
        "teacherid = :teacherid AND attendancedate = :attendancedate AND studentid $insql",
        $params, '', 'id, studentid, attendancetype, present');

    $result = [];
    foreach ($rows as $row) {
        $result[(int)$row->studentid][$row->attendancetype] = (int)$row->present;
    }
    return $result;
}

/**
 * Inserts or updates one attendance record.
 *
 * @param int    $teacherid Teacher user id.
 * @param int    $studentid Student user id.
 * @param string $type      'E' or 'M'.
 * @param int    $date      Midnight timestamp of the session date.
 * @param int    $present   1 = present, 0 = absent.
 * @return void
 */
function local_roomscheduler_save_attendance(int $teacherid, int $studentid, string $type, int $date, int $present): void {
    global $DB;

    $now      = time();
    $existing = $DB->get_record('local_roomscheduler_attendance', [
        'teacherid'      => $teacherid,
        'studentid'      => $studentid,
        'attendancetype' => $type,
        'attendancedate' => $date,
    ], 'id');

    if ($existing) {
        $DB->update_record('local_roomscheduler_attendance', (object)[
            'id'        => $existing->id,
            'present'   => $present,
            'timesaved' => $now,
        ]);
    } else {
        $DB->insert_record('local_roomscheduler_attendance', (object)[
            'teacherid'      => $teacherid,
            'studentid'      => $studentid,
            'attendancetype' => $type,
            'attendancedate' => $date,
            'present'        => $present,
            'timesaved'      => $now,
        ]);
    }
}

/**
 * Saves the posted attendance form for every student the room can track.
 *
 * Checkboxes post as presentela[] and presentmath[]. Any trackable student/type
 * that was not checked is saved as absent. A student may only be credited with one
 * subject per day, so a student posted with both ELA and Math checked is skipped
 * (nothing saved for them) and counted in the return value.
 *
 * @param int   $teacherid    Teacher user id.
 * @param array $studenttypes [studentid] => string[] types, from local_roomscheduler_attendance_types().
 * @param int   $date         Midnight timestamp of the session date.
 * @return int Number of students skipped because both subjects were checked.
 */
function local_roomscheduler_save_attendance_form(int $teacherid, array $studenttypes, int $date): int {
    $checked = [
        'E' => array_flip(optional_param_array('presentela', [], PARAM_INT)),
        'M' => array_flip(optional_param_array('presentmath', [], PARAM_INT)),
    ];
    $skipped = 0;
    foreach ($studenttypes as $sid => $types) {
        if (isset($checked['E'][$sid]) && isset($checked['M'][$sid])) {
            $skipped++;
            continue;
        }
        foreach ($types as $type) {
            $present = isset($checked[$type][$sid]) ? 1 : 0;
            local_roomscheduler_save_attendance($teacherid, (int)$sid, $type, $date, $present);
        }
    }
    return $skipped;
}

/**
 * Returns the attendance column types shown for a room: E, M, or both for IEP rooms.
 *
 * @param stdClass $room The teacher's local_roomscheduler_rooms record.
 * @return string[]
 */
function local_roomscheduler_attendance_columns(stdClass $room): array {
    if ($room->interventiontype === 'IEP') {
        return ['E', 'M'];
    }
    if (in_array($room->interventiontype, ['ELA1P', 'ELA1', 'ELA2'], true)) {
        return ['E'];
    }
    if (in_array($room->interventiontype, ['MTH1P', 'MTH1', 'MTH2'], true)) {
        return ['M'];
    }
    return [];
}

/**
 * Renders the attendance header cell(s) for a room.
 *
 * @param string[] $columns From local_roomscheduler_attendance_columns().
 * @return string HTML.
 */
function local_roomscheduler_attendance_header_cells(array $columns): string {
    if (count($columns) === 1) {
        return html_writer::tag('th', get_string('present', 'local_roomscheduler'), ['style' => 'width:8%']);
    }
    return html_writer::tag('th', get_string('present_ela', 'local_roomscheduler'), ['style' => 'width:8%'])
         . html_writer::tag('th', get_string('present_math', 'local_roomscheduler'), ['style' => 'width:8%']);
}

/**
 * Renders the attendance checkbox cell(s) for one student row.
 *
 * @param int      $uid      Student user id.
 * @param string[] $columns  From local_roomscheduler_attendance_columns().
 * @param string[] $types    Types this student can be marked for.
 * @param array    $existing From local_roomscheduler_load_attendance().
 * @return string HTML.
 */
function local_roomscheduler_attendance_cells(int $uid, array $columns, array $types, array $existing): string {
    $html = '';
    foreach ($columns as $type) {
        if (!in_array($type, $types, true)) {
            $html .= html_writer::tag('td', '—', ['style' => 'text-align:center;color:#ccc;']);
            continue;
        }
        $attrs = [
            'type'  => 'checkbox',
            'name'  => ($type === 'E') ? 'presentela[]' : 'presentmath[]',
            'value' => $uid,
            'class' => 'attendance-cb attendance-cb-' . strtolower($type),
            'title' => ($type === 'E') ? get_string('present_ela', 'local_roomscheduler')
                                       : get_string('present_math', 'local_roomscheduler'),
        ];
        if (count($columns) > 1) {
            // Only one subject per student: checking this box clears the other subject's box.
            $other = ($type === 'E') ? 'm' : 'e';
            $attrs['onchange'] = 'if (this.checked) { var o = document.querySelector(".attendance-cb-' . $other
                . '[value=\'' . $uid . '\']"); if (o) { o.checked = false; } }';
        }
        if (!empty($existing[$uid][$type])) {
            $attrs['checked'] = 'checked';
        }
        $html .= html_writer::tag('td', html_writer::empty_tag('input', $attrs), ['style' => 'text-align:center;']);
    }
    return $html;
}

/**
 * Renders the Check All button(s) for the attendance form.
 *
 * IEP rooms get separate ELA and Math buttons. Each one checks every student for that
 * subject and clears the other subject, since a student can only have one per day.
 *
 * @param string[] $columns From local_roomscheduler_attendance_columns().
 * @return string HTML.
 */
function local_roomscheduler_attendance_checkall_buttons(array $columns): string {
    if (count($columns) === 1) {
        return html_writer::tag('button', get_string('checkall', 'local_roomscheduler'), [
            'type'    => 'button',
            'class'   => 'btn btn-secondary btn-sm mr-2',
            'onclick' => 'document.querySelectorAll(".attendance-cb").forEach(cb => cb.checked = true);',
        ]);
    }
    return html_writer::tag('button', get_string('checkall_ela', 'local_roomscheduler'), [
            'type'    => 'button',
            'class'   => 'btn btn-secondary btn-sm mr-2',
            'onclick' => 'document.querySelectorAll(".attendance-cb-e").forEach(cb => cb.checked = true);'
                       . 'document.querySelectorAll(".attendance-cb-m").forEach(cb => cb.checked = false);',
        ])
        . html_writer::tag('button', get_string('checkall_math', 'local_roomscheduler'), [
            'type'    => 'button',
            'class'   => 'btn btn-secondary btn-sm mr-2',
            'onclick' => 'document.querySelectorAll(".attendance-cb-m").forEach(cb => cb.checked = true);'
                       . 'document.querySelectorAll(".attendance-cb-e").forEach(cb => cb.checked = false);',
        ]);
}

/**
 * Renders the "Class" table cell on the attendance and Assignments pages.
 *
 * Shows the full name of the course whose D/F grade placed the student in
 * their Thur/Fri room. It is only filled for D and F codes (including codes
 * with an A/B suffix). The name is kept on one line, in the table's normal
 * font, and cut to the width of the cell with "…". Hovering shows the whole
 * course name.
 *
 * @param stdClass $student Row with assignment_code and dfcoursename.
 * @return string HTML for the whole <td>.
 */
function local_roomscheduler_class_cell(stdClass $student): string {
    // max-width:0 lets the cell keep its header width instead of growing to
    // fit the course name, so the name is cropped rather than widening the table.
    $tdattrs = ['style' => 'max-width:0;'];
    $code    = (string)($student->assignment_code ?? '');
    $name    = trim((string)($student->dfcoursename ?? ''));
    if ($name === '' || !in_array(substr($code, 0, 1), ['D', 'F'], true)) {
        return html_writer::tag('td', '', $tdattrs);
    }
    $plain = format_string($name, true, ['escape' => false]);
    $inner = html_writer::div(format_string($name), 'roomscheduler-classname', [
        'title' => $plain,
        'style' => 'white-space:nowrap; overflow:hidden; text-overflow:ellipsis;',
    ]);
    return html_writer::tag('td', $inner, $tdattrs);
}

