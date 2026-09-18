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
 * Portable, validated preset exchange without database identifiers.
 *
 * @package qbank_questiongen
 * @copyright 2026 ISB Bayern
 * @author Dr. Peter Mayer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class preset_transfer {
    /** @var int Maximum upload size in bytes. */
    public const MAX_BYTES = 8388608;

    /** @var string[] Portable preset fields. */
    private const FIELDS = ['name', 'primer', 'instructions', 'example', 'selectiondescription'];

    /**
     * Encode presets without exporting local IDs or derived metadata.
     *
     * @param array $presets Database records
     * @return string JSON document
     */
    public static function encode(array $presets): string {
        $portable = [];
        foreach ($presets as $preset) {
            $record = [];
            foreach (self::FIELDS as $field) {
                $record[$field] = (string) ($preset->$field ?? '');
            }
            $portable[] = $record;
        }
        return json_encode(
            ['format' => 'qbank_questiongen_presets', 'version' => 1, 'presets' => $portable],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ) . "\n";
    }

    /**
     * Validate a complete document before any database writes.
     *
     * @param string $json Uploaded JSON
     * @return array Validated records with freshly derived question types
     * @throws questiongen_exception If any preset is invalid
     */
    public static function decode(string $json): array {
        if (strlen($json) > self::MAX_BYTES) {
            throw new questiongen_exception('errorpresetfile', 'qbank_questiongen');
        }
        try {
            $document = json_decode($json, false, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new questiongen_exception('errorpresetfile', 'qbank_questiongen');
        }
        if (
            !($document instanceof \stdClass) || ($document->format ?? '') !== 'qbank_questiongen_presets' ||
            ($document->version ?? null) !== 1 || !is_array($document->presets ?? null) ||
            !$document->presets || count($document->presets) > 100
        ) {
            throw new questiongen_exception('errorpresetfile', 'qbank_questiongen');
        }
        $records = [];
        foreach ($document->presets as $index => $preset) {
            try {
                if (!($preset instanceof \stdClass)) {
                    throw new \invalid_parameter_exception('Expected preset object');
                }
                $record = new \stdClass();
                foreach (self::FIELDS as $field) {
                    $value = $preset->$field ?? ($field === 'selectiondescription' ? '' : null);
                    if (!is_string($value) || ($field !== 'selectiondescription' && trim($value) === '')) {
                        throw new \invalid_parameter_exception('Invalid preset field');
                    }
                    $record->$field = $value;
                }
                if (
                    \core_text::strlen($record->name) > 255 ||
                    \core_text::strlen($record->selectiondescription) > 2000 ||
                    strlen($record->primer) > 262144 || strlen($record->instructions) > 262144
                ) {
                    throw new \invalid_parameter_exception('Preset field too long');
                }
                $types = xml_importer::validate_question($record->example);
                $record->qtype = $types->qtype;
                $record->xmltype = $types->xmltype;
                $records[] = $record;
            } catch (\invalid_parameter_exception $exception) {
                throw new questiongen_exception('errorpresetentry', 'qbank_questiongen', '', $index + 1);
            }
        }
        return $records;
    }

    /**
     * Add validated presets, skipping exact duplicates and preserving existing records.
     *
     * @param string $json Uploaded JSON
     * @return \stdClass Counts of imported and skipped entries
     */
    public static function import(string $json): \stdClass {
        global $DB;
        $records = self::decode($json);
        $transaction = $DB->start_delegated_transaction();
        $fingerprints = [];
        foreach ($DB->get_records('qbank_questiongen_preset') as $preset) {
            $fingerprints[hash('sha256', self::encode([$preset]))] = true;
        }
        $result = (object) ['imported' => 0, 'skipped' => 0];
        foreach ($records as $record) {
            $fingerprint = hash('sha256', self::encode([$record]));
            if (isset($fingerprints[$fingerprint])) {
                $result->skipped++;
                continue;
            }
            $record->timecreated = time();
            $record->timemodified = $record->timecreated;
            $DB->insert_record('qbank_questiongen_preset', $record);
            $fingerprints[$fingerprint] = true;
            $result->imported++;
        }
        $transaction->allow_commit();
        return $result;
    }
}
