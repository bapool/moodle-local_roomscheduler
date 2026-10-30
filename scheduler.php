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

defined('MOODLE_INTERNAL') || die();

/**
 * Main scheduling function.
 */
function local_roomscheduler_run_scheduler() {
    global $DB;

    // --- Load settings ---
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
    
    // --- Step 0a: Archive current requests then clear non-persistent ones ---
    $now     = time();
    // Calculate Monday midnight of current week.
    $weekday = (int)date('N'); // 1=Mon, 7=Sun.
    $monday  = mktime(0, 0, 0,
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
        list($insql, $inparams) = $DB->get_in_or_equal(
            $persistentteachers, SQL_PARAMS_NAMED, 'pt', false
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

    // --- Wipe all assignments ---
    $DB->set_field('local_roomscheduler_students', 'intervention_room', '',
        ['courseid' => $counselorcourseid]);
    $DB->set_field('local_roomscheduler_students', 'assignment_code', '',
        ['courseid' => $counselorcourseid]);
    $DB->set_field('local_roomscheduler_students', 'dfcourseid', 0,
        ['courseid' => $counselorcourseid]);

    // --- Load rooms ---
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
    $rewardRooms = [];
    foreach ($roomrecords as $room) {
        if (!empty($room->isrewardroom)) {
            $rewardRooms[] = $room;
        }
    }

    $roomsByType = [
        'ELA1P' => [], 'ELA1' => [], 'ELA2' => [],
        'MTH1P' => [], 'MTH1' => [], 'MTH2' => [],
        'GEN'   => [], 'IEP'  => [], 'NA'   => [],
    ];
    foreach ($roomrecords as $room) {
        if (!empty($room->isrewardroom)) {
            continue;
        }
        if (isset($roomsByType[$room->interventiontype])) {
            $roomsByType[$room->interventiontype][] = $room;
        }
    }

    // ELA 1+ / Math 1+ rooms only take cross-subject swap students (and
    // requests from that + teacher). teacherid => true.
    $plusRooms = [];
    foreach (array_merge($roomsByType['ELA1P'], $roomsByType['MTH1P']) as $room) {
        $plusRooms[$room->teacherid] = true;
    }

    // Each room's type as set on Room Settings, before this run turns any
    // ELA 1 / Math 1 room into an extra + room or into a General room.
    $origType = [];
    foreach ($roomrecords as $room) {
        $origType[$room->teacherid] = $room->interventiontype;
    }

    // roomAssigned: teacherid => [userid => userid]
    $roomAssigned = [];
    foreach ($roomrecords as $room) {
        $roomAssigned[$room->teacherid] = [];
    }

    $roomByTeacher = [];
    foreach ($roomrecords as $room) {
        $roomByTeacher[$room->teacherid] = $room;
    }

    $roomByNumber = [];
    foreach ($roomrecords as $room) {
        $roomByNumber[$room->roomnumber] = $room;
    }

    // --- Load students ---
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
        '', '*'
    );
    $stuByUser = [];
    foreach ($studentrecs as $s) {
        $stuByUser[$s->userid] = $s;
    }

    // A student with no Mon-Wed (Intervention) room must never be given a
    // Thur/Fri room by any step. Remove them from the working list here, once,
    // so no later step (IEP lock, E/M swap, Q, D/F, reward, homeroom,
    // displacement) can place them. All Thur/Fri rooms were wiped above, so
    // these students are left blank.
    foreach ($allusers as $uid => $u) {
        if (!isset($stuByUser[$uid]) || trim((string)$stuByUser[$uid]->homeroom) === '') {
            unset($allusers[$uid]);
            $results['skipped']++;
        }
    }

    // --- Lock All Rooms override: everyone stays in their Mon-Wed room for
    // Thur/Fri, coded H, and none of the other steps run at all. ---
    $lockall = get_config('local_roomscheduler', 'lockall');
    if ($lockall) {
        $now = time();
        foreach ($allusers as $u) {
            $uid = $u->id;
            if (isset($stuByUser[$uid]) && !empty($stuByUser[$uid]->homeroom)) {
                $rec                    = new stdClass();
                $rec->id                = $stuByUser[$uid]->id;
                $rec->intervention_room = $stuByUser[$uid]->homeroom;
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

    // --- Load HS courses and grades ---
    $hscourses   = local_roomscheduler_get_hs_courses($hscategoryid);
    $hscourseids = array_keys($hscourses);

    $teacherroleid  = $DB->get_field('role', 'id', ['shortname' => 'editingteacher']);
    $teacherroleid2 = $DB->get_field('role', 'id', ['shortname' => 'teacher']);

    // Two-step query to build teacherCourses reliably.
    // Step 1: Get all context IDs for HS courses.
    // Step 2: Get all teacher role assignments in those contexts.
    // Uses ra.id as first column to avoid get_records_sql key collisions.
    $teacherCourses = [];
    if (!empty($hscourseids)) {
        list($insql, $inparams) = $DB->get_in_or_equal($hscourseids, SQL_PARAMS_QM);
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

            list($ctxinsql, $ctxinparams) = $DB->get_in_or_equal($contextids, SQL_PARAMS_QM);
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
                    $teacherCourses[$ra->userid][$courseid] = $courseid;
                }
            }
        }
    }

    $studentGrades = local_roomscheduler_get_student_grades($hscourseids, $allusers);

    // Load behavior denial list (userids with denied=1).
    $behaviorDenied = [];
    $behaviorRows   = $DB->get_records_select(
        'local_roomscheduler_behavior',
        'denied = 1',
        [],
        '',
        'id, userid'
    );
    foreach ($behaviorRows as $b) {
        $behaviorDenied[$b->userid] = true;
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

        $absrow   = $absencedata[$sisid]  ?? null;
        $tardyrow = $tardydata[$sisid]    ?? null;

        $pct        = $absrow  ? (float)$absrow->percentage  : 100.0;
        $daysabsent = $absrow  ? (float)$absrow->daysabsent  : 0.0;
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

    $studentCourses = [];
    if (!empty($hscourseids) && !empty($allusers)) {
        $uids = array_keys($allusers);
        list($insql, $inparams) = $DB->get_in_or_equal($hscourseids, SQL_PARAMS_NAMED, 'sc');
        list($usql,  $uparams)  = $DB->get_in_or_equal($uids, SQL_PARAMS_NAMED, 'su');
        $sql = "SELECT DISTINCT ue.userid, e.courseid
                  FROM {user_enrolments} ue
                  JOIN {enrol} e ON e.id = ue.enrolid
                 WHERE e.courseid $insql AND ue.userid $usql";
        $scrows = $DB->get_records_sql($sql, array_merge($inparams, $uparams));
        foreach ($scrows as $sc) {
            $studentCourses[$sc->userid][$sc->courseid] = $sc->courseid;
        }
    }

    $requestRows = $DB->get_records_select(
        'local_roomscheduler_requests', 'requested = 1', [], 'timecreated ASC', '*'
    );
    $requestsByStudent = [];
    foreach ($requestRows as $req) {
        $requestsByStudent[$req->userid][$req->teacherid] = $req;
    }

    // --- assigned: userid => ['room' => roomnumber, 'code' => code, 'priority' => int] ---
    // Priority: 1=highest (E/M), 2=Q, 3=F, 4=D, 5=R, 6=H
    $assigned = [];

    // =========================================================
    // HELPER FUNCTIONS
    // =========================================================

    $getRoomCapacity = function($teacherid) use ($roomByTeacher) {
        return isset($roomByTeacher[$teacherid]) ? (int)$roomByTeacher[$teacherid]->maxstudents : 0;
    };

    $getRoomUsage = function($teacherid) use (&$roomAssigned) {
        return count($roomAssigned[$teacherid] ?? []);
    };

    $assignStudent = function($userid, $room, $code, $priority)
        use (&$assigned, &$roomAssigned) {
        $assigned[$userid] = [
            'room'     => $room->roomnumber,
            'code'     => $code,
            'priority' => $priority,
        ];
        $roomAssigned[$room->teacherid][$userid] = $userid;
    };

    // Reward rooms: distribute students evenly across all checked reward rooms,
    // ignoring capacity — a reward room may end up over its normal maxstudents.
    $rewardAssignedCount = [];
    foreach ($rewardRooms as $rr) {
        $rewardAssignedCount[$rr->teacherid] = 0;
    }

    $assignToRewardRoom = function($userid, $code, $priority)
        use (&$assigned, &$roomAssigned, &$rewardAssignedCount, $rewardRooms) {
        if (empty($rewardRooms)) {
            return false;
        }
        $rooms = $rewardRooms;
        usort($rooms, function($a, $b) use (&$rewardAssignedCount) {
            return $rewardAssignedCount[$a->teacherid] <=> $rewardAssignedCount[$b->teacherid];
        });
        $room = $rooms[0];
        $assigned[$userid] = [
            'room'     => $room->roomnumber,
            'code'     => $code,
            'priority' => $priority,
        ];
        $roomAssigned[$room->teacherid][$userid] = $userid;
        $rewardAssignedCount[$room->teacherid]++;
        return true;
    };

    $assignToHomeroom = function($userid, $code, $priority)
        use (&$assigned, &$roomAssigned, $stuByUser, $roomByNumber, &$plusRooms) {
        if (!isset($stuByUser[$userid])) {
            return false;
        }
        $homeroom = $stuByUser[$userid]->homeroom;
        if (empty($homeroom)) {
            return false;
        }
        if (isset($roomByNumber[$homeroom]) && !empty($roomByNumber[$homeroom]->isrewardroom)) {
            return false;
        }
        // ELA 1+ / Math 1+ rooms keep only their swap students on Thur/Fri.
        if (isset($roomByNumber[$homeroom]) && isset($plusRooms[$roomByNumber[$homeroom]->teacherid])) {
            return false;
        }
        $assigned[$userid] = [
            'room'     => $homeroom,
            'code'     => $code,
            'priority' => $priority,
        ];
        // Track in roomAssigned if room exists.
        if (isset($roomByNumber[$homeroom])) {
            $teacherid = $roomByNumber[$homeroom]->teacherid;
            $roomAssigned[$teacherid][$userid] = $userid;
        }
        return true;
    };

    $unassignStudent = function($userid) use (&$assigned, &$roomAssigned, $roomByNumber) {
        if (!isset($assigned[$userid])) {
            return;
        }
        $roomnumber = $assigned[$userid]['room'];
        if (isset($roomByNumber[$roomnumber])) {
            $teacherid = $roomByNumber[$roomnumber]->teacherid;
            unset($roomAssigned[$teacherid][$userid]);
        }
        unset($assigned[$userid]);
    };

    // Get available room of a type — fills rooms evenly within type.
    $getAvailableRoom = function($type) use (&$roomsByType, &$roomAssigned, $roomByTeacher) {
        $rooms = $roomsByType[$type] ?? [];
        // Sort by least full first (even distribution within same type).
        usort($rooms, function($a, $b) use ($roomAssigned) {
            return count($roomAssigned[$a->teacherid] ?? [])
                 - count($roomAssigned[$b->teacherid] ?? []);
        });
        foreach ($rooms as $room) {
            $cap = (int)$room->maxstudents;
            if ($cap > 0 && count($roomAssigned[$room->teacherid] ?? []) < $cap) {
                return $room;
            }
        }
        return null;
    };

    // Check if ALL rooms of a type are full.
    $allRoomsFull = function($type) use (&$roomsByType, &$roomAssigned) {
        foreach ($roomsByType[$type] ?? [] as $room) {
            $cap   = (int)$room->maxstudents;
            $usage = count($roomAssigned[$room->teacherid] ?? []);
            if ($cap > 0 && $usage < $cap) {
                return false;
            }
        }
        return true;
    };

    $getHomeroomType = function($userid) use ($stuByUser, $roomByNumber) {
        if (!isset($stuByUser[$userid])) return null;
        $hr = $stuByUser[$userid]->homeroom;
        if (empty($hr)) return null;
        return isset($roomByNumber[$hr]) ? $roomByNumber[$hr]->interventiontype : null;
    };

    $hasDFGrade = function($userid) use ($studentGrades, $dfthreshold) {
        if (!isset($studentGrades[$userid])) return false;
        foreach ($studentGrades[$userid] as $pct) {
            if ($pct < $dfthreshold) return true;
        }
        return false;
    };

    $hasAllABGrades = function($userid) use ($studentGrades, $abthreshold,
                                              $adjustedattendance, $attendancethreshold) {
        if (!isset($studentGrades[$userid]) || empty($studentGrades[$userid])) return false;
        foreach ($studentGrades[$userid] as $pct) {
            if ($pct < $abthreshold) return false;
        }
        // Check attendance threshold.
        $attPct = $adjustedattendance[$userid] ?? 100.0;
        if ($attPct < $attendancethreshold) return false;
        return true;
    };

    // Find best displacement room for a student being bumped. Levels the
    // load across rooms first — an emptier room always wins over a fuller
    // one — and only uses "teacher shares a course with this student"
    // (and, among those, the lowest grade in that shared course) to break
    // a tie between rooms that are equally full. This is what actually
    // keeps displaced students spread out instead of all piling into
    // whichever room happens to share a course with the most students.
    $findDisplacementRoom = function($userid, $excludeRoomNumber)
        use (&$roomsByType, &$roomAssigned, $studentCourses,
             $teacherCourses, $studentGrades, $roomByTeacher) {

        $alltypes   = ['ELA1', 'ELA2', 'MTH1', 'MTH2', 'GEN'];
        $candidates = [];

        foreach ($alltypes as $type) {
            foreach (($roomsByType[$type] ?? []) as $room) {
                if ($room->roomnumber === $excludeRoomNumber) continue;
                $cap   = (int)$room->maxstudents;
                $usage = count($roomAssigned[$room->teacherid] ?? []);
                if ($cap <= 0 || $usage >= $cap) continue;

                $shared = array_intersect(
                    $studentCourses[$userid] ?? [],
                    $teacherCourses[$room->teacherid] ?? []
                );
                $hasshared = !empty($shared);
                $bestpct   = 100;
                foreach ($shared as $cid) {
                    $pct = $studentGrades[$userid][$cid] ?? 100;
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

        usort($candidates, function($a, $b) {
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
    $forceAssign = function($userid, $room, $code, $priority)
        use (&$assigned, &$roomAssigned, &$results,
             $assignStudent, $unassignStudent, $findDisplacementRoom) {

        // Find lowest priority occupant (H=6 is most displaceable).
        $displaceuid = null;
        $highestpri  = 0;
        foreach ($roomAssigned[$room->teacherid] as $ouid) {
            $opri = $assigned[$ouid]['priority'] ?? 0;
            if ($opri > $highestpri && $opri >= 6) {
                $highestpri  = $opri;
                $displaceuid = $ouid;
            }
        }

        if (!$displaceuid) return false;

        $disproom = $findDisplacementRoom($displaceuid, $room->roomnumber);
        if (!$disproom) return false;

        $unassignStudent($displaceuid);
        $assignStudent($displaceuid, $disproom, 'S', 7);
        $results['displaced']++;
        $assignStudent($userid, $room, $code, $priority);
        return true;
    };

    // For a full ELA1/MTH1 room, try moving one of the E/M cross-subject-swap
    // occupants (priority=1) to a DIFFERENT room of the same type, so a D/F
    // student can be placed with their own actual teacher. The moved student
    // still ends up with an ELA1 or MTH1 teacher — just not this one.
    $swapToKeepOwnTeacher = function($userid, $room, $code, $priority)
        use (&$assigned, &$roomAssigned, &$results, &$roomsByType,
             $assignStudent, $unassignStudent) {

        $type = $room->interventiontype;
        if (!in_array($type, ['ELA1', 'MTH1'])) {
            return false;
        }

        // Find an E/M priority (cross-subject swap) occupant in this room.
        $swapuid = null;
        foreach ($roomAssigned[$room->teacherid] as $ouid) {
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
        foreach (($roomsByType[$type] ?? []) as $r) {
            if ($r->roomnumber === $room->roomnumber) continue;
            $cap = (int)$r->maxstudents;
            if ($cap > 0 && count($roomAssigned[$r->teacherid] ?? []) < $cap) {
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
        $unassignStudent($swapuid);
        $assignStudent($swapuid, $newroom, $swapcode, $swappriority);
        $results['step3swap']++;

        // Now place the D/F student in the freed slot with their own teacher.
        $assignStudent($userid, $room, $code, $priority);
        return true;
    };

    // =========================================================
    // STEP 0: Lock IEP students into their assigned IEP room (if enabled)
    // =========================================================
    $lockiep = get_config('local_roomscheduler', 'lockiep');
    if ($lockiep) {
        foreach ($allusers as $user) {
            $uid = $user->id;
            if (isset($assigned[$uid])) continue;
            if (!isset($stuByUser[$uid])) continue;

            $homeroom = $stuByUser[$uid]->homeroom;
            if (empty($homeroom) || !isset($roomByNumber[$homeroom])) continue;

            $room = $roomByNumber[$homeroom];
            if ($room->interventiontype !== 'IEP') continue;

            // Priority 0 — lower than anything else, so this student can
            // never be picked up as a displacement candidate later.
            $assignStudent($uid, $room, 'H', 0);
            $results['step0iep']++;
        }
    }

    // =========================================================
    // STEP 1a: Count the swap students. If there are more than the ELA 1+
    // (or Math 1+) rooms can hold, turn the ELA 1 (or Math 1) teacher with
    // the fewest Mon-Wed students into another + room for this run, and
    // repeat until there is room for everyone or no ELA 1 teacher is left.
    // Nothing happens if no + room is set on Room Settings.
    // =========================================================
    $swapNeed    = ['ELA' => 0, 'MTH' => 0];
    $monwedCount = [];
    foreach ($allusers as $user) {
        $uid = $user->id;
        if (!isset($stuByUser[$uid])) continue;
        $hr = $stuByUser[$uid]->homeroom;
        if (isset($roomByNumber[$hr])) {
            $tid = $roomByNumber[$hr]->teacherid;
            $monwedCount[$tid] = ($monwedCount[$tid] ?? 0) + 1;
        }
        if (isset($assigned[$uid])) continue;
        $hrtype = $getHomeroomType($uid);
        if (in_array($hrtype, ['ELA1P', 'ELA1', 'ELA2']) &&
                in_array((int)$stuByUser[$uid]->math_score, [1, 2], true)) {
            $swapNeed['MTH']++;
        } else if (in_array($hrtype, ['MTH1P', 'MTH1', 'MTH2']) &&
                in_array((int)$stuByUser[$uid]->ela_score, [1, 2], true)) {
            $swapNeed['ELA']++;
        }
    }

    foreach (['ELA' => ['ELA1P', 'ELA1'], 'MTH' => ['MTH1P', 'MTH1']] as $subject => $types) {
        list($plustype, $basetype) = $types;
        if (empty($roomsByType[$plustype])) {
            continue;
        }
        $capacity = 0;
        foreach ($roomsByType[$plustype] as $r) {
            $capacity += max(0, (int)$r->maxstudents);
        }
        $spare = [];
        foreach ($roomsByType[$basetype] as $r) {
            if ((int)$r->maxstudents > 0) {
                $spare[] = $r;
            }
        }
        usort($spare, function($a, $b) use ($monwedCount) {
            return ($monwedCount[$a->teacherid] ?? 0) <=> ($monwedCount[$b->teacherid] ?? 0);
        });
        while ($swapNeed[$subject] > $capacity && !empty($spare)) {
            $newplus = array_shift($spare);
            $keep    = [];
            foreach ($roomsByType[$basetype] as $r) {
                if ($r->teacherid != $newplus->teacherid) {
                    $keep[] = $r;
                }
            }
            $roomsByType[$basetype]        = $keep;
            $newplus->interventiontype     = $plustype; // This run only.
            $roomsByType[$plustype][]      = $newplus;
            $plusRooms[$newplus->teacherid] = true;
            $capacity += (int)$newplus->maxstudents;
            $results['plusadded']++;
        }
    }

    // =========================================================
    // STEP 1: Cross-subject swaps (state mandate — highest priority=1)
    // getAvailableRoom() always picks the least-full room of a type, so the
    // swap students are split evenly across the + rooms.
    // =========================================================

    foreach ([1, 2] as $scorepriority) {
        foreach ($allusers as $user) {
            $uid = $user->id;
            if (isset($assigned[$uid])) continue;
            if (!isset($stuByUser[$uid])) continue;

            $stu       = $stuByUser[$uid];
            $hrtype    = $getHomeroomType($uid);
            $elascore  = (int)$stu->ela_score;
            $mathscore = (int)$stu->math_score;

            // ELA homeroom + needs math.
            // Fill Math 1+ up to its Max # first, then MTH1 completely
            // before using MTH2.
            if (in_array($hrtype, ['ELA1P', 'ELA1', 'ELA2']) && $mathscore === $scorepriority) {
                $room = $getAvailableRoom('MTH1P');
                if (!$room) {
                    if (!$allRoomsFull('MTH1')) {
                        $room = $getAvailableRoom('MTH1');
                    } else {
                        $room = $getAvailableRoom('MTH2');
                    }
                }
                if ($room) {
                    $assignStudent($uid, $room, 'M', 1);
                    $results['step1']++;
                    if (isset($plusRooms[$room->teacherid])) {
                        $results['step1plus']++;
                    }
                } else {
                    // All MTH rooms full — force into MTH1 first, then MTH2.
                    $fullroom = null;
                    foreach (($roomsByType['MTH1'] ?? []) as $r) { $fullroom = $r; break; }
                    if (!$fullroom) {
                        foreach (($roomsByType['MTH2'] ?? []) as $r) { $fullroom = $r; break; }
                    }
                    if ($fullroom && $forceAssign($uid, $fullroom, 'M', 1)) {
                        $results['step1']++;
                    }
                }
            }

            // MTH homeroom + needs ELA.
            // Fill ELA 1+ up to its Max # first, then ELA1 completely
            // before using ELA2.
            if (!isset($assigned[$uid]) &&
                in_array($hrtype, ['MTH1P', 'MTH1', 'MTH2']) && $elascore === $scorepriority) {
                $room = $getAvailableRoom('ELA1P');
                if (!$room) {
                    if (!$allRoomsFull('ELA1')) {
                        $room = $getAvailableRoom('ELA1');
                    } else {
                        $room = $getAvailableRoom('ELA2');
                    }
                }
                if ($room) {
                    $assignStudent($uid, $room, 'E', 1);
                    $results['step1']++;
                    if (isset($plusRooms[$room->teacherid])) {
                        $results['step1plus']++;
                    }
                } else {
                    // All ELA rooms full — force into ELA1 first, then ELA2.
                    $fullroom = null;
                    foreach (($roomsByType['ELA1'] ?? []) as $r) { $fullroom = $r; break; }
                    if (!$fullroom) {
                        foreach (($roomsByType['ELA2'] ?? []) as $r) { $fullroom = $r; break; }
                    }
                    if ($fullroom && $forceAssign($uid, $fullroom, 'E', 1)) {
                        $results['step1']++;
                    }
                }
            }
        }
    }

    // =========================================================
    // STEP 1b: ELA/Math rooms that got no swap students this run are
    // treated as General rooms for every later step (D/F, displacement and
    // leveling). This only changes the in-memory copy for this run — the
    // Room Settings page is not changed.
    // =========================================================
    foreach (['ELA1', 'ELA2', 'MTH1', 'MTH2'] as $type) {
        $keep = [];
        foreach ($roomsByType[$type] as $room) {
            $hasswap = false;
            foreach ($roomAssigned[$room->teacherid] ?? [] as $ouid) {
                if (in_array($assigned[$ouid]['code'] ?? '', ['E', 'M'], true)) {
                    $hasswap = true;
                    break;
                }
            }
            if ($hasswap) {
                $keep[] = $room;
            } else {
                $room->interventiontype = 'GEN';
                $roomsByType['GEN'][]   = $room;
                $results['subjecttogen']++;
            }
        }
        $roomsByType[$type] = $keep;
    }

    // =========================================================
    // STEP 2: Teacher requests (priority=2)
    // =========================================================

    foreach ($allusers as $user) {
        $uid = $user->id;
        if (isset($assigned[$uid])) continue;
        if (!isset($requestsByStudent[$uid])) continue;

        $requests    = $requestsByStudent[$uid];
        $hasDf       = $hasDFGrade($uid);
        $bestteacher = null;

        if ($hasDf) {
            $lowestpct = PHP_INT_MAX;
            foreach ($requests as $teacherid => $req) {
                foreach ($teacherCourses[$teacherid] ?? [] as $cid) {
                    $pct = $studentGrades[$uid][$cid] ?? null;
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

        if ($bestteacher && isset($roomByTeacher[$bestteacher])) {
            $room = $roomByTeacher[$bestteacher];
            if (!empty($room->isrewardroom)) continue;
            if (in_array($room->interventiontype, ['IEP', 'NA'])) continue;
            $cap   = (int)$room->maxstudents;
            $usage = count($roomAssigned[$room->teacherid] ?? []);
            if (isset($plusRooms[$room->teacherid])) {
                // The ELA 1+ / Math 1+ teacher asked for this student, so place
                // them even past Max #. No one is bumped. A Max # of 0 means
                // the teacher is out, so the request is skipped.
                if ($cap > 0) {
                    $assignStudent($uid, $room, 'Q', 2);
                    $results['step2']++;
                }
            } else if ($cap > 0 && $usage < $cap) {
                $assignStudent($uid, $room, 'Q', 2);
                $results['step2']++;
            } else if ($forceAssign($uid, $room, 'Q', 2)) {
                $results['step2']++;
            }
        }
    }

    // =========================================================
    // STEP 3: D/F grade assignments (priority=3 for F, 4 for D)
    // =========================================================

    $dfassignments = [];
    foreach ($allusers as $user) {
        $uid = $user->id;
        if (isset($assigned[$uid])) continue;
        foreach ($studentGrades[$uid] ?? [] as $courseid => $pct) {
            if ($pct < $dfthreshold) {
                $dfassignments[] = ['userid' => $uid, 'courseid' => $courseid, 'pct' => $pct];
            }
        }
    }
    usort($dfassignments, fn($a, $b) => $a['pct'] <=> $b['pct']);

    // A + teacher never gets D/F students. Send the student to the least-full
    // ELA 1 (or Math 1) teacher that is not a + room, then ELA 2 (or Math 2),
    // bumping an H student if every one of them is full.
    $placeDFWithOtherTeacher = function($userid, $plusroom, $code, $priority)
        use (&$roomAssigned, &$plusRooms, $roomrecords, $origType,
             $assignStudent, $forceAssign) {
        $basetypes = ($plusroom->interventiontype === 'ELA1P') ? ['ELA1', 'ELA2'] : ['MTH1', 'MTH2'];
        foreach ($basetypes as $basetype) {
            $rooms = [];
            foreach ($roomrecords as $r) {
                if (($origType[$r->teacherid] ?? '') !== $basetype) continue;
                if (isset($plusRooms[$r->teacherid])) continue;
                if (!empty($r->isrewardroom) || (int)$r->maxstudents <= 0) continue;
                $rooms[] = $r;
            }
            usort($rooms, function($a, $b) use (&$roomAssigned) {
                return count($roomAssigned[$a->teacherid] ?? [])
                     <=> count($roomAssigned[$b->teacherid] ?? []);
            });
            foreach ($rooms as $r) {
                if (count($roomAssigned[$r->teacherid] ?? []) < (int)$r->maxstudents) {
                    $assignStudent($userid, $r, $code, $priority);
                    return true;
                }
            }
            foreach ($rooms as $r) {
                if ($forceAssign($userid, $r, $code, $priority)) {
                    return true;
                }
            }
        }
        return false;
    };

    $step3done = [];
    foreach ($dfassignments as $dfa) {
        $uid = $dfa['userid'];
        if (isset($assigned[$uid]) || isset($step3done[$uid])) continue;

        $code     = ($dfa['pct'] < 60) ? 'F' : 'D';
        $priority = ($dfa['pct'] < 60) ? 3 : 4;

        foreach ($teacherCourses as $teacherid => $courses) {
            if (!isset($courses[$dfa['courseid']])) continue;
            if (!isset($roomByTeacher[$teacherid])) continue;
            $room = $roomByTeacher[$teacherid];
            if (!empty($room->isrewardroom)) continue;
            if (isset($plusRooms[$room->teacherid])) {
                if ($placeDFWithOtherTeacher($uid, $room, $code, $priority)) {
                    $step3done[$uid] = true;
                    $assigned[$uid]['dfcourseid'] = (int)$dfa['courseid']; // Shown as "Class".
                    $results['step3']++;
                    $results['step3plus']++;
                    break;
                }
                continue;
            }
            if (in_array($room->interventiontype, ['IEP', 'NA'])) continue;
            $cap   = (int)$room->maxstudents;
            $usage = count($roomAssigned[$room->teacherid] ?? []);
            if ($cap > 0 && $usage < $cap) {
                $assignStudent($uid, $room, $code, $priority);
                $step3done[$uid] = true;
                $assigned[$uid]['dfcourseid'] = (int)$dfa['courseid']; // Shown as "Class".
                $results['step3']++;
                break;
            } else if ($swapToKeepOwnTeacher($uid, $room, $code, $priority)) {
                $step3done[$uid] = true;
                $assigned[$uid]['dfcourseid'] = (int)$dfa['courseid']; // Shown as "Class".
                $results['step3']++;
                break;
            } else if ($forceAssign($uid, $room, $code, $priority)) {
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
    // =========================================================
    $denialSuffix   = []; // userid => 'A' or 'B' for students denied rewards.
    $rewardEligible = []; // userids that earned a reward room this run.

    foreach ($allusers as $user) {
        $uid = $user->id;
        if (isset($assigned[$uid])) continue;

        // Check grades only (without attendance).
        $hasgoodgrades = true;
        if (!isset($studentGrades[$uid]) || empty($studentGrades[$uid])) {
            $hasgoodgrades = false;
        } else {
            foreach ($studentGrades[$uid] as $pct) {
                if ($pct < $abthreshold) {
                    $hasgoodgrades = false;
                    break;
                }
            }
        }

        if (!$hasgoodgrades) continue;

        // Has all A/B grades — check for denials.
        $attPct       = $adjustedattendance[$uid] ?? 100.0;
        $attDenied    = ($attPct < $attendancethreshold);
        $behaviorDen  = isset($behaviorDenied[$uid]);

        if ($attDenied && $behaviorDen) {
            // B takes priority.
            $denialSuffix[$uid] = 'B';
            $results['step4absent']++;
            $results['step4behavior']++;
            continue; // Falls through to normal flow.
        }
        if ($behaviorDen) {
            $denialSuffix[$uid] = 'B';
            $results['step4behavior']++;
            continue;
        }
        if ($attDenied) {
            $denialSuffix[$uid] = 'A';
            $results['step4absent']++;
            continue;
        }

        // Passes all checks — queue for a reward room.
        $rewardEligible[] = $uid;
    }

    // Reward students whose own homeroom is a reward room stay there, so no
    // one is moved from one reward room to another for no reason. They are
    // placed first so the even distribution below balances around them.
    $rewardRemaining = [];
    foreach ($rewardEligible as $uid) {
        $homeroom = $stuByUser[$uid]->homeroom ?? '';
        if ($homeroom !== '' && isset($roomByNumber[$homeroom]) &&
                !empty($roomByNumber[$homeroom]->isrewardroom)) {
            $hrroom = $roomByNumber[$homeroom];
            $assignStudent($uid, $hrroom, 'R', 5);
            $rewardAssignedCount[$hrroom->teacherid]++;
            $results['step4']++;
        } else {
            $rewardRemaining[] = $uid;
        }
    }

    // Everyone else is distributed evenly across the checked reward rooms.
    // If no reward rooms are configured, the student falls through to the
    // normal homeroom/general assignment flow in Steps 5 & 6.
    foreach ($rewardRemaining as $uid) {
        if ($assignToRewardRoom($uid, 'R', 5)) {
            $results['step4']++;
        }
    }
    
    // =========================================================
    // STEP 5 & 6: Everyone else → homeroom (priority=6)
    // Assign to homeroom first, then enforce capacity.
    // =========================================================

    // Homeroom is a reward room (no reward earned) or an ELA 1+ / Math 1+
    // room. These students are moved to another room in Step 7.
    $rewardHomeroomPending = [];
    foreach ($allusers as $user) {
        $uid = $user->id;
        if (isset($assigned[$uid])) continue;
        if (!isset($stuByUser[$uid]) || empty($stuByUser[$uid]->homeroom)) {
            $results['skipped']++;
            continue;
        }
        if ($assignToHomeroom($uid, 'H', 6)) {
            $results['step6']++;
        } else {
            // Homeroom is a reward room or a + room — placed in Step 7 below.
            $rewardHomeroomPending[] = $uid;
        }
    }

    // =========================================================
    // CAPACITY ENFORCEMENT PASS
    // For each room over capacity OR with capacity=0 (teacher absent),
    // displace students to other rooms with code S.
    // =========================================================
    foreach ($roomrecords as $room) {
        if (in_array($room->interventiontype, ['IEP', 'NA'])) continue;
        if (!empty($room->isrewardroom)) continue;
        // + rooms hold only swap students and their teacher's own requests,
        // which may go past Max # on purpose.
        if (isset($plusRooms[$room->teacherid])) continue;
        $cap       = (int)$room->maxstudents;
        $occupants = $roomAssigned[$room->teacherid] ?? [];
        $usage     = count($occupants);

        // cap=0 means teacher absent — displace ALL students.
        // cap>0 means normal capacity check.
        if ($cap > 0 && $usage <= $cap) continue;
        if ($cap > 0 && $usage == 0) continue;
        if ($cap == 0 && $usage == 0) continue;

        // Sort occupants by priority descending (highest = most displaceable first).
        $sorted = array_keys($occupants);
        usort($sorted, function($a, $b) use ($assigned) {
            return ($assigned[$b]['priority'] ?? 0) <=> ($assigned[$a]['priority'] ?? 0);
        });

        // Displace until within capacity (or all gone if cap=0).
        foreach ($sorted as $ouid) {
            $currentusage = count($roomAssigned[$room->teacherid]);
            // Stop if within capacity (for cap>0 rooms).
            if ($cap > 0 && $currentusage <= $cap) break;
            // For cap=0 rooms, displace everyone regardless of priority.
            $opri = $assigned[$ouid]['priority'] ?? 0;
            if ($cap > 0 && $opri < 6) break; // Don't displace high-priority from normal rooms.

            $disproom = $findDisplacementRoom($ouid, $room->roomnumber);
            if ($disproom) {
                $unassignStudent($ouid);
                $assignStudent($ouid, $disproom, 'S', 7);
                $results['displaced']++;
            } else {
                // No displacement room found — assign to homeroom as fallback.
                $unassignStudent($ouid);
                if (isset($stuByUser[$ouid]) && !empty($stuByUser[$ouid]->homeroom)) {
                    $assigned[$ouid] = [
                        'room'     => $stuByUser[$ouid]->homeroom,
                        'code'     => 'S',
                        'priority' => 7,
                    ];
                }
            }
        }
    }

    // =========================================================
    // STEP 7: Homeroom is a reward room but the student did not earn a
    // reward, or homeroom is an ELA 1+ / Math 1+ room -> move to a normal
    // room with space (code S). Runs after the capacity pass so these
    // students never push anyone else out. + rooms are never used here.
    // =========================================================
    foreach ($rewardHomeroomPending as $uid) {
        $homeroom = $stuByUser[$uid]->homeroom;
        $newroom  = $findDisplacementRoom($uid, $homeroom);

        if (!$newroom) {
            // Every non-reward room is full — use the least-full one anyway
            // rather than leave the student without a Thur/Fri room.
            foreach (['GEN', 'ELA2', 'MTH2', 'ELA1', 'MTH1'] as $type) {
                foreach (($roomsByType[$type] ?? []) as $r) {
                    if ((int)$r->maxstudents <= 0) {
                        continue;
                    }
                    $rcount = count($roomAssigned[$r->teacherid] ?? []);
                    if (!$newroom || count($roomAssigned[$newroom->teacherid] ?? []) > $rcount) {
                        $newroom = $r;
                    }
                }
            }
        }

        if ($newroom) {
            $assignStudent($uid, $newroom, 'S', 7);
            $results['displaced']++;
        } else {
            $results['skipped']++;
        }
    }

    // =========================================================
    // STEP 8: Level the General rooms.
    // The capacity pass only moves students out of a room that is over its
    // cap, so one General room can sit at 20 while another sits at 10. Here
    // we move a few H (homeroom) or S (already displaced) students from the
    // fullest General room to the emptiest one until the gap between them is
    // no more than $levelgap. D/F/Q/E/M students are never moved, and reward
    // rooms are neither donors nor receivers. The gap is set on the Run
    // Scheduler page (default 4); 0 turns leveling off.
    // =========================================================
    $levelgap = get_config('local_roomscheduler', 'levelgap');
    $levelgap = ($levelgap === false || $levelgap === '') ? 4 : (int)$levelgap;

    $levelrooms = [];
    foreach (($levelgap > 0 ? ($roomsByType['GEN'] ?? []) : []) as $r) {
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
            $count = count($roomAssigned[$r->teacherid] ?? []);
            if ($count < (int)$r->maxstudents && $count < $receivercount) {
                $receiver      = $r;
                $receivercount = $count;
            }
            if ($count > $donorcount) {
                foreach ($roomAssigned[$r->teacherid] ?? [] as $ouid) {
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
        foreach ($roomAssigned[$donor->teacherid] as $ouid) {
            if (($assigned[$ouid]['priority'] ?? 0) < 6) {
                continue;
            }
            if ($moveuid === null) {
                $moveuid = $ouid;
            }
            $shared = array_intersect(
                $studentCourses[$ouid] ?? [],
                $teacherCourses[$receiver->teacherid] ?? []
            );
            if (!empty($shared)) {
                $moveuid = $ouid;
                break;
            }
        }

        $unassignStudent($moveuid);
        $assignStudent($moveuid, $receiver, 'S', 7);
        $results['displaced']++;
    }

    // =========================================================
    // Apply denial suffixes to final assignments (A or B)
    // =========================================================
    foreach ($denialSuffix as $uid => $suffix) {
        if (isset($assigned[$uid])) {
            $assigned[$uid]['code'] = $assigned[$uid]['code'] . $suffix;
        }
    }

    // =========================================================
    // Write to database
    // =========================================================

    $now = time();
    foreach ($assigned as $userid => $data) {
        if (isset($stuByUser[$userid])) {
            $rec                    = new stdClass();
            $rec->id                = $stuByUser[$userid]->id;
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
 * Get all visible HS courses in the configured category and subcategories.
 */
function local_roomscheduler_get_hs_courses($categoryid) {
    global $DB;
    if (empty($categoryid)) return [];

    $catids  = [$categoryid];
    $tocheck = [$categoryid];
    while (!empty($tocheck)) {
        list($insql, $inparams) = $DB->get_in_or_equal($tocheck, SQL_PARAMS_NAMED, 'cat');
        $subcats = $DB->get_fieldset_select('course_categories', 'id', "parent $insql", $inparams);
        $tocheck = array_diff($subcats, $catids);
        $catids  = array_merge($catids, $subcats);
    }
    if (empty($catids)) return [];

    list($insql, $inparams) = $DB->get_in_or_equal($catids, SQL_PARAMS_NAMED, 'cat');
    return $DB->get_records_select('course', "category $insql AND visible = 1", $inparams);
}

/**
 * Get student grades for all HS courses.
 */
function local_roomscheduler_get_student_grades($courseids, $users) {
    global $DB;
    if (empty($courseids) || empty($users)) return [];

    $userids = array_keys($users);
    list($coursesql, $courseparams) = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'crs');
    list($usersql,   $userparams)   = $DB->get_in_or_equal($userids,   SQL_PARAMS_NAMED, 'usr');

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
               AND c.idnumber REGEXP '^[0-9]{3,4}[A-Za-z]{2}$'";

    $rows   = $DB->get_records_sql($sql, array_merge($courseparams, $userparams));
    $grades = [];
    foreach ($rows as $row) {
        $grades[$row->userid][$row->courseid] = round(($row->finalgrade / $row->grademax) * 100, 2);
    }
    return $grades;
}
