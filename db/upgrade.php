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

    return true;
}
