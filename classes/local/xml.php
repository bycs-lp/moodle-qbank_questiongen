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
 * Class to handle xml format.
 *
 * @package    qbank_questiongen
 * @copyright  ISB Bayern, 2024
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace qbank_questiongen\local;

use qbank_managecategories\question_categories;
use qbank_managecategories\question_category_object_test;
use SimpleXMLElement;
use stdClass;

/**
 * Class to handle xml format.
 *
 * @package    qbank_questiongen
 * @copyright  ISB Bayern, 2024
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class xml {

    /**
     * Parse the xml questions.
     *
     * @param int $categoryid
     * @param stdClass $llmresponse
     * @param int $numofquestions
     * @param bool $addidentifier
     * @return return true on success, false otherweise
     */
    public static function parse_questions(
            int $categoryid,
            stdClass $llmresponse,
            bool $addidentifier,
    ): bool {

        global $CFG, $DB;

        // Eventually add an prefix to the question title. We have to do this in the XML before importing.
        if (!$addidentifier) {
            $llmresponse->text = self::add_aiidentifiers($llmresponse->text);
        }

        // Work out if this is an uploaded file.
        // Or one from the filesarea.

        $fileformat = 'xml';
        $filedir = make_request_directory();
        $realfilename = uniqid() . "." . $fileformat;
        $importfile = $filedir . '/' . $realfilename;
        $filecreated = file_put_contents($importfile, $llmresponse->text);

        // $realfilename = $importform->get_new_filename('newfile');
        // $importfile = make_request_directory() . "/{$realfilename}";
        // if (!$result = $importform->save_file('newfile', $importfile, true)) {
        //     throw new moodle_exception('uploadproblem');
        // }

        $formatfile = $CFG->dirroot . '/question/format/xml/format.php';
        if (!is_readable($formatfile)) {
            throw new \moodle_exception('formatnotfound', 'question', '', $fileformat);
        }

        require_once($formatfile);

        $classname = 'qformat_xml';
        $qformat = new $classname();

        // Load data into class.
        $category = $DB->get_record('question_categories', ['id' => $categoryid]);
        $qformat->setCategory($category);
        $qformat->setContexts([\context_helper::instance_by_id($category->contextid)]);
        $qformat->setFilename($importfile);
        $qformat->setRealfilename($realfilename);
        // $qformat->setMatchgrades($form->matchgrades);
        // $qformat->setCatfromfile(!empty($form->catfromfile));
        // $qformat->setContextfromfile(!empty($form->contextfromfile));
        $qformat->setStoponerror(true);

        // Do anything before that we need to.
        if (!$qformat->importpreprocess()) {
            mtrace('Error(s) during importpreprocess: ');
            mtrace($qformat->importerrors);
            return false;
        }

        // Process the uploaded file.
        if (!$qformat->importprocess()) {
            mtrace('Error(s) during importprocess: ');
            mtrace($qformat->importerrors);
            return false;
        }

        // In case anything needs to be done after.
        if (!$qformat->importpostprocess()) {
            mtrace('Error(s) during importpostprocess: ');
            mtrace($qformat->importerrors);
            return false;
        }

        // Log the import into this category.
        $eventparams = [
                'contextid' => $qformat->category->contextid,
                'other' => ['format' => $fileformat, 'categoryid' => $qformat->category->id],
        ];

        // --- End Adaption.

        $event = \core\event\questions_imported::create($eventparams);
        $event->trigger();
        return true;
    }

    public static function add_aiidentifiers(string $xmlquestionasstring): string {
        $aiidentifier = get_config('qbank_questiongen', 'aiidentifier');
        $aiidentifiertag = get_config('qbank_questiongen', 'aiidentifiertag');

        if (empty($aiidentifier) && empty($aiidentifiertag)) {
            return $xmlquestionasstring;
        }

        $xmlasobject = new SimpleXMLElement($xmlquestionasstring);
        if (!empty($aiidentifier)) {
            $xmlasobject->question->name->text = $aiidentifier . $xmlasobject->question->name->text;
        }
        if (!empty($aiidentifiertag)) {
            if (!isset($xmlasobject->tags)) {
                $xmlasobject->question->addChild('tags');
            }
            $tagelement = $xmlasobject->question->tags->addChild('tag');
            $tagelement->addChild('text', $aiidentifiertag);
        }
        return $xmlasobject->asXML();
    }
}
