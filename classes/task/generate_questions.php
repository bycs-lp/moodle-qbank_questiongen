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

namespace qbank_questiongen\task;

use qbank_questiongen\local\question_generator;

/**
 * Adhoc task for questions generation.
 *
 * @package     qbank_questiongen
 * @category    admin
 * @copyright   2023 Ruthy Salomon <ruthy.salomon@gmail.com> , Yedidia Klein <yedidia@openapp.co.il>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class generate_questions extends \core\task\adhoc_task {
    use \core\task\stored_progress_task_trait;

    #[\Override]
    public function execute() {
        global $DB, $USER;

        try {
            $customdata = $this->get_custom_data();
            $questiongenids = $customdata->questiongenids;
            [$insql, $inparams] = $DB->get_in_or_equal($questiongenids);
            $questiongenrecords = $DB->get_records_select('qbank_questiongen', "id $insql", $inparams);
            $this->start_stored_progress();
            if (empty($questiongenrecords)) {
                // It should not really happen that we have no questions here.
                // Exception will be caught at the end. The task will finish silently.
                throw new \moodle_exception('errornogenerateentriesfound', 'qbank_questiongen');
            }
            $questionstocreatecount = count($questiongenrecords);
            $selection = $customdata->selection ?? null;
            foreach ($questiongenrecords as $record) {
                if (!empty($record->selectiondata)) {
                    if ((int) $record->userid !== (int) $USER->id) {
                        throw new \qbank_questiongen\local\questiongen_exception('errorselectioncatalogue', 'qbank_questiongen');
                    }
                    $selection = json_decode($record->selectiondata, false, 512, JSON_THROW_ON_ERROR);
                    break;
                }
            }
            $this->progress->update(
                0,
                $questionstocreatecount,
                get_string(
                    'questiongeneratingstatus',
                    'qbank_questiongen',
                    ['current' => 0, 'total' => $questionstocreatecount]
                )
            );

            // Before creating questions we need to check, if we need to generate the story from the course content first.
            if (property_exists($customdata, 'courseactivities') && !empty($customdata->courseactivities)) {
                $questiongenerator = $this->get_generator($customdata->contextid);
                $story = $questiongenerator->create_story_from_cms($customdata->courseactivities);

                foreach ($questiongenrecords as $dbrecord) {
                    $dbrecord->story = $story;
                    $DB->update_record('qbank_questiongen', $dbrecord);
                }
                if (empty(trim($story))) {
                    $this->progress->update_full(100, '');
                    $this->progress->error(get_string('errorcoursecontentsempty', 'qbank_questiongen'));
                    return;
                }
            }

            // Create questions.
            mtrace("[qbank_questiongen] Creating Questions with AI...\n");

            $i = 1;
            foreach ($questiongenids as $questiongenid) {
                $created = false;
                $error = ''; // Error message.
                $update = new \stdClass();

                $dbrecord = $DB->get_record('qbank_questiongen', ['id' => $questiongenid], '*', MUST_EXIST);
                if ((int) $dbrecord->userid !== (int) $USER->id) {
                    throw new \required_capability_exception(
                        \context_system::instance(),
                        'moodle/question:add',
                        'nopermissions',
                        ''
                    );
                }
                $category = $DB->get_record('question_categories', ['id' => $dbrecord->category], '*', MUST_EXIST);
                require_capability('moodle/question:add', \context::instance_by_id($category->contextid));
                if ((string) $dbrecord->success === '1') {
                    $i++;
                    continue;
                }
                $questiongenerator = $this->get_generator($customdata->contextid);
                $expectedtype = null;
                if (!empty($dbrecord->selectionmode)) {
                    if (empty($selection)) {
                        throw new \qbank_questiongen\local\questiongen_exception('errorselectioncatalogue', 'qbank_questiongen');
                    }
                    $catalogue = (array) $selection->catalogue;
                    $selected = $dbrecord->selectedpresetid ? ($catalogue[$dbrecord->selectedpresetid] ?? null) : null;
                    if (!$selected) {
                        $this->progress->update(
                            $i - 1,
                            $questionstocreatecount,
                            get_string('selectingpreset', 'qbank_questiongen', $i)
                        );
                        $selected = $questiongenerator->select_preset(
                            $dbrecord,
                            $selection,
                            $customdata->sendexistingquestionsascontext
                        );
                    }
                    if (!$selected) {
                        $DB->set_field('qbank_questiongen', 'success', '0', ['id' => $dbrecord->id]);
                        $this->progress->update(
                            $i,
                            $questionstocreatecount,
                            get_string('errorselectionresponse', 'qbank_questiongen')
                        );
                        $i++;
                        continue;
                    }
                    $dbrecord->selectedpresetid = $selected->id;
                    $dbrecord->primer = $selected->primer;
                    $dbrecord->instructions = $selected->instructions;
                    $dbrecord->example = $selected->example;
                    $dbrecord->timemodified = time();
                    $DB->update_record('qbank_questiongen', $dbrecord);
                    $dbrecord->pedagogy = $selection->pedagogy;
                    $expectedtype = \qbank_questiongen\local\xml_importer::validate_question($selected->example);
                }
                $maxtries = max(1, (int) $dbrecord->numoftries);
                mtrace("[qbank_questiongen] Creating Question $i ...\n");

                while (!$created && $dbrecord->tries <= $maxtries) {
                    // Get questions from AI API.
                    $question = $questiongenerator->generate_question($dbrecord, $customdata->sendexistingquestionsascontext);
                    if (!is_object($question)) {
                        // An error occurred.
                        // We do not retry here, because if the subsystem returns an error it's very likely that it's a general
                        // one. Retries are only meant to create slightly different questions in case of XML parsing fails.
                        $update->id = $dbrecord->id;
                        $update->timemodified = time();
                        $update->success = 0;
                        $DB->update_record('qbank_questiongen', $update);
                        $this->progress->update_full(100, '');
                        $this->progress->error($question);
                        return;
                    }

                    $update->id = $dbrecord->id;
                    $update->timemodified = time();
                    $update->llmresponse = $question->text;
                    $DB->update_record('qbank_questiongen', $update);

                    if ($expectedtype) {
                        $question->expectedtype = $expectedtype;
                    }
                    $created = \qbank_questiongen\local\xml_importer::parse_questions(
                        $dbrecord->category,
                        $question,
                        !empty($dbrecord->aiidentifier),
                    );

                    // If questions were not created.
                    if (!$created) {
                        // Insert error info to DB.
                        $update = new \stdClass();
                        $update->id = $dbrecord->id;
                        $update->tries = ++$dbrecord->tries;
                        $update->timemodified = time();
                        $DB->update_record('qbank_questiongen', $update);
                    }

                    // Print error message.
                    // It will be shown on cron/adhoc output (file/whatever).
                    if ($error != '') {
                        mtrace('[qbank_questiongen adhoc_task]' . $error);
                    }
                }

                // Write success state to DB.
                $update = new \stdClass();
                $update->id = $dbrecord->id;
                $update->success = $created ? 1 : 0;
                $DB->update_record('qbank_questiongen', $update);
                $this->progress->update(
                    $i,
                    $questionstocreatecount,
                    get_string(
                        'questiongeneratingstatus',
                        'qbank_questiongen',
                        ['current' => $i, 'total' => $questionstocreatecount]
                    )
                );
                $i++;
            }
            $this->progress->update_full(
                100,
                get_string('questiongeneratingfinished', 'qbank_questiongen', $questionstocreatecount)
            );
            $successstates = $DB->get_fieldset_select('qbank_questiongen', 'success', "id $insql", $inparams);
            $failedquestionscount = count(array_filter($successstates, fn($state) => intval($state) === 0));
            if ($failedquestionscount > 0) {
                $this->progress->error(
                    get_string(
                        'errorcreatingquestions',
                        'qbank_questiongen',
                        ['failed' => $failedquestionscount, 'total' => $questionstocreatecount]
                    )
                );
            }
        } catch (\Throwable $exception) {
            $usererrormessage = get_string('errorcreatingquestionscritical', 'qbank_questiongen');
            if ($exception instanceof \qbank_questiongen\local\questiongen_exception) {
                // If we have a questiongen_exception, we overwrite the user-faced message with the one of
                // the questiongen_exception.
                $usererrormessage = $exception->getMessage();
            }
            mtrace('Exception thrown during task. Task will not be requeued. This is just for debugging purposes.');
            mtrace('Question generation stopped: ' . get_class($exception));
            if ($this->progress->get_percent() === 0.0) {
                // If no progress has been made yet, set it to 100% so it's clear that the process is done and the user can see the
                // red color signaling an error.
                $this->progress->update_full(100, '');
            }
            $this->progress->error($usererrormessage);
        } finally {
            if (!empty($questiongenids)) {
                foreach ($DB->get_records_list('qbank_questiongen', 'id', $questiongenids) as $record) {
                    if ((int) $record->userid === (int) $USER->id && (string) $record->success === '') {
                        $DB->update_record('qbank_questiongen', (object) [
                            'id' => $record->id, 'success' => '0', 'timemodified' => time(),
                        ]);
                    }
                }
            }
            if (isset($customdata->selection)) {
                unset($customdata->selection);
                $this->set_custom_data($customdata);
                if (
                    $this->get_id() && $DB->record_exists('task_adhoc', ['id' => $this->get_id(),
                    'classname' => '\\qbank_questiongen\\task\\generate_questions'])
                ) {
                    $DB->set_field('task_adhoc', 'customdata', $this->get_custom_data_as_string(), ['id' => $this->get_id()]);
                }
            }
        }
    }

    /**
     * Create the generator for this task's context.
     *
     * @param int $contextid Request context
     * @return question_generator
     */
    protected function get_generator(int $contextid): question_generator {
        return new question_generator($contextid);
    }

    /**
     * Sets the initial progress of the associated progress bar.
     *
     * It adds a message that currently one is waiting for the adhoc task to be picked up.
     */
    public function set_initial_progress(): void {
        // Avoid rendering inline update JS in the initial page response before core progress scripts are available.
        $this->progress->auto_update(false);
        $this->progress->update_full(0, get_string('waitingforadhoctaskstart', 'qbank_questiongen'));
        $this->progress->auto_update(true);
    }

    #[\Override]
    public function retry_until_success(): bool {
        // We don't want to retry this task.
        return false;
    }
}
