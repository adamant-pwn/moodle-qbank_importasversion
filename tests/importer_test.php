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

namespace qbank_importasversion;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/question/format/xml/format.php');

/**
 * Import validation and persistence tests.
 *
 * @package qbank_importasversion
 * @copyright 2026 Oleksandr Kulkov
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \qbank_importasversion\importer
 */
final class importer_test extends \advanced_testcase {
    /**
     * Generic question-type save results determine whether a new version is committed.
     *
     * @dataProvider save_results
     * @param mixed $outcome Question-type save result.
     * @param bool|null $force Null exercises the legacy three-argument call.
     * @param bool $committed Whether the import should commit.
     * @param bool $draft Whether the new version should be Draft.
     */
    public function test_save_result_policy($outcome, ?bool $force, bool $committed, bool $draft = false): void {
        global $DB;
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $this->setAdminUser();
        $generator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $category = $generator->create_question_category();
        $data = $generator->create_question('truefalse', null, ['category' => $category->id]);
        $question = \question_bank::load_question($data->id);
        $before = $DB->get_records('question_versions');
        $questions = $DB->count_records('question');
        $property = new \ReflectionProperty(\question_bank::class, 'questiontypes');
        $property->setAccessible(true);
        $original = $property->getValue();
        $types = $original;
        $types['truefalse'] = $this->getMockBuilder(\qtype_truefalse::class)
            ->onlyMethods(['save_question_options'])->getMock();
        $types['truefalse']->expects($this->once())->method('save_question_options')->willReturn($outcome);
        $property->setValue(null, $types);
        $format = new \qformat_xml();
        $format->displayprogress = false;
        $sink = $this->redirectEvents();
        try {
            $file = __DIR__ . '/fixtures/edited-true-false-question.xml';
            $result = $force === null ? importer::import_file($format, $question, $file)
                : ($draft ? importer::import_file($format, $question, $file, $force, true)
                    : importer::import_file($format, $question, $file, $force));
        } finally {
            $property->setValue(null, $original);
        }
        $after = $DB->get_records('question_versions');
        if ($committed) {
            $this->assertEmpty($result->error ?? null);
            $this->assertCount(count($before) + 1, $after);
            $new = array_values(array_diff_key($after, $before));
            $this->assertEquals($draft ? 'draft' : 'ready', $new[0]->status);
            $this->assertEquals([$new[0]->questionid], $format->questionids);
            $events = array_filter($sink->get_events(), static function ($event) {
                return $event instanceof \qbank_importasversion\event\question_version_imported;
            });
            $this->assertCount(1, $events);
            if (!empty($outcome->notice)) {
                $this->assertEquals($outcome->notice, $result->notice);
            }
        } else {
            $this->assertNotEmpty($result->error ?? null);
            $this->assertEquals($before, $after);
            $this->assertEquals($questions, $DB->count_records('question'));
            $this->assertEmpty($sink->get_events());
            $this->assertEmpty($format->questionids);
            if (!empty($outcome->notice) && empty($outcome->error)) {
                $this->assertEquals($outcome->notice, $result->error);
            }
        }
        foreach ($before as $id => $version) {
            $this->assertEquals($version, $after[$id]);
        }
    }

    /** @return array Save outcomes with strict, forced and legacy API policies. */
    public static function save_results(): array {
        $cases = [];
        foreach ([false, true, null] as $force) {
            $policy = $force === null ? 'legacy' : ($force ? 'forced' : 'strict');
            foreach ([
                'success' => true,
                'null' => null,
                'notice' => (object) ['notice' => 'Question-type warning'],
                'error' => (object) ['error' => 'Question-type failure'],
                'error and notice' => (object) ['error' => 'Failure', 'notice' => 'Warning'],
                'false' => false,
            ] as $name => $outcome) {
                $committed = $outcome !== false && empty($outcome->error)
                    && ($force !== false || empty($outcome->notice));
                $cases[$policy . ' ' . $name] = [$outcome, $force, $committed];
                if ($force !== null) {
                    $cases[$policy . ' draft ' . $name] = [$outcome, $force, $committed, true];
                }
            }
        }
        return $cases;
    }

