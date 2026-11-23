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
 * Intervention room scheduling engine for local_roomscheduler.
 *
 * Assignment codes:
 *   E = Moved to ELA room (was in Math HR, needs ELA intervention)
 *   M = Moved to Math room (was in ELA HR, needs Math intervention)
 *   Q = Teacher requested
 *   F = Assigned due to F grade
 *   D = Assigned due to D grade
 *   R = Reward room (all A/B)
 *   S = Displaced to make space for higher priority
 *   H = Homeroom default
 *
 * ELA 1+ and Math 1+ rooms (types ELA1P / MTH1P):
 *   - Cross-subject swap students (E/M) go to the ELA 1+ / Math 1+ room.
 *     If there are more of them than the + room's Max #, the ELA 1 (or
 *     Math 1) teacher with the fewest Mon-Wed students becomes another +
 *     room for this run, and so on, and the swap students are split evenly
 *     across the + rooms. Only if every ELA 1 teacher is already a + room do
 *     extras go on to ELA 2 (or Math 2).
 *   - No one else is placed in a + room: the + teacher's own Mon-Wed students
 *     are moved out (code S), and displacement and leveling skip it.
 *   - A D/F student whose failing course is taught by a + teacher goes to
 *     the least-full ELA 1 (or Math 1) teacher that is not a + room, then
 *     ELA 2 (or Math 2). ELA teachers only teach ELA courses and Math
 *     teachers only teach Math courses, so the + teacher's type tells us
 *     the subject.
 *   - The one exception is a request made by the + teacher. It is always
 *     placed, even past Max #, and no one is bumped for it.
 *   - Any ELA 1 / ELA 2 / Math 1 / Math 2 room that gets no E/M students in
 *     a run is treated as a General room for the rest of that run (D/F,
 *     displacement and leveling), like every other General room.
 *   - For Mon-Wed, ELA 1+ counts as ELA 1 and Math 1+ as Math 1.
 *
 * @package    local_roomscheduler
 * @copyright  2026 Brian Pool
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Main scheduling function.
 */
