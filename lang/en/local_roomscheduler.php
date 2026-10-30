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
 * Language strings for local_roomscheduler.
 *
 * @package    local_roomscheduler
 * @copyright  2026 Brian Pool
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

// Core plugin strings.
$string['pluginname']              = 'NTHS Intervention Scheduler';

// Capabilities.
$string['roomscheduler:manage']    = 'Manage room assignments';
$string['roomscheduler:view']      = 'View room assignments';

// Page headings.
$string['pluginname_heading']      = 'Intervention Room Settings';
$string['assign_rooms']            = 'Assign Intervention Rooms';
$string['view_rooms']              = 'View Intervention Room Assignments';

// Table column headers.
$string['teacher']                 = 'Teacher';
$string['roomnumber']              = 'Room Number';
$string['maxstudents']             = 'Max Students';
$string['interventiontype']        = 'Intervention';
$string['actions']                 = 'Actions';
$string['lastupdated']             = 'Last Updated';

// Intervention types.
$string['type_ELA1']               = 'ELA 1';
$string['type_ELA1P']              = 'ELA 1+';
$string['type_ELA2']               = 'ELA 2';
$string['type_MTH1']               = 'Math 1';
$string['type_MTH1P']              = 'Math 1+';
$string['type_MTH2']               = 'Math 2';
$string['type_GEN']                = 'General';
$string['type_IEP']                = 'IEP';
$string['type_NA']                 = 'Not Assigned';

// Form strings.
$string['edit']                    = 'Edit';
$string['save']                    = 'Save';
$string['cancel']                  = 'Cancel';
$string['delete']                  = 'Delete';
$string['confirmdelete']           = 'Are you sure you want to delete this room assignment?';
$string['savedsuccess']            = 'Room assignment saved successfully.';
$string['deletesuccess']           = 'Room assignment deleted successfully.';

// Admin settings.
$string['settings']                = 'Room Scheduler Settings';
$string['teachercourseid']         = 'Teacher source course ID';
$string['teachercourseid_desc']    = 'The ID of the Moodle course whose enrolled users are treated as teachers in the room scheduler. (e.g. the "HS Teachers" course)';

// Error / access strings.
$string['notconfigured']           = 'The Room Scheduler has not been configured yet. Please contact your administrator.';
$string['nopermission']            = 'You do not have permission to access the Room Scheduler.';
$string['noteachercourseid']       = 'No teacher source course has been configured. Please set it in Site Administration → Local Plugins → HS Intervention Room Scheduler.';
$string['norecords']               = 'No room assignments have been entered yet.';

// Privacy metadata strings.
$string['privacy:metadata:local_roomscheduler_rooms']                    = 'The Room Scheduler stores room assignment data linked to teachers.';
$string['privacy:metadata:local_roomscheduler_rooms:teacherid']          = 'The user ID of the teacher assigned to the room.';
$string['privacy:metadata:local_roomscheduler_rooms:roomnumber']         = 'The room number assigned to the teacher.';
$string['privacy:metadata:local_roomscheduler_rooms:maxstudents']        = 'The maximum number of students allowed in the room.';
$string['privacy:metadata:local_roomscheduler_rooms:interventiontype']   = 'The type of intervention assigned to the room (e.g. ELA1, MTH2, IEP).';
$string['privacy:metadata:local_roomscheduler_rooms:timecreated']        = 'The time the room assignment record was created.';
$string['privacy:metadata:local_roomscheduler_rooms:timemodified']       = 'The time the room assignment record was last modified.';

// Counselor course settings.
$string['counselorcourseid']      = 'Counselor course ID';
$string['counselorcourseid_desc'] = 'The ID of the Moodle course whose course administration menu will show the Room Scheduler link. Only managers of this course can edit room assignments. (e.g. the "HS Counseling" course)';
$string['nocounselorcourseid']    = 'No counselor course has been configured. Please set it in Site Administration → Local Plugins → HS Intervention Room Scheduler.';

