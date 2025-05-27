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
 * CLI utility to test create questions.
 *
 * @package     qbank_questiongen
 * @category    admin
 * @copyright   2023 Ruthy Salomon <ruthy.salomon@gmail.com> , Yedidia Klein <yedidia@openapp.co.il>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../../config.php');
require_once(__DIR__ . '/../../../engine/bank.php');

global $DB;
$DB->delete_records('qbank_questiongen');

$qbankobject = new \stdClass();
$qbankobject->qformat = 'moodlexml';
$qbankobject->numofquestions = 1;
$qbankobject->category = 15;
$qbankobject->story = 'relativity theory';
$qbankobject->numoftries = 1;
$qbankobject->userid = 3;
$qbankobject->llmresponse = '';
$qbankobject->tries = 0;
$qbankobject->success = '';
$qbankobject->uniqid = '446818cd94845c21.21066595';
$qbankobject->primer = 'You are a helpful teacher\'s assistant that creates multiple choice questions based on the topics given by the user.';
$qbankobject->instructions = 'Please write a multiple choice question in {{currentlang}} language in XML format on a topic I will specify to you separately. Only return the plain XML, do not apply any formatting. Use the example provided for generating the questions in XML format. Inside the <quiz> tags you can specifiy multiple questions wrapped by <question></question>. Replace the string "Question title" in the example by the title of the question, the string "Question text" with the text of the question. Replace the possible answers "Choice 1", "Choice 2", "Choice 3" and "Choice 4" in the example with the options of the generated question. The option that is correct has to have the attribute fraction="100" in the opening "answer" tag, the other wrong options have to have fraction="0".';
$qbankobject->example = '<?xml version="1.0" encoding="UTF-8"?>
<quiz>
  <question type="multichoice">
    <name>
      <text>Question title</text>
    </name>
    <questiontext format="html">
      <text><![CDATA[<p>Question text</p>]]></text>
    </questiontext>
    <generalfeedback format="html">
      <text><![CDATA[<p>General feedback</p>]]></text>
    </generalfeedback>
    <defaultgrade>1</defaultgrade>
    <penalty>0</penalty>
    <hidden>0</hidden>
    <idnumber></idnumber>
    <single>true</single>
    <shuffleanswers>false</shuffleanswers>
    <answernumbering>none</answernumbering>
    <showstandardinstruction>0</showstandardinstruction>
    <correctfeedback format="html">
      <text><![CDATA[<p>The answer is correct.</p>]]></text>
    </correctfeedback>
    <partiallycorrectfeedback format="html">
      <text><![CDATA[<p>The answer is partially correct.</p>]]></text>
    </partiallycorrectfeedback>
    <incorrectfeedback format="html">
      <text><![CDATA[<p>The answer is wrong.</p>]]></text>
    </incorrectfeedback>
    <shownumcorrect/>
    <answer fraction="0" format="html">
      <text><![CDATA[<p>Choice 1</p>]]></text>
      <feedback format="html">
        <text><![CDATA[<p>Feedback 1</p>]]></text>
      </feedback>
    </answer>
    <answer fraction="0" format="html">
      <text><![CDATA[<p>Choice 2</p>]]></text>
      <feedback format="html">
        <text><![CDATA[<p>Feedback 2</p>]]></text>
      </feedback>
    </answer>
    <answer fraction="100" format="html">
      <text><![CDATA[<p>Choice 3</p>]]></text>
      <feedback format="html">
        <text><![CDATA[<p>Feedback 3</p>]]></text>
      </feedback>
    </answer>
    <answer fraction="0" format="html">
      <text><![CDATA[<p>Choice 4</p>]]></text>
      <feedback format="html">
        <text><![CDATA[<p>Feedback 4</p>]]></text>
      </feedback>
    </answer>
  </question>
</quiz>';
$recordid = $DB->insert_record('qbank_questiongen', $qbankobject);


$user = \core_user::get_user(3);
\core\session\manager::init_empty_session();
\core\session\manager::set_user($user);

$task = new \qbank_questiongen\task\generate_questions();
$task->set_userid(3);
$task->set_custom_data(['genaiid' => $recordid]);
$task->execute();
