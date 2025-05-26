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
 * Plugin administration pages are defined here.
 *
 * @package     qbank_questiongen
 * @category    admin
 * @copyright   2023 Ruthy Salomon <ruthy.salomon@gmail.com> , Yedidia Klein <yedidia@openapp.co.il>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage('qbank_questiongen_settings', new lang_string('pluginname', 'qbank_questiongen'));

    // Language model provider.
    $provideroptions = [
        'OpenAI' => 'OpenAI',
        'Azure' => 'Azure',
        'local_ai_manager' => 'Plugin "AI Manager"',
    ];
    $settings->add(new admin_setting_configselect(
        'qbank_questiongen/provider',
        get_string('provider', 'qbank_questiongen'),
        get_string('providerdesc', 'qbank_questiongen'),
        'OpenAI',
        $provideroptions,
    ));

    // Azure endpoint.

    $settings->add(new admin_setting_configtext(
        'qbank_questiongen/azure_api_endpoint',
        get_string('azureapiendpoint', 'qbank_questiongen'),
        get_string('azureapiendpointdesc', 'qbank_questiongen'),
        '',
        PARAM_URL
    ));


    // OpenAI key.
    $settings->add(new admin_setting_configpasswordunmask(
        'qbank_questiongen/key',
        get_string('openaikey', 'qbank_questiongen'),
        get_string('openaikeydesc', 'qbank_questiongen'),
        '',
        PARAM_TEXT,
        50
    ));

    // Model.
    $options = [
        'gpt-3.5-turbo' => 'gpt-3.5-turbo',
        'gpt-4' => 'gpt-4',
        'gpt-4o' => 'gpt-4o',
    ];
    $settings->add(new admin_setting_configselect(
        'qbank_questiongen/model',
        get_string('model', 'qbank_questiongen'),
        get_string('openaikeydesc', 'qbank_questiongen'),
        'gpt-3.5-turbo',
        $options,
    ));

    // Number of tries.
    $settings->add(new admin_setting_configtext(
        'qbank_questiongen/numoftries',
        get_string('numoftriesset', 'qbank_questiongen'),
        get_string('numoftriesdesc', 'qbank_questiongen'),
        10,
        PARAM_INT,
        10
    ));

    // Presets
    $settings->add(new admin_setting_heading(
        'qbank_questiongen/presets',
        get_string('presets', 'qbank_questiongen'),
        get_string('presetsdesc', 'qbank_questiongen') .
            get_string('shareyourprompts', 'qbank_questiongen'),
    ));

    for ($i = 1; $i <= 10; $i++) {

        // Preset header.
        $settings->add(new admin_setting_heading(
            'qbank_questiongen/preset' . $i,
            get_string('preset', 'qbank_questiongen') . " $i",
            null
        ));

        // Preset name.
        $settings->add(new admin_setting_configtext(
            'qbank_questiongen/presetname' . $i,
            get_string('presetname', 'qbank_questiongen'),
            get_string('presetnamedesc', 'qbank_questiongen'),
            get_string('presetnamedefault' . $i, 'qbank_questiongen'),
        ));

        // Preset primer.
        $settings->add(new admin_setting_configtextarea(
            'qbank_questiongen/presettprimer' . $i,
            get_string('presetprimer', 'qbank_questiongen'),
            get_string('primer_help', 'qbank_questiongen'),
            get_string('presetprimerdefault' . $i, 'qbank_questiongen'),
            PARAM_RAW,
            4000
        ));

        // Preset instructions.
        $settings->add(new admin_setting_configtextarea(
            'qbank_questiongen/presetinstructions' . $i,
            get_string('presetinstructions', 'qbank_questiongen'),
            get_string('instructions_help', 'qbank_questiongen'),
            get_string('presetinstructionsdefault' . $i, 'qbank_questiongen'),
            PARAM_RAW,
            4000
        ));

        // Preset format.
        $formatoptions = [
            'gift' => 'GIFT format',
            'moodlexml' => 'Moodle XML format',
        ];
        $settings->add( new admin_setting_configselect(
            'qbank_questiongen/presetformat' . $i,
            get_string('presetformat', 'qbank_questiongen'),
            get_string('presetformatdesc', 'qbank_questiongen'),
            'gift',
            $formatoptions
        ));

        // Preset example.
        $settings->add(new admin_setting_configtextarea(
            'qbank_questiongen/presetexample' . $i,
            get_string('presetexample', 'qbank_questiongen'),
            get_string('example_help', 'qbank_questiongen'),
            get_string('presetexampledefault' . $i, 'qbank_questiongen'),
            PARAM_RAW,
            4000
        ));
    }
}
