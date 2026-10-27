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

namespace qbank_importasversion;

use context;
use qformat_xml;
use stdClass;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/question/format/xml/format.php');
require_once($CFG->libdir . '/questionlib.php');

/**
 * Import the questions of one Moodle XML file, each as a new version of an existing question.
 *
 * Each question is matched by its ID number to the one question with that ID number in the
 * question bank. Questions that do not match exactly one question are skipped, never created.
 *
 * @package   qbank_importasversion
 * @copyright 2026 ETH Zurich
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class bulk_importer {
    /**
     * Import a file.
     *
     * @param string $file path of the Moodle XML file.
     * @param context $context the context of the question bank.
     * @param bool $mergetags merge the file's tags with the replaced version's tags.
     * @param bool $force retain a version that has save warnings, as Draft.
     * @return stdClass errors (messages about the file) and rows (one per question: name, idnumber,
     *      outcome imported, skipped or failed, and message).
     */
    public static function import(string $file, context $context, bool $mergetags, bool $force): stdClass {
        global $COURSE, $DB;
        $DB->transactions_forbidden();

        $qformat = new qformat_xml();
        $qformat->displayprogress = false;
        $qformat->setCourse($COURSE);
        $result = (object) ['errors' => '', 'rows' => []];
        ob_start();
        $lines = is_readable($file) ? file($file) : false;
        // The core reader fails on a file without any question element.
        $questions = $lines && stripos(implode('', $lines), '<question') !== false ? $qformat->readquestions($lines) : false;
        $result->errors = trim(strip_tags(ob_get_clean()));
        if (!$questions) {
            $result->errors = $result->errors ?: get_string('noquestionsinfile', 'question');
            return $result;
        }

        $questions = array_filter($questions, fn($question) => $question->qtype !== 'category');
        $idnumbers = array_count_values(array_map(fn($question) => (string) ($question->idnumber ?? ''), $questions));
        foreach ($questions as $question) {
            $idnumber = (string) ($question->idnumber ?? '');
            $row = (object) ['name' => $question->name, 'idnumber' => $idnumber, 'outcome' => 'skipped', 'message' => ''];
            $result->rows[] = $row;
            if ($idnumber === '') {
                $row->message = get_string('bulknoidnumber', 'qbank_importasversion');
            } else if ($idnumbers[$idnumber] > 1) {
                $row->message = get_string('bulkduplicateinfile', 'qbank_importasversion');
            } else if ($existing = self::find($context, $idnumber, $question, $row)) {
                self::save($qformat, $existing, $question, $mergetags, $force, $row);
            }
        }
        return $result;
    }

    /**
     * Find the one question in the bank with this ID number that may get a new version.
     *
     * @param context $context the context of the question bank.
     * @param string $idnumber the ID number.
     * @param stdClass $question the question from the file.
     * @param stdClass $row the row, whose message says why there is none.
     * @return stdClass|null the latest version of the question.
     */
    protected static function find(context $context, string $idnumber, stdClass $question, stdClass $row): ?stdClass {
        global $DB;
        $matches = $DB->get_records_sql(
            "SELECT qv.questionid AS id, qbe.id AS questionbankentryid, qbe.questioncategoryid AS category,
                    qc.contextid, q.qtype, q.createdby
               FROM {question_bank_entries} qbe
               JOIN {question_categories} qc ON qc.id = qbe.questioncategoryid
               JOIN {question_versions} qv ON qv.questionbankentryid = qbe.id
               JOIN {question} q ON q.id = qv.questionid
              WHERE qc.contextid = :contextid AND qbe.idnumber = :idnumber AND q.parent = 0
                    AND qv.version = (SELECT MAX(v.version) FROM {question_versions} v WHERE v.questionbankentryid = qbe.id)",
            ['contextid' => $context->id, 'idnumber' => $idnumber]
        );
        if (count($matches) !== 1) {
            $row->message = get_string($matches ? 'bulkambiguous' : 'bulknotfound', 'qbank_importasversion');
            return null;
        }
        $existing = reset($matches);
        if ($existing->qtype !== $question->qtype) {
            $row->message = get_string('bulktypemismatch', 'qbank_importasversion');
            return null;
        }
        if (!question_has_capability_on($existing, 'edit')) {
            $row->message = get_string('bulknopermission', 'qbank_importasversion');
            return null;
        }
        return $existing;
    }

    /**
     * Save a question as a new version, in its own transaction, as the single-question import does.
     *
     * @param qformat_xml $qformat the format the file was read with.
     * @param stdClass $existing the latest version of the existing question.
     * @param stdClass $question the question from the file.
     * @param bool $mergetags merge the file's tags with the replaced version's tags.
     * @param bool $force retain a version that has save warnings, as Draft.
     * @param stdClass $row the row, updated with the outcome.
     */
    protected static function save(
        qformat_xml $qformat,
        stdClass $existing,
        stdClass $question,
        bool $mergetags,
        bool $force,
        stdClass $row
    ): void {
        global $DB;
        try {
            $saved = importer::import_question_as_version($qformat, $existing, $question, $mergetags, $force, true);
        } catch (\Throwable $e) {
            if ($DB->is_transaction_started()) {
                $DB->force_transaction_rollback();
            }
            debugging($e->getMessage(), DEBUG_DEVELOPER);
            $saved = (object) ['error' => get_string('unknownerror', 'qbank_importasversion')];
        }
        $row->outcome = empty($saved->error) ? 'imported' : 'failed';
        $row->message = $saved->error ?? $saved->notice ?? '';
    }
}
