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
    /**
     * Generate a question by using an external LLM.
     *
     * @param stdClass $dataobject of the stored processing data from questiongen DB table extended with example data.
     * @return stdClass object containing information about the generated question
     */
    function generate_question($dataobject): stdClass {
        global $USER;
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
                [
                        "role" => "user",
                        "content" => 'Now, create a question based on this topic: "' . $this->escape_json($story) . '"',
                ]
        ];

        $generatedquestiontext = '';

        $model = get_config('qbank_questiongen', 'model');
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
                    [
                            'sender' => 'user',
                            'message' => 'Now, create a question for me based on this topic: "' . $this->escape_json($story) . '"',
                    ]
            ];

            $manager = new \local_ai_manager\manager('questiongeneration');
            $lastmessage = array_pop($messages);
            $result = $manager->perform_request($lastmessage['message'], 'qbank_questiongen', SYSCONTEXTID,
                    ['conversationcontext' => $messages]);
            if ($result->get_code() === 200) {
                $generatedquestiontext = $result->get_content();
                mtrace('Question generation successful. The external LLM returned: ');
                mtrace($result->get_content());
            } else {
                mtrace('Question generation failed. The external LLM returned code ' . $result->get_code() . ':');
                mtrace($result->get_errormessage());
                debugging($result->get_debuginfo(), DEBUG_DEVELOPER);
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

    public static function create_story_from_cms(array $courseactivities): string {
        [, $firstcm] = get_module_from_cmid(reset($courseactivities));
        $modinfo = get_fast_modinfo($firstcm->course);
        $story = '';
        $cms = array_filter($modinfo->get_cms(), fn($cm) => in_array($cm->id, $courseactivities));

        foreach ($cms as $cm) {
            if (!in_array($cm->id, $courseactivities)) {
                continue;
            }
            if (!in_array($cm->modname, self::get_supported_modtypes())) {
                debugging('Course module with id ' . $cm->id . ' is currently not supported');
                continue;
            }
            $story .= self::extract_content_from_cm($cm);
        }
        return $story;
    }

    public static function get_supported_modtypes(): array {
        return ['label', 'page'];
    }

    public static function extract_content_from_cm(cm_info $cm): string {
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
        }
        return strip_tags($content);
    }
}
