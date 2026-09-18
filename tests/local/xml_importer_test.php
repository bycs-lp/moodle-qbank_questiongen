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

namespace qbank_questiongen\local;

use core_question\local\bank\question_bank_helper;
use question_bank;
use question_definition;
use stdClass;

/**
 * Unit tests for the xml_importer class.
 *
 * @package   qbank_questiongen
 * @copyright 2025 ISB Bayern
 * @author    Philipp Memmel
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers    \local_ai_manager\local\observers
 */
#[\PHPUnit\Framework\Attributes\CoversClass(xml_importer::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\qformat_xml::class)]
final class xml_importer_test extends \advanced_testcase {
    /**
     * Import the shipped library for each question type installed on this site.
     */
    public function test_extended_preset_library(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/question/format/xml/format.php');
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('aiidentifier', '', 'qbank_questiongen');
        set_config('aiidentifiertag', '', 'qbank_questiongen');
        $course = $this->getDataGenerator()->create_course();
        $bank = question_bank_helper::create_default_open_instance($course, 'presetlibrary');
        question_get_top_category($bank->context->id, true);
        $generator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $library = json_decode(file_get_contents(__DIR__ . '/../../docs/presets-library.json'), false, 32, JSON_THROW_ON_ERROR);
        $this->assertCount(21, $library->presets);
        foreach ($library->presets as $preset) {
            $xml = $preset->example;
            $this->assertSame('<?xml version="1.0" encoding="UTF-8"?>', strtok($xml, "\r\n"), $preset->name);
            $xmltype = (string) simplexml_load_string($xml)->question['type'];
            $qtype = ['matching' => 'match', 'cloze' => 'multianswer'][$xmltype] ?? $xmltype;
            if (!question_bank::get_qtype($qtype, false)) {
                continue;
            }
            $filecount = $DB->count_records('files');
            $types = xml_importer::validate_question($xml);
            $this->assertSame($filecount, $DB->count_records('files'));
            $this->assertSame($qtype, $types->qtype);
            $decoded = preset_transfer::decode(preset_transfer::encode([$preset]));
            $this->assertSame($qtype, $decoded[0]->qtype);
            $target = $generator->create_question_category(['contextid' => $bank->context->id]);
            ob_start();
            try {
                $imported = xml_importer::parse_questions($target->id, (object) ['text' => $xml], false);
            } finally {
                ob_end_clean();
            }
            $this->assertTrue($imported, $qtype);
            $ids = question_bank::get_finder()->get_questions_from_categories([$target->id], null);
            $this->assertCount(1, $ids, $qtype);
        }
    }

    /**
     * Validate preset documents before any importing operation.
     */
    public function test_validate_question(): void {
        $presets = json_decode(file_get_contents(__DIR__ . '/../../db/initial_presets.json'));
        $this->assertSame('match', xml_importer::validate_question($presets[2]->example)->qtype);
        $this->assertSame('matching', xml_importer::validate_question($presets[2]->example)->xmltype);
        foreach (
            [
            '',
            '<quiz/>',
            '<quiz><question type="category"/></quiz>',
            '<quiz><question type="description"/></quiz>',
            '<quiz><question type="unknown"/></quiz>',
            '<quiz><question type="essay"/><question type="essay"/></quiz>',
            '<!DOCTYPE quiz [<!ENTITY content "unsafe">]><quiz><question type="essay"/></quiz>',
            ] as $xml
        ) {
            try {
                xml_importer::validate_question($xml);
                $this->fail('Invalid XML was accepted');
            } catch (\invalid_parameter_exception $exception) {
                $this->assertNotEmpty($exception->getMessage());
            }
        }
    }

    /**
     * Type mismatches are rejected before category lookup or database writes.
     */
    public function test_reject_unexpected_type(): void {
        $presets = json_decode(file_get_contents(__DIR__ . '/../../db/initial_presets.json'));
        $question = (object) ['text' => $presets[0]->example,
            'expectedtype' => (object) ['qtype' => 'match', 'xmltype' => 'matching']];
        $this->assertFalse(xml_importer::parse_questions(0, $question, false));
        $this->assertFalse(xml_importer::parse_questions(0, (object) ['text' => '<quiz/>'], false));
    }

