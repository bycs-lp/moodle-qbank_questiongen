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

namespace qbank_questiongen\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy provider tests for qbank_questiongen.
 *
 * @package    qbank_questiongen
 * @copyright  2026 ISB Bayern
 * @author     Fabian Barbuia
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \qbank_questiongen\privacy\provider
 */
final class provider_test extends \core_privacy\tests\provider_testcase {
    /**
     * Create a question generation request record for a user in a given category.
     *
     * @param int $userid the owning user id
     * @param int $categoryid the question category id
     * @return int the id of the created record
     */
    protected function create_request(int $userid, int $categoryid): int {
        global $DB;
        $now = time();
        return $DB->insert_record('qbank_questiongen', (object) [
            'category' => $categoryid,
            'mode' => 0,
            'story' => 'Some source text',
            'numoftries' => 1,
            'userid' => $userid,
            'llmresponse' => 'Generated questions',
            'tries' => 1,
            'success' => 'ok',
            'uniqid' => uniqid('', true),
            'primer' => 'primer',
            'instructions' => 'instructions',
            'example' => 'example',
            'aiidentifier' => 0,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
    }

    /**
     * Create a question category inside a fresh course category and return it together with its real context.
     *
     * @return array [\stdClass questioncategory, \context context]
     */
    protected function create_category(): array {
        global $DB;
        $generator = $this->getDataGenerator();
        $coursecat = $generator->create_category();
        $context = \context_coursecat::instance($coursecat->id);

        /** @var \core_question_generator $qgen */
        $qgen = $generator->get_plugin_generator('core_question');
        $qcat = $qgen->create_question_category(['contextid' => $context->id]);

        // Read the category back and derive the context from its actual stored contextid,
        // so the provider (which joins via question_categories.contextid) and the test always agree.
        $record = $DB->get_record('question_categories', ['id' => $qcat->id], '*', MUST_EXIST);
        $realcontext = \context::instance_by_id($record->contextid);

        return [$record, $realcontext];
    }

    /**
     * Test that the requests table is declared in the metadata.
     */
    public function test_get_metadata(): void {
        $collection = new collection('qbank_questiongen');
        $metadata = provider::get_metadata($collection);
        $tables = array_map(fn($item) => $item->get_name(), $metadata->get_collection());
        $this->assertContains('qbank_questiongen', $tables);
    }

    /**
     * Test that the context is returned for a user who created a request.
     */
    public function test_get_contexts_for_userid(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        [$qcat, $context] = $this->create_category();
        $this->create_request($user->id, $qcat->id);

        $contextlist = provider::get_contexts_for_userid($user->id);
        $this->assertCount(1, $contextlist);
        $this->assertEquals($context->id, $contextlist->current()->id);

        $other = $this->getDataGenerator()->create_user();
        $this->assertCount(0, provider::get_contexts_for_userid($other->id));
    }

    /**
     * Test that users are returned for a context.
     */
    public function test_get_users_in_context(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        [$qcat, $context] = $this->create_category();
        $this->create_request($user->id, $qcat->id);

        $userlist = new userlist($context, 'qbank_questiongen');
        provider::get_users_in_context($userlist);
        $this->assertEqualsCanonicalizing([$user->id], $userlist->get_userids());
    }

    /**
     * Test that a user's requests are exported.
     */
    public function test_export_user_data(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        [$qcat, $context] = $this->create_category();
        $this->create_request($user->id, $qcat->id);

        $this->export_context_data_for_user($user->id, $context, 'qbank_questiongen');
        $writer = writer::with_context($context);
        $this->assertTrue($writer->has_any_data());
    }

    /**
     * Test that deleting all data in the context removes the requests.
     */
    public function test_delete_data_for_all_users_in_context(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        [$qcat, $context] = $this->create_category();
        $this->create_request($user->id, $qcat->id);

        provider::delete_data_for_all_users_in_context($context);
        $this->assertFalse($DB->record_exists('qbank_questiongen', ['userid' => $user->id]));
    }

    /**
     * Test that deletion for a single user only affects that user in that context.
     */
    public function test_delete_data_for_user(): void {
        global $DB;
        $this->resetAfterTest();
        $user1 = $this->getDataGenerator()->create_user();
        $user2 = $this->getDataGenerator()->create_user();
        [$qcat, $context] = $this->create_category();
        $this->create_request($user1->id, $qcat->id);
        $this->create_request($user2->id, $qcat->id);

        $contextlist = new approved_contextlist($user1, 'qbank_questiongen', [$context->id]);
        provider::delete_data_for_user($contextlist);

        $this->assertFalse($DB->record_exists('qbank_questiongen', ['userid' => $user1->id]));
        $this->assertTrue($DB->record_exists('qbank_questiongen', ['userid' => $user2->id]));
    }

    /**
     * Test deletion for a specific set of users within a context.
     */
    public function test_delete_data_for_users(): void {
        global $DB;
        $this->resetAfterTest();
        $user1 = $this->getDataGenerator()->create_user();
        $user2 = $this->getDataGenerator()->create_user();
        [$qcat, $context] = $this->create_category();
        $this->create_request($user1->id, $qcat->id);
        $this->create_request($user2->id, $qcat->id);

        $approved = new approved_userlist($context, 'qbank_questiongen', [$user1->id]);
        provider::delete_data_for_users($approved);

        $this->assertFalse($DB->record_exists('qbank_questiongen', ['userid' => $user1->id]));
        $this->assertTrue($DB->record_exists('qbank_questiongen', ['userid' => $user2->id]));
    }
}
