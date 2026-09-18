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
     * Portable presets round-trip without IDs or overwriting existing entries.
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
    }

    /**
     * Reject unsupported formats and invalid field types without writing data.
     */
    public function test_invalid_preset_files(): void {
        global $DB;
        $this->resetAfterTest();
        $json = preset_transfer::encode($DB->get_records('qbank_questiongen_preset'));
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
    }

    /**
     * New presets immediately enter the catalogue and snapshots remain unchanged.
     */
    public function test_catalogue_snapshot(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $DB->delete_records('qbank_questiongen_preset');
        $presets = json_decode(file_get_contents(__DIR__ . '/../../db/initial_presets.json'));
        $preset = $presets[2];
        unset($preset->id);
        $preset->selectiondescription = 'Assess relationships';
        $preset->id = $DB->insert_record('qbank_questiongen_preset', $preset);
        $snapshot = utils::prepare_selection((object) ['qtypes' => [], 'pedagogy' => 'Activate prior knowledge']);
        $this->assertCount(1, $snapshot->catalogue);
        $this->assertSame('match', $snapshot->catalogue[$preset->id]->qtype);
        $this->assertSame('Activate prior knowledge', $snapshot->pedagogy);
        $DB->set_field('qbank_questiongen_preset', 'name', 'Changed', ['id' => $preset->id]);
        $duplicate = clone $preset;
        unset($duplicate->id);
        $DB->insert_record('qbank_questiongen_preset', $duplicate);
        $this->assertCount(2, utils::get_preset_catalogue(['match']));
        $this->assertSame($preset->name, $snapshot->catalogue[$preset->id]->name);
        $this->expectException(\invalid_parameter_exception::class);
        utils::get_preset_catalogue(['unknown']);
    }

    /**
     * Invalid presets do not become candidates.
     */
    public function test_empty_catalogue(): void {
        global $DB;
        $this->resetAfterTest();
        $DB->delete_records('qbank_questiongen_preset');
        $DB->insert_record('qbank_questiongen_preset', (object) ['name' => 'Invalid', 'example' => '<quiz/>']);
        $this->assertSame([], utils::get_preset_catalogue());
        $this->expectException(\invalid_parameter_exception::class);
        utils::prepare_selection((object) []);
    }

    /**
     * Tests the functionality that substitutes certain placeholders in a string.
     *
     * @covers \qbank_questiongen\local\utils::filter_prompts
     */
    public function test_filter_prompts(): void {
        global $USER;
        $teststring = 'This is a teststring that includes the language placeholder {{currentlang}}, which should be replaced';
        $expectedresultstring = 'This is a teststring that includes the language placeholder English, which should be replaced';
        $this->assertEquals($expectedresultstring, utils::filter_prompts($teststring));

        $USER->lang = 'de';
        $expectedresultstring = 'This is a teststring that includes the language placeholder German, which should be replaced';
        $this->assertEquals($expectedresultstring, utils::filter_prompts($teststring));
    }
}
