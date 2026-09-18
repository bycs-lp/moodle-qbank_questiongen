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
 * German strings for automatic preset selection.
 *
 * @package qbank_questiongen
 * @copyright 2026 ISB Bayern
 * @author Dr. Peter Mayer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['errorinvalidpresetxml'] = 'Geben Sie gültiges Moodle-XML mit genau einer unterstützten Frage ein (maximal 256 KiB). Kategorieanweisungen und Beschreibungen sind nicht erlaubt.';
$string['errorpresetentry'] = 'Vorlage {$a} ist ungültig oder ihr Fragetyp ist nicht verfügbar. Es wurden keine Vorlagen importiert.';
$string['errorpresetfile'] = 'Laden Sie ein Questiongen-JSON-Paket hoch (Formatversion 1, 1 bis 100 Vorlagen, maximal 8 MiB).';
$string['errorselectioncatalogue'] = 'Keine gültige Vorlage passt zur Auswahl oder der Vorlagenkatalog überschreitet 1 MiB. Prüfen Sie die ausgewählten Fragetypen und die Vorlagenkonfiguration.';
$string['errorselectionprovider'] = 'Die KI-Anfrage konnte nicht abgeschlossen werden. Prüfen Sie die Verfügbarkeit und das verbleibende Kontingent.';
$string['errorselectionresponse'] = 'Die KI konnte nach zwei Versuchen keine gültige Vorlage auswählen.';
$string['errortexttoolong'] = 'Geben Sie höchstens {$a} Zeichen ein.';
$string['exportpreset'] = 'Vorlage exportieren';
$string['exportpresets'] = 'Alle Vorlagen exportieren';
$string['importpresets'] = 'Vorlagen importieren';
$string['pedagogy'] = 'Pädagogische Vorgaben (optional)';
$string['pedagogy_help'] = 'Beschreiben Sie Lernziele, Zielgruppe, Anforderungsniveau oder Unterrichtsszenarien. Diese Vorgaben beeinflussen die Vorlagenauswahl und die Fragenerzeugung. Maximal 4000 Zeichen.';
$string['presetfile'] = 'Vorlagendatei';
$string['presetfile_help'] = 'Laden Sie ein exportiertes JSON-Paket hoch. Alle Einträge werden vor dem Import geprüft. Bestehende Vorlagen werden nicht überschrieben, identische Einträge übersprungen. Abweichender Inhalt mit gleichem Namen erzeugt eine weitere Vorlage. Dateien enthalten Prompts, keine API-Zugangsdaten.';
$string['presetsimported'] = '{$a->imported} Vorlagen importiert; {$a->skipped} identische Vorlagen übersprungen.';
$string['selectingpreset'] = 'Passende Vorlage für Frage {$a} wird ausgewählt.';
$string['selectionalltypes'] = 'Alle verfügbaren Fragetypen';
$string['selectionautomatic'] = 'KI-Auswahl';
$string['selectiondescription'] = 'Pädagogische Eignung';
$string['selectiondescription_help'] = 'Beschreiben Sie geeignete Lernziele und Einsatzsituationen sowie Einschränkungen dieser Vorlage. Ohne Eingabe werden die vorhandenen Vorlagenanweisungen verwendet. Maximal 2000 Zeichen.';
$string['selectionfixed'] = 'Feste Vorlage';
$string['selectionmode'] = 'Vorlagenauswahl';
$string['selectiontypes'] = 'Fragetypen einschränken (optional)';
$string['selectionunavailable'] = 'Nicht für die automatische Auswahl verfügbar: XML-Beispiel prüfen.';
