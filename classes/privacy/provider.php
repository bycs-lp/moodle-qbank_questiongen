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

namespace qbank_questiongen\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\metadata\provider as metadata_provider;
use core_privacy\local\request\core_userlist_provider;
use core_privacy\local\request\plugin\provider as request_provider;

/**
 * Privacy API implementation for the AI Text to questions generator plugin.
 *
 * @package     qbank_questiongen
 * @copyright   2023 Ruthy Salomon <ruthy.salomon@gmail.com> , Yedidia Klein <yedidia@openapp.co.il>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements core_userlist_provider, metadata_provider, request_provider {
    /**
     * Describe request records and delegated processing.
     *
     * @param \core_privacy\local\metadata\collection $collection Metadata collection
     * @return \core_privacy\local\metadata\collection
     */
    public static function get_metadata(collection $collection): collection {
        $fields = [];
        foreach (
            ['userid', 'category', 'story', 'primer', 'instructions', 'example', 'llmresponse',
            'selectionmode', 'selectedpresetid', 'selectiondata', 'success', 'tries', 'timecreated', 'timemodified'] as $field
        ) {
            $fields[$field] = 'privacy:requestdata';
        }
        $collection->add_database_table('qbank_questiongen', $fields, 'privacy:requestdata');
        $collection->add_subsystem_link('core', ['task_adhoc' => 'privacy:taskdata'], 'privacy:taskdata');
        $collection->add_plugintype_link('local_ai_manager', [], 'privacy:aimanager');
        return $collection;
    }

    /**
     * Find the system context holding a user's processing records.
     *
     * @param int $userid User ID
     * @return \core_privacy\local\request\contextlist
     */
    public static function get_contexts_for_userid(int $userid): \core_privacy\local\request\contextlist {
        global $DB;
        $contexts = new \core_privacy\local\request\contextlist();
        if (
            $DB->record_exists('qbank_questiongen', ['userid' => $userid]) ||
            $DB->record_exists('task_adhoc', ['userid' => $userid, 'classname' => '\\qbank_questiongen\\task\\generate_questions'])
        ) {
            $contexts->add_system_context();
        }
        return $contexts;
    }

    /**
     * Export processing records and any pending selection snapshots.
     *
     * @param \core_privacy\local\request\approved_contextlist $contextlist Approved contexts
     */
    public static function export_user_data(\core_privacy\local\request\approved_contextlist $contextlist): void {
        global $DB;
        $context = \context_system::instance();
        if (!in_array($context->id, $contextlist->get_contextids())) {
            return;
        }
        $userid = $contextlist->get_user()->id;
        $records = $DB->get_records('qbank_questiongen', ['userid' => $userid]);
        $tasks = $DB->get_records('task_adhoc', ['userid' => $userid,
            'classname' => '\\qbank_questiongen\\task\\generate_questions'], '', 'id,customdata');
        \core_privacy\local\request\writer::with_context($context)->export_data(
            [get_string('pluginname', 'qbank_questiongen')],
            (object) ['requests' => array_values($records), 'pendingtasks' => array_values($tasks)]
        );
    }

    /**
     * Delete all plugin processing records in the approved system context.
     *
     * @param \context $context Approved context
     */
    public static function delete_data_for_all_users_in_context(\context $context): void {
        global $DB;
        if ($context->contextlevel !== CONTEXT_SYSTEM) {
            return;
        }
        $DB->delete_records('qbank_questiongen');
        foreach ($DB->get_records('task_adhoc', ['classname' => '\\qbank_questiongen\\task\\generate_questions']) as $task) {
            \core\task\manager::delete_adhoc_task($task->id);
        }
    }

    /**
     * Delete one user's processing records.
     *
     * @param \core_privacy\local\request\approved_contextlist $contextlist Approved contexts
     */
    public static function delete_data_for_user(\core_privacy\local\request\approved_contextlist $contextlist): void {
        if (in_array(\context_system::instance()->id, $contextlist->get_contextids())) {
            self::delete_users([$contextlist->get_user()->id]);
        }
    }

    /**
     * List users with plugin processing records.
     *
     * @param \core_privacy\local\request\userlist $userlist Context user list
     */
    public static function get_users_in_context(\core_privacy\local\request\userlist $userlist): void {
        if ($userlist->get_context()->contextlevel !== CONTEXT_SYSTEM) {
            return;
        }
        $userlist->add_from_sql('userid', 'SELECT userid FROM {qbank_questiongen}', []);
        $userlist->add_from_sql(
            'userid',
            'SELECT userid FROM {task_adhoc} WHERE classname = :classname',
            ['classname' => '\\qbank_questiongen\\task\\generate_questions']
        );
    }

    /**
     * Delete processing data for approved users.
     *
     * @param \core_privacy\local\request\approved_userlist $userlist Approved user list
     */
    public static function delete_data_for_users(\core_privacy\local\request\approved_userlist $userlist): void {
        if ($userlist->get_context()->contextlevel === CONTEXT_SYSTEM) {
            self::delete_users($userlist->get_userids());
        }
    }

    /**
     * Delete records and queued tasks belonging to the given users.
     *
     * @param array $userids User IDs
     */
    private static function delete_users(array $userids): void {
        global $DB;
        if (!$userids) {
            return;
        }
        $DB->delete_records_list('qbank_questiongen', 'userid', $userids);
        [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $params['classname'] = '\\qbank_questiongen\\task\\generate_questions';
        foreach ($DB->get_records_select('task_adhoc', "userid $insql AND classname = :classname", $params) as $task) {
            \core\task\manager::delete_adhoc_task($task->id);
        }
    }
}
