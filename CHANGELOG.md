# Changelog

All notable changes to the NTHS Intervention Room Scheduler plugin will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to date-based versioning (YYYYMMDDXX).

## [2026103000] - 2026-10-30 (0.5.14)

### Added
- **Class column on both teacher attendance pages** (Intervention and Thur/Fri Inter) **and on the Assignments page** (printed for subs). For students placed because of a D or F grade (codes D/F, with or without an A/B suffix), it shows the full name of the course they have the D/F in, so the teacher or sub knows why the student is there. It is the last column, in the table's normal font, kept on one line and cut to the cell width with "…". Hovering shows the full name. On paper a long name prints cut off.
- New `dfcourseid` field on `local_roomscheduler_students` (upgrade step). The scheduler fills it when it places a D/F student and clears it on every run, so the column is blank until the scheduler runs once after upgrading.

### Changed
- On both teacher attendance pages and the Assignments page, the "Intervention" / "Thur/Fri Intervention" column headers are now **Mon-Wed** / **Thur-Fri** (new strings `col_monwed`, `col_thurfri`). The hyphen lets both headers wrap onto two lines, making the columns narrower. These two columns are centered. Other pages keep their wording.

### Reverted (from 0.5.13, by request)
- Teacher-facing labels are back to the originals: the tabs "Intervention" / "Thur/Fri Inter", the headings "Intervention Attendance" / "Thur/Fri Attendance", and the column strings "Intervention" / "Thur/Fri Intervention". The Students and Room Settings pages are back to "Mon-Wed" / "Thur/Fri". The export file headers are back to "Intervention" / "Thur/Fri Intervention", and the Blazer Room column stays removed. The Import page heading is now "Import Intervention and Thur/Fri Intervention Rooms".

## [2026102902] - 2026-10-29 (0.5.13)

### Changed
- **Import page rebuilt as Import Rooms.** The tab is now **Import Rooms**. It sets ELA/Math Levels, the Intervention Homeroom and the Intervention Thur/Fri room from one CSV. Before, it could only set the Mon-Wed room, so assignments couldn't be copied from production to the sandbox.
  - Columns are matched by header name, so the Export Room Assignments file from production imports as-is. The older headers (`Intervention`, `Thur/Fri Intervention`, `sisid, ela, math, homeroom`) still work, and other columns are ignored.
  - Only columns in the file change. A blank room cell clears the room, and a blank level is left alone.
  - **Preview before saving:** every change is listed (old → new) with Confirm Import / Cancel buttons. Nothing is saved until Confirm, and the save is one transaction.
  - Assignment codes are not imported, because the scheduler sets them. A changed Thur/Fri room clears the old code.
- **Renamed labels:** "Intervention" is now **Intervention Homeroom** and "Thur/Fri Intervention" is now **Intervention Thur/Fri** on the Students, Assignments, All Requests, Requests and attendance pages, and in the export headers. The Room Settings count columns are now **Homeroom** and **Thur/Fri**. The Intervention Homeroom is what used to be called the Blazer Room.
- The Students page short export (`sisid, ela, math, homeroom`) now also includes `thurfri`.

### Removed
- The Blazer Room import, the Blazer Room column in Export Room Assignments, and the `blazerroom` database field. An upgrade step drops the field.
- The **Assign Intervention Rooms** button on Set Levels and its code. It only worked from Blazer Rooms and wasn't used this year.

## [2026102901] - 2026-10-29 (0.5.12)

### Added
- **Extra + rooms when there are too many swap students (new Step 1a).** If there are more ELA swap students than the ELA 1+ room's Max #, the ELA 1 teacher with the fewest Mon-Wed students becomes another ELA 1+ room for that run. This repeats until everyone fits or no ELA 1 teacher is left. Math works the same way with Math 1. Swap students are split evenly across the + rooms. This replaces 0.5.11's overflow into the regular ELA 1 rooms, which still mixed swap students with other students.
- **+ teachers never get D/F students.** A student failing a course taught by a + teacher goes to the least-full ELA 1 (or Math 1) teacher who is not a + room, then ELA 2 (or Math 2). Since ELA 1 teachers only teach ELA and Math 1 teachers only teach Math, the + teacher's type tells the scheduler the subject.
- Two more lines on the Scheduler Results table and in the CLI log: extra + rooms used, and D/F students sent from a + teacher to another teacher.

