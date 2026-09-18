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

namespace qbank_questiongen\local;

use SimpleXMLElement;
use stdClass;

/**
 * Class to handle the import of generated questions in XML format.
 *
 * @package    qbank_questiongen
 * @copyright  ISB Bayern, 2024
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class xml_importer {
    /**
     * Resolve a single question without writing to the question bank.
     * Embedded files are excluded from type detection to avoid creating draft files.
     *
     * @param string $xml Moodle XML document
     * @return stdClass Derived internal Moodle question type
     * @throws \invalid_parameter_exception If the document cannot be used as a question preset
     */
    public static function validate_question(string $xml): stdClass {
        global $CFG;
        require_once($CFG->dirroot . '/question/engine/bank.php');
        require_once($CFG->dirroot . '/question/format/xml/format.php');

        if (strlen($xml) > 262144 || trim($xml) === '') {
            throw new \invalid_parameter_exception('Invalid question XML size');
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $document = new \DOMDocument();
            if (!$document->loadXML($xml, LIBXML_NONET) || $document->doctype !== null) {
                throw new \invalid_parameter_exception('Invalid question XML document');
            }
            $root = $document->documentElement;
            if ($root->tagName !== 'quiz' || $root->namespaceURI) {
                throw new \invalid_parameter_exception('Expected a quiz document');
            }
            $elements = [];
            foreach ($root->childNodes as $node) {
                if ($node instanceof \DOMElement) {
                    $elements[] = $node;
                }
            }
            if (count($elements) !== 1 || $elements[0]->tagName !== 'question' || $elements[0]->namespaceURI) {
                throw new \invalid_parameter_exception('Expected exactly one question');
            }
            $xmltype = $elements[0]->getAttribute('type');
            if ($xmltype === '' || in_array($xmltype, ['category', 'description'])) {
                throw new \invalid_parameter_exception('Unsupported question type');
            }
            if ($document->getElementsByTagName('image_base64')->length) {
                throw new \invalid_parameter_exception('Legacy image fields are not supported');
            }
            foreach (iterator_to_array($document->getElementsByTagName('file')) as $file) {
                if ($file->getAttribute('encoding') !== 'base64' || base64_decode($file->textContent, true) === false) {
                    throw new \invalid_parameter_exception('Invalid embedded file');
                }
                $file->parentNode->removeChild($file);
            }
            $format = new \qformat_xml();
            ob_start();
            try {
                $questions = $format->readquestions([$document->saveXML()]);
            } catch (\Throwable $exception) {
                throw new \invalid_parameter_exception('Cannot read question XML');
            } finally {
                ob_end_clean();
            }
            if ($format->importerrors || !is_array($questions) || count($questions) !== 1) {
                throw new \invalid_parameter_exception('Cannot read a single question');
            }
            $qtype = $questions[0]->qtype ?? '';
            if (!\question_bank::is_qtype_installed($qtype) || in_array($qtype, ['category', 'description', 'missingtype'])) {
                throw new \invalid_parameter_exception('Question type is not installed');
            }
            return (object) ['qtype' => $qtype];
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /**
     * Parse the XML questions.
     *
     * @param int $categoryid the question category to import the question to
     * @param stdClass $llmresponse the LLM response object that contains the question XML
     * @param bool $addidentifier if the question should be prefixed
     * @return true on success, false otherwise
     */
    public static function parse_questions(
        int $categoryid,
        stdClass $llmresponse,
        bool $addidentifier,
    ): bool {
        global $CFG, $DB;

        try {
            $actual = self::validate_question($llmresponse->text);
            if (
                isset($llmresponse->expectedtype) &&
                $actual->qtype !== $llmresponse->expectedtype->qtype
            ) {
                return false;
            }
        } catch (\invalid_parameter_exception $exception) {
            return false;
        }

        // Eventually add a prefix to the question title. We have to do this in the XML before importing.
        $llmresponse->text = self::add_aiidentifiers($llmresponse->text, $addidentifier);

        $fileformat = 'xml';
        $filedir = make_request_directory();
        $realfilename = uniqid() . "." . $fileformat;
        $importfile = $filedir . '/' . $realfilename;
        $filecreated = file_put_contents($importfile, $llmresponse->text);

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
        $qformat->setStoponerror(true);
        $qformat->set_display_progress(false);

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

        $eventparams = [
            'contextid' => $qformat->category->contextid,
            'other' => ['format' => $fileformat, 'categoryid' => $qformat->category->id],
        ];

        $event = \core\event\questions_imported::create($eventparams);
        $event->trigger();
        return true;
    }

    /**
     * Helper function add AI identifiers to the question title and as tag.
     *
     * We cannot hook into the XML importing mechanism of moodle. We also cannot easily get the id of the imported question.
     * So we're left with adding the identifier straight to the XML before importing the questions.
     * This function adds an AI identifier prefix (admin setting) to the question title if the user has selected it.
     * If the tag subsystem is enabled in the moodle instance and the related admin setting is set it will also add a tag
     * (independent of what the user has selected or not).
     *
     * @param string $xmlquestionasstring the question as XML string
     * @param bool $addidentifier if the user wants to add an identifier to the question title
     * @return string the altered XML string
     */
    public static function add_aiidentifiers(string $xmlquestionasstring, bool $addidentifier): string {
        $aiidentifier = get_config('qbank_questiongen', 'aiidentifier');
        $aiidentifiertag = get_config('qbank_questiongen', 'aiidentifiertag');

        if (empty($aiidentifier) && empty($aiidentifiertag)) {
            return $xmlquestionasstring;
        }

        $xmlasobject = new SimpleXMLElement($xmlquestionasstring);
        // If the user chose to add an identifier to the question title and the global admin setting provides a proper identifier,
        // we add this to the question title.
        if (!empty($aiidentifier) && $addidentifier) {
            if (
                !isset($xmlasobject->question)
                || !isset($xmlasobject->question->name)
                || !isset($xmlasobject->question->name->text)
            ) {
                // The XML could be broken, so we just output some debugging and return.
                debugging('Could not add an AI identifier because the XML is broken. Parsed XML:');
                debugging($xmlasobject->asXML());
            }
            $xmlasobject->question->name->text = $aiidentifier . $xmlasobject->question->name->text;
        }
        // If we have a global identifier for tags, we add a tag, no matter what the user chose.
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
