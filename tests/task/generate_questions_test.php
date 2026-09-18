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
     * Run fixed, mixed and failing batches through the same processing workflow.
     */
    public function test_batch_processing(): void {
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
        [$first, $second] = array_values(utils::get_preset_catalogue());
        $cases = [
            'fixed' => [[], [$first->example], ['1'], [$first->qtype]],
            'mixed' => [[$first, null, $second], ['<quiz/>', $first->example, $second->example],
                ['1', '0', '1'], [$first->qtype, $second->qtype]],
            'provider failure' => [[$first], ['Provider unavailable'], ['0', '0'], []],
            'invalid XML' => [[$first], ['<quiz/>', '<quiz/>'], ['0'], []],
        ];
        foreach ($cases as $scenario => [$selections, $responses, $statuses, $types]) {
            $category = $generator->create_question_category(['contextid' => $qbank->context->id]);
            $data = (object) ['mode' => 1, 'topic' => 'Synthetic topic', 'category' => $category->id . ',' . $qbank->context->id,
                'numofquestions' => count($statuses), 'preset' => $first->id, 'selectionmode' => $scenario === 'fixed' ? 0 : 1,
                'pedagogy' => 'Compare concepts'];
            foreach (['primer', 'instructions', 'example'] as $field) {
                $data->{$field . $first->id} = $first->$field;
            }
            $ids = utils::store_questiongen_data($data);
            $ai = $this->getMockBuilder(question_generator::class)->setConstructorArgs([$qbank->context->id])
                ->onlyMethods(['select_preset', 'generate_question'])->getMock();
            $ai->expects($this->exactly(count($selections)))->method('select_preset')
                ->willReturnCallback(function () use (&$selections) {
                    return array_shift($selections);
                });
            $ai->expects($this->exactly(count($responses)))->method('generate_question')->willReturnCallback(
                function ($record) use (&$responses, $scenario): \stdClass|string {
                    $this->assertSame($scenario === 'fixed' ? null : 'Compare concepts', $record->pedagogy ?? null);
                    $response = array_shift($responses);
                    return $response === 'Provider unavailable' ? $response : (object) ['text' => $response];
                }
            );
            $task = $this->getMockBuilder(generate_questions::class)->onlyMethods(['get_generator'])->getMock();
            $task->method('get_generator')->willReturn($ai);
            $task->set_id($ids[0]);
            $task->set_custom_data(['questiongenids' => $ids, 'contextid' => $qbank->context->id,
                'sendexistingquestionsascontext' => false]);
            ob_start();
            try {
                $task->execute();
            } finally {
                ob_end_clean();
            }
            $records = array_values($DB->get_records_list('qbank_questiongen', 'id', $ids, 'id'));
            $this->assertSame($statuses, array_column($records, 'success'), $scenario);
            $questionids = \question_bank::get_finder()->get_questions_from_categories([$category->id], null);
            $this->assertCount(count($types), $questionids, $scenario);
            $actualtypes = $questionids ? $DB->get_records_list('question', 'id', $questionids, '', 'id,qtype') : [];
            $this->assertEqualsCanonicalizing($types, array_column($actualtypes, 'qtype'), $scenario);
            $this->assertFalse($task->retry_until_success());
            $this->assertStringNotContainsString('Compare concepts', $task->get_custom_data_as_string());
            if ($scenario === 'mixed') {
                $this->assertEquals([$first->id, null, $second->id], array_column($records, 'selectedpresetid'));
                $this->assertEquals(2, $records[0]->tries);
                $this->assertNotEmpty($records[0]->selectiondata);
                $this->assertNull($records[1]->selectiondata);
                $this->assertNull($records[2]->selectiondata);
            }
        }
    }
}