## [2026102900] - 2026-10-29 (0.5.11)

### Added
- **New ELA 1+ and Math 1+ intervention types** (stored as `ELA1P` / `MTH1P`) on the Room Settings and Edit Room pages. Only about 15 students a week get a cross-subject swap, which left some ELA/Math rooms almost empty and others with a mix of swap and non-swap students. The + rooms give the Thur/Fri swap students one dedicated ELA teacher and one dedicated Math teacher, so those teachers can plan specific lessons.
- **Step 1 fills the + rooms first.** E students go to the ELA 1+ room and M students go to the Math 1+ room, up to that room's Max #. Only extra swap students (11 or more when Max # is 10) go on to ELA 1 then ELA 2, or Math 1 then Math 2, as before.
- **Only swap students go to a + room.** The + teacher's own Mon-Wed students are moved to another room on Thur/Fri (code S). D/F placements, displacement and leveling never use a + room.
- **Requests from a + teacher are always placed**, even past Max #, and no one is bumped. (A Max # of 0, meaning the teacher is out, still skips the request.) Requests to every other room still bump an H student when the room is full, as before.
- **ELA/Math rooms with no swap students become General for that run (new Step 1b).** Any ELA 1, ELA 2, Math 1 or Math 2 room that gets no E/M students is treated as a General room for D/F, displacement and General room leveling, so it ends up with a level number of students like every other General room. The Room Settings page is not changed.
- Two new lines on the Scheduler Results table (and in the CLI log): swaps placed with ELA 1+ / Math 1+ teachers, and the number of ELA/Math rooms treated as General.

### Changed
- For Mon-Wed, ELA 1+ counts as ELA 1 and Math 1+ as Math 1: they get students in Assign Intervention Rooms, record E/M attendance on both attendance pages, and their students who need the other subject are swapped on Thur/Fri.
- No database change. Existing rooms keep their current type until you change them on Room Settings.

## [2026102601] - 2026-10-26 (0.5.10)

### Added
- **General rooms are now leveled on Thur/Fri (new Step 8).** Before, the scheduler only moved students out of a room that was over its Max Students. That left some General rooms at 20 (often because many students were failing that teacher's class) while others sat at 10. After all other steps run, the scheduler now moves H (homeroom) or S (already displaced) students one at a time from the fullest General room to the emptiest General room that is under its cap. It stops when the gap between those two rooms is no more than the leveling gap.
- **New "General room leveling gap" setting on the Run Scheduler page**, under the three checkboxes. It's a number box with its own Save changes button (it's also saved whenever a checkbox is changed). Default is 4, 0 turns leveling off, and the maximum is 20. Stored as the `levelgap` plugin config, and used by both the Run Scheduler page and the Monday scheduled task. The confirmation page lists the gap along with the other settings.
- F, D, Q, E and M students are never moved by leveling, so a room full of failing students keeps them. Reward rooms are neither donors nor receivers. IEP and Not Assigned rooms are not touched.
- When choosing whom to move, the scheduler prefers a student who already has a class with the receiving teacher. Moved students get code S and are counted in the existing "displaced" total. Attendance/behavior suffixes (A/B) are still added afterward.

## [2026102300] - 2026-10-23 (0.5.9)

### Changed
- **Run Scheduler Now now opens a full confirmation page** instead of a browser popup. The page warns that every student's Thur/Fri room will be wiped and reassigned. It also shows whether the three run settings are ON or OFF (Reward room scheduling, Lock IEP Rooms, Lock all Rooms) before anything runs. Click **Cancel** to go back without running. Added after a run was started by mistake while changing the reward-room setting, which reassigned every Thur/Fri room. Those rooms had to be restored from the nightly database backup.
- **The scheduler only runs when the request explicitly says `action=run`.** Before, any POST to `runscheduler.php` that wasn't a checkbox change started a run. Now the Run button on the main page only opens the confirmation page (a GET to `action=confirm`), and only the red button on the confirmation page can start a run (it posts `action=run` with sesskey).

### Added
- Language strings `schedulerconfirm_title`, `schedulerconfirm`, `settingon` and `settingoff` for the confirmation page.

## [2026102001] - 2026-10-20 (0.5.8)

