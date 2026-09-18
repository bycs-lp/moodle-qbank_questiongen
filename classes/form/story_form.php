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

namespace qbank_questiongen\form;

use qbank_questiongen\local\question_generator;

defined('MOODLE_INTERNAL') || die();
require_once($CFG->libdir . '/formslib.php');

/**
 * Form to get the settings for spawning the question generation task.
 *
 * @package     qbank_questiongen
 * @copyright   2023 Ruthy Salomon <ruthy.salomon@gmail.com> , Yedidia Klein <yedidia@openapp.co.il>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class story_form extends \moodleform {
    /** @var array Validated preset catalogue for this form request. */
    private array $catalogue;

    /** @var \stdClass|null Validated snapshot, populated by get_data(). */
    private ?\stdClass $selection = null;

    /**
     * Return the snapshot after successful form validation.
     *
     * @return \stdClass|null
     */
    public function get_selection(): ?\stdClass {
        return $this->selection;
    }

    /** @var int constant defining the question generation mode: generate questions based on a topic. */
    const QUESTIONGEN_MODE_TOPIC = 1;

    /** @var int constant defining the question generation mode: generate questions based on content entered by the user. */
    const QUESTIONGEN_MODE_STORY = 2;

    /** @var int constant defining the question generation mode: generate questions based on course contents. */
    const QUESTIONGEN_MODE_COURSECONTENTS = 3;

    #[\Override]
    public function definition() {
        global $DB, $OUTPUT;

        $mform = $this->_form;
        $contexts = $this->_customdata['contexts']->having_cap('moodle/question:add');
        $contexts = array_filter(
            $contexts,
            fn($context) => $context->contextlevel !== CONTEXT_SYSTEM && $context->contextlevel !== CONTEXT_COURSECAT
        );

        $mform->addElement('hidden', 'cmid', $this->_customdata['cmid']);
        $mform->setType('cmid', PARAM_INT);

        // Question category.
        $mform->addElement(
            'questioncategory',
            'category',
            get_string('category', 'question'),
            ['contexts' => $contexts]
        );
        $mform->addHelpButton('category', 'category', 'qbank_questiongen');

        // Number of questions.
        $defaultnumofquestions = 2;
        $select = $mform->addElement(
            'select',
            'numofquestions',
            get_string('numofquestions', 'qbank_questiongen'),
            ['1' => 1, '2' => 2, '3' => 3, '4' => 4, '5' => 5, '6' => 6, '7' => 7, '8' => 8, '9' => 9, '10' => 10]
        );
        $select->setSelected($defaultnumofquestions);
        $mform->setType('numofquestions', PARAM_INT);

        $mform->addElement(
            'select',
            'mode',
            get_string('mode', 'qbank_questiongen'),
            [
                self::QUESTIONGEN_MODE_TOPIC => get_string('modetopic', 'qbank_questiongen'),
                self::QUESTIONGEN_MODE_STORY => get_string('modestory', 'qbank_questiongen'),
                self::QUESTIONGEN_MODE_COURSECONTENTS => get_string('modecoursecontents', 'qbank_questiongen'),
            ]
        );
        $mform->setType('mode', PARAM_INT);
        $mform->setDefault('mode', self::QUESTIONGEN_MODE_TOPIC);
        $mform->addHelpButton('mode', 'mode', 'qbank_questiongen');

        // Story.
        $mform->addElement(
            'textarea',
            'topic',
            get_string('topic', 'qbank_questiongen'),
            'wrap="virtual" rows="10" cols="50"'
        );
        $mform->setType('topic', PARAM_RAW);
        $mform->addHelpButton('topic', 'topic', 'qbank_questiongen');
        $mform->hideIf('topic', 'mode', 'neq', self::QUESTIONGEN_MODE_TOPIC);

        // Story.
        $mform->addElement(
            'textarea',
            'story',
            get_string('story', 'qbank_questiongen'),
            'wrap="virtual" rows="10" cols="50"'
        );
        $mform->setType('story', PARAM_RAW);
        $mform->addHelpButton('story', 'story', 'qbank_questiongen');
        $mform->hideIf('story', 'mode', 'neq', self::QUESTIONGEN_MODE_STORY);

        [, $cmrec] = get_module_from_cmid($this->_customdata['cmid']);

        $modinfo = get_fast_modinfo($cmrec->course);

        $courseactivities = [];
        foreach ($modinfo->get_cms() as $cm) {
            if (question_generator::is_cm_supported($cm) && $cm->uservisible) {
                $courseactivities[$cm->id] = $cm->name;
            }
        }

        $mform->addElement(
            'autocomplete',
            'courseactivities',
            get_string('activitylist', 'qbank_questiongen'),
            $courseactivities,
            ['multiple' => true]
        );
        $mform->hideIf('courseactivities', 'mode', 'neq', self::QUESTIONGEN_MODE_COURSECONTENTS);
        $mform->addHelpButton('courseactivities', 'activitylist', 'qbank_questiongen');

        // Preset selection.
        $mform->addElement('select', 'selectionmode', get_string('selectionmode', 'qbank_questiongen'), [
            0 => get_string('selectionfixed', 'qbank_questiongen'),
            1 => get_string('selectionautomatic', 'qbank_questiongen'),
        ]);
        $mform->setType('selectionmode', PARAM_INT);
        $mform->setDefault('selectionmode', 0);
        $presetrecords = $DB->get_records('qbank_questiongen_preset', null, 'name, id');
        $this->catalogue = \qbank_questiongen\local\utils::get_preset_catalogue($presetrecords);
        $types = [];
        foreach ($this->catalogue as $candidate) {
            $types[$candidate->qtype] = get_string('pluginname', 'qtype_' . $candidate->qtype);
        }
        \core_collator::asort($types);
        $mform->addElement(
            'autocomplete',
            'qtypes',
            get_string('selectiontypes', 'qbank_questiongen'),
            $types,
            ['multiple' => true, 'noselectionstring' => get_string('selectionalltypes', 'qbank_questiongen')]
        );
        $mform->setType('qtypes', PARAM_ALPHANUMEXT);
        $mform->hideIf('qtypes', 'selectionmode', 'eq', 0);
        $mform->addElement(
            'textarea',
            'pedagogy',
            get_string('pedagogy', 'qbank_questiongen'),
            ['rows' => 4, 'cols' => 50, 'maxlength' => 4000]
        );
        $mform->setType('pedagogy', PARAM_TEXT);
        $mform->addHelpButton('pedagogy', 'pedagogy', 'qbank_questiongen');
        $mform->hideIf('pedagogy', 'selectionmode', 'eq', 0);
        $presets = [];
        foreach ($presetrecords as $presetrecord) {
            $presets[$presetrecord->id] = $presetrecord->name;
        }
        $mform->addElement('select', 'preset', get_string('preset', 'qbank_questiongen'), $presets);
        $mform->hideIf('preset', 'selectionmode', 'eq', 1);

        if (has_capability('qbank/questiongen:manage', \context_system::instance())) {
            $mform->addElement(
                'static',
                'manageglobalpresets',
                '',
                $OUTPUT->render_from_template('qbank_questiongen/managelink', [])
            );
        }

        // Edit preset.
        $mform->addElement('checkbox', 'editpreset', get_string('editpreset', 'qbank_questiongen'));
        $mform->hideIf('editpreset', 'selectionmode', 'eq', 1);

        // Create elements for all presets.
        foreach ($presetrecords as $presetrecord) {
            $id = $presetrecord->id;

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
            $mform->hideIf('primer' . $id, 'selectionmode', 'eq', 1);

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
            $mform->hideIf('instructions' . $id, 'selectionmode', 'eq', 1);

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
            $mform->hideIf('example' . $id, 'selectionmode', 'eq', 1);
        }

        $mform->addElement(
            'checkbox',
            'sendexistingquestionsascontext',
            get_string('sendexistingquestionsascontext', 'qbank_questiongen')
        );
        $mform->addHelpButton('sendexistingquestionsascontext', 'sendexistingquestionsascontext', 'qbank_questiongen');
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

        $buttonarray = [];
        $buttonarray[] = &$mform->createElement('submit', 'submitbutton', get_string('generate', 'qbank_questiongen'));
        $buttonarray[] = &$mform->createElement('cancel', 'cancel', get_string('backtocourse', 'qbank_questiongen'));
        $mform->addGroup($buttonarray, 'buttonar', '', [' '], false);
    }

    #[\Override]
    public function validation($data, $files) {
        global $DB;
        $errors = [];
        if (!in_array((int) ($data['selectionmode'] ?? 0), [0, 1], true)) {
            $errors['selectionmode'] = get_string('invaliddata', 'error');
        } else if (!empty($data['selectionmode'])) {
            try {
                $submitted = (array) $this->_form->getSubmitValue('qtypes');
                if (array_filter($submitted, 'is_string') !== $submitted) {
                    throw new \invalid_parameter_exception('Invalid question type filter');
                }
                $data['qtypes'] = array_values(array_diff($submitted, ['_qf__force_multiselect_submission']));
                $this->selection = \qbank_questiongen\local\utils::prepare_selection((object) $data, $this->catalogue);
            } catch (\qbank_questiongen\local\questiongen_exception $exception) {
                $errors['pedagogy'] = $exception->getMessage();
            } catch (\invalid_parameter_exception $exception) {
                $errors['qtypes'] = get_string('errorselectioncatalogue', 'qbank_questiongen');
            }
        } else if (!$DB->record_exists('qbank_questiongen_preset', ['id' => $data['preset'] ?? 0])) {
            $errors['preset'] = get_string('errorselectioncatalogue', 'qbank_questiongen');
        }
        if ((int) $data['numofquestions'] < 1 || (int) $data['numofquestions'] > 10) {
            $errors['numofquestions'] = get_string('invaliddata', 'error');
        }
        if (intval($data['mode']) === self::QUESTIONGEN_MODE_TOPIC && empty(trim($data['topic']))) {
            $errors['topic'] = get_string('errortopicempty', 'qbank_questiongen');
        }
        if (intval($data['mode']) === self::QUESTIONGEN_MODE_STORY && empty(trim($data['story']))) {
            $errors['story'] = get_string('errorstoryempty', 'qbank_questiongen');
        }
        if (intval($data['mode']) === self::QUESTIONGEN_MODE_COURSECONTENTS && empty($data['courseactivities'])) {
            $errors['courseactivities'] = get_string('errornoactivitiesselected', 'qbank_questiongen');
        }
        if (intval($data['mode']) === self::QUESTIONGEN_MODE_COURSECONTENTS && !empty($data['courseactivities'])) {
            [, $cm] = get_module_from_cmid($this->_customdata['cmid']);
            $allowed = [];
            foreach (get_fast_modinfo($cm->course)->get_cms() as $activity) {
                if ($activity->uservisible && question_generator::is_cm_supported($activity)) {
                    $allowed[] = $activity->id;
                }
            }
            if (array_diff($data['courseactivities'], $allowed)) {
                $errors['courseactivities'] = get_string('invaliddata', 'error');
            }
        }
        if (
            !in_array((int) $data['mode'], [self::QUESTIONGEN_MODE_TOPIC, self::QUESTIONGEN_MODE_STORY,
            self::QUESTIONGEN_MODE_COURSECONTENTS], true)
        ) {
            $errors['mode'] = get_string('invaliddata', 'error');
        }
        return $errors;
    }
}