// Student scores page strings.
$string['studentscores']           = 'Student Settings';
$string['studentscores_heading']   = 'Student Settings and Assignments';
$string['firstname']               = 'First Name';
$string['lastname']                = 'Last Name';
$string['username']                = 'Username';
$string['sisid']                   = 'SIS ID';
$string['ela_score']               = 'ELA';
$string['math_score']              = 'Math';
$string['score_1']                 = '1 - High Priority';
$string['score_2']                 = '2 - Identified';
$string['score_3']                 = '3 - Low Priority';
$string['allgroups']               = 'All Groups';
$string['selectgroup']             = 'Filter by Group';
$string['studentssavedsuccess']    = 'Student settings saved successfully.';
$string['nostudents']              = 'No students found in this course.';
$string['gotostudents'] = 'Students';
$string['assignments']             = 'Assignments';
$string['assignments_heading']     = 'Room Assignments';
$string['assignmentsroomlabel']    = 'Room:';
$string['assignmentsselectroom']   = 'Select a room to view its assignments.';
$string['assignmentsnorooms']      = 'No students are currently assigned to this room for this intervention type.';
$string['gotorooms']               = 'Room Settings';
$string['nosisid']                 = 'No SIS ID';

// Room fields for students.
$string['homeroom']           = 'Intervention Homeroom';
$string['intervention_room']  = 'Intervention Thur/Fri';
$string['notassigned']        = 'Not Assigned';

// Import strings.
$string['importtitle']        = 'Import Intervention Rooms';
$string['importmaintitle']    = 'Import Intervention Homeroom and Thur/Fri Rooms';
$string['importbutton']       = 'Import Rooms';
$string['exportcsv']           = 'Export CSV';
$string['exportassignmentsbutton'] = 'Export Room Assignments';
$string['intschedulerheading'] = 'Intervention Counts';
$string['intschedulergrade']      = 'Grade';
$string['intschedulerelalevel1']  = 'ELA Level 1';
$string['intschedulerelalevel2']  = 'ELA Level 2';
$string['intschedulermathlevel1'] = 'Math Level 1';
$string['intschedulermathlevel2'] = 'Math Level 2';
$string['intschedulertotal']      = 'Total';
$string['intschedulerboth']       = 'Both ELA & Math';
$string['intschedulerunknowngrade'] = 'Unknown Grade';
$string['importinstructions'] = '<p>Upload a CSV file with a header row. The <strong>Export Room Assignments</strong> file from the Students page, on this server or another one, can be imported as-is.</p><p>Columns are matched by their header names, in any order: <strong>SSID</strong> (required), <strong>ELA Level</strong>, <strong>Math Level</strong>, <strong>Intervention Homeroom</strong>, <strong>Intervention Thur/Fri</strong>. The older headers <em>Intervention</em> and <em>Thur/Fri Intervention</em> also work. Any other columns, such as name or grade, are ignored.</p><ul><li>Only the columns in the file are changed. Students not in the file are not changed.</li><li>A blank room cell clears that room. A blank ELA or Math Level is left as it is.</li><li>Rooms must be 100 to 299. ELA and Math Levels must be 1, 2 or 3.</li><li>Nothing is saved until you check the preview and click <strong>Confirm Import</strong>.</li></ul>';
$string['importfile']         = 'CSV File';
$string['importsubmit']       = 'Upload and Preview';
$string['importnotfound']     = 'SSIDs in the file that are not students in this course (skipped)';
$string['importchanged']      = 'Changes';
$string['importnochanges']    = 'Nothing to change — every student in the file already matches.';
$string['importold']          = 'Old Value';
$string['importnew']          = 'New Value';
$string['importfield']        = 'Field';
$string['importnofile']       = 'No file was uploaded.';
$string['importpreview']        = 'Preview — nothing has been saved yet. Check the changes below, then click Confirm Import.';
$string['importpreviewsummary'] = '{$a->rows} students in the file matched this course. {$a->changed} will change and {$a->unchanged} already match. Columns used: {$a->columns}.';
$string['importcodenote']       = 'When a student\'s Intervention Thur/Fri room changes, their assignment code is cleared. The scheduler sets it again on its next run.';
$string['importinvalid']        = 'Values that are not valid (skipped)';
$string['importnosisidcol']     = 'The file has no SSID column. Check that the first row is the header row.';
$string['importnodatacols']     = 'The file has no ELA Level, Math Level, Intervention Homeroom or Intervention Thur/Fri column.';
$string['importexpired']        = 'The preview has expired. Please upload the file again.';
$string['importconfirm']        = 'Confirm Import';
$string['importcancel']         = 'Cancel';
$string['importstudent']        = 'Student';
$string['importnewrecord']      = '(new)';
$string['importblank']          = '(blank)';
$string['homeroomshort']        = 'Homeroom';
$string['thurfrishort']         = 'Thur/Fri';
$string['importinvalidfile']  = 'Invalid file type. Please upload a CSV file.';
$string['importsuccess']      = 'Import complete. {$a} students were added or changed.';