### Changed
- **Every student in an IEP room can now be checked for ELA or Math**, regardless of their levels, since everyone in an IEP room is receiving intervention. (0.5.7 only allowed subjects where the student was a Level 1 or 2.)
- **Only one subject per student per day.** Checking ELA clears that student's Math box and vice versa. Check All ELA checks every ELA box and clears every Math box; Check All Math does the reverse. If both somehow arrive at the server, that student is not saved and the teacher sees a warning.
- **ELA/Math Summary reports now include any student with a recorded session of that type**, not only students who are a Level 1 or 2 in that subject, so Level 3 students credited in an IEP room still appear.

## [2026102000] - 2026-10-20 (0.5.7)

### Added
- **IEP teachers now take intervention attendance.** IEP rooms track attendance on both the Intervention (Mon-Wed) and Thur/Fri pages, the same as ELA and Math rooms. Every student in the room who is a Level 1 or 2 in ELA or Math can be marked.
- **Separate ELA Present and Math Present checkboxes for IEP rooms**, so the IEP teacher records which intervention each student received. A checkbox only appears for a subject where the student is a Level 1 or 2. ELA checks save as ELA sessions and Math checks as Math sessions, so they count in the ELA Summary, Math Summary and Individual Student reports exactly like sessions from ELA/Math teachers.
- **Check All ELA / Check All Math buttons** on IEP attendance pages, in place of the single Check All Present button.

### Changed
- **Attendance Report → Individual Student dropdown only lists students with at least one recorded ELA or Math session** this school year (still filtered by group), instead of every student in the counselor course.
- The Individual Student view now keeps the selected group filter when a student is picked.
- ELA/Math Summary session counts now count distinct dates, matching the Individual Student view, so two teachers marking the same student on the same day counts once.
- Attendance load/save logic moved into shared functions in `lib.php` used by both attendance pages.

## [2026100800] - 2026-10-08 (0.5.6)

### Fixed
- **Students with no Mon-Wed (Intervention) room are never given a Thur/Fri room.** Previously the reward step (Step 4) placed any all-A/B student in a reward room even when their Mon-Wed room was blank, because only the homeroom step checked for a blank room. The scheduler now removes students with no Mon-Wed room from its working list immediately after loading, so no step (IEP lock, E/M swap, teacher request, D/F, reward, homeroom, or displacement) can place them. They are counted as skipped and left with a blank Thur/Fri room.

### Changed
- **Assignments page Room dropdown is now sorted by teacher name (Last, First)** and shows the name first (e.g. "Barnes, Jackie — 207"), since most staff know the teacher but not the room number.
- **Shared rooms now list every teacher.** When two teachers share a room number (e.g. 102, 214), the dropdown shows both names in a single entry. Previously one of the two teachers was silently dropped from the list.

## [2026092401] - 2026-09-24

### Added
- **Automatic cleanup when a user is deleted.** New `user_deleted` observer removes the deleted user's student record, requests about them, archived requests, attendance, and behavior flags, so orphaned rows no longer show up with a stale Mon-Wed room.

## [2026092400] - 2026-09-24

### Fixed
- **Students whose Mon-Wed room is a reward room no longer end up with a blank Thur/Fri room.** Step 5/6 tried to send them back to their homeroom, but that is refused when the homeroom is a reward room. The return value was never checked, the student was still counted as assigned, and the capacity pass skips reward rooms, so nothing ever placed them.

### Changed
- **Reward students whose own homeroom is a reward room now stay there (code R)** instead of being moved into a different reward room by the round-robin. They are placed first, and the remaining reward students are spread evenly around them.
- **New Step 7:** students whose homeroom is a reward room but who did not earn a reward (including attendance/behavior denials) are moved to the least-full non-reward room with space, coded S (SA/SB with a denial suffix). If every non-reward room is full, they go to the least-full one anyway rather than being left unassigned. Step 7 runs after the capacity pass, so it never displaces anyone else.

## [2026081900] - 2026-08-19

### Changed
- **Assign Intervention Rooms simplified to Blazer-Room-only matching.** Removed the score-based ELA/Math Level 1/2 auto-placement steps entirely (they were pulling students away from a valid Blazer Room match before the Blazer Room step ever ran). The process is now three rules only: 1) students already assigned are left alone, 2) a student whose Blazer Room is typed General or IEP is assigned that same room, 3) everyone else is assigned a General room matching their grade, evening out the numbers as it goes.
- **Thur/Fri Attendance checkbox now driven by assignment code, not raw score.** A student only gets an attendance checkbox on `intattendance.php` if their Thur/Fri assignment code is E (ELA Move) or M (Math Move) — a student with an ELA/Math score of 1 or 2 who is simply sitting in their regular homeroom (code H) no longer shows a checkbox.

