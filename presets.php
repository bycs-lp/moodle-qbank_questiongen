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
 * Management site: Manage, create and edit presets.
 *
 * @package    qbank_questiongen
 * @copyright  2025 ISB Bayern
 * @author     Philipp Memmel
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require('../../../config.php');

require_login();

global $DB, $OUTPUT, $PAGE;

$url = new moodle_url('/question/bank/questiongen/presets.php');
$PAGE->set_url($url);
$PAGE->set_context(context_system::instance());
$PAGE->set_heading(get_string('managepresets', 'qbank_questiongen'));
$PAGE->requires->css(new moodle_url('/question/bank/questiongen/styles.css'));

require_capability('qbank/questiongen:manage', context_system::instance());

$export = optional_param('export', 0, PARAM_BOOL);
if ($export) {
    require_sesskey();
    $id = optional_param('id', 0, PARAM_INT);
    // A zero ID exports the catalogue; a concrete ID exports one preset using the same portable bundle format.
    $records = $id ? [$DB->get_record('qbank_questiongen_preset', ['id' => $id], '*', MUST_EXIST)] :
        $DB->get_records('qbank_questiongen_preset', null, 'name, id');
    require_once($CFG->libdir . '/filelib.php');
    send_file(
        \qbank_questiongen\local\preset_transfer::encode($records),
        $id ? 'questiongen-preset-' . $id . '.json' : 'questiongen-presets.json',
        0,
        0,
        true,
        true,
        'application/json'
    );
}

$importform = new \qbank_questiongen\form\import_presets_form($url);
$importerror = '';
if ($importform->get_data()) {
    require_sesskey();
    try {
        // Read the current user's uploaded draft through Moodle's form API, not a supplied filesystem path.
        $result = \qbank_questiongen\local\preset_transfer::import((string) $importform->get_file_content('presetfile'));
        redirect(
            $url,
            get_string('presetsimported', 'qbank_questiongen', $result),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    } catch (\qbank_questiongen\local\questiongen_exception $exception) {
        $importerror = $exception->getMessage();
    }
}

echo $OUTPUT->header();
if ($importerror !== '') {
    echo $OUTPUT->notification(s($importerror));
}
$importform->display();

$presetsrecords = $DB->get_records('qbank_questiongen_preset');
require_once($CFG->dirroot . '/question/engine/bank.php');
$presets = [];
foreach ($presetsrecords as $preset) {
    // Display stored validation metadata; listing presets must not parse their XML examples again.
    $selectionstatus = !empty($preset->qtype) && question_bank::is_qtype_installed($preset->qtype)
        ? get_string('pluginname', 'qtype_' . $preset->qtype) : get_string('selectionunavailable', 'qbank_questiongen');
    // The template renders prompt/example HTML unescaped, so prepare plain-text formatting and escaped XML here.
    $presets[] = [
            'id' => $preset->id,
            'name' => $preset->name,
            'selectionstatus' => $selectionstatus,
            'selectiondescription' => $preset->selectiondescription,
            'exporturl' => (new moodle_url($url, ['export' => 1, 'id' => $preset->id, 'sesskey' => sesskey()]))->out(false),
            'primer' => format_text($preset->primer, FORMAT_PLAIN, ['filter' => false]),
            'instructions' => format_text($preset->instructions, FORMAT_PLAIN, ['filter' => false]),
            'example' => '<pre><code>' . s($preset->example) . '</code></pre>',
    ];
}

echo $OUTPUT->render_from_template('qbank_questiongen/presets', [
    'presets' => array_values($presets),
    'exporturl' => (new moodle_url($url, ['export' => 1, 'sesskey' => sesskey()]))->out(false),
]);

echo $OUTPUT->footer();
