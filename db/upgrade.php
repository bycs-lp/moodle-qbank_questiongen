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

/**
 * Upgrade script for qbank_questiongen.
 *
 * @package   qbank_questiongen
 * @copyright 2026 ISB Bayern
 * @author    Philipp Memmel
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Upgrade the plugin.
 *
 * @param int $oldversion The old version.
 * @return bool
 */
function xmldb_qbank_questiongen_upgrade(int $oldversion): bool {
    if ($oldversion < 2026072800) {
        global $DB;

        $dbman = $DB->get_manager();

        // Drop the obsolete extraction cache table, now handled by local_ai_content.
        $table = new xmldb_table('qbank_questiongen_resource_cache');
        if ($dbman->table_exists($table)) {
            $dbman->drop_table($table);
        }

        upgrade_plugin_savepoint(true, 2026072800, 'qbank', 'questiongen');
    }

    if ($oldversion < 2026091700) {
        global $DB;
        $dbman = $DB->get_manager();
        $table = new xmldb_table('qbank_questiongen_preset');
        // Leave existing presets untouched; qtype is populated when an administrator reviews and saves each preset.
        $field = new xmldb_field('qtype', XMLDB_TYPE_CHAR, '100');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $field = new xmldb_field('selectiondescription', XMLDB_TYPE_TEXT);
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $table = new xmldb_table('qbank_questiongen');
        $field = new xmldb_field('selectionmode', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $field = new xmldb_field('selectedpresetid', XMLDB_TYPE_INTEGER, '10');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_plugin_savepoint(true, 2026091700, 'qbank', 'questiongen');
    }

    if ($oldversion < 2026091701) {
        global $DB;
        $dbman = $DB->get_manager();
        $table = new xmldb_table('qbank_questiongen');
        $field = new xmldb_field('selectiondata', XMLDB_TYPE_TEXT);
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_plugin_savepoint(true, 2026091701, 'qbank', 'questiongen');
    }

    if ($oldversion < 2026091800) {
        global $DB;
        $dbman = $DB->get_manager();
        $table = new xmldb_table('qbank_questiongen_preset');
        // Earlier development versions stored the XML spelling separately; fresh installs no longer create this field.
        $field = new xmldb_field('xmltype');
        if ($dbman->field_exists($table, $field)) {
            $dbman->drop_field($table, $field);
        }
        // Register the new provider before sending; review is manual, without invoking XML/qtype code during upgrade.
        message_update_providers('qbank_questiongen');
        $url = new moodle_url('/question/bank/questiongen/presets.php');
        foreach (get_admins() as $admin) {
            $message = new \core\message\message();
            $message->component = 'qbank_questiongen';
            $message->name = 'presetsreviewrequired';
            $message->courseid = SITEID;
            $message->userfrom = \core_user::get_noreply_user();
            $message->userto = $admin;
            $message->subject = get_string('presetsreviewsubject', 'qbank_questiongen');
            $message->fullmessage = get_string('presetsreviewmessage', 'qbank_questiongen', $url->out(false));
            $message->fullmessageformat = FORMAT_PLAIN;
            $message->fullmessagehtml = '';
            $message->smallmessage = $message->subject;
            $message->notification = 1;
            $message->contexturl = $url->out(false);
            $message->contexturlname = get_string('managepresets', 'qbank_questiongen');
            message_send($message);
        }
        upgrade_plugin_savepoint(true, 2026091800, 'qbank', 'questiongen');
    }

    return true;
}
