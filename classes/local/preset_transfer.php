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
        $json = json_encode(
            ['format' => 'qbank_questiongen_presets', 'version' => 1, 'presets' => $portable],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ) . "\n";
        if (count($portable) > 100 || strlen($json) > self::MAX_BYTES) {
            throw new questiongen_exception('errorpresetexportsize', 'qbank_questiongen');
        }
        return $json;
    }

    /**
     * Validate editable fields and derive the question type once at the write boundary.
     *
     * @param \stdClass $preset Preset whose qtype is populated on success
     * @return array Field errors shared by the form and JSON importer
     */
    public static function validate_preset(\stdClass $preset): array {
        $errors = [];
        $preset->selectiondescription ??= '';
        foreach (self::FIELDS as $field) {
            $value = $preset->$field ?? null;
            if (!is_string($value) || ($field !== 'selectiondescription' && trim($value) === '')) {
                $errors[$field] = get_string('errorformfieldempty', 'qbank_questiongen');
                continue;
            }
            $limit = ['name' => 255, 'selectiondescription' => 2000][$field] ?? 262144;
            $length = in_array($field, ['name', 'selectiondescription']) ? \core_text::strlen($value) : strlen($value);
            if ($length > $limit) {
                $errors[$field] = get_string('errorpresetfieldlength', 'qbank_questiongen', $limit);
            }
        }
        if (!isset($errors['example'])) {
            try {
                $preset->qtype = xml_importer::validate_question($preset->example)->qtype;
            } catch (\invalid_parameter_exception $exception) {
                $errors['example'] = get_string('errorinvalidpresetxml', 'qbank_questiongen');
            }
        }
        return $errors;
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
            if (!($preset instanceof \stdClass)) {
                throw new questiongen_exception('errorpresetentry', 'qbank_questiongen', '', $index + 1);
            }
            $record = (object) array_intersect_key(get_object_vars($preset), array_flip(self::FIELDS));
            if (self::validate_preset($record)) {
                throw new questiongen_exception('errorpresetentry', 'qbank_questiongen', '', $index + 1);
            }
            $records[] = $record;
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
            $record->timecreated = \core\di::get(\core\clock::class)->time();
            $record->timemodified = $record->timecreated;
            $DB->insert_record('qbank_questiongen_preset', $record);
            $fingerprints[$fingerprint] = true;
            $result->imported++;
        }
        $transaction->allow_commit();
        return $result;
    }
}
