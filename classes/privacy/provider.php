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
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy provider for qbank_questiongen.
 *
 * @package     qbank_questiongen
 * @copyright   2026 ISB Bayern
 * @author      Fabian Barbuia
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {

    #[\Override]
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('qbank_questiongen', [
            'category' => 'privacy:metadata:qbank_questiongen:category',
            'userid' => 'privacy:metadata:qbank_questiongen:userid',
            'story' => 'privacy:metadata:qbank_questiongen:story',
            'llmresponse' => 'privacy:metadata:qbank_questiongen:llmresponse',
            'success' => 'privacy:metadata:qbank_questiongen:success',
            'primer' => 'privacy:metadata:qbank_questiongen:primer',
            'instructions' => 'privacy:metadata:qbank_questiongen:instructions',
            'example' => 'privacy:metadata:qbank_questiongen:example',
            'timecreated' => 'privacy:metadata:qbank_questiongen:timecreated',
            'timemodified' => 'privacy:metadata:qbank_questiongen:timemodified',
        ], 'privacy:metadata:qbank_questiongen');

        $collection->add_database_table('qbank_questiongen_preset', [
            'name' => 'privacy:metadata:qbank_questiongen_preset:name',
            'primer' => 'privacy:metadata:qbank_questiongen_preset:primer',
            'instructions' => 'privacy:metadata:qbank_questiongen_preset:instructions',
            'example' => 'privacy:metadata:qbank_questiongen_preset:example',
            'timecreated' => 'privacy:metadata:qbank_questiongen_preset:timecreated',
            'timemodified' => 'privacy:metadata:qbank_questiongen_preset:timemodified',
        ], 'privacy:metadata:qbank_questiongen_preset');

        return $collection;
    }

    #[\Override]
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();
        $sql = "SELECT DISTINCT qc.contextid
                  FROM {qbank_questiongen} qg
                  JOIN {question_categories} qc ON qc.id = qg.category
                 WHERE qg.userid = :userid";
        $contextlist->add_from_sql($sql, ['userid' => $userid]);
        return $contextlist;
    }

    #[\Override]
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;

        if ($contextlist->count() === 0) {
            return;
        }

        $userid = $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            $records = $DB->get_records_sql(
                "SELECT qg.*
                   FROM {qbank_questiongen} qg
                   JOIN {question_categories} qc ON qc.id = qg.category
                  WHERE qg.userid = :userid
                    AND qc.contextid = :contextid
               ORDER BY qg.id",
                [
                    'userid' => $userid,
                    'contextid' => $context->id,
                ]
            );
            if (empty($records)) {
                continue;
            }
            writer::with_context($context)->export_data(
                [
                    get_string('pluginname', 'qbank_questiongen'),
                    get_string('privacy:metadata:qbank_questiongen', 'qbank_questiongen'),
                ],
                (object) ['requests' => array_values($records)]
            );
        }
    }

    #[\Override]
    public static function delete_data_for_all_users_in_context(\context $context): void {
        global $DB;

        $categoryids = self::get_category_ids_in_context($context->id);
        if (empty($categoryids)) {
            return;
        }
        [$insql, $params] = $DB->get_in_or_equal($categoryids, SQL_PARAMS_NAMED);
        $DB->delete_records_select('qbank_questiongen', "category $insql", $params);
    }

    #[\Override]
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;

        if ($contextlist->count() === 0) {
            return;
        }

        $userid = $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            $categoryids = self::get_category_ids_in_context($context->id);
            if (empty($categoryids)) {
                continue;
            }
            [$insql, $params] = $DB->get_in_or_equal($categoryids, SQL_PARAMS_NAMED);
            $params['userid'] = $userid;
            $DB->delete_records_select('qbank_questiongen', "userid = :userid AND category $insql", $params);
        }
    }

    #[\Override]
    public static function get_users_in_context(userlist $userlist): void {
        $sql = "SELECT DISTINCT qg.userid
                  FROM {qbank_questiongen} qg
                  JOIN {question_categories} qc ON qc.id = qg.category
                 WHERE qc.contextid = :contextid";
        $userlist->add_from_sql('userid', $sql, ['contextid' => $userlist->get_context()->id]);
    }

    #[\Override]
    public static function delete_data_for_users(approved_userlist $userlist): void {
        global $DB;

        if ($userlist->count() === 0) {
            return;
        }

        $categoryids = self::get_category_ids_in_context($userlist->get_context()->id);
        if (empty($categoryids)) {
            return;
        }
        [$usersql, $userparams] = $DB->get_in_or_equal($userlist->get_userids(), SQL_PARAMS_NAMED, 'usr');
        [$categorysql, $categoryparams] = $DB->get_in_or_equal($categoryids, SQL_PARAMS_NAMED, 'cat');
        $DB->delete_records_select(
            'qbank_questiongen',
            "userid $usersql AND category $categorysql",
            $userparams + $categoryparams
        );
    }

    /**
     * Return all question category ids belonging to a context.
     *
     * @param int $contextid the context id
     * @return int[] the matching question category ids
     */
    private static function get_category_ids_in_context(int $contextid): array {
        global $DB;
        return $DB->get_fieldset_select('question_categories', 'id', 'contextid = :contextid', ['contextid' => $contextid]);
    }
}
