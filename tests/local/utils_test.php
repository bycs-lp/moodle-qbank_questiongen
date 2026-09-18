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
 * Unit tests for the qbank_questiongen utility functions.
 *
 * @package   qbank_questiongen
 * @copyright 2025 ISB Bayern
 * @author    Philipp Memmel
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(utils::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(preset_transfer::class)]
final class utils_test extends \advanced_testcase {
    /**
     * Upgrade informs administrators without replacing or parsing existing presets.
     */
    public function test_upgrade_requires_manual_preset_review(): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/upgradelib.php');
        require_once(__DIR__ . '/../../db/upgrade.php');
        $this->resetAfterTest();
        $this->setAdminUser();
        $preset = $DB->get_record('qbank_questiongen_preset', ['id' => 1], '*', MUST_EXIST);
        $preset->primer = 'User customisation';
        $preset->example = 'An old example that must not be parsed during upgrade';
        $preset->qtype = null;
        $DB->update_record('qbank_questiongen_preset', $preset);
        $before = $DB->get_records('qbank_questiongen_preset', null, 'id');
        $generator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $category = $generator->create_question_category();
        $question = $generator->create_question('shortanswer', null, ['category' => $category->id]);
        set_config('version', 2026072800, 'qbank_questiongen');
        $sink = $this->redirectMessages();
        $this->assertTrue(xmldb_qbank_questiongen_upgrade(2026072800));
        $this->assertEquals($before, $DB->get_records('qbank_questiongen_preset', null, 'id'));
        $this->assertTrue($DB->record_exists('question', ['id' => $question->id]));
        $messages = $sink->get_messages();
        $this->assertCount(count(get_admins()), $messages);
        foreach ($messages as $message) {
            $this->assertSame('presetsreviewrequired', $message->eventtype);
            $this->assertStringContainsString('NOT been replaced', $message->fullmessage);
            $this->assertStringContainsString('/question/bank/questiongen/presets.php', $message->fullmessage);
        }
        $sink->close();
    }

    /**
     * Exchange presets end to end: round-trip, duplicates, atomic errors and limits.
     */
    public function test_preset_transfer(): void {
        global $DB;
        $this->resetAfterTest();
        $presets = $DB->get_records('qbank_questiongen_preset');
        $json = preset_transfer::encode($presets);
        $portable = json_decode($json)->presets;
        $this->assertFalse(property_exists($portable[0], 'id'));
        $result = preset_transfer::import($json);
        $this->assertSame(0, $result->imported);
        $this->assertSame(count($presets), $result->skipped);
        $DB->delete_records('qbank_questiongen_preset');
        $result = preset_transfer::import($json);
        $this->assertSame(count($presets), $result->imported);
        $this->assertSame($json, preset_transfer::encode($DB->get_records('qbank_questiongen_preset', null, 'id')));
        $document = json_decode($json);
        $document->presets[0]->name = 'Must not be imported';
        $document->presets[1]->example = '<quiz/>';
        try {
            preset_transfer::import(json_encode($document));
            $this->fail('Invalid bundle accepted');
        } catch (questiongen_exception $exception) {
            $this->assertSame('errorpresetentry', $exception->errorcode);
        }
        $this->assertFalse($DB->record_exists('qbank_questiongen_preset', ['name' => 'Must not be imported']));
        $modified = clone reset($presets);
        $modified->instructions .= ' Additional guidance.';
        $result = preset_transfer::import(preset_transfer::encode([$modified, $modified]));
        $this->assertSame(1, $result->imported);
        $this->assertSame(1, $result->skipped);
        $this->assertSame(2, $DB->count_records('qbank_questiongen_preset', ['name' => $modified->name]));
        $document = json_decode($json);
        $invalid = ['not json', '[]', '{}', str_repeat(' ', preset_transfer::MAX_BYTES + 1)];
        $document->version = 2;
        $invalid[] = json_encode($document);
        $document->version = 1;
        $document->presets[0]->primer = ['unexpected'];
        $invalid[] = json_encode($document);
        $document->presets = [];
        $invalid[] = json_encode($document);
        $count = $DB->count_records('qbank_questiongen_preset');
        foreach ($invalid as $input) {
            try {
                preset_transfer::import($input);
                $this->fail('Invalid file accepted');
            } catch (questiongen_exception $exception) {
                $this->assertContains($exception->errorcode, ['errorpresetentry', 'errorpresetfile']);
            }
        }
        $this->assertSame($count, $DB->count_records('qbank_questiongen_preset'));
        $this->expectException(questiongen_exception::class);
        preset_transfer::encode(array_fill(0, 101, $modified));
    }

    /**
     * Read metadata, filter candidates and retain snapshots while the catalogue changes.
     */
    public function test_catalogue_snapshot(): void {
        global $DB, $USER;
        $this->resetAfterTest();
        $this->setAdminUser();
        $DB->delete_records('qbank_questiongen_preset');
        $presets = json_decode(file_get_contents(__DIR__ . '/../../db/initial_presets.json'));
        $preset = $presets[2];
        unset($preset->id);
        $preset->selectiondescription = 'Assess relationships';
        $preset->primer = 'Language: {{currentlang}}';
        $preset->example = 'Validated on write, not parsed on read';
        $preset->id = $DB->insert_record('qbank_questiongen_preset', $preset);
        $snapshot = utils::prepare_selection((object) ['qtypes' => [], 'pedagogy' => 'Activate prior knowledge']);
        $this->assertCount(1, $snapshot->catalogue);
        $this->assertSame('match', $snapshot->catalogue[$preset->id]->qtype);
        $this->assertSame('Activate prior knowledge', $snapshot->pedagogy);
        $this->assertSame('Language: English', $snapshot->catalogue[$preset->id]->primer);
        $this->assertSame($preset->example, $snapshot->catalogue[$preset->id]->example);
        $USER->lang = 'de';
        $this->assertSame('Language: German', utils::get_preset_catalogue()[$preset->id]->primer);
        $this->assertSame('Language: {{currentlang}}', $DB->get_field('qbank_questiongen_preset', 'primer', ['id' => $preset->id]));
        $DB->set_field('qbank_questiongen_preset', 'name', 'Changed', ['id' => $preset->id]);
        $duplicate = clone $preset;
        unset($duplicate->id);
        $DB->insert_record('qbank_questiongen_preset', $duplicate);
        $this->assertCount(2, utils::prepare_selection((object) ['qtypes' => ['match']])->catalogue);
        $this->assertSame($preset->name, $snapshot->catalogue[$preset->id]->name);
        try {
            utils::prepare_selection((object) ['qtypes' => ['unknown']]);
            $this->fail('Unknown type accepted');
        } catch (\invalid_parameter_exception $exception) {
            $this->assertNotEmpty($exception->getMessage());
        }
        $DB->delete_records('qbank_questiongen_preset');
        $DB->insert_record('qbank_questiongen_preset', (object) ['name' => 'Invalid', 'example' => '<quiz/>']);
        $preset->qtype = 'questiongen_missing';
        $DB->insert_record('qbank_questiongen_preset', $preset);
        $this->assertSame([], utils::get_preset_catalogue());
        $this->expectException(\invalid_parameter_exception::class);
        utils::prepare_selection((object) []);
    }
}