function local_roomscheduler_run_scheduler() {
    global $DB;

    // Load settings.
    $counselorcourseid = (int)   get_config('local_roomscheduler', 'counselorcourseid');
    $hscategoryid      = (int)   get_config('local_roomscheduler', 'hscategoryid');
    $dfthreshold          = (float) get_config('local_roomscheduler', 'dfthreshold');
    $abthreshold          = (float) get_config('local_roomscheduler', 'abthreshold');
    $attendancethreshold  = (float) get_config('local_roomscheduler', 'attendancethreshold');
    if (empty($attendancethreshold)) {
        $attendancethreshold = 90.0;
    }

    $results = [
        'step0iep'      => 0,
        'lockall'       => 0,
        'step1'         => 0,
        'step1plus'     => 0,
        'subjecttogen'  => 0,
        'plusadded'     => 0,
        'step3plus'     => 0,
        'step2'         => 0,
        'step3'         => 0,
        'step3swap'     => 0,
        'step4'         => 0,
        'step4absent'   => 0,
        'step4behavior' => 0,
        'step5'         => 0,
        'step6'         => 0,
        'displaced'     => 0,
        'skipped'       => 0,
        'total'         => 0,
    ];

    // Step 0a: Archive current requests then clear non-persistent ones.
    $now     = time();
    // Calculate Monday midnight of current week.
    $weekday = (int)date('N'); // ISO day number, Monday is 1 and Sunday is 7.
    $monday  = mktime(
        0,
        0,
        0,
        (int)date('n'),
        (int)date('j') - $weekday + 1,
        (int)date('Y')
    );

    // Get all current checked requests.
    $currentrequests = $DB->get_records_select(
        'local_roomscheduler_requests',
        'requested = 1',
        [],
        '',
        '*'
    );

    // Archive them all.
    foreach ($currentrequests as $req) {
        // Check if already archived for this week.
        $existing = $DB->get_record('local_roomscheduler_request_archive', [
            'userid'    => $req->userid,
            'teacherid' => $req->teacherid,
            'weekof'    => $monday,
        ]);
        if (!$existing) {
            $arc               = new stdClass();
            $arc->userid       = $req->userid;
            $arc->teacherid    = $req->teacherid;
            $arc->weekof       = $monday;
            $arc->timearchived = $now;
            $DB->insert_record('local_roomscheduler_request_archive', $arc);
        }
    }

    // Prune archive — keep only last 4 weeks.
    $fourweeksago = $monday - (4 * 7 * 24 * 60 * 60);
    $DB->delete_records_select(
        'local_roomscheduler_request_archive',
        'weekof < :cutoff',
        ['cutoff' => $fourweeksago]
    );

    // Clear non-persistent requests.
    // Get teachers with persistent=1.
    $persistentteachers = $DB->get_fieldset_select(
        'local_roomscheduler_teacher_prefs',
        'teacherid',
        'persistent = 1'
    );

    if (!empty($persistentteachers)) {
        [$insql, $inparams] = $DB->get_in_or_equal(
            $persistentteachers,
            SQL_PARAMS_NAMED,
            'pt',
            false
        );
        // Delete requests from non-persistent teachers only.
        // get_in_or_equal with false returns NOT IN clause.
        $DB->delete_records_select(
            'local_roomscheduler_requests',
            "teacherid $insql",
            $inparams
        );
    } else {
        // No persistent teachers — clear all requests.
        $DB->delete_records('local_roomscheduler_requests');
    }

    // Wipe all assignments.
    $DB->set_field(
        'local_roomscheduler_students',
        'intervention_room',
        '',
        ['courseid' => $counselorcourseid]
    );
    $DB->set_field(
        'local_roomscheduler_students',
        'assignment_code',
        '',
        ['courseid' => $counselorcourseid]
    );
    $DB->set_field(
        'local_roomscheduler_students',
        'dfcourseid',
        0,
        ['courseid' => $counselorcourseid]
    );

    // Load rooms.
    $roomrecords = $DB->get_records('local_roomscheduler_rooms', null, '', '*');

    // If reward-room scheduling is turned off, treat every room as a normal
    // room this run — no reward pool, no reward round-robin, no exemption
    // from the capacity/displacement pass.
    $rewardsenabled = get_config('local_roomscheduler', 'rewardsenabled');
    if ($rewardsenabled === false || $rewardsenabled === '') {
        $rewardsenabled = 1; // Default on if the setting has never been saved.
    }
    if (!$rewardsenabled) {
        foreach ($roomrecords as $r) {
            $r->isrewardroom = 0;
        }
    }

    // Reward rooms are dedicated to reward students only — excluded from the
    // normal intervention-type pools so steps 1-3 never place students there.
    $rewardrooms = [];
    foreach ($roomrecords as $room) {
        if (!empty($room->isrewardroom)) {
            $rewardrooms[] = $room;
        }
    }

    $roomsbytype = [
        'ELA1P' => [], 'ELA1' => [], 'ELA2' => [],
        'MTH1P' => [], 'MTH1' => [], 'MTH2' => [],
        'GEN'   => [], 'IEP'  => [], 'NA'   => [],
    ];
    foreach ($roomrecords as $room) {
        if (!empty($room->isrewardroom)) {
            continue;
        }
        if (isset($roomsbytype[$room->interventiontype])) {
            $roomsbytype[$room->interventiontype][] = $room;
        }
    }

    // ELA 1+ / Math 1+ rooms only take cross-subject swap students (and
    // requests from that + teacher). teacherid => true.
    $plusrooms = [];
    foreach (array_merge($roomsbytype['ELA1P'], $roomsbytype['MTH1P']) as $room) {
        $plusrooms[$room->teacherid] = true;
    }

    // Each room's type as set on Room Settings, before this run turns any
    // ELA 1 / Math 1 room into an extra + room or into a General room.
    $origtype = [];
    foreach ($roomrecords as $room) {
        $origtype[$room->teacherid] = $room->interventiontype;
    }

    // Map of $roomassigned: teacherid => [userid => userid].
    $roomassigned = [];
    foreach ($roomrecords as $room) {
        $roomassigned[$room->teacherid] = [];
    }

    $roombyteacher = [];
    foreach ($roomrecords as $room) {
        $roombyteacher[$room->teacherid] = $room;
    }

    $roombynumber = [];
    foreach ($roomrecords as $room) {
        $roombynumber[$room->roomnumber] = $room;
    }

    // Load students.
    $studentroleid    = $DB->get_field('role', 'id', ['shortname' => 'student']);
    $counselorcontext = context_course::instance($counselorcourseid);

    $sql = "SELECT DISTINCT u.id, u.firstname, u.lastname, u.idnumber
              FROM {user} u
              JOIN {user_enrolments} ue ON ue.userid = u.id
              JOIN {enrol} e ON e.id = ue.enrolid
              JOIN {role_assignments} ra ON ra.userid = u.id
             WHERE e.courseid = :courseid
               AND ra.contextid = :contextid
               AND ra.roleid = :roleid
               AND u.deleted = 0
               AND u.suspended = 0";
    $allusers = $DB->get_records_sql($sql, [
        'courseid'  => $counselorcourseid,
        'contextid' => $counselorcontext->id,
        'roleid'    => $studentroleid,
    ]);

    $studentrecs = $DB->get_records(
        'local_roomscheduler_students',
        ['courseid' => $counselorcourseid],
        '',
        '*'
    );
    $stubyuser = [];
    foreach ($studentrecs as $s) {
        $stubyuser[$s->userid] = $s;
    }

    // A student with no Mon-Wed (Intervention) room must never be given a
    // Thur/Fri room by any step. Remove them from the working list here, once,
    // so no later step (IEP lock, E/M swap, Q, D/F, reward, homeroom,
    // displacement) can place them. All Thur/Fri rooms were wiped above, so
    // these students are left blank.
    foreach ($allusers as $uid => $u) {
        if (!isset($stubyuser[$uid]) || trim((string)$stubyuser[$uid]->homeroom) === '') {
            unset($allusers[$uid]);
            $results['skipped']++;
        }
    }

    // Lock All Rooms override: everyone stays in their Mon-Wed room for
    // Thur/Fri, coded H, and none of the other steps run at all.
    $lockall = get_config('local_roomscheduler', 'lockall');
    if ($lockall) {
        $now = time();
        foreach ($allusers as $u) {
            $uid = $u->id;
            if (isset($stubyuser[$uid]) && !empty($stubyuser[$uid]->homeroom)) {
                $rec                    = new stdClass();
                $rec->id                = $stubyuser[$uid]->id;
                $rec->intervention_room = $stubyuser[$uid]->homeroom;
                $rec->assignment_code   = 'H';
                $rec->timemodified      = $now;
                $DB->update_record('local_roomscheduler_students', $rec);
                $results['lockall']++;
            } else {
                $results['skipped']++;
            }
        }
        $results['total'] = $results['lockall'];

        set_config('lastrun', $now, 'local_roomscheduler');
        set_config('lastrunresults', json_encode($results), 'local_roomscheduler');

        return $results;
    }

    // Load HS courses and grades.
    $hscourses   = local_roomscheduler_get_hs_courses($hscategoryid);
    $hscourseids = array_keys($hscourses);

    $teacherroleid  = $DB->get_field('role', 'id', ['shortname' => 'editingteacher']);
    $teacherroleid2 = $DB->get_field('role', 'id', ['shortname' => 'teacher']);

    // Two-step query to build teacherCourses reliably.
    // Step 1: Get all context IDs for HS courses.
    // Step 2: Get all teacher role assignments in those contexts.
    // Uses ra.id as first column to avoid get_records_sql key collisions.
    $teachercourses = [];
    if (!empty($hscourseids)) {
        [$insql, $inparams] = $DB->get_in_or_equal($hscourseids, SQL_PARAMS_QM);
        $ctxsql = "SELECT ctx.id, ctx.instanceid as courseid
                     FROM {context} ctx
                     JOIN {course} c ON c.id = ctx.instanceid
                    WHERE ctx.contextlevel = ?
                      AND c.visible = 1
                      AND ctx.instanceid $insql";
        $contexts = $DB->get_records_sql(
            $ctxsql,
            array_merge([CONTEXT_COURSE], $inparams)
        );

        if (!empty($contexts)) {
            $ctxbyid = [];
            foreach ($contexts as $ctx) {
                $ctxbyid[$ctx->id] = $ctx->courseid;
            }
            $contextids = array_keys($contexts);

            [$ctxinsql, $ctxinparams] = $DB->get_in_or_equal($contextids, SQL_PARAMS_QM);
            $rasql = "SELECT ra.id, ra.userid, ra.contextid
                        FROM {role_assignments} ra
                       WHERE ra.contextid $ctxinsql
                         AND (ra.roleid = ? OR ra.roleid = ?)";
            $rarows = $DB->get_records_sql(
                $rasql,
                array_merge($ctxinparams, [$teacherroleid, $teacherroleid2])
            );

            foreach ($rarows as $ra) {
                $courseid = $ctxbyid[$ra->contextid] ?? null;
                if ($courseid) {
                    $teachercourses[$ra->userid][$courseid] = $courseid;
                }
            }
        }
    }

    $studentgrades = local_roomscheduler_get_student_grades($hscourseids, $allusers);

    // Load behavior denial list (userids with denied=1).
    $behaviordenied = [];
    $behaviorrows   = $DB->get_records_select(
        'local_roomscheduler_behavior',
        'denied = 1',
        [],
        '',
        'id, userid'
    );
    foreach ($behaviorrows as $b) {
        $behaviordenied[$b->userid] = true;
    }

    // Load absence data keyed by studentid (matches u.idnumber).
    $absencedata = $DB->get_records('absent', null, '', 'studentid, daysabsent, percentage');
    $tardydata   = $DB->get_records('abtardy', null, '', 'studentid, daystardy');

    // Build adjusted attendance percentage keyed by Moodle userid.
    // absent.studentid matches u.idnumber (SIS ID).
    $adjustedattendance = [];
    foreach ($allusers as $user) {
        $sisid = trim($user->idnumber);
        if (empty($sisid)) {
            $adjustedattendance[$user->id] = 100.0;
            continue;
        }

        $absrow   = $absencedata[$sisid] ?? null;
        $tardyrow = $tardydata[$sisid] ?? null;

        $pct        = $absrow ? (float)$absrow->percentage : 100.0;
        $daysabsent = $absrow ? (float)$absrow->daysabsent : 0.0;
        $daystardy  = $tardyrow ? (float)$tardyrow->daystardy : 0.0;

        // Calculate total school days from absence data.
        // total_days = daysabsent / ((100 - pct) / 100)
        // If pct is 100 (no absences) total_days can't be calculated —
        // use tardy penalty only if we have absence data.
        if ($absrow && $pct < 100 && $daysabsent > 0) {
            $absentpct  = (100 - $pct) / 100;
            $totaldays  = $daysabsent / $absentpct;
            // 4 tardies = 1 absent day.
            $tardypenalty = ($daystardy / 4) / $totaldays * 100;
            $adjustedpct  = $pct - $tardypenalty;
        } else {
            // No absence data — just use 100% minus tardy penalty.
            // Estimate total days as 166 (typical mid-year value).
            $totaldays    = 166.0;
            $tardypenalty = ($daystardy / 4) / $totaldays * 100;
            $adjustedpct  = 100.0 - $tardypenalty;
        }

        $adjustedattendance[$user->id] = round($adjustedpct, 2);
    }

    $studentcourses = [];
    if (!empty($hscourseids) && !empty($allusers)) {
        $uids = array_keys($allusers);
        [$insql, $inparams] = $DB->get_in_or_equal($hscourseids, SQL_PARAMS_NAMED, 'sc');
        [$usql, $uparams]  = $DB->get_in_or_equal($uids, SQL_PARAMS_NAMED, 'su');
        $sql = "SELECT DISTINCT ue.userid, e.courseid
                  FROM {user_enrolments} ue
                  JOIN {enrol} e ON e.id = ue.enrolid
                 WHERE e.courseid $insql AND ue.userid $usql";
        $scrows = $DB->get_records_sql($sql, array_merge($inparams, $uparams));
        foreach ($scrows as $sc) {
            $studentcourses[$sc->userid][$sc->courseid] = $sc->courseid;
        }
    }

    $requestrows = $DB->get_records_select(
        'local_roomscheduler_requests',
        'requested = 1',
        [],
        'timecreated ASC',
        '*'
    );
    $requestsbystudent = [];
    foreach ($requestrows as $req) {
        $requestsbystudent[$req->userid][$req->teacherid] = $req;
    }

    // Each $assigned entry is keyed by userid and holds the room number, code and priority.
    // Priority: 1=highest (E/M), 2=Q, 3=F, 4=D, 5=R, 6=H.
    $assigned = [];

    // HELPER FUNCTIONS.

    $getroomcapacity = function ($teacherid) use ($roombyteacher) {
        return isset($roombyteacher[$teacherid]) ? (int)$roombyteacher[$teacherid]->maxstudents : 0;
    };

    $getroomusage = function ($teacherid) use (&$roomassigned) {
        return count($roomassigned[$teacherid] ?? []);
    };

    $assignstudent = function ($userid, $room, $code, $priority) use (&$assigned, &$roomassigned) {
        $assigned[$userid] = [
            'room'     => $room->roomnumber,
            'code'     => $code,
            'priority' => $priority,
        ];
        $roomassigned[$room->teacherid][$userid] = $userid;
    };

    // Reward rooms: distribute students evenly across all checked reward rooms,
    // ignoring capacity — a reward room may end up over its normal maxstudents.
    $rewardassignedcount = [];
    foreach ($rewardrooms as $rr) {
        $rewardassignedcount[$rr->teacherid] = 0;
    }

    $assigntorewardroom = function (
        $userid,
        $code,
        $priority
    ) use (
        &$assigned,
        &$roomassigned,
        &$rewardassignedcount,
        $rewardrooms
    ) {
        if (empty($rewardrooms)) {
            return false;
        }
        $rooms = $rewardrooms;
        usort($rooms, function ($a, $b) use (&$rewardassignedcount) {
            return $rewardassignedcount[$a->teacherid] <=> $rewardassignedcount[$b->teacherid];
        });
        $room = $rooms[0];
        $assigned[$userid] = [
            'room'     => $room->roomnumber,
            'code'     => $code,
            'priority' => $priority,
        ];
        $roomassigned[$room->teacherid][$userid] = $userid;
        $rewardassignedcount[$room->teacherid]++;
        return true;
    };

    $assigntohomeroom = function (
        $userid,
        $code,
        $priority
    ) use (
        &$assigned,
        &$roomassigned,
        $stubyuser,
        $roombynumber,
        &$plusrooms
    ) {
        if (!isset($stubyuser[$userid])) {
            return false;
        }
        $homeroom = $stubyuser[$userid]->homeroom;
        if (empty($homeroom)) {
            return false;
        }
        if (isset($roombynumber[$homeroom]) && !empty($roombynumber[$homeroom]->isrewardroom)) {
            return false;
        }
        // ELA 1+ / Math 1+ rooms keep only their swap students on Thur/Fri.
        if (isset($roombynumber[$homeroom]) && isset($plusrooms[$roombynumber[$homeroom]->teacherid])) {
            return false;
        }
        // A Max # of 0 means the teacher takes no Thur/Fri students. The
        // capacity pass skips IEP and Not Assigned rooms, so the student
        // must not be placed here. Step 7 finds them a room with space.
        if (isset($roombynumber[$homeroom]) && (int)$roombynumber[$homeroom]->maxstudents <= 0) {
            return false;
        }
        $assigned[$userid] = [
            'room'     => $homeroom,
            'code'     => $code,
            'priority' => $priority,
        ];
        // Track in roomAssigned if room exists.
        if (isset($roombynumber[$homeroom])) {
            $teacherid = $roombynumber[$homeroom]->teacherid;
            $roomassigned[$teacherid][$userid] = $userid;
        }
        return true;
    };

    $unassignstudent = function ($userid) use (&$assigned, &$roomassigned, $roombynumber) {
        if (!isset($assigned[$userid])) {
            return;
        }
        $roomnumber = $assigned[$userid]['room'];
        if (isset($roombynumber[$roomnumber])) {
            $teacherid = $roombynumber[$roomnumber]->teacherid;
            unset($roomassigned[$teacherid][$userid]);
        }
        unset($assigned[$userid]);
    };

    // Get available room of a type — fills rooms evenly within type.
    $getavailableroom = function ($type) use (&$roomsbytype, &$roomassigned, $roombyteacher) {
        $rooms = $roomsbytype[$type] ?? [];
        // Sort by least full first (even distribution within same type).
        usort($rooms, function ($a, $b) use ($roomassigned) {
            return count($roomassigned[$a->teacherid] ?? [])
                 - count($roomassigned[$b->teacherid] ?? []);
        });
        foreach ($rooms as $room) {
            $cap = (int)$room->maxstudents;
            if ($cap > 0 && count($roomassigned[$room->teacherid] ?? []) < $cap) {
                return $room;
            }
        }
        return null;
    };

    // Check if ALL rooms of a type are full.
    $allroomsfull = function ($type) use (&$roomsbytype, &$roomassigned) {
        foreach ($roomsbytype[$type] ?? [] as $room) {
            $cap   = (int)$room->maxstudents;
            $usage = count($roomassigned[$room->teacherid] ?? []);
            if ($cap > 0 && $usage < $cap) {
                return false;
            }
        }
        return true;
    };

    $gethomeroomtype = function ($userid) use ($stubyuser, $roombynumber) {
        if (!isset($stubyuser[$userid])) {
            return null;
        }
        $hr = $stubyuser[$userid]->homeroom;
        if (empty($hr)) {
            return null;
        }
        return isset($roombynumber[$hr]) ? $roombynumber[$hr]->interventiontype : null;
    };

    $hasdfgrade = function ($userid) use ($studentgrades, $dfthreshold) {
        if (!isset($studentgrades[$userid])) {
            return false;
        }
        foreach ($studentgrades[$userid] as $pct) {
            if ($pct < $dfthreshold) {
                return true;
            }
        }
        return false;
    };

    $hasallabgrades = function ($userid) use (
        $studentgrades,
        $abthreshold,
        $adjustedattendance,
        $attendancethreshold
) {
        if (!isset($studentgrades[$userid]) || empty($studentgrades[$userid])) {
            return false;
        }
        foreach ($studentgrades[$userid] as $pct) {
            if ($pct < $abthreshold) {
                return false;
            }
        }
        // Check attendance threshold.
        $attpct = $adjustedattendance[$userid] ?? 100.0;
        if ($attpct < $attendancethreshold) {
            return false;
        }
        return true;
    };

    // Find best displacement room for a student being bumped. Levels the
    // load across rooms first — an emptier room always wins over a fuller
    // one — and only uses "teacher shares a course with this student"
    // (and, among those, the lowest grade in that shared course) to break
    // a tie between rooms that are equally full. This is what actually
    // keeps displaced students spread out instead of all piling into
    // whichever room happens to share a course with the most students.
    $finddisplacementroom = function (
        $userid,
        $excluderoomnumber
    ) use (
        &$roomsbytype,
        &$roomassigned,
        $studentcourses,
        $teachercourses,
        $studentgrades,
        $roombyteacher
) {

        $alltypes   = ['ELA1', 'ELA2', 'MTH1', 'MTH2', 'GEN'];
        $candidates = [];

        foreach ($alltypes as $type) {
            foreach (($roomsbytype[$type] ?? []) as $room) {
                if ($room->roomnumber === $excluderoomnumber) {
                    continue;
                }
                $cap   = (int)$room->maxstudents;
                $usage = count($roomassigned[$room->teacherid] ?? []);
                if ($cap <= 0 || $usage >= $cap) {
                    continue;
                }

                $shared = array_intersect(
                    $studentcourses[$userid] ?? [],
                    $teachercourses[$room->teacherid] ?? []
                );
                $hasshared = !empty($shared);
                $bestpct   = 100;
                foreach ($shared as $cid) {
                    $pct = $studentgrades[$userid][$cid] ?? 100;
                    if ($pct < $bestpct) {
                        $bestpct = $pct;
                    }
                }

                $candidates[] = [
                    'room'      => $room,
                    'usage'     => $usage,
                    'hasshared' => $hasshared,
                    'bestpct'   => $bestpct,
                ];
            }
        }

        if (empty($candidates)) {
            return null;
        }

        usort($candidates, function ($a, $b) {
            if ($a['usage'] !== $b['usage']) {
                return $a['usage'] <=> $b['usage'];
            }
            if ($a['hasshared'] !== $b['hasshared']) {
                return $b['hasshared'] <=> $a['hasshared'];
            }
            return $a['bestpct'] <=> $b['bestpct'];
        });

        return $candidates[0]['room'];
    };

    // Force assign to a room that may be full — displace an H-priority student.
    $forceassign = function (
        $userid,
        $room,
        $code,
        $priority
    ) use (
        &$assigned,
        &$roomassigned,
        &$results,
        $assignstudent,
        $unassignstudent,
        $finddisplacementroom
) {

        // Find lowest priority occupant (H=6 is most displaceable).
        $displaceuid = null;
        $highestpri  = 0;
        foreach ($roomassigned[$room->teacherid] as $ouid) {
            $opri = $assigned[$ouid]['priority'] ?? 0;
            if ($opri > $highestpri && $opri >= 6) {
                $highestpri  = $opri;
                $displaceuid = $ouid;
            }
        }

        if (!$displaceuid) {
            return false;
        }

        $disproom = $finddisplacementroom($displaceuid, $room->roomnumber);
        if (!$disproom) {
            return false;
        }

        $unassignstudent($displaceuid);
        $assignstudent($displaceuid, $disproom, 'S', 7);
        $results['displaced']++;
        $assignstudent($userid, $room, $code, $priority);
        return true;
    };

    // For a full ELA1/MTH1 room, try moving one of the E/M cross-subject-swap
    // occupants (priority=1) to a DIFFERENT room of the same type, so a D/F
    // student can be placed with their own actual teacher. The moved student
    // still ends up with an ELA1 or MTH1 teacher — just not this one.
    $swaptokeepownteacher = function (
        $userid,
        $room,
        $code,
        $priority
    ) use (
        &$assigned,
        &$roomassigned,
        &$results,
        &$roomsbytype,
        $assignstudent,
        $unassignstudent
) {

        $type = $room->interventiontype;
        if (!in_array($type, ['ELA1', 'MTH1'])) {
            return false;
        }

        // Find an E/M priority (cross-subject swap) occupant in this room.
        $swapuid = null;
        foreach ($roomassigned[$room->teacherid] as $ouid) {
            if (($assigned[$ouid]['priority'] ?? 0) === 1) {
                $swapuid = $ouid;
                break;
            }
        }
        if (!$swapuid) {
            return false;
        }

        // Find another room of the SAME type with space.
        $newroom = null;
        foreach (($roomsbytype[$type] ?? []) as $r) {
            if ($r->roomnumber === $room->roomnumber) {
                continue;
            }
            $cap = (int)$r->maxstudents;
            if ($cap > 0 && count($roomassigned[$r->teacherid] ?? []) < $cap) {
                $newroom = $r;
                break;
            }
        }
        if (!$newroom) {
            return false;
        }

        // Move the E/M student to the other same-type room, keeping their code/priority.
        $swapcode     = $assigned[$swapuid]['code'];
        $swappriority = $assigned[$swapuid]['priority'];
        $unassignstudent($swapuid);
        $assignstudent($swapuid, $newroom, $swapcode, $swappriority);
        $results['step3swap']++;

        // Now place the D/F student in the freed slot with their own teacher.
        $assignstudent($userid, $room, $code, $priority);
        return true;
    };

    // STEP 0: Lock IEP students into their assigned IEP room (if enabled).
    $lockiep = get_config('local_roomscheduler', 'lockiep');
    if ($lockiep) {
        foreach ($allusers as $user) {
            $uid = $user->id;
            if (isset($assigned[$uid])) {
                continue;
            }
            if (!isset($stubyuser[$uid])) {
                continue;
            }

            $homeroom = $stubyuser[$uid]->homeroom;
            if (empty($homeroom) || !isset($roombynumber[$homeroom])) {
                continue;
            }

            $room = $roombynumber[$homeroom];
            if ($room->interventiontype !== 'IEP') {
                continue;
            }

            // Priority 0 — lower than anything else, so this student can
            // never be picked up as a displacement candidate later.
            $assignstudent($uid, $room, 'H', 0);
            $results['step0iep']++;
        }
    }

    // STEP 1a: Count the swap students. If there are more than the ELA 1+
    // (or Math 1+) rooms can hold, turn the ELA 1 (or Math 1) teacher with
    // the fewest Mon-Wed students into another + room for this run, and
    // repeat until there is room for everyone or no ELA 1 teacher is left.
    // Nothing happens if no + room is set on Room Settings.
    $swapneed    = ['ELA' => 0, 'MTH' => 0];
    $monwedcount = [];
    foreach ($allusers as $user) {
        $uid = $user->id;
        if (!isset($stubyuser[$uid])) {
            continue;
        }
        $hr = $stubyuser[$uid]->homeroom;
        if (isset($roombynumber[$hr])) {
            $tid = $roombynumber[$hr]->teacherid;
            $monwedcount[$tid] = ($monwedcount[$tid] ?? 0) + 1;
        }
        if (isset($assigned[$uid])) {
            continue;
        }
        $hrtype = $gethomeroomtype($uid);
        if (
            in_array($hrtype, ['ELA1P', 'ELA1', 'ELA2']) &&
                in_array((int)$stubyuser[$uid]->math_score, [1, 2], true)
        ) {
            $swapneed['MTH']++;
        } else if (
            in_array($hrtype, ['MTH1P', 'MTH1', 'MTH2']) &&
                in_array((int)$stubyuser[$uid]->ela_score, [1, 2], true)
        ) {
            $swapneed['ELA']++;
        }
    }

    foreach (['ELA' => ['ELA1P', 'ELA1'], 'MTH' => ['MTH1P', 'MTH1']] as $subject => $types) {
        [$plustype, $basetype] = $types;
        if (empty($roomsbytype[$plustype])) {
            continue;
        }
        $capacity = 0;
        foreach ($roomsbytype[$plustype] as $r) {
            $capacity += max(0, (int)$r->maxstudents);
        }
        $spare = [];
        foreach ($roomsbytype[$basetype] as $r) {
            if ((int)$r->maxstudents > 0) {
                $spare[] = $r;
            }
        }
        usort($spare, function ($a, $b) use ($monwedcount) {
            return ($monwedcount[$a->teacherid] ?? 0) <=> ($monwedcount[$b->teacherid] ?? 0);
        });
        while ($swapneed[$subject] > $capacity && !empty($spare)) {
            $newplus = array_shift($spare);
            $keep    = [];
            foreach ($roomsbytype[$basetype] as $r) {
                if ($r->teacherid != $newplus->teacherid) {
                    $keep[] = $r;
                }
            }
            $roomsbytype[$basetype]        = $keep;
            $newplus->interventiontype     = $plustype; // This run only.
            $roomsbytype[$plustype][]      = $newplus;
            $plusrooms[$newplus->teacherid] = true;
            $capacity += (int)$newplus->maxstudents;
            $results['plusadded']++;
        }
    }

    // STEP 1: Cross-subject swaps (state mandate — highest priority=1)
    // getAvailableRoom() always picks the least-full room of a type, so the
    // swap students are split evenly across the + rooms.

    foreach ([1, 2] as $scorepriority) {
        foreach ($allusers as $user) {
            $uid = $user->id;
            if (isset($assigned[$uid])) {
                continue;
            }
            if (!isset($stubyuser[$uid])) {
                continue;
            }

            $stu       = $stubyuser[$uid];
            $hrtype    = $gethomeroomtype($uid);
            $elascore  = (int)$stu->ela_score;
            $mathscore = (int)$stu->math_score;

            // ELA homeroom + needs math.
            // Fill Math 1+ up to its Max # first, then MTH1 completely
            // before using MTH2.
            if (in_array($hrtype, ['ELA1P', 'ELA1', 'ELA2']) && $mathscore === $scorepriority) {
                $room = $getavailableroom('MTH1P');
                if (!$room) {
                    if (!$allroomsfull('MTH1')) {
                        $room = $getavailableroom('MTH1');
                    } else {
                        $room = $getavailableroom('MTH2');
                    }
                }
                if ($room) {
                    $assignstudent($uid, $room, 'M', 1);
                    $results['step1']++;
                    if (isset($plusrooms[$room->teacherid])) {
                        $results['step1plus']++;
                    }
                } else {
                    // All MTH rooms full — force into MTH1 first, then MTH2.
                    $fullroom = null;
                    foreach (($roomsbytype['MTH1'] ?? []) as $r) {
                        $fullroom = $r;
                        break;
                    }
                    if (!$fullroom) {
                        foreach (($roomsbytype['MTH2'] ?? []) as $r) {
                            $fullroom = $r;
                            break;
                        }
                    }
                    if ($fullroom && $forceassign($uid, $fullroom, 'M', 1)) {
                        $results['step1']++;
                    }
                }
            }

            // MTH homeroom + needs ELA.
            // Fill ELA 1+ up to its Max # first, then ELA1 completely
            // before using ELA2.
            if (
                !isset($assigned[$uid]) &&
                in_array($hrtype, ['MTH1P', 'MTH1', 'MTH2']) && $elascore === $scorepriority
            ) {
                $room = $getavailableroom('ELA1P');
                if (!$room) {
                    if (!$allroomsfull('ELA1')) {
                        $room = $getavailableroom('ELA1');
                    } else {
                        $room = $getavailableroom('ELA2');
                    }
                }
                if ($room) {
                    $assignstudent($uid, $room, 'E', 1);
                    $results['step1']++;
                    if (isset($plusrooms[$room->teacherid])) {
                        $results['step1plus']++;
                    }
                } else {
                    // All ELA rooms full — force into ELA1 first, then ELA2.
                    $fullroom = null;
                    foreach (($roomsbytype['ELA1'] ?? []) as $r) {
                        $fullroom = $r;
                        break;
                    }
                    if (!$fullroom) {
                        foreach (($roomsbytype['ELA2'] ?? []) as $r) {
                            $fullroom = $r;
                            break;
                        }
                    }
                    if ($fullroom && $forceassign($uid, $fullroom, 'E', 1)) {
                        $results['step1']++;
                    }
                }
            }
        }
    }

    // STEP 1b: ELA/Math rooms that got no swap students this run are
    // treated as General rooms for every later step (D/F, displacement and
    // leveling). This only changes the in-memory copy for this run — the
    // Room Settings page is not changed.
    foreach (['ELA1', 'ELA2', 'MTH1', 'MTH2'] as $type) {
        $keep = [];
        foreach ($roomsbytype[$type] as $room) {
            $hasswap = false;
            foreach ($roomassigned[$room->teacherid] ?? [] as $ouid) {
                if (in_array($assigned[$ouid]['code'] ?? '', ['E', 'M'], true)) {
                    $hasswap = true;
                    break;
                }
            }
            if ($hasswap) {
                $keep[] = $room;
            } else {
                $room->interventiontype = 'GEN';
                $roomsbytype['GEN'][]   = $room;
                $results['subjecttogen']++;
            }
        }
        $roomsbytype[$type] = $keep;
    }

    // STEP 2: Teacher requests (priority=2).

    foreach ($allusers as $user) {
        $uid = $user->id;
        if (isset($assigned[$uid])) {
            continue;
        }
        if (!isset($requestsbystudent[$uid])) {
            continue;
        }

        $requests    = $requestsbystudent[$uid];
        $hasdf       = $hasdfgrade($uid);
        $bestteacher = null;

        if ($hasdf) {
            $lowestpct = PHP_INT_MAX;
            foreach ($requests as $teacherid => $req) {
                foreach ($teachercourses[$teacherid] ?? [] as $cid) {
                    $pct = $studentgrades[$uid][$cid] ?? null;
                    if ($pct !== null && $pct < $lowestpct) {
                        $lowestpct   = $pct;
                        $bestteacher = $teacherid;
                    }
                }
            }
        } else {
            $earliest = PHP_INT_MAX;
            foreach ($requests as $teacherid => $req) {
                if ($req->timecreated < $earliest) {
                    $earliest    = $req->timecreated;
                    $bestteacher = $teacherid;
                }
            }
        }

        if ($bestteacher && isset($roombyteacher[$bestteacher])) {
            $room = $roombyteacher[$bestteacher];
            if (!empty($room->isrewardroom)) {
                continue;
            }
            if (in_array($room->interventiontype, ['IEP', 'NA'])) {
                continue;
            }
            $cap   = (int)$room->maxstudents;
            $usage = count($roomassigned[$room->teacherid] ?? []);
            if (isset($plusrooms[$room->teacherid])) {
                // The ELA 1+ / Math 1+ teacher asked for this student, so place
                // them even past Max #. No one is bumped. A Max # of 0 means
                // the teacher is out, so the request is skipped.
                if ($cap > 0) {
                    $assignstudent($uid, $room, 'Q', 2);
                    $results['step2']++;
                }
            } else if ($cap > 0 && $usage < $cap) {
                $assignstudent($uid, $room, 'Q', 2);
                $results['step2']++;
            } else if ($forceassign($uid, $room, 'Q', 2)) {
                $results['step2']++;
            }
        }
    }

    // STEP 3: D/F grade assignments (priority=3 for F, 4 for D).

    $dfassignments = [];
    foreach ($allusers as $user) {
        $uid = $user->id;
        if (isset($assigned[$uid])) {
            continue;
        }
        foreach ($studentgrades[$uid] ?? [] as $courseid => $pct) {
            if ($pct < $dfthreshold) {
                $dfassignments[] = ['userid' => $uid, 'courseid' => $courseid, 'pct' => $pct];
            }
        }
    }
    usort($dfassignments, fn($a, $b) => $a['pct'] <=> $b['pct']);

    // A + teacher never gets D/F students. Send the student to the least-full
    // ELA 1 (or Math 1) teacher that is not a + room, then ELA 2 (or Math 2),
    // bumping an H student if every one of them is full.
    $placedfwithotherteacher = function (
        $userid,
        $plusroom,
        $code,
        $priority
    ) use (
        &$roomassigned,
        &$plusrooms,
        $roomrecords,
        $origtype,
        $assignstudent,
        $forceassign
) {
        $basetypes = ($plusroom->interventiontype === 'ELA1P') ? ['ELA1', 'ELA2'] : ['MTH1', 'MTH2'];
        foreach ($basetypes as $basetype) {
            $rooms = [];
            foreach ($roomrecords as $r) {
                if (($origtype[$r->teacherid] ?? '') !== $basetype) {
                    continue;
                }
                if (isset($plusrooms[$r->teacherid])) {
                    continue;
                }
                if (!empty($r->isrewardroom) || (int)$r->maxstudents <= 0) {
                    continue;
                }
                $rooms[] = $r;
            }
            usort($rooms, function ($a, $b) use (&$roomassigned) {
                return count($roomassigned[$a->teacherid] ?? [])
                     <=> count($roomassigned[$b->teacherid] ?? []);
            });
            foreach ($rooms as $r) {
                if (count($roomassigned[$r->teacherid] ?? []) < (int)$r->maxstudents) {
                    $assignstudent($userid, $r, $code, $priority);
                    return true;
                }
            }
            foreach ($rooms as $r) {
                if ($forceassign($userid, $r, $code, $priority)) {
                    return true;
                }
            }
        }
        return false;
    };

    $step3done = [];
    foreach ($dfassignments as $dfa) {
        $uid = $dfa['userid'];
        if (isset($assigned[$uid]) || isset($step3done[$uid])) {
            continue;
        }

        $code     = ($dfa['pct'] < 60) ? 'F' : 'D';
        $priority = ($dfa['pct'] < 60) ? 3 : 4;

        foreach ($teachercourses as $teacherid => $courses) {
            if (!isset($courses[$dfa['courseid']])) {
                continue;
            }
            if (!isset($roombyteacher[$teacherid])) {
                continue;
            }
            $room = $roombyteacher[$teacherid];
            if (!empty($room->isrewardroom)) {
                continue;
            }
            if (isset($plusrooms[$room->teacherid])) {
                if ($placedfwithotherteacher($uid, $room, $code, $priority)) {
                    $step3done[$uid] = true;
                    $assigned[$uid]['dfcourseid'] = (int)$dfa['courseid']; // Shown as "Class".
                    $results['step3']++;
                    $results['step3plus']++;
                    break;
                }
                continue;
            }
            if (in_array($room->interventiontype, ['IEP', 'NA'])) {
                continue;
            }
            $cap   = (int)$room->maxstudents;
            $usage = count($roomassigned[$room->teacherid] ?? []);
            if ($cap > 0 && $usage < $cap) {
                $assignstudent($uid, $room, $code, $priority);
                $step3done[$uid] = true;
                $assigned[$uid]['dfcourseid'] = (int)$dfa['courseid']; // Shown as "Class".
                $results['step3']++;
                break;
            } else if ($swaptokeepownteacher($uid, $room, $code, $priority)) {
                $step3done[$uid] = true;
                $assigned[$uid]['dfcourseid'] = (int)$dfa['courseid']; // Shown as "Class".
                $results['step3']++;
                break;
            } else if ($forceassign($uid, $room, $code, $priority)) {
                $step3done[$uid] = true;
                $assigned[$uid]['dfcourseid'] = (int)$dfa['courseid']; // Shown as "Class".
                $results['step3']++;
                break;
            }
        }
    }

    // STEP 4: All A/B → reward room (priority=5, no capacity limit)
    // Students denied for attendance get suffix A, behavior gets suffix B.
    // If both, B takes priority over A.
    // Denied students fall through to normal assignment flow (H/S).
    $denialsuffix   = []; // Maps userid => 'A' or 'B' for students denied rewards.
    $rewardeligible = []; // The userids that earned a reward room this run.

    foreach ($allusers as $user) {
        $uid = $user->id;
        if (isset($assigned[$uid])) {
            continue;
        }

        // Check grades only (without attendance).
        $hasgoodgrades = true;
        if (!isset($studentgrades[$uid]) || empty($studentgrades[$uid])) {
            $hasgoodgrades = false;
        } else {
            foreach ($studentgrades[$uid] as $pct) {
                if ($pct < $abthreshold) {
                    $hasgoodgrades = false;
                    break;
                }
            }
        }

        if (!$hasgoodgrades) {
            continue;
        }

        // Has all A/B grades — check for denials.
        $attpct       = $adjustedattendance[$uid] ?? 100.0;
        $attdenied    = ($attpct < $attendancethreshold);
        $behaviorden  = isset($behaviordenied[$uid]);

        if ($attdenied && $behaviorden) {
            // B takes priority.
            $denialsuffix[$uid] = 'B';
            $results['step4absent']++;
            $results['step4behavior']++;
            continue; // Falls through to normal flow.
        }
        if ($behaviorden) {
            $denialsuffix[$uid] = 'B';
            $results['step4behavior']++;
            continue;
        }
        if ($attdenied) {
            $denialsuffix[$uid] = 'A';
            $results['step4absent']++;
            continue;
        }

        // Passes all checks — queue for a reward room.
        $rewardeligible[] = $uid;
    }

    // Reward students whose own homeroom is a reward room stay there, so no
    // one is moved from one reward room to another for no reason. They are
    // placed first so the even distribution below balances around them.
    $rewardremaining = [];
    foreach ($rewardeligible as $uid) {
        $homeroom = $stubyuser[$uid]->homeroom ?? '';
        if (
            $homeroom !== '' && isset($roombynumber[$homeroom]) &&
                !empty($roombynumber[$homeroom]->isrewardroom)
        ) {
            $hrroom = $roombynumber[$homeroom];
            $assignstudent($uid, $hrroom, 'R', 5);
            $rewardassignedcount[$hrroom->teacherid]++;
            $results['step4']++;
        } else {
            $rewardremaining[] = $uid;
        }
    }

    // Everyone else is distributed evenly across the checked reward rooms.
    // If no reward rooms are configured, the student falls through to the
    // normal homeroom/general assignment flow in Steps 5 & 6.
    foreach ($rewardremaining as $uid) {
        if ($assigntorewardroom($uid, 'R', 5)) {
            $results['step4']++;
        }
    }

    // STEP 5 & 6: Everyone else → homeroom (priority=6)
    // Assign to homeroom first, then enforce capacity.

    // Homeroom is a reward room (no reward earned) or an ELA 1+ / Math 1+
    // room. These students are moved to another room in Step 7.
    $rewardhomeroompending = [];
    foreach ($allusers as $user) {
        $uid = $user->id;
        if (isset($assigned[$uid])) {
            continue;
        }
        if (!isset($stubyuser[$uid]) || empty($stubyuser[$uid]->homeroom)) {
            $results['skipped']++;
            continue;
        }
        if ($assigntohomeroom($uid, 'H', 6)) {
            $results['step6']++;
        } else {
            // Homeroom is a reward room or a + room — placed in Step 7 below.
            $rewardhomeroompending[] = $uid;
        }
    }

    // CAPACITY ENFORCEMENT PASS
    // For each room over capacity OR with capacity=0 (teacher absent),
    // displace students to other rooms with code S.
    foreach ($roomrecords as $room) {
        if (in_array($room->interventiontype, ['IEP', 'NA'])) {
            continue;
        }
        if (!empty($room->isrewardroom)) {
            continue;
        }
        // Plus (+) rooms hold only swap students and their teacher's own requests,
        // which may go past Max # on purpose.
        if (isset($plusrooms[$room->teacherid])) {
            continue;
        }
        $cap       = (int)$room->maxstudents;
        $occupants = $roomassigned[$room->teacherid] ?? [];
        $usage     = count($occupants);

        // A cap of 0 means teacher absent — displace ALL students.
        // cap>0 means normal capacity check.
        if ($cap > 0 && $usage <= $cap) {
            continue;
        }
        if ($cap > 0 && $usage == 0) {
            continue;
        }
        if ($cap == 0 && $usage == 0) {
            continue;
        }

        // Sort occupants by priority descending (highest = most displaceable first).
        $sorted = array_keys($occupants);
        usort($sorted, function ($a, $b) use ($assigned) {
            return ($assigned[$b]['priority'] ?? 0) <=> ($assigned[$a]['priority'] ?? 0);
        });

        // Displace until within capacity (or all gone if cap=0).
        foreach ($sorted as $ouid) {
            $currentusage = count($roomassigned[$room->teacherid]);
            // Stop if within capacity (for cap>0 rooms).
            if ($cap > 0 && $currentusage <= $cap) {
                break;
            }
            // For cap=0 rooms, displace everyone regardless of priority.
            $opri = $assigned[$ouid]['priority'] ?? 0;
            if ($cap > 0 && $opri < 6) {
                break; // Don't displace high-priority from normal rooms.
            }

            $disproom = $finddisplacementroom($ouid, $room->roomnumber);
            if ($disproom) {
                $unassignstudent($ouid);
                $assignstudent($ouid, $disproom, 'S', 7);
                $results['displaced']++;
            } else if ($cap == 0) {
                // No room with space, and this room takes no one. Hand the
                // student to Step 7, which uses the least-full room if needed.
                $unassignstudent($ouid);
                $rewardhomeroompending[] = $ouid;
            } else {
                // No displacement room found — assign to homeroom as fallback.
                $unassignstudent($ouid);
                if (isset($stubyuser[$ouid]) && !empty($stubyuser[$ouid]->homeroom)) {
                    $assigned[$ouid] = [
                        'room'     => $stubyuser[$ouid]->homeroom,
                        'code'     => 'S',
                        'priority' => 7,
                    ];
                }
            }
        }
    }

    // STEP 7: Homeroom is a reward room but the student did not earn a
    // reward, or homeroom is an ELA 1+ / Math 1+ room -> move to a normal
    // room with space (code S). Runs after the capacity pass so these
    // students never push anyone else out. + rooms are never used here.
    foreach ($rewardhomeroompending as $uid) {
        $homeroom = $stubyuser[$uid]->homeroom;
        $newroom  = $finddisplacementroom($uid, $homeroom);

        if (!$newroom) {
            // Every non-reward room is full — use the least-full one anyway
            // rather than leave the student without a Thur/Fri room.
            foreach (['GEN', 'ELA2', 'MTH2', 'ELA1', 'MTH1'] as $type) {
                foreach (($roomsbytype[$type] ?? []) as $r) {
                    if ((int)$r->maxstudents <= 0) {
                        continue;
                    }
                    $rcount = count($roomassigned[$r->teacherid] ?? []);
                    if (!$newroom || count($roomassigned[$newroom->teacherid] ?? []) > $rcount) {
                        $newroom = $r;
                    }
                }
            }
        }

        if ($newroom) {
            $assignstudent($uid, $newroom, 'S', 7);
            $results['displaced']++;
        } else {
            $results['skipped']++;
        }
    }

    // STEP 8: Level the General rooms.
    // The capacity pass only moves students out of a room that is over its
    // cap, so one General room can sit at 20 while another sits at 10. Here
    // we move a few H (homeroom) or S (already displaced) students from the
    // fullest General room to the emptiest one until the gap between them is
    // no more than $levelgap. D/F/Q/E/M students are never moved, and reward
    // rooms are neither donors nor receivers. The gap is set on the Run
    // Scheduler page (default 4); 0 turns leveling off.
    $levelgap = get_config('local_roomscheduler', 'levelgap');
    $levelgap = ($levelgap === false || $levelgap === '') ? 4 : (int)$levelgap;

    $levelrooms = [];
    foreach (($levelgap > 0 ? ($roomsbytype['GEN'] ?? []) : []) as $r) {
        if (!empty($r->isrewardroom) || (int)$r->maxstudents <= 0) {
            continue;
        }
        $levelrooms[] = $r;
    }

    // Each move closes the gap by 2, so this limit can never be reached in
    // normal use. It is only a safety net against an endless loop.
    $maxmoves = count($assigned);
    for ($moves = 0; $moves < $maxmoves && count($levelrooms) > 1; $moves++) {
        // General rooms include ELA/Math rooms that got no swap students this
        // run (Step 1b). + rooms are never General, so they are never leveled.
        // Fullest room that still has a movable (H or S) student.
        $donor      = null;
        $donorcount = -1;
        // Emptiest room that is still under its own cap.
        $receiver      = null;
        $receivercount = PHP_INT_MAX;

        foreach ($levelrooms as $r) {
            $count = count($roomassigned[$r->teacherid] ?? []);
            if ($count < (int)$r->maxstudents && $count < $receivercount) {
                $receiver      = $r;
                $receivercount = $count;
            }
            if ($count > $donorcount) {
                foreach ($roomassigned[$r->teacherid] ?? [] as $ouid) {
                    if (($assigned[$ouid]['priority'] ?? 0) >= 6) {
                        $donor      = $r;
                        $donorcount = $count;
                        break;
                    }
                }
            }
        }

        if (!$donor || !$receiver || $donor->teacherid == $receiver->teacherid) {
            break;
        }
        if ($donorcount - $receivercount <= $levelgap) {
            break;
        }

        // Pick the student to move: prefer one who has a class with the
        // receiving teacher, otherwise the first H/S student in the room.
        $moveuid = null;
        foreach ($roomassigned[$donor->teacherid] as $ouid) {
            if (($assigned[$ouid]['priority'] ?? 0) < 6) {
                continue;
            }
            if ($moveuid === null) {
                $moveuid = $ouid;
            }
            $shared = array_intersect(
                $studentcourses[$ouid] ?? [],
                $teachercourses[$receiver->teacherid] ?? []
            );
            if (!empty($shared)) {
                $moveuid = $ouid;
                break;
            }
        }

        $unassignstudent($moveuid);
        $assignstudent($moveuid, $receiver, 'S', 7);
        $results['displaced']++;
    }

    // Apply denial suffixes to final assignments (A or B).
    foreach ($denialsuffix as $uid => $suffix) {
        if (isset($assigned[$uid])) {
            $assigned[$uid]['code'] = $assigned[$uid]['code'] . $suffix;
        }
    }

    // Write to database.

    $now = time();
    foreach ($assigned as $userid => $data) {
        if (isset($stubyuser[$userid])) {
            $rec                    = new stdClass();
            $rec->id                = $stubyuser[$userid]->id;
            $rec->intervention_room = $data['room'];
            $rec->assignment_code   = $data['code'];
            $rec->dfcourseid        = $data['dfcourseid'] ?? 0;
            $rec->timemodified      = $now;
            $DB->update_record('local_roomscheduler_students', $rec);
        }
    }

    $results['total'] = array_sum([
        $results['step1'], $results['step2'], $results['step3'],
        $results['step4'], $results['step5'], $results['step6'],
    ]);

    set_config('lastrun', $now, 'local_roomscheduler');
    set_config('lastrunresults', json_encode($results), 'local_roomscheduler');

    return $results;
}

