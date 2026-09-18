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
    /** @var int Arbitrarily chosen size limit of 16 MiB, not a Moodle requirement. */
    public const MAX_BYTES = 16 * 1024 * 1024;

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
        // Export only editable content so database IDs and derived type metadata remain local to each site.
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
        // Keep downloads within the same limits as uploads so an exported bundle can be reimported.
        if (count($portable) > 100 || strlen($json) > self::MAX_BYTES) {
            throw new questiongen_exception('errorpresetexportsize', 'qbank_questiongen');
        }
        return $json;
    }

    /**
     * Apply the shared validation rules for manual preset editing and JSON import.
     *
     * @param \stdClass $preset Editable preset; defaults and derived qtype are applied in place
     * @return array<string, string> Localised errors keyed by field name; empty when all checks pass
     */
    public static function validate_preset(\stdClass $preset): array {
        $errors = [];
        // Only the suitability description is optional; normalise missing or null values on the supplied object.
        $preset->selectiondescription ??= '';
        foreach (self::FIELDS as $field) {
            $value = $preset->$field ?? null;
            // Reject non-string values and blank required fields. trim() checks emptiness without changing the stored text.
            if (!is_string($value) || ($field !== 'selectiondescription' && trim($value) === '')) {
                $errors[$field] = get_string('errorformfieldempty', 'qbank_questiongen');
                continue;
            }
            // Count Unicode characters for name/description; limit each prompt and XML example to 256 KiB in bytes.
            $limit = ['name' => 255, 'selectiondescription' => 2000][$field] ?? 262144;
            $length = in_array($field, ['name', 'selectiondescription']) ? \core_text::strlen($value) : strlen($value);
            if ($length > $limit) {
                $errors[$field] = get_string('errorpresetfieldlength', 'qbank_questiongen', $limit);
            }
        }
        // Parse XML only after its type, required-value and size checks pass; no question is imported here.
        if (!isset($errors['example'])) {
            try {
                // Resolve the installed qtype on the supplied object, even if another field has validation errors.
                $preset->qtype = xml_importer::validate_question($preset->example)->qtype;
            } catch (\invalid_parameter_exception $exception) {
                $errors['example'] = get_string('errorinvalidpresetxml', 'qbank_questiongen');
            }
        }
        // Callers must require an empty error array before saving; this method neither saves nor sanitises field contents.
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
        // Bound the input before allocating the decoded object tree; nested JSON is limited separately below.
        if (strlen($json) > self::MAX_BYTES) {
            throw new questiongen_exception('errorpresetfile', 'qbank_questiongen');
        }
        try {
            $document = json_decode($json, false, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new questiongen_exception('errorpresetfile', 'qbank_questiongen');
        }
        // Accept only the current bundle contract, not arbitrary JSON or unsupported format versions.
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
            // Ignore imported IDs, timestamps and qtype; shared validation derives fresh metadata from the example.
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
        // Validate the whole bundle before writing; a later database failure rolls back all inserts in this import.
        $records = self::decode($json);
        $transaction = $DB->start_delegated_transaction();
        $fingerprints = [];
        // Compare portable content rather than names: a changed preset with the same name is a separate entry.
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
            // Also recognise duplicates appearing later in this same upload.
            $fingerprints[$fingerprint] = true;
            $result->imported++;
        }
        $transaction->allow_commit();
        return $result;
    }
}