    /**
     * A valid question can be Ready or Draft without enabling the warning override.
     *
     * @dataProvider draft_choices
     * @param bool $draft Whether to create a Draft version.
     */
    public function test_valid_question_imports_without_force(bool $draft): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $generator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $category = $generator->create_question_category();
        $data = $generator->create_question('truefalse', null, ['category' => $category->id]);
        $question = \question_bank::load_question($data->id);
        $before = $DB->get_records('question_versions');
        $format = new \qformat_xml();
        $format->displayprogress = false;
        $result = importer::import_file($format, $question, __DIR__ . '/fixtures/edited-true-false-question.xml', false, $draft);
        $this->assertEmpty($result->error ?? null);
        $after = $DB->get_records('question_versions');
        $new = array_values(array_diff_key($after, $before));
        $this->assertCount(1, $new);
        $this->assertEquals($draft ? 'draft' : 'ready', $new[0]->status);
        $loaded = \question_bank::load_question($new[0]->questionid);
        $this->assertInstanceOf(\qtype_truefalse_question::class, $loaded);
        $available = \question_bank::get_finder()->get_questions_from_categories([$category->id], '');
        $this->assertEquals([$draft ? $question->id : $new[0]->questionid], array_values($available));
        foreach ($before as $id => $version) {
            $this->assertEquals($version, $after[$id]);
        }
    }

    /** @return array Explicit publication choices. */
    public static function draft_choices(): array {
        return [[false], [true]];
    }

    /**
     * Exercise the real STACK parser when the optional question type is installed.
     *
     * @dataProvider stack_input_types
     * @param string $type STACK input type.
     * @param bool $force Whether to allow question-type save notices.
     * @param bool $draft Whether the new version should be Draft.
     */
    public function test_stack_missing_validation_obeys_force(string $type, bool $force = false, bool $draft = false): void {
        global $DB, $CFG, $PAGE;
        if (!is_dir($CFG->dirroot . '/question/type/stack')) {
            $this->markTestSkipped('Optional integration test requires STACK.');
        }
        $this->resetAfterTest();
        $this->preventResetByRollback();
        require_once($CFG->dirroot . '/question/type/stack/tests/fixtures/test_base.php');
        \qtype_stack_testcase::setup_test_maxima_connection($this);
        $this->setAdminUser();
        $generator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $category = $generator->create_question_category();
        $data = $generator->create_question('stack', 'test1', ['category' => $category->id]);
        $question = \question_bank::load_question($data->id);
        $PAGE->set_pagetype('question-bank-importasversion-import');
        $xml = '<quiz><question type="stack"><name><text>Invalid dropdown</text></name>
            <questiontext format="html"><text>[[input:ans1]]</text></questiontext>
            <questionvariables><text>ta1:[[1,true],[2,false]];</text></questionvariables>
            <specificfeedback format="html"><text></text></specificfeedback>
            <questionnote><text>Dropdown with no validation marker</text></questionnote>
            <input><name>ans1</name><type>' . $type . '</type><tans>ta1</tans>
            <mustverify>0</mustverify><showvalidation>0</showvalidation></input>
            </question></quiz>';
        $file = make_request_directory() . '/invalid-dropdown.xml';
        file_put_contents($file, $xml);
        $format = new \qformat_xml();
        $format->displayprogress = false;
        $before = $DB->get_records('question_versions', ['questionbankentryid' => $question->questionbankentryid]);
        $questions = $DB->count_records('question');
        $result = importer::import_file($format, $question, $file, $force, $draft);
        $after = $DB->get_records('question_versions', ['questionbankentryid' => $question->questionbankentryid]);
        if ($force) {
            $newversions = array_values(array_diff_key($after, $before));
            $this->assertCount(1, $newversions);
            $this->assertEquals($draft ? 'draft' : 'ready', $newversions[0]->status);
            $available = \question_bank::get_finder()->get_questions_from_categories([$category->id], '');
            $this->assertEquals([$draft ? $question->id : $newversions[0]->questionid], array_values($available));
            $this->assertEquals(1, $DB->get_field(
                'qtype_stack_options',
                'isbroken',
                ['questionid' => $newversions[0]->questionid]
            ));
            $this->assertStringContainsString('[[validation:ans1]]', $result->notice);
            foreach ($before as $id => $version) {
                $this->assertEquals($version, $after[$id]);
            }
            return;
        }
        $this->assertEquals($before, $after, 'Invalid import must not create a new Ready version.');
        $this->assertEquals($questions, $DB->count_records('question'));
        $this->assertNotEmpty($result->error ?? null);
        $this->assertStringContainsString('[[validation:ans1]]', $result->error);
    }

    /**
     * Selection inputs and expression inputs must all preserve the existing version on failure.
     *
     * @return array
     */
    public static function stack_input_types(): array {
        return [
            'algebraic' => ['algebraic'],
            'boolean' => ['boolean'],
            'checkbox' => ['checkbox'],
            'dropdown' => ['dropdown'],
            'dropdown forced' => ['dropdown', true],
            'dropdown forced draft' => ['dropdown', true, true],
            'equiv' => ['equiv'],
            'freetext' => ['freetext'],
            'geogebra' => ['geogebra'],
            'json' => ['json'],
            'matrix' => ['matrix'],
            'notes' => ['notes'],
            'numerical' => ['numerical'],
            'parsons' => ['parsons'],
            'radio' => ['radio'],
            'singlechar' => ['singlechar'],
            'string' => ['string'],
            'textarea' => ['textarea'],
            'units' => ['units'],
            'varmatrix' => ['varmatrix'],
        ];
    }

}