### Added
- **Blazer Room, ELA Level, and Math Level columns** added to the Export Room Assignments CSV on the Students page, for easier auditing of why a student landed where they did.
- **Lock all Rooms** checkbox on the Run Scheduler page, below Lock IEP Rooms. When enabled, the weekly scheduler skips every other step entirely — every student's Thur/Fri room is simply set to match their Mon-Wed (Intervention) room, coded H.

## [2026080500] - 2026-08-05

### Changed
- **Assign Intervention Rooms no longer moves anyone who already has a room.** Previously every run wiped and reassigned every student's Intervention (Mon-Wed) room from scratch. Now it only fills in students who are still unassigned — existing assignments are left completely alone and count toward each room's capacity.
- **General-room grade matching is now dynamic.** When placing a general-population student, a General (GEN) room's "current grade" is worked out from the grade levels of the students actually sitting in it right now (existing occupants plus anyone placed there earlier in the same run), instead of the room's fixed Grade setting. ELA/Math rooms still use their configured Grade setting for this comparison.
- Results notification on the Set Levels page now reports newly-assigned, Blazer-Room-assigned, already-assigned (left alone), skipped, and total counts, and the confirmation dialog text was updated to reflect the new "unassigned students only" behavior.

### Added
- **Blazer Room import and first-choice placement.** New `blazerroom` field on the student record, importable via a new "Import Blazer Room Assignments" CSV upload (columns: SSID, BlazerRm) on the Import page. When Assign Intervention Rooms runs, an unassigned student's Blazer Room is tried first — they're placed there if it's configured as a General or IEP room in Room Assignments and still has an open seat; otherwise they fall through to the normal General/ELA/Math matching.
- **Export Room Assignments** button/tab — downloads a CSV of First Name, Last Name, SSID, and Intervention Room Assignment for every student.

## [2026072406] - 2026-07-27

### Changed
- **Assign Intervention Rooms algorithm redesigned** to a cap-and-overflow model instead of grade-matching-with-closest-fallback:
  1. ELA Level 1 → the grade-matched ELA1 teacher, capped at 10; once that teacher hits 10, further ELA Level 1 students go to whichever ELA1 teacher (any grade) currently has the fewest.
  2. Math Level 1 → the same pattern with MTH1 teachers.
  3. ELA Level 2 → whichever ELA1 teacher currently has the fewest students, until every ELA1 teacher reaches 10; only then does it fall back to whichever ELA2 teacher has the fewest.
  4. Math Level 2 → the same pattern, filling MTH1 teachers first before MTH2.
  5. Everyone else (general population, or anyone unmatched above) → General rooms and any ELA/Math room not otherwise full. A room already holding ELA/Math priority students is capped at 10 for general students; an otherwise-unused ELA/Math room behaves like a General room (soft cap 20). Prefers a grade match, then the closest grade, then whichever eligible room has the fewest students — and still places the student even if every candidate is over its cap, rather than leaving anyone unassigned.
  - This replaces the previous "grade 10/11 exact match, closest-grade fallback to a dedicated Level 2 teacher" design, which could leave a Level 1 teacher with zero students (because none of their exact grade needed Level 1) while a Level 2 or General teacher became badly overloaded.

### Added
- **Clear All Assignments** button on the Set Levels page, next to Assign Intervention Rooms — clears both the Intervention (Mon-Wed) and Th/Fri Intervention rooms (and the stale assignment code) for every student, without assigning anything new.
- **Lock IEP Rooms** checkbox on the Run Scheduler page, next to the reward toggle. When enabled, any student whose Intervention (Mon-Wed) room is typed as IEP is locked into that same room for Th/Fri as Step 0 of the weekly scheduler, before cross-subject swaps, teacher requests, D/F assignment, or reward assignment ever run — so IEP students are never moved out of their assigned room. Off by default.
- Assign Intervention Rooms now excludes students with "J" anywhere in their state `StudentStatus` code (CTC building all day) from the whole process — they're never touched or assigned a room.

## [2026072405] - 2026-07-26