// Intervention request strings.
$string['requests']              = 'Requests';
$string['requests_heading']      = 'Requests';
$string['allrequests']           = 'All Requests';
$string['allrequests_heading']   = 'All Intervention Requests';
$string['requested']             = 'Request';
$string['requestedby']           = 'Requested By';
$string['requestsaved']          = 'Intervention requests saved successfully.';
$string['norequests']            = 'No intervention requests have been made yet.';
$string['nostudentscourse']      = 'No students found in this course.';
$string['hscategoryid']          = 'HS course category ID';
$string['hscategoryid_desc']     = 'The ID of the Moodle course category (and its subcategories) whose courses will show the Intervention Requests link in the course admin menu.';
$string['gotorequests']          = 'Requests';
$string['coursecolumn']          = 'Course';
$string['teachercolumn']         = 'Requested By';

// Scheduler strings.
$string['scheduler']             = 'Run Intervention Scheduler';
$string['scheduler_heading']     = 'Intervention Room Scheduler';
$string['schedulerrun']          = 'Run Scheduler Now';
$string['schedulersuccess']      = 'Intervention rooms assigned successfully.';
$string['schedulerrunning']      = 'Scheduler is running...';
$string['schedulerconfirm_title'] = 'Confirm: Run the Intervention Room Scheduler';
$string['schedulerconfirm']      = '<p><strong>You are about to RUN THE SCHEDULER.</strong></p><p>This will WIPE and REASSIGN every student\'s Thur/Fri intervention room. It cannot be undone from this page.</p><p>If you only meant to change a setting or save room settings, click <strong>Cancel</strong>.</p><p>This run will use these settings:</p><ul><li>Reward room scheduling: <strong>{$a->rewards}</strong></li><li>Lock IEP Rooms: <strong>{$a->lockiep}</strong></li><li>Lock all Rooms: <strong>{$a->lockall}</strong></li><li>General room leveling gap: <strong>{$a->levelgap}</strong></li></ul>';
$string['settingon']             = 'ON';
$string['settingoff']            = 'OFF';
$string['schedulerlastrun']      = 'Last run';
$string['gradelevel']          = 'Grade';
$string['gradeopen']           = 'Open';
$string['clearinterventionbutton']  = 'Clear All Room Assignments';
$string['clearlevelsbutton']        = 'Clear All Intervention Levels';
$string['rewardsenabled']       = 'Enable reward room scheduling';
$string['rewardsenabled_on']    = 'Reward rooms are being assigned normally.';
$string['rewardsenabled_off']   = 'Reward rooms are OFF — no student will be assigned to a reward room, and every reward room is treated as a normal room, until this is re-enabled.';
$string['lockiep']             = 'Lock IEP Rooms';
$string['lockiep_on']          = 'IEP Rooms are LOCKED — students assigned to an IEP room stay there for Thur/Fri and are never moved by any other step.';
$string['lockiep_off']         = 'IEP Rooms are not locked — students in an IEP room can still be moved by other scheduling steps.';
$string['lockall']             = 'Lock all Rooms';
$string['lockall_on']          = 'ALL rooms are LOCKED — every student stays in their Mon-Wed room for Thur/Fri (code H), and no other scheduling step runs.';
$string['lockall_off']         = 'Rooms are not all locked — Thur/Fri assignments run through the normal scheduling steps below.';
$string['levelgap']            = 'General room leveling gap';
$string['levelgap_desc']       = 'After all other steps, H and S students are moved from the fullest General room (including ELA/Math rooms that got no swap students this run) to the emptiest until the difference between them is no more than this many students. A lower number levels the rooms more. Enter 0 to turn leveling off. Default is 4.';
$string['levelgap_off']        = 'OFF';
$string['step0iep']            = 'Step 0 — IEP students locked in place';
$string['step0lockall']        = 'Step 0 — All rooms locked to Mon-Wed (H)';
$string['runschedulertask']     = 'Run Intervention Room Scheduler (weekly)';
$string['schedulerneverrun']     = 'Never run';
$string['scheduleresults']       = 'Scheduler Results';
//$string['rewardroom']            = 'Reward room number';
//$string['rewardroom_desc']       = 'Room number assigned to students with all A/B grades (e.g. 100 for cafeteria/gym reward).';
$string['dfthreshold']           = 'D/F grade threshold (%)';
$string['dfthreshold_desc']      = 'Percentage below which a grade is considered D or F. Default is 70.';
$string['abthreshold']           = 'A/B grade threshold (%)';
$string['abthreshold_desc']      = 'Percentage at or above which a grade is considered a B or better. Default is 79.5.';
$string['step1assigned']         = 'Step 1 — Cross-subject swaps';
$string['step1plus']             = 'Step 1 — Swaps placed with ELA 1+ / Math 1+ teachers';
$string['plusadded']             = 'Step 1 — Extra ELA 1 / Math 1 teachers used as + rooms (rooms)';
$string['subjecttogen']          = 'Step 1 — ELA/Math rooms with no swaps, treated as General (rooms)';
$string['step2assigned']         = 'Step 2 — Teacher requests';
$string['step3assigned']         = 'Step 3 — D/F grade assignments';
$string['step3plus']             = 'Step 3 — D/F students sent from a + teacher to another ELA/Math teacher';
$string['step3swap']             = 'Step 3 — Moved another student to keep D/F student with own teacher';
$string['step4assigned']         = 'Step 4 — Reward room (all A/B)';
$string['step5assigned'] = 'Step 5 — Other Moves (S)';
$string['step6assigned']         = 'Step 6 — Intervention default';
$string['totalassigned']         = 'Total students assigned';
$string['totalskipped']          = 'Total students skipped (no intervention room)';
$string['goscheduler']           = 'Run Scheduler';

