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
 * Story Form Class is defined here.
 *
 * @package     qbank_questiongen
 * @category    admin
 * @copyright   2023 Ruthy Salomon <ruthy.salomon@gmail.com> , Yedidia Klein <yedidia@openapp.co.il>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


namespace qbank_questiongen\form;

use qbank_questiongen\local\question_generator;

defined('MOODLE_INTERNAL') || die();
require_once($CFG->libdir . '/formslib.php');

/**
 * Form to get the story from the user.
 *
 * @package     qbank_questiongen
 * @category    admin
 */
class story_form extends \moodleform {
    /**
     * Defines forms elements
     */
    public function definition() {
        global $DB;

        $mform = $this->_form;
        $contexts = $this->_customdata['contexts']->having_cap('moodle/question:add');
        $contexts = array_filter($contexts, fn ($context) => $context->contextlevel !== CONTEXT_SYSTEM && $context->contextlevel !== CONTEXT_COURSECAT);

        // Question category.
        $mform->addElement('questioncategory', 'category', get_string('category', 'question'), ['contexts' => $contexts]);
        $mform->addHelpButton('category', 'category', 'qbank_questiongen');

        /*$modulecontexts = array_filter($contexts, fn($context) => $context->contextlevel === CONTEXT_MODULE);
        if (count($modulecontexts) === 0) {
            $coursecontext = array_values(array_filter($contexts, fn($context) => $context->contextlevel === CONTEXT_COURSE))[0];
            $mform->addElement('hidden', 'courseid', $coursecontext->instanceid);
            $mform->setType('courseid', PARAM_INT);
        }*/

        // Number of questions.
        $defaultnumofquestions = 4;
        $select = $mform->addElement(
            'select',
            'numofquestions',
            get_string('numofquestions', 'qbank_questiongen'),
            ['1' => 1, '2' => 2, '3' => 3, '4' => 4, '5' => 5, '6' => 6, '7' => 7, '8' => 8, '9' => 9, '10' => 10]
        );
        $select->setSelected($defaultnumofquestions);
        $mform->setType('numofquestions', PARAM_INT);

        // Story.
        $mform->addElement(
            'textarea',
            'story',
            get_string('story', 'qbank_questiongen'),
            'wrap="virtual" rows="10" cols="50"'
        );
        $mform->setType('story', PARAM_RAW);
        $mform->addHelpButton('story', 'story', 'qbank_questiongen');
        $mform->hideIf('story', 'coursecontents', 'eq', '1');

        // Use course contents instead.
        $mform->addElement('checkbox', 'coursecontents', get_string('use_coursecontents', 'qbank_questiongen'));
        $mform->setDefault('coursecontents', 0); // Default of "no"
        $mform->setType('coursecontents', PARAM_BOOL);

        [, $cmrec] = get_module_from_cmid($this->_customdata['cmid']);

        $modinfo = get_fast_modinfo($cmrec->course);

        $courseactivities = [];
        foreach ($modinfo->get_cms() as $cm) {
            if (in_array($cm->modname, question_generator::get_supported_modtypes())) {
                $courseactivities[$cm->id] = $cm->name;
            }
        }

        $mform->addElement('autocomplete', 'courseactivities', get_string('activitylist', 'qbank_questiongen'), $courseactivities, ['multiple' => true]);
        $mform->hideIf('courseactivities', 'coursecontents');

        $mform->addElement('checkbox', 'sendexistingquestionsascontext', get_string('sendexistingquestionsascontext', 'qbank_questiongen'));
        $mform->setDefault('sendexistingquestionsascontext', 1);
        $mform->setType('sendexistingquestionsascontext', PARAM_BOOL);

        $aiidentifier = get_config('qbank_questiongen', 'aiidentifier');
        if (!empty($aiidentifier)) {
            // Add a prefix to the question name.
            $mform->addElement('checkbox', 'addidentifier', get_string('addidentifier', 'qbank_questiongen', $aiidentifier));
            $mform->setDefault('addidentifier', 1);
            $mform->setType('addidentifier', PARAM_BOOL);
        } else {
            $mform->addElement('hidden', 'addidentifier', 0);
        }

        // Preset selection.
        $presetrecords = $DB->get_records('qbank_questiongen_presets', null, 'name ASC');
        $presets = [];
        foreach ($presetrecords as $presetrecord) {
            $presets[$presetrecord->id] = $presetrecord->name;
        }
        $mform->addElement('select', 'preset', get_string('preset', 'qbank_questiongen'), $presets);

        // Edit preset.
        $mform->addElement('checkbox', 'editpreset', get_string('editpreset', 'qbank_questiongen'));
        $mform->addElement('html', get_string('shareyourprompts', 'qbank_questiongen'));


        // Create elements for all presets.
        foreach ($presetrecords as $presetrecord) {
            $id = $presetrecord->id;
            // Format.
            $formatoptions = [
                    \qbank_questiongen\task\generate_questions::PARAM_GENAI_GIFT => get_string('gift_format', 'qbank_questiongen'),
                    \qbank_questiongen\task\generate_questions::PARAM_GENAI_XML => get_string('xml_format', 'qbank_questiongen'),
            ];
            $mform->addElement('select', 'presetformat' . $id, get_string('presetformat', 'qbank_questiongen'), $formatoptions);
            $mform->setDefault('presetformat' . $id, $presetrecord->format);
            $mform->addHelpButton('presetformat' . $id, 'example', 'qbank_questiongen');
            $mform->hideIf('presetformat' . $id, 'editpreset');
            $mform->hideIf('presetformat' . $id, 'preset', 'neq', "$id");

            // Primer.
            $mform->addElement(
                'textarea',
                'primer' . $id,
                get_string('primer', 'qbank_questiongen'),
                'wrap="virtual" rows="10" cols="50"'
            );
            $mform->setType('primer' . $id, PARAM_RAW);
            $mform->setDefault('primer' . $id, $presetrecord->primer);
            $mform->addHelpButton('primer' . $id, 'primer', 'qbank_questiongen');
            $mform->hideIf('primer' . $id, 'editpreset');
            $mform->hideIf('primer' . $id, 'preset', 'neq', "$id");

            // Instructions.
            $mform->addElement(
                'textarea',
                'instructions' . $id,
                get_string('instructions', 'qbank_questiongen'),
                'wrap="virtual" rows="10" cols="50"'
            );
            $mform->setType('instructions' . $id, PARAM_RAW);
            $mform->setDefault('instructions' . $id, $presetrecord->instructions);
            $mform->addHelpButton('instructions' . $id, 'instructions', 'qbank_questiongen');
            $mform->hideIf('instructions' . $id, 'editpreset');
            $mform->hideIf('instructions' . $id, 'preset', 'neq', "$id");

            // Example.
            $mform->addElement(
                'textarea',
                'example' . $id,
                get_string('example', 'qbank_questiongen'),
                'wrap="virtual" rows="10" cols="50"'
            );
            $mform->setType('example' . $id, PARAM_RAW);
            $mform->setDefault('example' . $id, $presetrecord->example);
            $mform->addHelpButton('example' . $id, 'example', 'qbank_questiongen');
            $mform->hideIf('example' . $id, 'editpreset');
            $mform->hideIf('example' . $id, 'preset', 'neq', "$id");
        }

        $mform->addElement('hidden', 'cmid', $this->_customdata['cmid']);
        $mform->setType('cmid', PARAM_INT);

        $buttonarray = [];
        $buttonarray[] = &$mform->createElement('submit', 'submitbutton', get_string('generate', 'qbank_questiongen'));
        $buttonarray[] = &$mform->createElement('cancel', 'cancel', get_string('backtocourse', 'qbank_questiongen'));
        $mform->addGroup($buttonarray, 'buttonar', '', [' '], false);
    }
    /**
     * Form validation
     *
     * @param array $data
     * @param array $files
     * @return array
     */
    public function validation($data, $files) {
        // TODO Make validation fail if story is empty or no course modules have been selected
        return [];
    }
}