### Added
- **Assign Intervention Rooms** — new one-time (or occasional) beginning-of-year process on the Set Levels page that sets every student's Intervention (Mon/Tue/Wed homeroom) room automatically. Clears all existing Intervention room assignments, then reassigns everyone in this order (a student already assigned by an earlier step is never reassigned by a later one):
  1. ELA Level 1, grade 10 → grade 10 ELA1 teacher (exact grade match)
  2. ELA Level 1, grade 11 → grade 11 ELA1 teacher (exact grade match)
  3. Math Level 1, all grades → each student's own grade's MTH1 teacher (exact match)
  4. ELA Level 2, grade 9 → the grade 9 ELA1 teacher (exact match)
  5. ELA Level 2, grades 10-12 → closest-grade ELA2 teacher
  6. Math Level 2, all grades → closest-grade MTH2 teacher
  7. Everyone else (no ELA/Math need, or unmatched above) → closest-grade General teacher, leveled toward whichever General room has fewer students so far
  - Students with "J" anywhere in their state `StudentStatus` code (CTC building all day) are excluded entirely and never assigned a room.
  - Capacity is ignored throughout, matching how reward rooms already behave.
- **Grade preference** field (9, 10, 11, 12, or Open) added to Room Settings for ELA1/ELA2/MTH1/MTH2/GEN rooms — drives the Assign Intervention Rooms matching above.
- **Intervention Counts** table on the Set Levels page — shows ELA/Math Level 1 and Level 2 counts broken out by grade, plus a "Both ELA & Math" column identifying students who need intervention in both subjects (only one can be served by a single Intervention room assignment).
- **Reward room scheduling on/off toggle** on the Run Scheduler page — when turned off, every room (including ones checked as Reward) is treated as a normal room for that run, and no student is assigned to a reward room. The setting is sticky (stored, not just a one-time form field).
- **Automatic weekly scheduling** via a proper Moodle scheduled task (default: Monday 8:00 AM). Enable/disable and reschedule it from Site administration → Server → Scheduled tasks, alongside all of Moodle's other scheduled tasks — no custom cron UI needed.
- **CSV export** on the Students page, in the exact column format the CSV importer expects (`sisid, ela, math, homeroom`), so scores/rooms can be exported, hand-edited, and re-imported. Respects the current group filter.
- Room Settings table now shows separate **Homeroom** and **Th/Fri** assignment-count columns (previously a single "Assigned" column only reflected Th/Fri), plus a single **Last Saved** timestamp next to the Save button instead of a per-room "Updated" column.

### Changed
- Seniors (12th grade) are now capped at intervention level 2 during Set Levels — never assigned level 1, in either subject.
- The room-displacement logic (used when a student is bumped to make room for a higher-priority assignment) now prefers whichever eligible room currently has the fewest students, instead of just the first one it finds — spreads displaced students out evenly.
- D/F grade assignment can now move a lower-priority student to a *different* same-type (ELA1/MTH1) room to free up a seat, so a D/F student can stay with their own actual teacher more often.

### Fixed
- A page could throw "Call to undefined function" when calling a `lib.php` function before `$OUTPUT->header()` — Moodle was only loading this plugin's `lib.php` as a side effect of building page navigation during header rendering. Pages now `require_once` `lib.php` explicitly instead of relying on that side effect.

## [2026072402] - 2026-07-24

### Added
- Per-room "Reward Room" checkbox on Room Settings — replaces the old single hardcoded reward room (room 100). Scheduler now distributes reward-eligible students evenly across all checked reward rooms, ignoring capacity.
- Tab-based navigation across the whole plugin (styled after the "My Student Data" plugin), replacing the old per-page button rows. Two shared tab bars added to `lib.php`: one for the four teacher-facing Intervention pages, one for the admin/manager hub pages. Tabs are reorderable and individually restrictable by role.
- New "Assignments" tab/page (`assignments.php`) — lets a manager pick a room and either "Intervention" (homeroom) or "Th/Fri Intervention" and see every student currently assigned there, read-only, with print support.
- "Behavior" tab added to the Intervention page group (visible to managers only), replacing its old location under Settings.
- Print support (clean single-page printouts, no Moodle header/nav/footer) added to Home Room Attendance, Thu/Fri Attendance, and the new Assignments page.
- Th/Fri Intervention column added to the Intervention (homeroom) attendance page, so teachers can see where their homeroom students go Thu/Fri.
- Group filter dropdown added to the Attendance Report (ELA/Math summary and Individual Student views).
- Scheduler enhancement: when a D/F student's own teacher's ELA1/MTH1 room is full of cross-subject-swap (E/M) students, the scheduler now tries moving one of those students to a different room of the same type so the D/F student can stay with their own teacher.
- Scheduler Results on the Run Scheduler page now persist across page visits (previously only shown immediately after a run).

