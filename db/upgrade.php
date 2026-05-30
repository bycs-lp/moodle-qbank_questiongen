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
 * Plugin upgrade steps are defined here.
 *
 * @package     qbank_questiongen
 * @category    upgrade
 * @copyright   2025 ISB Bayern
 * @author      Umut Zerman
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Execute qbank_questiongen upgrade from the given old version.
 *
 * @param int $oldversion
 * @return bool
 */
function xmldb_qbank_questiongen_upgrade($oldversion) {
    if ($oldversion < 2025101301) {
        // Migrate systemprompt to the new Mustache template if it was not customised.
        // Also covers the case where the old default (without mode sections) was saved,
        // e.g. after the first implementation attempt that only had primer/instructions/example.
        $currentsystemprompt = get_config('qbank_questiongen', 'systemprompt');
        $needsmigration = empty($currentsystemprompt)
            || strpos($currentsystemprompt, 'mode_topic') === false;
        if ($needsmigration) {
            set_config(
                'systemprompt',
                \qbank_questiongen\local\question_generator::get_default_prompt_template(),
                'qbank_questiongen'
            );
        }

        // The userprompt setting is replaced by the single systemprompt Mustache template.
        unset_config('userprompt', 'qbank_questiongen');

        upgrade_plugin_savepoint(true, 2025101301, 'qbank', 'questiongen');
    }

    return true;
}