/**
 * Runs the scheduler as a dry run, without saving anything.
 *
 * The full scheduler runs inside a database transaction that is always rolled
 * back. The counts are exactly what a real run would give right now with the
 * current settings, but no Thur/Fri room, assignment code, teacher request,
 * request archive row or Last run value is changed.
 *
 * @return array The same results array as local_roomscheduler_run_scheduler().
 */
function local_roomscheduler_dry_run_scheduler() {
    global $DB;

    $transaction = $DB->start_delegated_transaction();
    try {
        $results = local_roomscheduler_run_scheduler();
    } catch (\Throwable $e) {
        // Undo everything and pass the real error on.
        $transaction->rollback($e);
    }

    // Always undo the run. rollback() throws the exception it is given, so
    // catch our own marker exception and let anything else through.
    try {
        $transaction->rollback(new \moodle_exception('dryrunrollback', 'local_roomscheduler'));
    } catch (\moodle_exception $e) {
        if ($e->errorcode !== 'dryrunrollback') {
            throw $e;
        }
    }

    // The run called set_config() for lastrun and lastrunresults inside the
    // rolled-back transaction. Clear the plugin's config cache so the next
    // get_config() reads the restored values from the database.
    cache_helper::invalidate_by_definition('core', 'config', [], 'local_roomscheduler');

    return $results;
}

