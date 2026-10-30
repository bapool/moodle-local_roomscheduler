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
 * Delete a room assignment for local_roomscheduler.
 *
 * @package    local_roomscheduler
 * @copyright  2026 Brian Pool
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

require_login();

$context = context_system::instance();

// Managers only.
require_capability('local/roomscheduler:manage', $context);

// Get and validate parameters.
$id = required_param('id', PARAM_INT);
require_sesskey();

// Make sure the record exists.
$record = $DB->get_record('local_roomscheduler_rooms', ['id' => $id], '*', MUST_EXIST);

// Delete it.
$DB->delete_records('local_roomscheduler_rooms', ['id' => $id]);

// Redirect back with success message.
redirect(
    new moodle_url('/local/roomscheduler/index.php'),
    get_string('deletesuccess', 'local_roomscheduler'),
    null,
    \core\output\notification::NOTIFY_SUCCESS
);
