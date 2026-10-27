<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Import many questions from one file, each as a new version of the question with the same ID number.
 *
 * @package   qbank_importasversion
 * @copyright 2026 ETH Zurich
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use qbank_importasversion\bulk_importer;
use qbank_importasversion\form\import_form;

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/question/editlib.php');

require_login();
core_question\local\bank\helper::require_plugin_enabled('qbank_importasversion');
[$thispageurl, $contexts] = question_edit_setup('import', '/question/bank/importasversion/bulkimport.php');
$returnurl = new moodle_url('/question/edit.php', $thispageurl->params());

$title = get_string('bulkimport', 'qbank_importasversion');
$PAGE->set_url($thispageurl);
$PAGE->set_title($title);
$PAGE->set_heading($COURSE->fullname);
$PAGE->activityheader->disable();

$importform = new import_form($thispageurl);
if ($importform->is_cancelled()) {
    redirect($returnurl);
}

echo $OUTPUT->header();
$renderer = $PAGE->get_renderer('core_question', 'bank');
if (class_exists('\core_question\output\qbank_action_menu')) {
    echo $renderer->render(new \core_question\output\qbank_action_menu($thispageurl));
} else if (class_exists('\core_question\output\qbank_actionbar')) {
    // Moodle 4.0 to 4.2.
    echo $renderer->render(new \core_question\output\qbank_actionbar($thispageurl));
}
echo $OUTPUT->heading($title);

if (!$fromform = $importform->get_data()) {
    echo html_writer::div(get_string('bulkimportintro', 'qbank_importasversion'), 'mb-3');
    $importform->display();
    echo $OUTPUT->footer();
    exit;
}

$importfile = make_request_directory() . '/bulk.xml';
if (!$importform->save_file('newfile', $importfile, true)) {
    throw new moodle_exception('uploadproblem');
}
// Remove the upload, so that reloading this page cannot import it a second time.
get_file_storage()->delete_area_files(context_user::instance($USER->id)->id, 'user', 'draft', $fromform->newfile);

$result = bulk_importer::import($importfile, $contexts->lowest(), isset($fromform->mergetagsbutton), !empty($fromform->force));
if ($result->errors !== '') {
    echo $OUTPUT->notification(s($result->errors), \core\output\notification::NOTIFY_ERROR);
}
$table = new html_table();
$table->head = [get_string('name'), get_string('idnumber', 'question'), get_string('status'), get_string('details')];
foreach ($result->rows as $row) {
    $table->data[] = [s($row->name), s($row->idnumber),
        get_string('bulk' . $row->outcome, 'qbank_importasversion'), format_string($row->message)];
}
echo html_writer::table($table);
echo $OUTPUT->continue_button($returnurl);
echo $OUTPUT->footer();
