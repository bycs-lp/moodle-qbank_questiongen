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

namespace qbank_questiongen\task;

/**
 * Unit tests for the error handling of the generate_questions task.
 *
 * @package   qbank_questiongen
 * @copyright 2026 ISB Bayern
 * @author    Dr. Peter Mayer
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers    \qbank_questiongen\task\generate_questions
 */
final class generate_questions_debugging_test extends \advanced_testcase {
    #[\PHPUnit\Framework\Attributes\Group('baseline')]
    /**
     * A failing generation reports the error without changing the site debugging level.
     *
     * @covers \qbank_questiongen\task\generate_questions::execute
     */
    public function test_execute_failure_keeps_debugging_level(): void {
        global $CFG, $DB;
        $this->resetAfterTest();
        set_debugging(DEBUG_NONE, false);

        $task = new generate_questions();
        $task->set_custom_data(['questiongenids' => [-1]]);
        $task->initialise_stored_progress();
        ob_start();
        $task->execute();
        ob_end_clean();

        $this->assertTrue($DB->record_exists('stored_progress', ['haserrored' => 1]));
        $this->assertEquals(DEBUG_NONE, $CFG->debug);
        $this->assertEmpty($CFG->debugdisplay);
    }
}
