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
 * Tests for automatic question generation tasks.
 *
 * @package qbank_questiongen
 * @copyright 2026 ISB Bayern
 * @author Dr. Peter Mayer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace qbank_questiongen\task;

use qbank_questiongen\local\question_generator;
use qbank_questiongen\local\utils;

/**
 * Exercise the task with deterministic AI responses and real question imports.
 *
 * @package qbank_questiongen
 * @copyright 2026 ISB Bayern
 * @author Dr. Peter Mayer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(generate_questions::class)]
final class generate_questions_test extends \advanced_testcase {
    /**
     * Fixed legacy payloads and bounded failure paths remain supported.
     *
     * @param string $scenario Processing case
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('failure_cases')]
    public function test_processing_outcomes(string $scenario): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('numoftries', 2, 'qbank_questiongen');
        set_config('aiidentifier', '', 'qbank_questiongen');
        set_config('aiidentifiertag', '', 'qbank_questiongen');
        $course = $this->getDataGenerator()->create_course();
        $qbank = \core_question\local\bank\question_bank_helper::create_default_open_instance($course, 'outcomebank');
        question_get_top_category($qbank->context->id, true);
        $generator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $category = $generator->create_question_category(['contextid' => $qbank->context->id]);
        $presets = utils::get_preset_catalogue();
        $preset = reset($presets);
        $data = (object) ['mode' => 1, 'topic' => 'Synthetic topic', 'category' => $category->id . ',' . $qbank->context->id,
            'numofquestions' => 1, 'preset' => $preset->id];
        if ($scenario !== 'legacy') {
            $data->selectionmode = 1;
        }
        $data->{'primer' . $preset->id} = $preset->primer;
        $data->{'instructions' . $preset->id} = $preset->instructions;
        $data->{'example' . $preset->id} = $preset->example;
        $ids = utils::store_questiongen_data($data);
        $ai = $this->getMockBuilder(question_generator::class)->setConstructorArgs([$qbank->context->id])
            ->onlyMethods(['select_preset', 'generate_question'])->getMock();
        $ai->expects($scenario === 'legacy' ? $this->never() : $this->once())->method('select_preset')
            ->willReturn($scenario === 'invalidselection' ? null : $preset);
        $calls = match ($scenario) {
            'invalidselection' => 0,
            'invalidxml' => 2,
            default => 1,
        };
        $response = match ($scenario) {
            'providererror' => 'Provider unavailable',
            'invalidxml' => (object) ['text' => '<quiz/>'],
            default => (object) ['text' => $preset->example],
        };
        $ai->expects($this->exactly($calls))->method('generate_question')->willReturn($response);
        $task = $this->getMockBuilder(generate_questions::class)->onlyMethods(['get_generator'])->getMock();
        $task->method('get_generator')->willReturn($ai);
        $task->set_id(12346);
        $task->set_custom_data(['questiongenids' => $ids, 'contextid' => $qbank->context->id,
            'sendexistingquestionsascontext' => false]);
        ob_start();
        try {
            $task->execute();
        } finally {
            ob_end_clean();
        }
        $record = $DB->get_record('qbank_questiongen', ['id' => $ids[0]]);
        $this->assertSame($scenario === 'legacy' ? '1' : '0', $record->success);
        $questionids = \question_bank::get_finder()->get_questions_from_categories([$category->id], null);
        $this->assertCount($scenario === 'legacy' ? 1 : 0, $questionids);
        $this->assertFalse($task->retry_until_success());
    }

    /**
     * Processing outcomes to verify without external requests.
     *
     * @return array Test cases
     */
    public static function failure_cases(): array {
        return [['legacy'], ['invalidselection'], ['providererror'], ['invalidxml']];
    }

    /**
     * A mixed batch preserves selection across XML retries.
     */
    public function test_mixed_batch(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('numoftries', 2, 'qbank_questiongen');
        set_config('aiidentifier', '', 'qbank_questiongen');
        set_config('aiidentifiertag', '', 'qbank_questiongen');
        $course = $this->getDataGenerator()->create_course();
        $qbank = \core_question\local\bank\question_bank_helper::create_default_open_instance($course, 'testbank');
        question_get_top_category($qbank->context->id, true);
        $generator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $category = $generator->create_question_category(['contextid' => $qbank->context->id]);
        $data = (object) ['mode' => 1, 'topic' => 'Synthetic topic', 'category' => $category->id . ',' . $qbank->context->id,
            'numofquestions' => 2, 'selectionmode' => 1, 'pedagogy' => 'Compare concepts'];
        $selection = utils::prepare_selection($data);
        $presets = array_values($selection->catalogue);
        $first = $presets[0];
        $second = $presets[1];
        $ids = utils::store_questiongen_data($data, $selection);
        $ai = $this->getMockBuilder(question_generator::class)->setConstructorArgs([$qbank->context->id])
            ->onlyMethods(['select_preset', 'generate_question'])->getMock();
        $ai->expects($this->exactly(2))->method('select_preset')->willReturnOnConsecutiveCalls($first, $second);
        $responses = ['<quiz/>', $first->example, $second->example];
        $ai->expects($this->exactly(3))->method('generate_question')->willReturnCallback(
            function ($record) use (&$responses): \stdClass {
                $this->assertSame('Compare concepts', $record->pedagogy);
                return (object) ['text' => array_shift($responses)];
            }
        );
        $task = $this->getMockBuilder(generate_questions::class)->onlyMethods(['get_generator'])->getMock();
        $task->method('get_generator')->willReturn($ai);
        $task->set_id(12345);
        $task->set_custom_data((object) ['questiongenids' => $ids, 'contextid' => $qbank->context->id,
            'sendexistingquestionsascontext' => false]);
        ob_start();
        try {
            $task->execute();
        } finally {
            ob_end_clean();
        }
        $records = array_values($DB->get_records_list('qbank_questiongen', 'id', $ids, 'id'));
        $this->assertSame(['1', '1'], array_column($records, 'success'));
        $this->assertEquals($first->id, $records[0]->selectedpresetid);
        $this->assertEquals($second->id, $records[1]->selectedpresetid);
        $this->assertEquals(2, $records[0]->tries);
        $this->assertNotEmpty($records[0]->selectiondata);
        $this->assertNull($records[1]->selectiondata);
        $this->assertStringNotContainsString('Compare concepts', $task->get_custom_data_as_string());
        $questionids = \question_bank::get_finder()->get_questions_from_categories([$category->id], null);
        $this->assertCount(2, $questionids);
        $types = array_values($DB->get_records_list('question', 'id', $questionids, 'id', 'id,qtype'));
        $this->assertEqualsCanonicalizing([$first->qtype, $second->qtype], array_column($types, 'qtype'));
    }
}