### Changed
- Default "Minutes per intervention session" changed from 42 to 30.
- Settings page label "Student Settings" renamed to "Students"; "View All Requests" renamed to "All Requests".

### Fixed
- Reward rooms could previously still receive Teacher-requested (Q), D/F, and Homeroom-default (H) assignments, and could be trimmed by the over-capacity displacement pass — all four paths now correctly skip reward rooms, which are dedicated to reward students only.
- Request History page never checked the manager role, so the Settings and Behavior tabs never appeared there even for managers — fixed to match the other three Intervention pages.
- Several tables (Students, Room Settings, Assignments, both attendance pages) had header text that could wrap mid-word or fail to wrap at all due to missing `table-layout: fixed`.

### Removed
- Dead "Reward room number" setting (superseded by the per-room Reward Room checkbox).

## [2026042401] - 2026-04-29

### Added
- `setlevels.php` — new Set Levels page that automatically sets student ELA/Math intervention levels from Ohio State Test scores in the external `NTstatetesting` table
- Set Levels uses a configurable passing score (default 684, adjustable on the confirmation screen)
- Set Levels logic: High School ELA2 below passing → level 1; Eighth Grade ELA below passing → level 2; High School ALG1 below passing → level 1; Eighth Grade Math (M) below passing → level 2; always uses highest score found per subject
- Student linkage via `mdl_user.idnumber` → `mdl_contacts.StudentNumber` → `mdl_contacts.StateStudentID` → `mdl_NTstatetesting.ssid`
- Warning/confirmation screen before Set Levels runs, with Cancel button and passing score field
- Results summary showing updated / unchanged / skipped counts after Set Levels runs
- Set Levels button on Student Settings page (blue, between Return to Course and Import CSV)
- Set Levels button is hidden when `NTstatetesting` table does not exist or has no data

### Changed
- `install.xml` updated to include all 7 plugin tables — previously only `local_roomscheduler_rooms` was defined, causing fresh installations to fail with missing table errors
- `settings.php` — all 8 settings now have NTLS defaults pre-loaded on installation (previously the first 3 were empty); each setting has a clearly marked `*** NTLS DEFAULT ***` comment for easy adjustment
- Behavior page (`behavior.php`) — removed Incidents counter column; Deny Reward checkbox only

