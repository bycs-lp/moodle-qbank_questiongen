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
 * Plugin strings are defined here.
 *
 * @package     qbank_questiongen
 * @category    string
 * @copyright   2023 Ruthy Salomon <ruthy.salomon@gmail.com> , Yedidia Klein <yedidia@openapp.co.il>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['activitylist'] = 'List of activities';
$string['addidentifier'] = 'Add a preconfigured prefix ("{$a}") to the question name';
$string['aiidentifiersetting'] = 'Question name prefix';
$string['aiidentifiersettingdesc'] = 'Specify the prefix to be added to the question name when importing to the question bank. Leave it empty to disable the adding of a prefix. The prefix will be just put straight in front of the question name. If you want an additional delimiter (dash, colon, spaces etc.) make sure you add it to the prefix.';
$string['aiidentifiertagsetting'] = 'Question tag';
$string['aiidentifiertagsettingdesc'] = 'Specify the name of the tag that should be added to the question when importing to the question bank. Leave it empty to disable the adding of a tag.';
$string['aiquestions'] = 'AI Questions';
$string['azureapiendpoint'] = 'Azure API Endpoint';
$string['azureapiendpointdesc'] = 'Enter the Azure API endpoint URL here';
$string['backtocourse'] = 'Back to course';
$string['category'] = 'Question category';
$string['category_help'] = 'If the category selection is empty, open the question bank for this course once.';
$string['createdquestionssuccess'] = 'Created questions successfully';
$string['createdquestionsuccess'] = 'Created question successfully';
$string['createdquestionwithid'] = 'Created question with id ';
$string['cronoverdue'] = 'The cron task seems not to run,
questions generation rely on AdHoc Tasks that are created by the cron task, please check your cron settings.
See <a href="https://docs.moodle.org/en/Cron#Setting_up_cron_on_your_system">
https://docs.moodle.org/en/Cron#Setting_up_cron_on_your_system
</a> for more information.';
$string['editpreset'] = 'Edit the preset before sending it to the AI';
$string['errornotcreated'] = 'Error: questions were not created';
$string['example'] = 'Example';
$string['example_help'] = 'The example shows the AI an example output, to clarify the formatting.';
$string['generate'] = 'Generate questions';
$string['generatemore'] = 'Generate more questions';
$string['generating'] = 'Generating your questions... (You can safely leave this page, and check later on the question bank)';
$string['generationfailed'] = 'The question generation failed after {$a} tries';
$string['generationtries'] = 'Number of tries sent to OpenAI: <b>{$a}</b>';
$string['gift_format'] = 'GIFT format';
$string['gotoquestionbank'] = 'Go to question bank';
$string['instructions'] = 'Instructions';
$string['instructions_help'] = 'The instructions tell the AI what to do.';
$string['model'] = 'Model';
$string['model_desc'] = 'Language model to use. <a href="https://platform.openai.com/docs/models/">More info</a>.';
$string['numofquestions'] = 'Number of questions to generate';
$string['numoftries'] = '<b>{$a}</b> tries';
$string['numoftriesdesc'] = 'Number of retries that should be performed if generating and import of a question fails';
$string['numoftriesset'] = 'Number of retries';
$string['openaikey'] = 'OpenAI or Azure API key';
$string['openaikeydesc'] = 'You can get an OpenAI API key from <a href="https://platform.openai.com/account/api-keys">https://platform.openai.com/account/api-keys</a><br>
Select the "+ Create New Secret Key" button and copy the key to this field.<br>
Note that you need to have an OpenAI account that includes billing settings to get an API key.';
$string['outof'] = 'out of';
$string['pluginname'] = 'AI text to questions generator';
$string['pluginname_desc'] = 'This plugin allows you to automatically generate questions from a text using a language AI (eg chatGPT).';
$string['pluginname_help'] = 'Use this plugin from the course administration menu or the question bank.';
$string['preset'] = 'Preset';
$string['presetformat'] = 'Preset format';
$string['presetformatdesc'] = 'Select the format of the example for the LLM to return';
$string['presetinstructions'] = 'Preset instructions';
$string['presetname'] = 'Preset name';
$string['presetnamedesc'] = 'Name that will be shown to the user';
$string['presetprimer'] = 'Preset primer';
$string['presetprimerdefault1'] = "You are a helpful teacher's assistant that creates multiple choice questions based on the topics given by the user.";
$string['presets'] = 'Presets';
$string['preview'] = 'Preview question in new tab';
$string['primer'] = 'Primer';
$string['primer_help'] = 'The primer is the first information to be sent to the AI, priming it for its task.';
$string['privacy:metadata'] = 'AI text to questions generator does not store any personal data.';
$string['provider'] = 'GPT provider';
$string['providerdesc'] = 'Select if you are using Azure of OpenAI';
$string['sendexistingquestionsascontext'] = 'Send existing questions as context';
$string['sendexistingquestionsascontext_help'] = 'Enable to make the tool send all question titles and question texts from all the questions in the current category to the external AI system to enable the AI system to generate questions that are different from the already existing ones.';
$string['shareyourprompts'] = 'You can find more prompt ideas or share yours at <a target="_blank" href="https://docs.moodle.org/402/en/AI_Text_to_questions_generator">the Moodle Docs page for this plugin</a>.';
$string['story'] = 'Topic';
$string['story_help'] = 'The topic of your questions. You can also copy/paste whole articles, eg from wikipedia.';
$string['tasksuccess'] = 'The question generation task was successfully created';
$string['use_coursecontents'] = 'Use course contents as topic instead';
$string['xml_format'] = 'Moodle XML format';