// Assignment code.
$string['assignmentcode'] = 'Code';
$string['assignmentcode_desc'] = 'E=ELA Move, M=Math Move, Q=Requested, F=F Grade, D=D Grade, R=Reward, G=General, S=Displaced, H=Home Intervention room';

// Attendance strings.
$string['hrattendance']          = 'Intervention Homeroom';
$string['intattendance']         = 'Intervention Thur/Fri';
$string['hrattendance_heading']  = 'Intervention Homeroom Attendance';
$string['intattendance_heading'] = 'Intervention Thur/Fri Attendance';
$string['attendancedate']        = 'Attendance Date';
$string['checkall']              = 'Check All Present';
$string['saveattendance']        = 'Save Attendance';
$string['attendancesaved']       = 'Attendance saved successfully.';
$string['attendancealreadysaved'] = 'Attendance for this date has been updated.';
$string['present']               = 'Present';
$string['noattendancestudents']  = 'No students require attendance tracking for your room.';
$string['attendancereadonly']    = 'Your room type does not require intervention attendance tracking.';
$string['attendanceinstructions'] = 'Check all present students. Uncheck any absent students. Click Save Attendance when done.';
$string['attendanceinstructions_iep'] = 'Check ELA or Math for each present student to record which intervention they received today. Use Check All ELA or Check All Math for the lesson you taught. A student can only be checked for one subject per day. Click Save Attendance when done.';
$string['attendancebothskipped'] = 'Attendance saved, but {$a} student(s) had both ELA and Math checked and were not saved. A student can only be checked for one subject per day.';
$string['checkall_ela']          = 'Check All ELA';
$string['checkall_math']         = 'Check All Math';
$string['present_ela']           = 'ELA Present';
$string['present_math']          = 'Math Present';
$string['selectdate']            = 'Select Date';
$string['roomtype_ela']          = 'ELA Intervention';
$string['roomtype_math']         = 'Math Intervention';

// Attendance threshold strings.
$string['attendancethreshold']      = 'Minimum attendance % for reward room';
$string['attendancethreshold_desc'] = 'Students with adjusted attendance percentage below this value will not qualify for the reward room, even with all A/B grades. Tardy days are factored in at 4 tardies = 1 absent day. Default is 90.';

// Attendance denial string.
$string['step4absent'] = 'Step 4 — A/B grades denied reward (attendance)';

// Attendance report strings.
$string['attendancereport']         = 'Attendance Report';
$string['attendancereport_heading'] = 'Intervention Attendance Report';
$string['elasummary']               = 'ELA Summary';
$string['mathsummary']              = 'Math Summary';
$string['individualreport']         = 'Individual Student';
$string['sessions']                 = 'Sessions';
$string['hours']                    = 'Hours';
$string['selectstudent']            = 'Select student';
$string['eladates']                 = 'ELA Intervention Days';
$string['mathdates']                = 'Math Intervention Days';
$string['nosessions']               = 'No intervention sessions recorded.';
$string['nostudentswithsessions']   = 'No students have intervention sessions recorded yet this school year.';
$string['minutespersession']        = 'Minutes per intervention session';
$string['minutespersession_desc']   = 'The number of minutes of instruction credit per attendance record. Default is 30.';
$string['goattendancereport']       = 'Attendance Report';


