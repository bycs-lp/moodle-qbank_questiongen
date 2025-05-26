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

namespace qbank_questiongen;

use qtype_aitext;
use qtype_aitext_question;
use qtype_multichoice;
use qtype_multichoice_single_question;
use question_bank;

final class temp_test extends \advanced_testcase {
    public function test_tmp(): void {
        global $CFG;
        /*$this->resetAfterTest();
        require_once($CFG->dirroot . '/question/type/multichoice/tests/helper.php');
        $mc = \test_question_maker::get_question_form_data('multichoice');
        print_r($mc);*/

        /*question_bank::load_question_definition_classes('aitext');
        $mc = new qtype_multichoice_single_question();
        require_once($CFG->dirroot . '/question/engine/tests/helpers.php');
        \test_question_maker::initialise_a_question($mc);
        print_r($mc);*/

        require_once($CFG->dirroot . '/question/type/multichoice/questiontype.php');
        require_once($CFG->dirroot . '/question/type/multichoice/question.php');
        $mctype = new qtype_multichoice();


        $mcquestion = new qtype_multichoice_single_question();
        print_r($mctype->get_question_options($mcquestion));

    }

}
