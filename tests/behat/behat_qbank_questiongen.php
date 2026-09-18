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
 * Navigation steps for question generator acceptance tests.
 *
 * @package qbank_questiongen
 * @copyright 2026 ISB Bayern
 * @author Dr. Peter Mayer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../../../lib/behat/behat_base.php');

/**
 * Open the generator for a named question bank using normal access checks.
 *
 * @package qbank_questiongen
 * @copyright 2026 ISB Bayern
 * @author Dr. Peter Mayer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class behat_qbank_questiongen extends behat_base {
    /**
     * Visit preset administration without bypassing its access checks.
     *
     * @Given /^I open the question preset management page$/
     */
    public function open_preset_management(): void {
        $this->getSession()->visit($this->locate_path('/question/bank/questiongen/presets.php'));
    }

    /**
     * Verify an expected access error without leaving Behat on an exception page.
     *
     * @Then /^question preset administration access is denied$/
     */
    public function preset_administration_is_denied(): void {
        $this->open_preset_management();
        $text = $this->getSession()->getPage()->getText();
        if (strpos($text, 'Sorry, but you do not currently have permissions to do that') === false) {
            throw new \Exception('Expected preset administration to be denied.');
        }
        $this->getSession()->visit($this->locate_path('/my/'));
    }

    /**
     * Open the form for a question bank.
     *
     * @Given /^I open the AI question form for "([^"]*)"$/
     * @param string $name Question bank name
     */
    public function open_question_form(string $name): void {
        global $DB;
        $bank = $DB->get_record('qbank', ['name' => $name], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('qbank', $bank->id, 0, false, MUST_EXIST);
        $this->getSession()->visit($this->locate_path('/question/bank/questiongen/story.php?cmid=' . $cm->id));
    }
}
