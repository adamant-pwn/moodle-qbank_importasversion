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

/**
 * Tests for the bulk import.
 *
 * @package   qbank_importasversion
 * @copyright 2026 ETH Zurich
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers    \qbank_importasversion\bulk_importer
 */
final class bulk_importer_test extends \advanced_testcase {
    /** @var \core_question_generator */
    protected $generator;

    /** @var \context the context of the question bank */
    protected $context;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $this->setAdminUser();
        $this->generator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $this->context = $this->create_bank();
    }

    /**
     * Create a question bank: a qbank module from Moodle 5.0, else a course.
     *
     * @return \context the context of the question bank.
     */
    private function create_bank(): \context {
        $course = $this->getDataGenerator()->create_course();
        if (\core_component::get_component_directory('mod_qbank')) {
            $qbank = $this->getDataGenerator()->create_module('qbank', ['course' => $course->id]);
            return \context_module::instance($qbank->cmid);
        }
        return \context_course::instance($course->id);
    }

    /**
     * Create a true/false question in a new category of the bank.
     *
     * @param string $idnumber the ID number.
     * @param \context|null $context the context, by default the bank.
     * @return \stdClass the question.
     */
    private function create_existing(string $idnumber, ?\context $context = null): \stdClass {
        $category = $this->generator->create_question_category(['contextid' => ($context ?? $this->context)->id]);
        return $this->generator->create_question('truefalse', null, ['category' => $category->id, 'idnumber' => $idnumber]);
    }

    /**
     * Write a Moodle XML file of true/false questions, or of questions of the given type.
     *
     * @param array $questions [idnumber, text, qtype] per question.
     * @return string the file path.
     */
    private function write_file(array $questions): string {
        $xml = '';
        foreach ($questions as [$idnumber, $text, $qtype]) {
            $xml .= '<question type="' . ($qtype ?? 'truefalse') . '"><name><text>File ' . $idnumber . '</text></name>'
                . '<questiontext format="html"><text><![CDATA[<p>' . $text . '</p>]]></text></questiontext>'
                . '<generalfeedback format="html"><text></text></generalfeedback>'
                . '<defaultgrade>1</defaultgrade><penalty>1</penalty><hidden>0</hidden><idnumber>' . $idnumber . '</idnumber>'
                . '<answer fraction="100" format="moodle_auto_format"><text>true</text><feedback><text></text></feedback></answer>'
                . '<answer fraction="0" format="moodle_auto_format"><text>false</text><feedback><text></text></feedback></answer>'
                . '<usecase>0</usecase></question>';
        }
        $path = make_request_directory() . '/bulk.xml';
        file_put_contents($path, '<?xml version="1.0" encoding="UTF-8"?><quiz>' . $xml . '</quiz>');
        return $path;
    }

    /**
     * Get the latest version of a question's bank entry.
     *
     * @param \stdClass $question the question.
     * @return int the version.
     */
    private function latest_version(\stdClass $question): int {
        global $DB;
        return (int) $DB->get_field('question_versions', 'MAX(version)', ['questionbankentryid' => $question->questionbankentryid]);
    }

    public function test_matched_questions_get_a_new_version(): void {
        global $DB;
        $a = $this->create_existing('ID-A');
        $b = $this->create_existing('ID-B');
        $entries = $DB->count_records('question_bank_entries');

        $result = bulk_importer::import(
            $this->write_file([['ID-A', 'New A', null], ['ID-B', 'New B', null]]),
            $this->context,
            false,
            false
        );

        $this->assertSame('', $result->errors);
        $this->assertSame(['imported', 'imported'], array_column($result->rows, 'outcome'));
        $this->assertSame(2, $this->latest_version($a));
        $this->assertSame(2, $this->latest_version($b));
        $this->assertSame($entries, $DB->count_records('question_bank_entries'));
        $this->assertSame('ID-A', $DB->get_field('question_bank_entries', 'idnumber', ['id' => $a->questionbankentryid]));
    }

    public function test_questions_without_exactly_one_match_are_skipped(): void {
        $a = $this->create_existing('ID-A');
        $twice1 = $this->create_existing('TWICE');
        $twice2 = $this->create_existing('TWICE');
        $elsewhere = $this->create_existing('ELSEWHERE', $this->create_bank());
        $file = $this->write_file([
            ['', 'No ID number', null],
            ['NOPE', 'Unknown', null],
            ['TWICE', 'Ambiguous', null],
            ['ELSEWHERE', 'In another bank', null],
            ['ID-A', 'Wrong type', 'shortanswer'],
            ['DUP', 'First', null],
            ['DUP', 'Second', null],
        ]);

        $result = bulk_importer::import($file, $this->context, false, false);

        $this->assertSame(array_fill(0, 7, 'skipped'), array_column($result->rows, 'outcome'));
        $this->assertSame([
            get_string('bulknoidnumber', 'qbank_importasversion'),
            get_string('bulknotfound', 'qbank_importasversion'),
            get_string('bulkambiguous', 'qbank_importasversion'),
            get_string('bulknotfound', 'qbank_importasversion'),
            get_string('bulktypemismatch', 'qbank_importasversion'),
            get_string('bulkduplicateinfile', 'qbank_importasversion'),
            get_string('bulkduplicateinfile', 'qbank_importasversion'),
        ], array_column($result->rows, 'message'));
        foreach ([$a, $twice1, $twice2, $elsewhere] as $question) {
            $this->assertSame(1, $this->latest_version($question));
        }
    }

    public function test_a_file_without_questions_is_an_error(): void {
        $path = make_request_directory() . '/empty.xml';
        file_put_contents($path, '<?xml version="1.0" encoding="UTF-8"?><quiz></quiz>');

        $result = bulk_importer::import($path, $this->context, false, false);

        $this->assertNotSame('', $result->errors);
        $this->assertSame([], $result->rows);
    }
}
