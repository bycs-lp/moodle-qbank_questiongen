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

namespace qbank_questiongen\form;

/**
 * Verify write-boundary validation and selection snapshots with real form submissions.
 *
 * @package qbank_questiongen
 * @copyright 2026 ISB Bayern
 * @author Dr. Peter Mayer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(story_form::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(edit_preset_form::class)]
final class preset_forms_test extends \advanced_testcase {
    /**
     * Submit optional guidance and type restrictions through the complete form workflow.
     */
    public function test_selection_submission(): void {
        global $CFG;
        require_once($CFG->dirroot . '/question/editlib.php');
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $bank = \core_question\local\bank\question_bank_helper::create_default_open_instance($course, 'formbank');
        question_get_top_category($bank->context->id, true);
        $generator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $category = $generator->create_question_category(['contextid' => $bank->context->id]);
        $cases = [
            'empty' => [[], '', null],
            'sentinel' => [['_qf__force_multiselect_submission'], '', null],
            'one type' => [['match'], 'Compare concepts', null],
            'removed type' => [['removedtype'], '', 'errorselectioncatalogue'],
            'partially removed' => [['match', 'removedtype'], '', 'errorselectioncatalogue'],
            'length boundary' => [['match'], str_repeat('a', 4000), null],
            'too long' => [['match'], str_repeat('a', 4001), 'errortexttoolong'],
        ];
        foreach ($cases as $scenario => [$qtypes, $pedagogy, $error]) {
            story_form::mock_submit([
                'cmid' => $bank->id, 'category' => $category->id . ',' . $bank->context->id,
                'selectionmode' => 1, 'mode' => 1, 'topic' => 'Synthetic topic', 'numofquestions' => 1,
                'qtypes' => $qtypes, 'pedagogy' => $pedagogy,
            ]);
            $form = new story_form(null, ['cmid' => $bank->id,
                'contexts' => new \core_question\local\bank\question_edit_contexts($bank->context)]);
            $data = $form->get_data();
            if ($error !== null) {
                $this->assertFalse((bool) $data, $scenario);
                $html = $form->render();
                $this->assertStringContainsString(get_string($error, 'qbank_questiongen', 4000), $html, $scenario);
                if ($error === 'errortexttoolong') {
                    $this->assertStringNotContainsString(get_string('errorselectioncatalogue', 'qbank_questiongen'), $html);
                }
                continue;
            }
            $this->assertNotFalse($data, $scenario);
            $selection = $form->get_selection();
            $this->assertSame($pedagogy, $selection->pedagogy);
            $this->assertCount(in_array('match', $qtypes) ? 1 : 5, $selection->catalogue);
            $this->assertSame($selection, $form->get_selection());
        }
    }

    /**
     * Manual editing applies the same limits as JSON import and returns derived metadata.
     */
    public function test_edit_preset_validation(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $preset = json_decode(file_get_contents(__DIR__ . '/../../db/initial_presets.json'))[0];
        edit_preset_form::mock_submit((array) $preset);
        $form = new edit_preset_form(null, []);
        $this->assertNotFalse($form->get_data());
        $this->assertSame('multichoice', $form->get_preset()->qtype);
        $preset->name = str_repeat('n', 256);
        $preset->primer = str_repeat('p', 262145);
        edit_preset_form::mock_submit((array) $preset);
        $form = new edit_preset_form(null, []);
        $this->assertFalse((bool) $form->get_data());
        $errors = \qbank_questiongen\local\preset_transfer::validate_preset($preset);
        $this->assertEqualsCanonicalizing(['name', 'primer'], array_keys($errors));
    }
}