    /**
     * Verify that preset types can be resolved without importing questions.
     */
    public function test_preset_type_resolution(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/question/format/xml/format.php');
        $this->resetAfterTest();

        $presets = json_decode(file_get_contents(__DIR__ . '/../../db/initial_presets.json'), true, 512, JSON_THROW_ON_ERROR);
        $expectedtypes = ['multichoice', 'truefalse', 'match', 'shortanswer', 'numerical'];
        $questioncount = $DB->count_records('question');
        foreach ($presets as $index => $preset) {
            $format = new \qformat_xml();
            $questions = $format->readquestions([$preset['example']]);
            $this->assertCount(1, $questions);
            $this->assertSame($expectedtypes[$index], $questions[0]->qtype);
            $this->assertSame(0, $format->importerrors);
            $this->assertSame($expectedtypes[$index], question_bank::get_qtype($questions[0]->qtype, false)->name());
        }

        $format = new \qformat_xml();
        $questions = $format->readquestions([
            '<quiz><question type="essay"><name><text>Transfer</text></name>' .
            '<questiontext format="html"><text>Explain your reasoning.</text></questiontext>' .
            '</question></quiz>',
        ]);
        $this->assertCount(1, $questions);
        $this->assertSame('essay', $questions[0]->qtype);
        $this->assertSame(0, $format->importerrors);
        $this->assertSame($questioncount, $DB->count_records('question'));
    }

    /**
     * Verify complete imports for shipped presets and a delegated XML type.
     */
    public function test_preset_examples_import(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/question/format/xml/format.php');
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('aiidentifier', '', 'qbank_questiongen');
        set_config('aiidentifiertag', '', 'qbank_questiongen');

        $course = $this->getDataGenerator()->create_course();
        $qbank = question_bank_helper::create_default_open_instance($course, 'spikequestionbank');
        question_get_top_category($qbank->context->id, true);
        $generator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $presets = json_decode(file_get_contents(__DIR__ . '/../../db/initial_presets.json'), true, 512, JSON_THROW_ON_ERROR);
        $presets[] = ['example' => '<quiz><question type="gapselect"><name><text>Gap selection</text></name>' .
            '<questiontext format="html"><text>Water freezes at [[1]] degrees Celsius.</text></questiontext>' .
            '<selectoption><text>0</text><group>1</group></selectoption>' .
            '<selectoption><text>100</text><group>1</group></selectoption></question></quiz>'];
        $expectedtypes = ['multichoice', 'truefalse', 'match', 'shortanswer', 'numerical', 'gapselect'];

        foreach ($presets as $index => $preset) {
            $category = $generator->create_question_category(['contextid' => $qbank->context->id]);
            $format = new \qformat_xml();
            $parsed = $format->readquestions([$preset['example']]);
            $this->assertCount(1, $parsed);
            $this->assertSame($expectedtypes[$index], $parsed[0]->qtype);
            $this->assertSame(0, $format->importerrors);

            ob_start();
            try {
                $success = xml_importer::parse_questions($category->id, (object) ['text' => $preset['example']], false);
            } finally {
                $output = ob_get_clean();
            }
            $this->assertTrue($success, $output);
            $questionids = question_bank::get_finder()->get_questions_from_categories([$category->id], null);
            $this->assertCount(1, $questionids);
            $this->assertSame($expectedtypes[$index], $DB->get_field('question', 'qtype', ['id' => reset($questionids)]));
        }
    }

    /**
     * Characterise XML reader results that require additional preset validation.
     */
    public function test_preset_reader_boundaries(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/question/format/xml/format.php');
        $this->resetAfterTest();
        $questioncount = $DB->count_records('question');
        $essay = '<question type="essay"><name><text>Transfer</text></name>' .
            '<questiontext format="html"><text>Explain your reasoning.</text></questiontext></question>';
        $format = new \qformat_xml();
        $this->assertCount(2, $format->readquestions(['<quiz>' . $essay . $essay . '</quiz>']));
        $this->assertSame(0, $format->importerrors);

        $format = new \qformat_xml();
        $questions = $format->readquestions([
            '<quiz><question type="category"><category><text>Unwanted category</text></category></question></quiz>',
        ]);
        $this->assertCount(1, $questions);
        $this->assertSame('category', $questions[0]->qtype);
        $this->assertSame(0, $format->importerrors);

        $format = new \qformat_xml();
        ob_start();
        try {
            $questions = $format->readquestions(['<quiz><question type="questiongenspikeunknown"/></quiz>']);
        } finally {
            ob_end_clean();
        }
        $this->assertSame([], $questions);
        $this->assertSame(1, $format->importerrors);
        $this->assertSame($questioncount, $DB->count_records('question'));
    }