/**
 * Get all visible HS courses in the configured category and subcategories.
 *
 * @param int $categoryid the top-level course category id to search from
 * @return array course records keyed by course id
 */
function local_roomscheduler_get_hs_courses($categoryid) {
    global $DB;
    if (empty($categoryid)) {
        return [];
    }

    $catids  = [$categoryid];
    $tocheck = [$categoryid];
    while (!empty($tocheck)) {
        [$insql, $inparams] = $DB->get_in_or_equal($tocheck, SQL_PARAMS_NAMED, 'cat');
        $subcats = $DB->get_fieldset_select('course_categories', 'id', "parent $insql", $inparams);
        $tocheck = array_diff($subcats, $catids);
        $catids  = array_merge($catids, $subcats);
    }
    if (empty($catids)) {
        return [];
    }

    [$insql, $inparams] = $DB->get_in_or_equal($catids, SQL_PARAMS_NAMED, 'cat');
    return $DB->get_records_select('course', "category $insql AND visible = 1", $inparams);
}

/**
 * Get student grades for all HS courses.
 *
 * Only grades from courses where the student has an active enrolment are
 * returned. Moodle keeps grade_grades rows after a student is unenrolled or
 * suspended (schedule changes, last year's courses), and those old grades
 * must never place a student in a D/F room or block a reward.
 *
 * An enrolment is active when the user enrolment is active, the enrolment
 * method is enabled, and the current time is inside its start/end dates.
 *
 * @param array $courseids HS course ids.
 * @param array $users Student records keyed by user id.
 * @return array Grade percentages as [userid][courseid] => pct.
 */
