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

use cm_info;
use assignfeedback_editpdf\pdf;
use local_ai_manager\ai_manager_utils;
use local_ai_manager\manager;
use question_bank;
use stdClass;

/**
 * Question generator class.
 *
 * @package    qbank_questiongen
 * @copyright  2025 ISB Bayern
 * @author     Philipp Memmel
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class question_generator {

    private readonly \core\clock $clock;

    const ITT_MIMETYPES = ['application/pdf', 'image/png', 'image/jpeg', 'image/jpg'];

    public function __construct(
        /** @var int The id of the context the question_generator is called from. */
            private readonly int $contextid
    ) {
        $this->clock = \core\di::get(\core\clock::class);
    }

    /**
     * Generate a question by using an external LLM.
     *
     * @param stdClass $dataobject of the stored processing data from questiongen DB table extended with example data.
     * @return stdClass|string object containing information about the generated question or string containing an error message
     *  in case of an error occurred and no question could be generated
     */
    public function generate_question(stdClass $dataobject, bool $sendexistingquestionsascontext): stdClass|string {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/question/engine/bank.php');

        // Build primer.
        $primer = $dataobject->primer;
        $primer .= "Write a question.";

        $key = get_config('qbank_questiongen', 'key');

        // Remove new lines and carriage returns.
        $story = str_replace("\n", " ", $dataobject->story);
        $story = str_replace("\r", " ", $story);
        $instructions = str_replace("\n", " ", $dataobject->instructions);
        $instructions = str_replace("\r", " ", $instructions);
        $example = str_replace("\n", " ", $dataobject->example);
        $example = str_replace("\r", " ", $example);

        $messages = [
                [
                        "role" => "system",
                        "content" => "' . $primer . '",
                ],
                [
                        "role" => "system",
                        "name" => "example_user",
                        "content" => "' . $instructions . '",
                ],
                [
                        "role" => "system",
                        "name" => "example_assistant",
                        "content" => "' . $example . '",
                ],
        ];

        $provider = get_config('qbank_questiongen', 'provider'); // OpenAI (default) or Azure

        $headers = [
                'Content-Type' => 'application/json',
        ];
        if ($provider === 'local_ai_manager') {

            $messages = [
                    [
                            'sender' => 'system',
                            'message' => '"' . $primer . '"',
                    ],
                    [
                            'sender' => 'system',
                            'message' => '"' . $instructions . '"',
                    ],
                    [
                            'sender' => 'system',
                            'message' => '"' . $example . '"',
                    ],
            ];

            if ($sendexistingquestionsascontext) {
                /*$sql = "SELECT q.id as questionid, q.name as questionname, q.questiontext as questiontext, MAX(qv.version) AS maxversion FROM {question_versions} qv
                    JOIN {question} q ON qv.questionid = q.id
                                            JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
                                            WHERE qv.status = 'ready' AND version = maxversion AND qbe.questioncategoryid = :questioncategoryid GROUP BY qv.questionid";
                $params = ['questioncategoryid' => $dataobject->categoryid];*/
                $questionidsincategory = question_bank::get_finder()->get_questions_from_categories([$dataobject->category], null);
                if (!empty($questionidsincategory)) {
                    [$insql, $inparams] = $DB->get_in_or_equal($questionidsincategory);
                    $rs = $DB->get_recordset_select('question', "id $insql", $inparams);
                    $questiontextsinqbankcat = [];
                    foreach ($rs as $record) {
                        $questiontextsinqbankcat[] = [
                                'title' => $record->name,
                                'question_text' => strip_tags($record->questiontext),
                        ];
                    }
                    $rs->close();

                    $messages[] =
                            [
                                    'sender' => 'system',
                                    'message' => 'The question that will be generated by you has to be different from all of the following questions in this JSON string: "' .
                                            $this->escape_json(json_encode($questiontextsinqbankcat)) . '"',
                            ];
                }
            }
            $topicinstruction = $sendexistingquestionsascontext ?
                    'Create a question based on the following json encoded content, only use this content for the question: "' .
                    $this->escape_json($story) . '"'
                    : $story;
            $messages[] =
                    [
                            'sender' => 'user',
                            'message' => $topicinstruction,
                    ];

            $manager = new \local_ai_manager\manager('questiongeneration');
            $lastmessage = array_pop($messages);
            $result = $manager->perform_request($lastmessage['message'], 'qbank_questiongen', $this->contextid,
                    ['conversationcontext' => $messages]);
            if ($result->get_code() === 200) {
                $generatedquestiontext = $result->get_content();
                mtrace('Question generation successful. The external LLM returned: ');
                mtrace($result->get_content());
            } else {
                mtrace('Question generation failed. The external LLM returned code ' . $result->get_code() . ':');
                mtrace($result->get_errormessage());
                debugging($result->get_debuginfo(), DEBUG_DEVELOPER);
                // Return the error message.
                return $result->get_errormessage();
            }
        } else {
            if ($provider === 'Azure') {
                // If the provider is Azure, use the Azure API endpoint and Azure-specific HTTP header
                $url = get_config('qbank_questiongen', 'azure_api_endpoint'); // Use the Azure API endpoint from settings
                $headers['api-key'] = $key;
            } else {
                // If the provider is not Azure, use the OpenAI API URL and OpenAI style HTTP header
                $url = 'https://api.openai.com/v1/chat/completions';
                $headers['Authorization'] = 'Bearer ' . $key;
            }

            $model = get_config('qbank_questiongen', 'model');
            $data = json_encode([
                    'model' => $model,
                    'messages' => $messages,
            ]);

            $httpclient = new \core\http_client();
            $options['headers'] = $headers;
            $options['body'] = $data;

            $result = json_decode($httpclient->post($url, $options)->getBody()->getContents());

            $generatedquestiontext = $result->choices[0]->message->content;
        }
        $question = new stdClass();
        $question->text = $generatedquestiontext;
        $question->prompt = $story;

        return $question;
    }

    /**
     * Escape json.
     *
     * @param string $value json to escape
     * @return string result escaped json
     */
    function escape_json($value) {
        $escapers = ["\\", "/", "\"", "\n", "\r", "\t", "\x08", "\x0c"];
        $replacements = ["\\\\", "\\/", "\\\"", "\\n", "\\r", "\\t", "\\f", "\\b"];
        $result = str_replace($escapers, $replacements, $value);
        return $result;
    }

    public function create_story_from_cms(array $courseactivities): string {
        global $CFG;
        require_once($CFG->dirroot . '/question/editlib.php');

        [, $firstcm] = get_module_from_cmid(reset($courseactivities));
        $modinfo = get_fast_modinfo($firstcm->course);
        $story = '';
        $cms = array_filter($modinfo->get_cms(), fn($cm) => in_array($cm->id, $courseactivities));

        foreach ($cms as $cm) {
            if (!in_array($cm->id, $courseactivities)) {
                continue;
            }
            if (!$this->is_cm_supported($cm)) {
                debugging('Course module with id ' . $cm->id . ' is currently not supported');
                continue;
            }
            $story .= $this->extract_content_from_cm($cm);
        }
        return $story;
    }

    public static function is_cm_supported(cm_info $cm): bool {
        if (in_array($cm->modname, ['page, label'])) {
            return true;
        }
        if ($cm->modname === 'resource') {
            $context = \context_module::instance($cm->id);
            $fs = get_file_storage();
            $files = $fs->get_area_files($context->id, 'mod_resource', 'content', 0, 'sortorder DESC, id ASC', false);
            $file = reset($files);
            return in_array($file->get_mimetype(), self::get_supported_mimetypes());
        }
        return false;
    }

    public static function get_supported_mimetypes(): array {
        return ['text/plain', 'text/html', 'text/csv', 'application/pdf', 'image/png', 'image/jpeg', 'image/jpg'];
    }

    public function extract_content_from_cm(cm_info $cm): string {
        // TODO Eventually also respect course module descriptions and title?
        $content = '';
        $instance = $cm->get_instance_record();
        switch ($cm->modname) {
            case 'page':
                $content = $instance->content;
                break;
            case 'label':
                $content = $instance->intro;
                break;
            case 'resource':
                $context = \context_module::instance($cm->id);
                $fs = get_file_storage();
                $files = $fs->get_area_files($context->id, 'mod_resource', 'content', 0, 'sortorder DESC, id ASC', false);
                $file = reset($files);
                if (!empty($file) && in_array($file->get_mimetype(), self::get_supported_mimetypes())) {
                    if (in_array($file->get_mimetype(), self::ITT_MIMETYPES)) {
                        $content = $this->extract_content_from_pdf_or_image($file);
                    } else {
                        $content = $file->get_content();
                    }
                }
                break;
        }
        return strip_tags($content);
    }

    public function extract_content_from_pdf_or_image(\stored_file $file): string {
        global $DB;
        if ($record = $DB->get_record('qbank_questiongen_resource_cache', ['contenthash' => $file->get_contenthash()])) {
            $record->timelastaccessed = $this->clock->time();
            $DB->update_record('qbank_questiongen_resource_cache', $record);
            return $record->extractedcontent;
        }
        $imageprompt =
                'Return the text that is written on the image/document. Do not wrap any explanatory text around. '
                . 'Return only the bare content.';
        $purposeoptions = ai_manager_utils::get_available_purpose_options('itt');
        // For example 'application/pdf' is not supported by some AI systems.
        if (in_array($file->get_mimetype(), $purposeoptions['allowedmimetypes'])) {
            $requestoptions = [
                    'image' => 'data:' . $file->get_mimetype() . ';base64,' . base64_encode($file->get_content()),
            ];
            $aimanager = new manager('itt');
            $result = $aimanager->perform_request($imageprompt, 'qbank_questiongen', $this->contextid,
                    $requestoptions);
            if ($result->get_code() !== 200) {
                $errormessage = $result->get_errormessage();
                if (debugging()) {
                    $errormessage .= ' Debugging info: ' . $result->get_debuginfo();
                }
                throw new \moodle_exception('Could not extract from PDF. Error: ' . $errormessage);
            }
            $this->store_to_record_cache($file, $result->get_content());;
            return $result->get_content();
        }

        if ($file->get_mimetype() !== 'application/pdf') {
            // Not perfect to throw an exception here. We probably need some image format conversion here.
            throw new \moodle_exception('Unsupported file type: ' . $file->get_mimetype());
        }

        // Depending on what models/AI tools are configured, some of them do not support sending PDF files directly. So we have to
        // convert each PDF page to an image and extract the text from the images one by one.
        $content = '';

        $tmpdir = \make_request_directory();
        $fileextension = explode('/', $file->get_mimetype())[1];
        $tmpfilename = 'qbank_questiongen_tmp_' . uniqid() . '.' . $fileextension;
        file_put_contents($tmpdir . '/' . $tmpfilename, $file->get_content());
        $pdf = new pdf();
        $pdf->set_image_folder($tmpdir);
        $pdf->set_pdf($tmpdir . '/' . $tmpfilename);
        $images = $pdf->get_images();
        foreach ($images as $image) {
            $imagecontent = file_get_contents($tmpdir . '/' . $image);
            $aimanager = new manager('itt');
            $requestoptions = [
                    'image' => 'data:' . mime_content_type($tmpdir . '/' . $image) . ';base64,' .
                            base64_encode($imagecontent)
            ];
            $result = $aimanager->perform_request($imageprompt, 'qbank_questiongen', $this->contextid,
                    $requestoptions);
            if ($result->get_code() !== 200) {
                $errormessage = $result->get_errormessage();
                if (debugging()) {
                    $errormessage .= ' Debugging info: ' . $result->get_debuginfo();
                }
                throw new \moodle_exception('Could not extract from PDF. Error: ' . $errormessage);
            }
            $content .= $result->get_content();
        }
        $this->store_to_record_cache($file, $content);
        return $content;
    }

    public function store_to_record_cache(\stored_file $file, string $extractedcontent): void {
        global $DB;
        $time = $this->clock->time();
        if ($currentrecord = $DB->get_record('qbank_questiongen_resource_cache', ['contenthash' => $file->get_contenthash()])) {
            if ($currentrecord->extractedcontent !== $extractedcontent) {
                $currentrecord->extractedcontent = $extractedcontent;
            }
            $currentrecord->timemodified = $time;
            $currentrecord->timelastaccessed = $time;
            $DB->update_record('qbank_questiongen_resource_cache', $currentrecord);
            return;
        }

        $record = new stdClass();
        $record->contenthash = $file->get_contenthash();
        $record->extractedcontent = $extractedcontent;
        $record->timemodified = $time;
        $record->timecreated = $time;
        $record->timelastaccessed = $time;
        $DB->insert_record('qbank_questiongen_resource_cache', $record);
    }
}
