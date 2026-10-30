# NTHS Intervention Room Scheduler

A Moodle local plugin for automated high school intervention room scheduling based on state-mandated requirements for students failing ELA and Math assessments.

## Overview

This plugin addresses the Ohio state mandate requiring additional ELA and Math instruction time for students failing assessment tests. It handles two related but distinct jobs:

1. **Intervention Homeroom** (Mon-Wed) — each student's base room. It is set from the counselor's file with the **Import Rooms** page, or typed in on the Students page. (This is what the district used to call the Blazer Room.)
2. **Intervention Thur/Fri** — filled each week by the **weekly scheduler** based on test scores, course grades, teacher requests, attendance, behavior, and room capacity.

**Naming note (0.5.13):** the database field `homeroom` holds the Intervention Homeroom and `intervention_room` holds the Intervention Thur/Fri room. Before 0.5.13 the pages called these "Intervention" and "Thur/Fri Intervention", and there was a separate Blazer Room field and import that is now removed.

Navigation across the plugin is tab-based (styled after the district's "My Student Data" plugin): one tab bar for the four teacher-facing Intervention/attendance pages, another for the admin/manager hub pages (Room Settings, Students, Assignments, All Requests, Attendance Report, Run Scheduler, Import Rooms, Set Levels).

## Features

- **Room Management** — Configure teachers, room numbers, capacities, intervention types (ELA1+, ELA1, ELA2, MTH1+, MTH1, MTH2, GEN, IEP, NA), grade preference (9/10/11/12/Open), and reward-room status
- **Student Scoring** — Manual entry, or CSV import/export, of ELA/Math intervention priority scores (1-3), Intervention Homeroom and Intervention Thur/Fri rooms
- **Import Rooms** — Upload a CSV (for example the Export Room Assignments file from production, or a counselor's file) to set ELA/Math Levels, Intervention Homeroom and Intervention Thur/Fri rooms. Shows a preview of every change before anything is saved
- **Set Levels from State Tests** — One-click import of intervention levels from Ohio State Test scores in `NTstatetesting`; seniors are automatically capped at level 2 (never level 1)
- **Intervention Counts** — Per-grade breakdown of ELA/Math Level 1 and Level 2 counts, plus a "Both ELA & Math" count of students needing intervention in both subjects
- **Teacher Requests** — Teachers can request specific students for intervention from any course in the HS category
- **Request Persistence** — Teachers can mark their requests to carry over to the next week
- **Request History** — 4-week archive of past intervention requests viewable by each teacher
- **Automated Weekly Scheduling** — 6-step priority algorithm with capacity enforcement, student displacement, and a per-room reward toggle
- **Automatic scheduling** — a Moodle scheduled task can run the weekly scheduler automatically (default Monday 8:00 AM); enable/disable and reschedule from Site administration → Server → Scheduled tasks
- **Reward Rooms** — any room can be checked as a Reward room; reward-eligible students are distributed evenly across all checked reward rooms, ignoring capacity. Reward scheduling can be turned off entirely from the Run Scheduler page, treating every room as normal for that run
- **Assignment Codes** — Each student assignment is tagged with a reason code (E, M, Q, F, D, R, S, H) plus optional suffix (A/B)
- **Behavior Denial** — Principal can deny reward room via behavior tracking
- **Attendance Tracking** — ELA and Math teachers record student attendance at intervention sessions, with a Th/Fri Intervention column visible on the Intervention (homeroom) attendance page too
- **IEP Attendance** — IEP teachers record attendance for every student in their room, whatever their levels, using separate ELA and Math checkboxes (one subject per student per day) so each session is credited to the right subject. Check All ELA / Check All Math buttons match the lesson taught
- **Attendance Reports** — ELA summary, Math summary, and individual student date-by-date reports, filterable by group (the individual student list only shows students who have recorded sessions)
- **Assignments Viewer** — Pick any room and either Intervention or Th/Fri Intervention to see everyone currently assigned there. The Room dropdown is sorted by teacher name (Last, First) with the room number after it, since most staff know the teacher but not the room. Shared rooms list every teacher in one entry.
- **Print-friendly Reports** — Clean, single-page print output (no Moodle chrome) for attendance pages, the Assignments viewer, and Attendance Report
- **CLI Scheduler** — Command-line scheduler for cron automation with automatic log rotation
- **Privacy API** — Full GDPR compliance with data export/deletion support

## Import Rooms

The **Import Rooms** tab sets ELA/Math Levels and Intervention Homeroom / Intervention Thur/Fri rooms from a CSV file. It was rebuilt in 0.5.13 so the assignments on production can be copied to the sandbox, and so the counselor's file can be loaded.

- **Columns are matched by header name**, in any order, so the **Export Room Assignments** file from the Students page imports as-is. Accepted headers: `SSID` (required), `ELA Level`, `Math Level`, `Intervention Homeroom`, `Intervention Thur/Fri`. The old headers `Intervention` and `Thur/Fri Intervention`, and the short ones `sisid, ela, math, homeroom, thurfri`, also work. Other columns (name, grade, the old Blazer Room) are ignored.
- **Only the columns in the file are changed**, and students not in the file are not touched. A counselor's file can be just `SSID` plus one room column.
- **A blank room cell clears that room**, so importing the production export makes the sandbox a true copy. A blank ELA/Math Level is left as it is.
- Rooms must be 100–299, and levels must be 1, 2 or 3. Bad values, and SSIDs that aren't students in the counselor course, are listed and skipped.
- **Preview first.** Uploading only shows every change (old → new). Nothing is saved until **Confirm Import** is clicked, and the save is one database transaction.
- **Assignment codes are not imported.** They are set when the scheduler runs. If a student's Thur/Fri room changes on import, their old code is cleared.

**Removed in 0.5.13:** the Blazer Room import, the `blazerroom` database field, and the **Assign Intervention Rooms** button on Set Levels, which only worked from Blazer Rooms and was not used in 2026-27. The **Clear All Room Assignments** button is still on Set Levels.

## Weekly Scheduling Algorithm

The weekly scheduler (Run Scheduler page, or the scheduled task) fills the **Th/Fri Intervention** room using a 6-step priority-based assignment.

**Eligibility rule:** a student with no **Intervention** (Mon-Wed) room is never given a Th/Fri room. These students are removed from the scheduler's working list before any step runs, so none of the steps below (including Lock IEP, Lock All Rooms, and Reward) can place them. They are counted as skipped and their Th/Fri room is left blank. To include a student, give them an Intervention Homeroom first (Students page or Import Rooms).

The steps:

1. **Cross-subject swaps (E/M codes)** — State mandate priority. ELA-homeroom students with Math score 1/2 move to Math rooms; Math-homeroom students with ELA score 1/2 move to ELA rooms. Swap students go to the **ELA 1+ / Math 1+** rooms, split evenly. If they don't all fit, extra ELA 1 / Math 1 teachers become + rooms for that run (Step 1a).
   - **Step 1b (added in 0.5.11):** any ELA 1/ELA 2/Math 1/Math 2 room that got no swap students is treated as a General room for the rest of the run, so it is leveled like the other General rooms.
2. **Teacher requests (Q code)** — If student has D/F, the lowest-scoring course teacher gets priority; otherwise the earliest request wins. Reward rooms are never used to satisfy a request.
3. **D/F grade assignments (D/F codes)** — Students assigned to the failing course teacher's room, lowest grade first. If that teacher's ELA1/MTH1 room is full of cross-subject-swap students, the scheduler will try moving one of them to a *different* same-type room so the D/F student can join their own teacher.
4. **Reward room (R code)** — Students with all A/B grades AND attendance above threshold get distributed evenly across every room checked as a Reward room, ignoring capacity. This step can be turned off entirely from a toggle on the Run Scheduler page — when off, no reward rooms are used and every room is treated as normal.
5. **Other moves (S code)** — Students displaced from over-capacity rooms to make space for higher-priority assignments, preferring whichever eligible room currently has the fewest students.
6. **Homeroom default (H code)** — All remaining students stay in their Intervention (homeroom) room. Reward rooms are never used as a homeroom default.

If **Lock IEP Rooms** is checked on the Run Scheduler page, a Step 0 runs before any of the above: any student whose Intervention (homeroom) room is typed IEP is locked into that same room for Th/Fri immediately, so none of the later steps can move them out of it. Off by default.

**ELA 1+ and Math 1+ rooms (added in 0.5.11).** These are dedicated Thur/Fri rooms for cross-subject swap students, so one ELA teacher and one Math teacher can teach targeted lessons to just those students instead of every subject teacher getting a few. Only swap students (E/M) are placed there. If there are more swap students than the + room's Max #, the ELA 1 (or Math 1) teacher with the fewest Mon-Wed students becomes another + room for that run, and the swap students are split evenly between the + rooms (added in 0.5.12). The + teacher's own Mon-Wed students are moved to other rooms (code S), and displacement and leveling skip + rooms. A + teacher never gets D/F students: a student failing a + teacher's course goes to the least-full ELA 1 (or Math 1) teacher who is not a + room, then ELA 2 (or Math 2). A request made by the + teacher is always honored, even past Max #, without bumping anyone. For Mon-Wed, ELA 1+ works the same as ELA 1 and Math 1+ the same as Math 1.

**Leveling General rooms (S code, added in 0.5.10).** After every other step, the scheduler evens out the General rooms. Without this, a teacher with many failing students could end up with 20 on Thur/Fri while another General teacher had 10, because students were only moved out of a room that went over its Max Students. Now it moves one H or S student at a time from the fullest General room to the emptiest General room that is under its cap. It stops when the gap between those two rooms is no more than the **General room leveling gap** set on the Run Scheduler page. The default is 4, 0 turns leveling off, and the maximum is 20. The Monday scheduled task uses the same saved value, and the confirmation page shows it before a manual run.
- F, D, Q, E and M students are never moved, so a teacher keeps their own failing students.
- Reward, IEP and Not Assigned rooms are left out.
- When choosing whom to move, it prefers a student who already has a class with the receiving teacher.

## Assignment Codes

| Code | Meaning |
|------|---------|
| E    | Moved to ELA room (was in Math homeroom, needs ELA intervention) |
| M    | Moved to Math room (was in ELA homeroom, needs Math intervention) |
| Q    | Teacher requested |
| F    | Assigned due to F grade |
| D    | Assigned due to D grade |
| R    | Reward room (all A/B grades) |
| S    | Displaced to make space for higher priority, or moved to level out General room sizes |
| H    | Homeroom default |

### Denial Suffixes

Students with all A/B grades who are denied the reward room receive a suffix on their assignment code:

| Suffix | Meaning |
|--------|---------|
| A      | Attendance denied reward (below attendance threshold) |
| B      | Behavior denied reward (Principal-marked) |

Example: **HB** = Student has A/B grades but was sent to homeroom due to behavior denial. When both apply, **B takes priority** over A.

## Requirements

- Moodle 4.5 or later
- PHP 8.1 or later
- MySQL 8.0 or MariaDB 10.6+
- External tables `absent` and `abtardy` for attendance-based reward denial
- External table `NTstatetesting` for Set Levels feature (optional — button hidden if absent)
- External table `contacts` for student SIS ID linking, grade level (`GradeLevelCode`), and CTC status (`StudentStatus`)

## Installation

1. Copy the plugin directory to `/var/www/html/local/roomscheduler/`
2. Visit **Site administration → Notifications** to run the database upgrade
3. Configure plugin settings at **Site administration → Plugins → Local plugins → NTHS Intervention Scheduler**
4. Optionally, review the automatic scheduled task at **Site administration → Server → Scheduled tasks** (search "Run Intervention Room Scheduler") to set your preferred day/time or disable automatic running

## Configuration

Navigate to plugin settings and configure:

- **Teacher source course ID** — Course whose enrolled users are treated as teachers (e.g. "HS Teachers"). Default: 5513
- **Counselor course ID** — Course whose admin menu shows the Room Scheduler link (e.g. "HS Counseling"). Default: 53
- **HS course category ID** — Category whose courses show the Intervention Requests link. Default: 2
- **D/F grade threshold** — Percentage below which a grade is D or F. Default: 70
- **A/B grade threshold** — Percentage at/above which a grade is B or better. Default: 79.5
- **Minimum attendance %** — Attendance required for reward room. Default: 90
- **Minutes per intervention session** — Credit per attendance record. Default: 30

Reward rooms are no longer a single global setting — instead, check the **Reward** box on any room's row in Room Settings. Whether reward scheduling runs at all for a given weekly run is controlled by a separate toggle on the Run Scheduler page (sticky — it stays as you set it until changed).

All defaults are pre-loaded on installation. Adjustable settings are clearly marked with `*** NTLS DEFAULT ***` comments in `settings.php`.

## Set Levels from State Tests

The **Set Levels** button on the Set Levels page sets initial ELA and Math intervention levels by reading scores from the `NTstatetesting` table. It is only visible when that table exists and contains data.

**Rules applied:**
- High School **ELA2** score below passing threshold → ELA level **1**
- No ELA2 found, but **Eighth Grade ELA** score below passing threshold → ELA level **2**
- High School **ALG1** score below passing threshold → Math level **1**
- No ALG1 found, but **Eighth Grade Math (M)** score below passing threshold → Math level **2**
- **Seniors (grade 12) are capped at level 2** in both subjects — never assigned level 1
- Students who pass, or have no matching test data, are **not changed**
- Always uses the **highest score** found per subject

The passing score threshold defaults to **684** and can be adjusted on the confirmation screen before running. This process is intended to be run **once at the beginning of the year**.

Students are matched via: `mdl_user.idnumber` → `mdl_contacts.StudentNumber` → `mdl_contacts.StateStudentID` → `mdl_NTstatetesting.ssid`

The same page also shows the **Intervention Counts** table (ELA/Math Level 1 and 2 counts by grade, plus a "Both ELA & Math" column) and the **Export Room Assignments** and **Clear All Room Assignments** buttons.

## Data Flow

- **SIS ID** maps to `mdl_user.idnumber` (StudentNumber in contacts table)
- **CSV import/export** matches students by SIS ID; the Import Rooms page reads both export formats by header name
- **Grades** pulled from Moodle gradebook for HS courses matching pattern `^[0-9]{3,4}[A-Za-z]{2}$`
- **Grade level** (9-12) pulled from `mdl_contacts.GradeLevelCode`, used for Set Levels' senior cap and the Export Room Assignments file
- **Attendance** factored into reward eligibility using tardy penalty (4 tardies = 1 absent day)
- **State test scores** read from external `NTstatetesting` table via `contacts` table linkage

## CLI Scheduler / Cron

The weekly scheduler can run automatically two ways:

**Moodle scheduled task (recommended)** — registered automatically on install, default Monday 8:00 AM. Adjust the day/time or disable it entirely from **Site administration → Server → Scheduled tasks**, alongside Moodle's other scheduled tasks. Requires Moodle's own cron (`admin/cli/cron.php`) to already be running on a schedule.

**Standalone CLI script** — `cli/runscheduler.php` runs the scheduling engine directly and logs results to `cli/logs/` (keeping the last 4 runs), independent of Moodle's task system.

    /usr/bin/php /var/www/html/local/roomscheduler/cli/runscheduler.php

**Cron example — runs every Monday at 10:00am:**

    0 10 * * 1 /usr/bin/php /var/www/html/local/roomscheduler/cli/runscheduler.php >/dev/null 2>&1

## Access Control

- **Site admin** — Full access to all features
- **Course 53 (counselor) manager** — Edit rooms, student scores, view all requests, run scheduler, view attendance reports, set levels from state tests, assign intervention rooms
- **HS Teachers course members** — Read-only access to rooms and student scores
- **Category 2 course teachers** — Make intervention requests for students in their courses; track attendance for their room
- **ELA1+/ELA1/ELA2/MTH1+/MTH1/MTH2 and IEP teachers** — Save attendance records (credits intervention hours)
- **Principal / Course 53 managers** — Can set behavior denial via Behavior page

## Database Tables

- `local_roomscheduler_rooms` — Teacher room assignments, intervention type, grade preference, reward-room flag
- `local_roomscheduler_students` — Student scores, homeroom, intervention room, assignment code
- `local_roomscheduler_requests` — Current teacher requests
- `local_roomscheduler_teacher_prefs` — Teacher persistence preferences
- `local_roomscheduler_request_archive` — Historical weekly requests (last 4 weeks)
- `local_roomscheduler_attendance` — ELA/Math attendance records
- `local_roomscheduler_behavior` — Principal-managed behavior denial for reward room

### External Tables (not managed by this plugin)

- `NTstatetesting` — Ohio State Test scores, imported externally; used by Set Levels feature
- `contacts` — SIS student roster with StudentNumber, StateStudentID, GradeLevelCode, and StudentStatus for linking, grade matching, and CTC exclusion

## Author

**Brian Pool**
National Trail Local Schools
Copyright © 2026

## License

GNU GPL v3 or later — see [http://www.gnu.org/copyleft/gpl.html](http://www.gnu.org/copyleft/gpl.html)