// Request history strings.
$string['requesthistory']          = 'Req History';
$string['requesthistory_heading']  = 'Request History (Last 4 Weeks)';
$string['gorequesthistory']        = 'Request History';
$string['gotointervention']        = 'Intervention';
$string['gotointscheduler']        = 'Settings';
$string['weekof']                  = 'Week of';
$string['norequesthistory']        = 'No request history for this teacher yet.';

// Privacy strings.
$string['privacy:metadata:rooms']                            = 'Teacher room assignments for intervention scheduling.';
$string['privacy:metadata:rooms:teacherid']                  = 'The Moodle user ID of the teacher.';
$string['privacy:metadata:rooms:roomnumber']                 = 'The room number assigned to this teacher.';
$string['privacy:metadata:rooms:maxstudents']                = 'Maximum students the room holds.';
$string['privacy:metadata:rooms:interventiontype']           = 'The type of intervention (ELA1P, ELA1, ELA2, MTH1P, MTH1, MTH2, GEN, IEP, NA).';

$string['privacy:metadata:students']                         = 'Student scores and room assignments for intervention.';
$string['privacy:metadata:students:userid']                  = 'The Moodle user ID of the student.';
$string['privacy:metadata:students:ela_score']               = 'The ELA assessment priority score (1, 2, or 3).';
$string['privacy:metadata:students:math_score']              = 'The Math assessment priority score (1, 2, or 3).';
$string['privacy:metadata:students:homeroom']                = 'The student\'s Intervention Homeroom (Mon-Wed room).';
$string['privacy:metadata:students:intervention_room']       = 'The room assigned by the intervention scheduler.';
$string['privacy:metadata:students:assignment_code']         = 'The reason code for the current intervention assignment.';

$string['privacy:metadata:requests']                         = 'Teacher requests for student intervention.';
$string['privacy:metadata:requests:userid']                  = 'The Moodle user ID of the student being requested.';
$string['privacy:metadata:requests:teacherid']               = 'The Moodle user ID of the teacher making the request.';
$string['privacy:metadata:requests:requested']               = 'Whether the student is currently requested (1) or not (0).';

$string['privacy:metadata:teacher_prefs']                    = 'Teacher preferences for intervention requests.';
$string['privacy:metadata:teacher_prefs:teacherid']          = 'The Moodle user ID of the teacher.';
$string['privacy:metadata:teacher_prefs:persistent']         = 'Whether the teacher requests persist across scheduler runs.';

$string['privacy:metadata:request_archive']                  = 'Historical record of weekly intervention requests.';
$string['privacy:metadata:request_archive:userid']           = 'The Moodle user ID of the student that was requested.';
$string['privacy:metadata:request_archive:teacherid']        = 'The Moodle user ID of the teacher who made the request.';
$string['privacy:metadata:request_archive:weekof']           = 'The week (Monday midnight) the request was archived.';

$string['privacy:metadata:attendance']                       = 'Teacher-recorded attendance for ELA/Math intervention sessions.';
$string['privacy:metadata:attendance:teacherid']             = 'The Moodle user ID of the teacher taking attendance.';
$string['privacy:metadata:attendance:studentid']             = 'The Moodle user ID of the student being marked.';
$string['privacy:metadata:attendance:attendancetype']        = 'Type of attendance — E for ELA, M for Math.';
$string['privacy:metadata:attendance:attendancedate']        = 'The date of the intervention session.';
$string['privacy:metadata:attendance:present']               = 'Whether the student was present (1) or absent (0).';

// Behavior strings.
$string['behavior']             = 'Behavior';
$string['behavior_heading']     = 'Student Behavior — Reward Denial';
$string['gobehavior']           = 'Behavior';
$string['denyreward']           = 'Deny Reward';
$string['incidents']            = 'Incidents';
$string['behaviorsaved']        = 'Behavior settings saved successfully.';
$string['behaviorinstructions'] = 'Check "Deny Reward" to prevent a student from being assigned the reward room, even with all A/B grades. The number of incidents is informational only. This setting persists until unchecked.';
$string['step4behavior']        = 'Step 4 — A/B grades denied reward (behavior)';

// Course reset strings.
$string['resetstudentdata']      = 'Delete all student intervention data';
$string['resetstudentdata_desc'] = 'Clears student scores, room assignments, teacher requests, request archive, attendance records, and behavior denials. Rooms, teacher preferences, and plugin settings are preserved.';
$string['reset_students']        = 'Student scores and assignments deleted';
$string['reset_requests']        = 'Teacher requests deleted';
$string['reset_archive']         = 'Request archive deleted';
$string['reset_attendance']      = 'Attendance records deleted';
$string['reset_behavior']        = 'Behavior denials deleted';
