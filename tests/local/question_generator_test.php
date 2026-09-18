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

use context_module;
use core_question\local\bank\question_bank_helper;
use qbank_questiongen\form\story_form;
use stdClass;

/**
 * Unit tests for the question_generator class.
 *
 * @package   qbank_questiongen
 * @copyright 2025 ISB Bayern
 * @author    Philipp Memmel
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(question_generator::class)]
final class question_generator_test extends \advanced_testcase {
    /**
     * Select from the catalogue with bounded retries, single-candidate bypass and provider failure.
     */
    #[\PHPUnit\Framework\Attributes\Group('baseline')]
    public function test_select_preset_contract(): void {
        $this->resetAfterTest();
        $catalogue = [3 => (object) ['id' => 3, 'name' => 'Match', 'qtype' => 'match', 'selectiondescription' => 'Relationships'],
            4 => (object) ['id' => 4, 'name' => 'Short', 'qtype' => 'shortanswer', 'selectiondescription' => 'Recall']];
        $selection = (object) ['catalogue' => $catalogue, 'pedagogy' => 'Compare concepts'];
        $data = (object) ['mode' => story_form::QUESTIONGEN_MODE_STORY, 'story' => 'Synthetic source', 'category' => 0];
        // Each invalid answer must consume exactly one retry and then accept an ID from the unchanged catalogue.
        foreach (['[]', '{"presetid":"3"}', '{"presetid":99}', '{"presetid":3,"other":true}', 'broken'] as $invalid) {
            $generator = $this->getMockBuilder(question_generator::class)->setConstructorArgs([SYSCONTEXTID])
                ->onlyMethods(['retrieve_llm_response'])->getMock();
            $generator->expects($this->exactly(2))->method('retrieve_llm_response')->willReturnOnConsecutiveCalls(
                ['generatedquestiontext' => $invalid, 'errormessage' => ''],
                ['generatedquestiontext' => '{"presetid":3}', 'errormessage' => '']
            );
            $this->assertSame($catalogue[3], $generator->select_preset($data, $selection, false));
        }
        $generator = $this->getMockBuilder(question_generator::class)->setConstructorArgs([SYSCONTEXTID])
            ->onlyMethods(['retrieve_llm_response'])->getMock();
        $generator->expects($this->exactly(2))->method('retrieve_llm_response')
            ->willReturn(['generatedquestiontext' => '{}', 'errormessage' => '']);
        $this->assertNull($generator->select_preset($data, $selection, false));
        $generator = $this->getMockBuilder(question_generator::class)->setConstructorArgs([SYSCONTEXTID])
            ->onlyMethods(['retrieve_llm_response'])->getMock();
        $generator->expects($this->once())->method('retrieve_llm_response')
            ->willReturn(['generatedquestiontext' => '', 'errormessage' => 'Quota exhausted']);
        $selection->catalogue = [3 => $catalogue[3]];
        $this->assertSame($catalogue[3], $generator->select_preset($data, $selection, false));
        $selection->catalogue = $catalogue;
        $this->expectException(questiongen_exception::class);
        $generator->select_preset($data, $selection, false);
    }

    /**
        * Verify the public manager contract without depending on its internal configuration classes.
     */
    #[\PHPUnit\Framework\Attributes\Group('baseline')]
    public function test_selection_response_transport(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $contextid = \context_system::instance()->id;
        $conversation = [['sender' => 'system', 'message' => 'Return only the selected preset ID.']];
        // Discover the installed manager's response type without coupling this test to its internal class namespace.
        $method = new \ReflectionMethod(\local_ai_manager\manager::class, 'perform_request');
        $responsetype = $method->getReturnType()->getName();
        $cases = [
            [200, '{"presetid":3}', '', '{"presetid":3}', ''],
            [429, '', 'Quota exhausted', '', 'Quota exhausted'],
            [500, '', '', '', get_string('errorselectionprovider', 'qbank_questiongen')],
        ];
        foreach ($cases as [$code, $content, $error, $expectedcontent, $expectederror]) {
            $response = $this->createMock($responsetype);
            $response->method('get_code')->willReturn($code);
            $response->method('get_content')->willReturn($content);
            $response->method('get_errormessage')->willReturn($error);
            $manager = $this->createMock(\local_ai_manager\manager::class);
            $manager->expects($this->once())->method('perform_request')
                ->with('Choose preset 3.', 'qbank_questiongen', $contextid, ['conversationcontext' => $conversation])
                ->willReturn($response);
            $generator = $this->getMockBuilder(question_generator::class)->setConstructorArgs([$contextid])
                ->onlyMethods(['get_manager'])->getMock();
            $generator->method('get_manager')->willReturn($manager);
            $result = $generator->retrieve_llm_response([
                ...$conversation, ['sender' => 'user', 'message' => 'Choose preset 3.'],
            ]);
            $this->assertSame(['generatedquestiontext' => $expectedcontent, 'errormessage' => $expectederror], $result);
        }
    }

    /**
     * Tests the functionality that substitutes certain placeholders in a string.
     *
     * @covers \qbank_questiongen\local\question_generator::generate_question
     */
    #[\PHPUnit\Framework\Attributes\Group('baseline')]
    public function test_generate_question(): void {
        global $CFG;
        $this->resetAfterTest();
        set_config('provider', 'local_ai_manager', 'qbank_questiongen');

        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $qbankcminfo = question_bank_helper::create_default_open_instance($course, 'testquestionbank');
        $questionplugingenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $questioncategory = $questionplugingenerator->create_question_category(['contextid' => $qbankcminfo->context->id]);
        $questioncategory2 = $questionplugingenerator->create_question_category(['contextid' => $qbankcminfo->context->id]);

        $generatedxmlfixture = file_get_contents($CFG->dirroot . '/question/bank/questiongen/tests/fixtures/multichoice.xml');
        $questiongenerator = $this->getMockBuilder(question_generator::class)
            ->setConstructorArgs([$qbankcminfo->context->id])->onlyMethods(['retrieve_llm_response'])->getMock();
        $questiongenerator->method('retrieve_llm_response')->willReturn(['generatedquestiontext' => $generatedxmlfixture,
            'errormessage' => '']);

        $dataobject = new stdClass();
        $dataobject->mode = story_form::QUESTIONGEN_MODE_TOPIC;
        $dataobject->category = $questioncategory->id;
        $dataobject->numoftries = 3;
        $dataobject->story = 'French revolution';
        // We import our initial presets and use the first one (for multiple choice question) for testing.
        // In reality the user is able to manipulate each of the preset entries, but we don't want to test that here.
        $presetjson = json_decode(file_get_contents($CFG->dirroot . '/question/bank/questiongen/db/initial_presets.json'))[0];
        $this->assertEquals('Multiple choice question', $presetjson->name);
        $dataobject->primer = $presetjson->primer;
        $dataobject->instructions = $presetjson->instructions;
        $dataobject->example = $presetjson->example;

        $questionobject = $questiongenerator->generate_question($dataobject, false);
        $this->assertEquals($generatedxmlfixture, $questionobject->text);
        $this->assertEquals($presetjson->primer, $questionobject->primer);
        $this->assertEquals($presetjson->instructions, $questionobject->instructions);
        $this->assertEquals($presetjson->example, $questionobject->example);
        $expectedstoryprompt = '## QUESTION GENERATION MODE - TOPIC' . "\n"
            . 'Create a question about the following topic. Use your own training data to generate it:' . "\n"
            . $dataobject->story;
        $this->assertEquals($expectedstoryprompt, $questionobject->storyprompt);
        $this->assertEmpty($questionobject->questiontextsinqbankprompt);
        $dataobject->pedagogy = 'Compare different causes for age 14';
        $guided = $questiongenerator->generate_question($dataobject, false);
        $this->assertStringContainsString($dataobject->pedagogy, $guided->instructions);
        unset($dataobject->pedagogy);

        // Now test if sending questions as context works.
        $questionplugingenerator->create_question(
            'essay',
            null,
            [
                'category' => $questioncategory->id,
                'name' => 'Test question 1',
                'questiontext' => ['text' => 'Write some intelligent stuff', 'format' => FORMAT_MOODLE],
            ]
        );
        $questionplugingenerator->create_question(
            'essay',
            null,
            [
                'category' => $questioncategory->id,
                'name' => 'Test question 2',
                'questiontext' => ['text' => 'Write some more intelligent stuff', 'format' => FORMAT_MOODLE],
            ]
        );
        $questionplugingenerator->create_question(
            'essay',
            null,
            [
                'category' => $questioncategory2->id,
                'name' => 'Test question 3',
                'questiontext' => ['text' => 'This question should not be sent, because it\'s in a different category',
                    'format' => FORMAT_MOODLE],
            ]
        );
        $questionobject = $questiongenerator->generate_question($dataobject, true);
        $this->assertEquals($generatedxmlfixture, $questionobject->text);
        $this->assertEquals($presetjson->primer, $questionobject->primer);
        $this->assertEquals($presetjson->instructions, $questionobject->instructions);
        $this->assertEquals($presetjson->example, $questionobject->example);
        // The variable $expectedstoryprompt still is valid and thus does not need to be redefined.
        $this->assertEquals($expectedstoryprompt, $questionobject->storyprompt);
        $this->assertStringStartsWith('## ALREADY EXISTING QUESTIONS' . "\n", $questionobject->questiontextsinqbankprompt);
        $this->assertStringContainsString(
            'The question that will be generated by you has to be as different '
            . 'as possible from all of the following questions in this JSON string: "',
            $questionobject->questiontextsinqbankprompt
        );
        $this->assertStringContainsString('Test question 1', $questionobject->questiontextsinqbankprompt);
        $this->assertStringContainsString('Write some intelligent stuff', $questionobject->questiontextsinqbankprompt);
        $this->assertStringContainsString('Test question 2', $questionobject->questiontextsinqbankprompt);
        $this->assertStringContainsString('Write some more intelligent stuff', $questionobject->questiontextsinqbankprompt);
        $this->assertStringNotContainsString('Test question 3', $questionobject->questiontextsinqbankprompt);
        $this->assertStringNotContainsString(
            'This question should not be sent, because it\'s in a different category',
            $questionobject->questiontextsinqbankprompt
        );

        // Test story mode.
        $dataobject->mode = story_form::QUESTIONGEN_MODE_STORY;
        $dataobject->story = 'This is a lot of content that the LLM can use to generate questions from.';
        $questionobject = $questiongenerator->generate_question($dataobject, false);
        $this->assertEquals($generatedxmlfixture, $questionobject->text);
        $this->assertEquals($presetjson->primer, $questionobject->primer);
        $this->assertEquals($presetjson->instructions, $questionobject->instructions);
        $this->assertEquals($presetjson->example, $questionobject->example);
        $expectedstoryprompt = '## QUESTION GENERATION MODE - CONTENTS' . "\n"
            . 'Create a question from the following contents. '
            . 'Only use this contents and do not use any training data:' . "\n"
            . '### START OF USER PROVIDED CONTENT' . "\n"
            . $dataobject->story . "\n"
            . '### END OF USER PROVIDED CONTENT';
        $this->assertEquals($expectedstoryprompt, $questionobject->storyprompt);
        $this->assertEmpty($questionobject->questiontextsinqbankprompt);

        // Test course contents mode.
        // We do not really test the generation of text from course contents, this is being done by the test for
        // the method create_story_from_cms.
        $dataobject->mode = story_form::QUESTIONGEN_MODE_COURSECONTENTS;
        $dataobject->story = 'This is a lot of content that the LLM can use to generate questions from.';
        $questionobject = $questiongenerator->generate_question($dataobject, false);
        $this->assertEquals($generatedxmlfixture, $questionobject->text);
        $this->assertEquals($presetjson->primer, $questionobject->primer);
        $this->assertEquals($presetjson->instructions, $questionobject->instructions);
        $this->assertEquals($presetjson->example, $questionobject->example);
        $expectedstoryprompt = '## QUESTION GENERATION MODE - CONTENTS' . "\n"
            . 'Create a question from the following contents. '
            . 'Only use this contents and do not use any training data:' . "\n"
            . '### START OF USER PROVIDED CONTENT' . "\n"
            . $dataobject->story . "\n"
            . '### END OF USER PROVIDED CONTENT';
        $this->assertEquals($expectedstoryprompt, $questionobject->storyprompt);
        $this->assertEmpty($questionobject->questiontextsinqbankprompt);
    }

    /**
     * Both AI requests respect viewmine and viewall independently of the add capability.
     */
    #[\PHPUnit\Framework\Attributes\Group('baseline')]
    public function test_existing_question_permissions(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('provider', 'local_ai_manager', 'qbank_questiongen');
        $course = $this->getDataGenerator()->create_course();
        $bank = question_bank_helper::create_default_open_instance($course, 'security');
        $user = $this->getDataGenerator()->create_user();
        $roleid = create_role('Question contributor', 'questioncontributor', '');
        $this->getDataGenerator()->enrol_user($user->id, $course->id, $roleid);
        assign_capability('moodle/question:add', CAP_ALLOW, $roleid, $bank->context->id);
        $generator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $category = $generator->create_question_category(['contextid' => $bank->context->id]);
        $generator->create_question('shortanswer', null, ['category' => $category->id, 'name' => 'Private foreign question']);
        $generator->create_question('shortanswer', null, ['category' => $category->id,
            'createdby' => $user->id, 'name' => 'Own question']);
        $selection = (object) ['catalogue' => [
            1 => (object) ['id' => 1, 'name' => 'One', 'qtype' => 'shortanswer', 'selectiondescription' => 'Recall'],
            2 => (object) ['id' => 2, 'name' => 'Two', 'qtype' => 'essay', 'selectiondescription' => 'Explain'],
        ], 'pedagogy' => ''];
        $data = (object) ['category' => $category->id, 'mode' => story_form::QUESTIONGEN_MODE_TOPIC,
            'story' => 'A topic', 'primer' => '', 'instructions' => '', 'example' => ''];
        // Grant read permissions incrementally; question:add alone must never expose existing question contents.
        foreach (['none' => 0, 'viewmine' => 1, 'viewall' => 2] as $capability => $expected) {
            if ($capability !== 'none') {
                assign_capability('moodle/question:' . $capability, CAP_ALLOW, $roleid, $bank->context->id);
            }
            accesslib_clear_all_caches_for_unit_testing();
            $this->setUser($user);
            $requests = [];
            $questiongenerator = $this->getMockBuilder(question_generator::class)
                ->setConstructorArgs([$bank->context->id])->onlyMethods(['retrieve_llm_response'])->getMock();
            $questiongenerator->method('retrieve_llm_response')->willReturnCallback(function ($messages) use (&$requests) {
                $requests[] = json_encode($messages);
                return ['generatedquestiontext' => '{"presetid":1}', 'errormessage' => ''];
            });
            $questiongenerator->select_preset($data, $selection, true);
            $questiongenerator->generate_question($data, true);
            $this->assertCount(2, $requests);
            foreach ($requests as $request) {
                $this->assertSame($expected > 0, str_contains($request, 'Own question'));
                $this->assertSame($expected > 1, str_contains($request, 'Private foreign question'));
            }
        }
    }

    /**
     * Tests the extracting of content from course modules.
     *
     * @covers \qbank_questiongen\local\question_generator::extract_content_from_cm
     */
    public function test_extract_content_from_cm(): void {
        global $CFG, $USER;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $this->setAdminUser();
        $qbankcminfo = question_bank_helper::create_default_open_instance($course, 'testquestionbank');
        $questiongenerator = new question_generator($qbankcminfo->context->id);

        // Test mod_page.
        $testcontent = 'Very interesting content in a page to generate questions from';
        $pagegenerator = $this->getDataGenerator()->get_plugin_generator('mod_page');
        $page = $pagegenerator->create_instance(['course' => $course->id, 'name' => 'testpage', 'content' => $testcontent]);
        $content = $questiongenerator->extract_content_from_cm(get_fast_modinfo($course)->get_cm($page->cmid));
        $this->assertEquals($testcontent, $content);

        // Test mod_label.
        $testcontent = 'Very interesting content in a label to generate questions from';
        $labelgenerator = $this->getDataGenerator()->get_plugin_generator('mod_label');
        $label = $labelgenerator->create_instance(['course' => $course->id, 'name' => 'testlabel', 'intro' => $testcontent]);
        $content = $questiongenerator->extract_content_from_cm(get_fast_modinfo($course)->get_cm($label->cmid));
        $this->assertEquals($testcontent, $content);

        // Test mod_resource.
        $testcontent = 'Very interesting content in a resource to generate questions from';
        $resourcegenerator = $this->getDataGenerator()->get_plugin_generator('mod_resource');
        $resource = $resourcegenerator->create_instance(['course' => $course->id, 'name' => 'testresource']);
        $context = context_module::instance($resource->cmid);
        $fs = get_file_storage();
        // First of all, cleanup generated files from module generator so we can add our own one.
        foreach ($fs->get_area_files($context->id, 'mod_resource', 'content') as $file) {
            $file->delete();
        }

        // Now test different file types.
        // We start with simple .txt file.
        $filerecord = ['component' => 'mod_resource', 'filearea' => 'content',
            'contextid' => $context->id, 'itemid' => 0, 'filepath' => '/',
            'userid' => $this->getDataGenerator()->create_user()->id];
        $filerecord['filename'] = 'testfile.txt';
        $file = $fs->create_file_from_string($filerecord, $testcontent);

        $extractor = $this->createMock(\local_ai_content\document_extractor::class);
        $extractor->method('is_file_supported')
            ->willReturnCallback(fn(\stored_file $file) => in_array($file->get_mimetype(), ['application/pdf', 'image/png']));
        $extractor->method('extract_text_from_file')
            ->with($this->isInstanceOf(\stored_file::class), $qbankcminfo->context->id, $USER->id, 'qbank_questiongen')
            ->willReturn('Extracted PDF or image content');
        \core\di::set(\local_ai_content\document_extractor::class, $extractor);
        $content = $questiongenerator->extract_content_from_cm(get_fast_modinfo($course)->get_cm($resource->cmid));
        $this->assertEquals($testcontent, $content);
        $file->delete();

        // Test a PDF file. We mock the extraction of the content from the PDF. We actually just check if the correct method is
        // being called and assume the extraction of the content (which is done by an external LLM) will return the text.
        // The fixture PDF is only being used to determine its mimetype and call the method.
        $filerecord['filename'] = 'testpdf.pdf';
        $file = $fs->create_file_from_string(
            $filerecord,
            file_get_contents($CFG->dirroot . '/question/bank/questiongen/tests/fixtures/testpdf.pdf')
        );
        $content = $questiongenerator->extract_content_from_cm(get_fast_modinfo($course)->get_cm($resource->cmid));
        $this->assertEquals('Extracted PDF or image content', $content);
        $file->delete();

        $filerecord['filename'] = 'testimage.png';
        $file = $fs->create_file_from_string($filerecord, file_get_contents($CFG->dirroot . '/pix/s/approve.png'));
        $content = $questiongenerator->extract_content_from_cm(get_fast_modinfo($course)->get_cm($resource->cmid));
        $this->assertEquals('Extracted PDF or image content', $content);
        $file->delete();

        // Test mod_folder.
        $testcontent = 'Very interesting content in a file in a folder to generate questions from';
        $foldergenerator = $this->getDataGenerator()->get_plugin_generator('mod_folder');
        $folder = $foldergenerator->create_instance(['course' => $course->id, 'name' => 'testfolder']);
        $context = context_module::instance($folder->cmid);
        $fs = get_file_storage();
        $filerecord = ['component' => 'mod_folder', 'filearea' => 'content', 'contextid' => $context->id, 'itemid' => 0,
            'filepath' => '/'];
        $filerecord['filename'] = 'testfile.txt';
        $file1 = $fs->create_file_from_string($filerecord, $testcontent);
        $filerecord['filename'] = 'testpdf.pdf';
        $file2 = $fs->create_file_from_string(
            $filerecord,
            file_get_contents($CFG->dirroot . '/question/bank/questiongen/tests/fixtures/testpdf.pdf')
        );
        $filerecord['filename'] = 'testimage.png';
        $file3 = $fs->create_file_from_string($filerecord, file_get_contents($CFG->dirroot . '/pix/s/approve.png'));

        $this->assertEquals(
            $testcontent . "\n\n" . 'Extracted PDF or image content' . "\n\n" . 'Extracted PDF or image content',
            $questiongenerator->extract_content_from_cm(get_fast_modinfo($course)->get_cm($folder->cmid))
        );
        $file1->delete();
        $file2->delete();
        $file3->delete();

        // Test mod_lesson.
        $testcontent1 = 'Very interesting content in a lesson, page 1';
        $testcontent2 = 'Very interesting content in a lesson, page 2';
        $lessongenerator = $this->getDataGenerator()->get_plugin_generator('mod_lesson');
        $lesson = $lessongenerator->create_instance(['course' => $course->id, 'name' => 'testlesson']);
        $contentrecord1 = [
            'contents_editor' => [
                'text' => $testcontent1,
                'format' => FORMAT_MOODLE,
                'itemid' => 0,
            ],
        ];
        $contentrecord2 = [
            'contents_editor' => [
                'text' => $testcontent2,
                'format' => FORMAT_MOODLE,
                'itemid' => 0,
            ],
        ];
        $lessongenerator->create_content($lesson, $contentrecord1);
        $lessongenerator->create_content($lesson, $contentrecord2);
        $content = $questiongenerator->extract_content_from_cm(get_fast_modinfo($course)->get_cm($lesson->cmid));
        $this->assertEquals($testcontent2 . "\n\n" . $testcontent1, $content);

        // Test mod_book.
        $testtitle1 = 'Chapter 1 title';
        $testtitle2 = 'Chapter 2 title';
        $testcontent1 = 'Very interesting content in a book, chapter 1';
        $testcontent2 = 'Very interesting content in a book, chapter 2';
        $bookgenerator = $this->getDataGenerator()->get_plugin_generator('mod_book');
        $book = $bookgenerator->create_instance(['course' => $course->id, 'name' => 'testbook']);
        $contentrecord1 = ['title' => $testtitle1, 'content' => $testcontent1];
        $contentrecord2 = ['title' => $testtitle2, 'content' => $testcontent2];
        $bookgenerator->create_content($book, $contentrecord1);
        $bookgenerator->create_content($book, $contentrecord2);
        $content = $questiongenerator->extract_content_from_cm(get_fast_modinfo($course)->get_cm($book->cmid));
        $this->assertEquals($testtitle2 . "\n" . $testcontent2 . "\n\n" . $testtitle1 . "\n" . $testcontent1, $content);
    }

    /**
     * Source extraction follows module, chapter, course and file-area permissions.
     */
    #[\PHPUnit\Framework\Attributes\Group('baseline')]
    public function test_source_permissions(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $othercourse = $this->getDataGenerator()->create_course();
        $bank = question_bank_helper::create_default_open_instance($course, 'security');
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($user->id, $othercourse->id, 'student');
        $roleid = $DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);
        $modules = [];
        foreach (['page', 'resource', 'folder', 'book', 'lesson'] as $type) {
            $modules[$type] = $this->getDataGenerator()->create_module($type, ['course' => $course->id]);
        }
        $bookgenerator = $this->getDataGenerator()->get_plugin_generator('mod_book');
        $bookgenerator->create_content($modules['book'], ['title' => 'Visible chapter', 'content' => 'Public content']);
        $bookgenerator->create_content($modules['book'], [
            'title' => 'Hidden chapter', 'content' => 'Private content', 'hidden' => 1,
        ]);
        $foreign = $this->getDataGenerator()->create_module('page', ['course' => $othercourse->id]);
        $hidden = $this->getDataGenerator()->create_module('page', ['course' => $course->id, 'visible' => 0]);
        $label = $this->getDataGenerator()->create_module('label', ['course' => $course->id, 'intro' => 'Course content']);
        $generator = new question_generator($bank->context->id);
        $this->setUser($user);
        $bookcm = get_fast_modinfo($course)->get_cm($modules['book']->cmid);
        $story = $generator->create_story_from_cms([$bookcm->id]);
        $this->assertStringContainsString('Public content', $story);
        $this->assertStringNotContainsString('Private content', $story);
        assign_capability('mod/book:viewhiddenchapters', CAP_ALLOW, $roleid, $bookcm->context->id);
        accesslib_clear_all_caches_for_unit_testing();
        $this->assertStringContainsString('Private content', $generator->create_story_from_cms([$bookcm->id]));
        $capabilities = ['page' => 'view', 'resource' => 'view', 'folder' => 'view', 'book' => 'read', 'lesson' => 'manage'];
        foreach ($capabilities as $type => $cap) {
            $cm = get_fast_modinfo($course)->get_cm($modules[$type]->cmid);
            assign_capability('mod/' . $type . ':' . $cap, CAP_PROHIBIT, $roleid, $cm->context->id);
            accesslib_clear_all_caches_for_unit_testing();
            $this->assertFalse(question_generator::is_cm_supported(get_fast_modinfo($course)->get_cm($cm->id)));
            try {
                $generator->extract_content_from_cm($cm);
                $this->fail('Denied module content was read: ' . $type);
            } catch (questiongen_exception $exception) {
                $this->assertSame('errornoactivitiesselected', $exception->errorcode);
            }
        }
        foreach ([$foreign, $hidden] as $module) {
            try {
                $generator->extract_content_from_cm(get_fast_modinfo($module->course)->get_cm($module->cmid));
                $this->fail('Content outside the permitted course selection was read');
            } catch (questiongen_exception $exception) {
                $this->assertSame('errornoactivitiesselected', $exception->errorcode);
            }
        }
        $this->setAdminUser();
        $file = get_file_storage()->create_file_from_string([
            'contextid' => context_module::instance($modules['resource']->cmid)->id,
            'component' => 'mod_resource', 'filearea' => 'intro', 'itemid' => 0, 'filepath' => '/', 'filename' => 'private.pdf',
        ], 'Not a source file');
        try {
            $generator->extract_content_from_pdf_or_image($file);
            $this->fail('A file outside the source content area was read');
        } catch (questiongen_exception $exception) {
            $this->assertSame('errornoactivitiesselected', $exception->errorcode);
        }
        $enrol = enrol_get_plugin('manual');
        // Use a label after unenrolment so this denial tests course access, not an earlier module-capability prohibition.
        $instance = $DB->get_record('enrol', ['courseid' => $course->id, 'enrol' => 'manual'], '*', MUST_EXIST);
        $enrol->unenrol_user($instance, $user->id);
        $this->setUser($user);
        try {
            $generator->create_story_from_cms([$label->cmid]);
            $this->fail('Unenrolled user read course content');
        } catch (questiongen_exception $exception) {
            $this->assertSame('errornoactivitiesselected', $exception->errorcode);
        }
    }

    /**
     * Tests the formatting of the extract cm content.
     *
     * @param string $content the content to format
     * @param string $expected the expected formatted content
     * @dataProvider format_extracted_cm_content_provider
     * @covers       \qbank_questiongen\local\question_generator::format_extracted_cm_content
     */
    public function test_format_extracted_cm_content(string $content, string $expected): void {
        $this->assertEquals($expected, question_generator::format_extracted_cm_content($content));
    }

    /**
     * Data provider for test_format_extracted_cm_content test function
     *
     * @return array[] array of test cases
     */
    public static function format_extracted_cm_content_provider(): array {
        return [
            'remove_p_tags' => [
                'content' => '<p style="color:red">test text</p>',
                'expected' => 'test text' . "\n",
            ],
            'multiple_paragraphs' => [
                'content' => '<p style="color: red;">test text</p><div>Some text in between</div><p>another test text</p>',
                'expected' => 'test text' . "\n\n" . 'Some text in between' . "\n" . 'another test text' . "\n",
            ],
            'line_breaks' => [
                'content' => 'test text<br/>another test text<br>final test text',
                'expected' => 'test text' . "\n" . 'another test text' . "\n" . 'final test text',
            ],
            'general_removal_of_tags' => [
                'content' => '<style>p { color: red; }</style>test <b>text</b> with <span>tags</span>',
                // The word "text" is emphasized.
                'expected' => 'test TEXT with tags',
            ],
            'trailingwhitespaces' => [
                'content' => '   test text  ',
                'expected' => 'test text',
            ],
        ];
    }

    /**
     * Tests the extraction of content from PDF or image with an external AI system.
     *
     * @covers \qbank_questiongen\local\question_generator::extract_content_from_pdf_or_image
     */
    public function test_extract_content_from_pdf_or_image(): void {
        global $CFG, $USER;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $qbankcminfo = question_bank_helper::create_default_open_instance($course, 'testquestionbank');
        $fs = get_file_storage();
        $resource = $this->getDataGenerator()->create_module('resource', ['course' => $course->id]);
        $owner = $this->getDataGenerator()->create_user();
        $filerecord = ['component' => 'mod_resource', 'filearea' => 'content',
            'contextid' => context_module::instance($resource->cmid)->id, 'userid' => $owner->id,
            'itemid' => 0, 'filepath' => '/', 'filename' => 'testpdf.pdf'];
        $file = $fs->create_file_from_string(
            $filerecord,
            file_get_contents($CFG->dirroot . '/question/bank/questiongen/tests/fixtures/testpdf.pdf')
        );

        $extractor = $this->createMock(\local_ai_content\document_extractor::class);
        $extractor->expects($this->once())
            ->method('extract_text_from_file')
            ->with($file, $qbankcminfo->context->id, $USER->id, 'qbank_questiongen')
            ->willReturn('content from file');
        \core\di::set(\local_ai_content\document_extractor::class, $extractor);

        $questiongenerator = new question_generator($qbankcminfo->context->id);
        $this->assertEquals('content from file', $questiongenerator->extract_content_from_pdf_or_image($file));
    }

    /**
     * Tests that extraction failures are wrapped as questiongen_exception.
     *
     * @covers \qbank_questiongen\local\question_generator::extract_content_from_cm
     */
    public function test_extract_content_from_cm_wraps_extractor_exception(): void {
        global $CFG;

        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $qbankcminfo = question_bank_helper::create_default_open_instance($course, 'testquestionbank');

        $resourcegenerator = $this->getDataGenerator()->get_plugin_generator('mod_resource');
        $resource = $resourcegenerator->create_instance(['course' => $course->id, 'name' => 'testresource']);
        $context = context_module::instance($resource->cmid);
        $fs = get_file_storage();
        foreach ($fs->get_area_files($context->id, 'mod_resource', 'content') as $file) {
            $file->delete();
        }

        $filerecord = [
            'component' => 'mod_resource',
            'filearea' => 'content',
            'contextid' => $context->id,
            'itemid' => 0,
            'filepath' => '/',
            'filename' => 'testpdf.pdf',
        ];
        $fs->create_file_from_string(
            $filerecord,
            file_get_contents($CFG->dirroot . '/question/bank/questiongen/tests/fixtures/testpdf.pdf')
        );

        $extractor = $this->createMock(\local_ai_content\document_extractor::class);
        $extractor->method('is_file_supported')->willReturn(true);
        $extractor->method('extract_text_from_file')
            ->willThrowException(new \moodle_exception('error_pdfrenderingunavailable', 'local_ai_content'));
        \core\di::set(\local_ai_content\document_extractor::class, $extractor);

        $questiongenerator = new question_generator($qbankcminfo->context->id);
        $this->expectException(questiongen_exception::class);
        $this->expectExceptionMessage(get_string('error_pdfrenderingunavailable', 'local_ai_content'));
        $questiongenerator->extract_content_from_cm(get_fast_modinfo($course)->get_cm($resource->cmid));
    }
}