### Fixed
- Fresh installation now correctly creates all 7 required database tables (previously only 1 was created; the other 6 required an upgrade path that didn't run on new installs)
- External tables `NTstatetesting` and `contacts` now referenced via raw prefixed table names (`$DB->get_prefix()`) instead of Moodle `{}` syntax, which caused SQL errors since these tables are not managed by Moodle XMLDB
- Collation mismatch errors on JOIN between `mdl_contacts` and `mdl_NTstatetesting` resolved using `COLLATE utf8mb4_unicode_ci` on string comparisons

## [2026042203] - 2026-04-23

### Added
- Behavior denial feature for Principal to deny reward room by checkbox
- `local_roomscheduler_behavior` table with userid, sisid, denied flag, and incidents count
- Behavior management page (`behavior.php`) accessible from Student Scores
- Assignment code suffixes: **A** for attendance denial, **B** for behavior denial
- Step 4.6 counter tracking A/B students denied reward due to behavior
- B takes priority over A when both apply (students denied for both show only B)

### Changed
- `assignment_code` column expanded from CHAR(1) to VARCHAR(2) to hold suffix codes
- Attendance page code legends now show primary codes on one line and suffix codes on a separate line
- Students denied rewards no longer get a separate code — they fall through to normal flow (H/S/etc.) with the denial suffix appended

### Fixed
- Removed obsolete G (General) code from student code legend

## [2026042201] - 2026-04-22

### Added
- Request persistence — teachers can mark their requests to carry over to the next week
- `local_roomscheduler_teacher_prefs` table for per-teacher preferences
- `local_roomscheduler_request_archive` table for 4-week request history
- "Clear All Requests" button on All Requests page (counselor/admin only)
- Request History page accessible from Intervention Requests
- Archive logic in scheduler — archives current requests before clearing
- Automatic pruning of archive entries older than 4 weeks
- 4-week request history columns on the requests page
- HR Attendance and Intervention Attendance pages
- `local_roomscheduler_attendance` table with teacher/student/date/type/present
- Attendance codes E (ELA teacher) and M (Math teacher) for tracking mandated intervention hours
- Check All Present button and per-date attendance editing
- Attendance Report page (ELA Summary, Math Summary, Individual Student)
- Configurable minutes per intervention session
- Print button and print-friendly CSS on all reports
- Attendance threshold setting — denies reward room for chronically absent students
- Tardy-adjusted attendance calculation (4 tardies = 1 absent day)
- Step 4.5 in scheduler results showing students denied reward due to attendance
- CLI scheduler at `cli/runscheduler.php` for cron automation
- Automatic log rotation — keeps last 4 scheduler runs in `cli/logs/`
- Full Privacy API support for all 6 database tables
- README.md with complete plugin documentation

### Changed
- Grade query now filters by course idnumber pattern `^[0-9]{3,4}[A-Za-z]{2}$` to exclude non-academic courses
- Step 5 renamed from "General rooms" to "Other Moves (S)" — displaced student count
- Scheduler now uses two-step query approach for `teacherCourses` lookup to avoid `get_records_sql` key collisions
- Rooms with `maxstudents = 0` now treated as closed — ALL students displaced with code S
- Step 1 cross-subject swaps now fill MTH1/ELA1 rooms completely before overflowing to MTH2/ELA2
- Capacity enforcement pass added after all step assignments to handle over-capacity rooms
- Displacement logic prefers rooms where displaced student shares a course with teacher

### Fixed
- Return to Course button on Intervention Requests page (was using `courseid` instead of `id`)
- Reward room being assigned to students with poor attendance
- MTH2/ELA2 teachers getting students before MTH1/ELA1 teachers were full
- Teachers with deep subcategory course assignments not found in scheduler queries
- Over-capacity room assignments when students were assigned to their homeroom without capacity checks
- "Error writing to database" during scheduler run due to invalid `NOT =` SQL syntax

## [2026041900] - 2026-04-19

### Added
- `assignment_code` column on `local_roomscheduler_students` table
- Assignment codes E, M, Q, F, D, R, S, H to identify the reason for each student placement
- Assignment code legend displayed at bottom of student reports
- Two-pass displacement logic — students can be bumped from rooms to make space for higher priority
- Displacement prefers placing students in rooms of teachers they share courses with

### Changed
- Scheduler results now show code counts per step
- Reward room check now includes attendance threshold

## [2026041800] - 2026-04-18

### Added
- `local_roomscheduler_requests` table for teacher intervention requests
- Teacher intervention request page accessible from any category 2 course
- Counselor view of all requests with read-only listing
- HS category ID setting for configurable category targeting
- Navigation hook adds "Intervention Requests" link to category 2 course admin menus
- Core scheduling engine (`scheduler.php`) with 6-step priority algorithm
- Manual scheduler trigger page (`runscheduler.php`) for counselor testing
- Reward room, D/F threshold, A/B threshold settings

### Changed
- Scheduler reads grades from Moodle gradebook for HS category courses
- Students with all A/B grades get assigned to configurable reward room

## [2026041701] - 2026-04-17

### Added
- `local_roomscheduler_students` table for student scores and room assignments
- Student Scores page with ELA/Math priority (1-3), homeroom, and intervention room columns
- CSV import with SIS ID matching (fields: sisid, ela, math, homeroom)
- Import preview showing records changed with old/new values
- Group filter dropdown for student list

### Changed
- Row color-coding based on minimum score (red=1, yellow=2, green=3)

## [2026041600] - 2026-04-16

### Added
- Initial plugin release
- `local_roomscheduler_rooms` table for teacher room assignments
- Room Scheduler page accessible from counselor course admin menu
- Inline editing for room number, intervention type, and max capacity
- Intervention types: ELA1, ELA2, MTH1, MTH2, GEN, IEP, NA
- Teacher source course and counselor course settings
- Basic Privacy API provider for rooms table