    /**
     * Tests the functionality that substitutes certain placeholders in a string.
     *
     * @covers \qbank_questiongen\local\xml_importer::parse_questions
     * @covers \qbank_questiongen\local\xml_importer::add_aiidentifiers
     */
    public function test_parse_questions(): void {
        global $CFG;
        require_once($CFG->dirroot . '/question/engine/bank.php');
        $this->resetAfterTest();

        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $qbankcminfo = question_bank_helper::create_default_open_instance($course, 'testquestionbank');
        question_get_top_category($qbankcminfo->context->id, true);

        $qgen = $this->getDataGenerator()->get_plugin_generator('core_question');

        $qcat = $qgen->create_question_category(['contextid' => $qbankcminfo->context->id]);

        $questionidsincategory = question_bank::get_finder()->get_questions_from_categories([$qcat->id], null);
        $this->assertCount(0, $questionidsincategory);

        $question = $this->import_question($qcat->id, false);

        $this->assertEquals('French Revolution Cause', $question->name);
        $this->assertEquals(
            '<p>Which of the following was a major contributing factor to the outbreak of the French Revolution?</p>',
            $question->questiontext
        );

        // Reset, so we can run the test again with different aiidentifier parameter.
        question_delete_question($question->id);

        // Now both global configs are empty, that means we have no aiidentifier no matter what is passed to the function.
        set_config('aiidentifier', '', 'qbank_questiongen');
        set_config('aiidentifiertag', '', 'qbank_questiongen');
        $question = $this->import_question($qcat->id, true);
        $this->assertEquals('French Revolution Cause', $question->name);
        $this->assertEmpty(\core_tag_tag::get_item_tags('core_question', 'question', $question->id));

        // Reset, so we can run the test again with different aiidentifier parameter.
        question_delete_question($question->id);

        // Now both global configs are empty, that means we have no aiidentifier no matter what is passed to the function.
        set_config('aiidentifier', '', 'qbank_questiongen');
        set_config('aiidentifiertag', 'aigenerated', 'qbank_questiongen');
        $question = $this->import_question($qcat->id, true);
        $this->assertEquals('French Revolution Cause', $question->name);
        $tags = \core_tag_tag::get_item_tags('core_question', 'question', $question->id);
        $this->assertCount(1, $tags);
        $this->assertEquals('aigenerated', reset($tags)->get_display_name());

        // Reset, so we can run the test again with different aiidentifier parameter.
        question_delete_question($question->id);

        set_config('aiidentifier', 'AI generated: ', 'qbank_questiongen');
        set_config('aiidentifiertag', 'aigenerated', 'qbank_questiongen');
        $question = $this->import_question($qcat->id, true);
        $tags = \core_tag_tag::get_item_tags('core_question', 'question', $question->id);
        $this->assertCount(1, $tags);
        $this->assertEquals('AI generated: French Revolution Cause', $question->name);
    }

    /**
     * Helper function importing a fixture question and returns the imported question.
     *
     * @param int $qcatid The question category id to which the question should be imported
     * @param bool $addidentifier if a prefix should be added to the title
     * @return question_definition the imported question as question_definition object
     */
    private function import_question(int $qcatid, bool $addidentifier): question_definition {
        global $CFG;
        $xml = file_get_contents($CFG->dirroot . '/question/bank/questiongen/tests/fixtures/multichoice.xml');
        $question = new stdClass();
        $question->text = $xml;
        ob_start();
        xml_importer::parse_questions($qcatid, $question, $addidentifier);
        ob_end_clean();
        $questionidsincategory = question_bank::get_finder()->get_questions_from_categories([$qcatid], null);
        $this->assertCount(1, $questionidsincategory);
        return question_bank::load_question(reset($questionidsincategory));
    }
}
