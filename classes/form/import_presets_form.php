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

defined('MOODLE_INTERNAL') || die();
require_once($CFG->libdir . '/formslib.php');

/**
 * Upload a portable preset bundle.
 *
 * @package qbank_questiongen
 * @copyright 2026 ISB Bayern
 * @author Dr. Peter Mayer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class import_presets_form extends \moodleform {
    #[\Override]
    public function definition(): void {
        $form = $this->_form;
        $form->addElement('header', 'importheading', get_string('importpresets', 'qbank_questiongen'));
        $form->addElement(
            'filepicker',
            'presetfile',
            get_string('presetfile', 'qbank_questiongen'),
            null,
            ['accepted_types' => ['.json'], 'maxbytes' => \qbank_questiongen\local\preset_transfer::MAX_BYTES]
        );
        $form->addRule('presetfile', null, 'required');
        $form->addHelpButton('presetfile', 'presetfile', 'qbank_questiongen');
        $form->addElement('submit', 'importpresets', get_string('importpresets', 'qbank_questiongen'));
    }
}
