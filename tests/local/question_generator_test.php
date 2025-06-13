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
use qbank_questiongen\form\story_form;
use stdClass;

/**
 * Unit tests for the question_generator class.
 *
 * @package   qbank_questiongen
 * @copyright 2025 ISB Bayern
 * @author    Philipp Memmel
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class question_generator_test extends \advanced_testcase {

    /**
     * Tests the functionality that substitutes certain placeholders in a string.
     *
     * @covers \qbank_questiongen\local\question_generator::generate_question
     */
    public function test_generate_question(): void {
        global $CFG;
        $this->resetAfterTest();
        set_config('provider', 'local_ai_manager', 'qbank_questiongen');

        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $qbankcminfo = question_bank_helper::create_default_open_instance($course, 'testquestionbank');

        $generatedxmlfixture = file_get_contents($CFG->dirroot . '/question/bank/questiongen/tests/fixtures/multichoice.xml');
        $questiongenerator =
                $this->getMockBuilder(\qbank_questiongen\local\question_generator::class)
                        ->setConstructorArgs([$qbankcminfo->context->id])
                        ->onlyMethods(['retrieve_llm_response'])
                        ->getMock();
        $questiongenerator->method('retrieve_llm_response')
                ->willReturn([
                        'generatedquestiontext' => $generatedxmlfixture,
                        'errormessage' => ''
                ]);

        $dataobject = new stdClass();
        $dataobject->mode = story_form::QUESTIONGEN_MODE_TOPIC;
        $dataobject->numoftries = 3;
        $dataobject->story = 'French revolution';
        // We import our initial presets and use the first one (for multiple choice question) for testing.
        // In reality the user is able to manipulate each of the preset entries, but we don't want to test that here.
        $presetjson = json_decode(file_get_contents($CFG->dirroot . '/question/bank/questiongen/db/initial_presets.json'))[0];
        $this->assertEquals('Multiple choice question', $presetjson->name);
        $dataobject->primer = $presetjson->primer;
        $dataobject->instructions = $presetjson->instructions;
        $dataobject->example = $presetjson->example;

        $questionobject = $questiongenerator->generate_question($dataobject, false);
        $this->assertEquals($generatedxmlfixture, $questionobject->text);
        $this->assertEquals($presetjson->primer, $questionobject->primer);
        $this->assertEquals($presetjson->instructions, $questionobject->instructions);
        $this->assertEquals($presetjson->example, $questionobject->example);
        $expectedstoryprompt = 'Create a question about the following topic. Use your own training data to generate it: "' .
                $dataobject->story . '"';
        $this->assertEquals($expectedstoryprompt, $questionobject->storyprompt);
        $this->assertEmpty($questionobject->questiontextsinqbankprompt);

        // TODO continue testing
    }
}