function local_roomscheduler_get_student_grades($courseids, $users) {
    global $DB;
    if (empty($courseids) || empty($users)) {
        return [];
    }

    $userids = array_keys($users);
    [$coursesql, $courseparams] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'crs');
    [$usersql, $userparams]   = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'usr');

    $now = time();

    $sql = "SELECT gg.id, gg.userid, gi.courseid,
                   gg.finalgrade, gi.grademax
              FROM {grade_grades} gg
              JOIN {grade_items} gi ON gi.id = gg.itemid
              JOIN {course} c ON c.id = gi.courseid
             WHERE gi.itemtype = 'course'
               AND gi.courseid $coursesql
               AND gg.userid $usersql
               AND gg.finalgrade IS NOT NULL
               AND gi.grademax > 0
               AND c.visible = 1
               AND c.idnumber REGEXP '^[0-9]{3,4}[A-Za-z]{2}$'
               AND EXISTS (
                       SELECT 1
                         FROM {user_enrolments} ue
                         JOIN {enrol} e ON e.id = ue.enrolid
                        WHERE ue.userid = gg.userid
                          AND e.courseid = gi.courseid
                          AND ue.status = :uestatus
                          AND e.status = :estatus
                          AND ue.timestart <= :now1
                          AND (ue.timeend = 0 OR ue.timeend > :now2)
                   )";

    $params = array_merge($courseparams, $userparams, [
        'uestatus' => ENROL_USER_ACTIVE,
        'estatus'  => ENROL_INSTANCE_ENABLED,
        'now1'     => $now,
        'now2'     => $now,
    ]);

    $rows   = $DB->get_records_sql($sql, $params);
    $grades = [];
    foreach ($rows as $row) {
        $grades[$row->userid][$row->courseid] = round(($row->finalgrade / $row->grademax) * 100, 2);
    }
    return $grades;
}
