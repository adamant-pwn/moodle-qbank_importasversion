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
 * Plugin strings are defined here.
 *
 * @package   qbank_importasversion
 * @category  string
 * @copyright 2023 MootDACH DevCamp
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['bulkambiguous'] = 'More than one question in this question bank has this ID number.';
$string['bulkduplicateinfile'] = 'This ID number occurs more than once in the file.';
$string['bulkfailed'] = 'Failed';
$string['bulkimport'] = 'Import new versions in bulk';
$string['bulkimported'] = 'New version';
$string['bulkimportintro'] = 'Each question in the file becomes a new version of the question with the same ID number in this question bank. Questions without such a match are skipped; use the normal import to add new questions.';
$string['bulknoidnumber'] = 'The question in the file has no ID number.';
$string['bulknopermission'] = 'You may not edit the existing question.';
$string['bulknotfound'] = 'No question in this question bank has this ID number.';
$string['bulkskipped'] = 'Skipped';
$string['bulktypemismatch'] = 'The existing question has another question type.';
$string['event:question_version_imported'] = 'Question version imported';
$string['forceimport'] = 'Import with warnings as Draft';
$string['forceimport_help'] = 'By default, question-type save warnings stop this upload. Enable this option to retain a version with warnings as Draft for review or repair, leaving existing versions unchanged. Imports without warnings are still saved as Ready. Save errors cannot be overridden.';
$string['importandmergetags'] = 'Import and merge tags';
$string['importasversion'] = 'Import a new version';
$string['importasversion_help'] = 'Imports a question from a file but creates a new version of an existing question';
$string['importedwithwarningsasdraft'] = 'The new version was imported as Draft for review or repair.';
$string['importerror'] = 'Error while importing the file';
$string['importnewversionofx'] = 'Import a new version of question {$a}';
$string['pluginname'] = 'Import question as new version';
$string['privacy:metadata'] = 'The Import question as new version question bank plugin does not store any personal data.';
$string['questionimportedasversion'] = 'New version of question \'{$a}\' imported successfully.';
$string['questionimportedwithwarnings'] = 'New version of question \'{$a->name}\' imported successfully. However, an issue was raised: {$a->notice}';
$string['questionimportfailed'] = 'Question import failed for \'{$a->name}\'. {$a->error}';
$string['toomanyquestionsinfile'] = 'Your file did not contain exactly one question!';
$string['unknownerror'] = 'There was an error saving the question.';
