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
 * Privacy provider tests.
 *
 * @package qbank_questiongen
 * @copyright 2026 ISB Bayern
 * @author Dr. Peter Mayer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace qbank_questiongen\privacy;

/**
 * Check ownership boundaries for request exports and deletion.
 *
 * @package qbank_questiongen
 * @copyright 2026 ISB Bayern
 * @author Dr. Peter Mayer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(provider::class)]
final class provider_test extends \core_privacy\tests\provider_testcase {
    /**
     * Export and deletion include snapshots without affecting another user.
     */
    public function test_request_privacy(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        foreach ([$user, $other] as $owner) {
            $record = (object) ['userid' => $owner->id, 'category' => 0, 'numoftries' => 1,
                'llmresponse' => '', 'success' => '', 'uniqid' => 'privacy' . $owner->id, 'story' => 'Private input'];
            $DB->insert_record('qbank_questiongen', $record);
            $task = new \qbank_questiongen\task\generate_questions();
            $task->set_userid($owner->id);
            $task->set_custom_data(['selection' => ['pedagogy' => 'Private pedagogical prompt']]);
            \core\task\manager::queue_adhoc_task($task);
        }
        $context = \context_system::instance();
        $userlist = new \core_privacy\local\request\userlist($context, 'qbank_questiongen');
        provider::get_users_in_context($userlist);
        $this->assertEqualsCanonicalizing([$user->id, $other->id], $userlist->get_userids());
        $this->assertEquals([$context->id], provider::get_contexts_for_userid($user->id)->get_contextids());
        $approved = new \core_privacy\local\request\approved_contextlist($user, 'qbank_questiongen', [$context->id]);
        provider::get_metadata(new \core_privacy\local\metadata\collection('qbank_questiongen'));
        provider::export_user_data($approved);
        $export = \core_privacy\local\request\writer::with_context($context)
            ->get_data([get_string('pluginname', 'qbank_questiongen')]);
        $this->assertCount(1, $export->requests);
        $this->assertCount(1, $export->pendingtasks);
        $this->assertStringContainsString('Private pedagogical prompt', $export->pendingtasks[0]->customdata);
        provider::delete_data_for_user($approved);
        $this->assertFalse($DB->record_exists('qbank_questiongen', ['userid' => $user->id]));
        $this->assertFalse($DB->record_exists('task_adhoc', ['userid' => $user->id]));
        $this->assertTrue($DB->record_exists('qbank_questiongen', ['userid' => $other->id]));
        $this->assertTrue($DB->record_exists('task_adhoc', ['userid' => $other->id]));
    }
}
