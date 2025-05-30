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

/**
 * Class to handle gift format.
 *
 * @package    qbank_questiongen
 * @copyright  ISB Bayern, 2024
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class gift {

    /**
     * Parse the gift questions.
     *
     * @param int $categoryid
     * @param object $llmresponse
     * @param int $userid
     * @param int $genaiid
     * @param bool $addidentifier
     * @return false|object[]
     */
    public static function parse_question(
            int $categoryid,
            object $llmresponse,
            int $userid,
            bool $addidentifier,
            int $genaiid
    ) {
        global $DB, $CFG;
        require_once($CFG->libdir . '/questionlib.php');
        require_once($CFG->dirroot . '/question/format.php');
        require_once($CFG->dirroot . '/question/format/gift/format.php');

        $qformat = new \qformat_gift();

        $singlequestion = explode("\n", $llmresponse->text);

        // Manipulating question text manually for question text field.
        $questiontext = explode('{', $singlequestion[0]);

        $questiontext = trim(preg_replace('/^.*::/', '', $questiontext[0]));

        $qtype = 'multichoice';
        $q = $qformat->readquestion($singlequestion);

        // Check if question is valid.
        if (!$q) {
            return false;
        }
        $q->category = $categoryid;
        $q->createdby = $userid;
        $q->modifiedby = $userid;
        $q->timecreated = time();
        $q->timemodified = time();
        $q->questiontext = ['text' => "<p>" . $questiontext . "</p>"];
        $q->questiontextformat = 1;
        $prefix = get_config('qbank_questiongen', 'aiidentifier');
        if (!empty($addidentifier) && !empty($prefix)) {
            $q->name = $prefix . $q->name; // Adds a "watermark" to the question
        }
        $created = \question_bank::get_qtype($qtype)->save_question($q, $q);

        return !empty($created);
    }
}
