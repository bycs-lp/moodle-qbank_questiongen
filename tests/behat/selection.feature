@qbank @qbank_questiongen @javascript
Feature: Optional pedagogical guidance and question type selection
  In order to generate questions suited to my teaching
  As a teacher
  I can optionally guide automatic preset selection

  Background:
    Given the following config values are set as admin:
      | provider | none | qbank_questiongen |
    And the following "courses" exist:
      | fullname | shortname | category |
      | Course 1 | C1        | 0        |
    And the following "activities" exist:
      | activity | name      | course | section |
      | qbank    | Test bank | C1     | 0       |
    And I log in as "admin"
    And I open the AI question form for "Test bank"

  Scenario: Automatic controls are optional and fixed presets remain available
    Then the field "Preset selection" matches value "Fixed preset"
    And I should not see "Pedagogical requirements (optional)"
    When I set the field "Preset selection" to "AI selection"
    Then I should see "All available question types"
    And I should see "Pedagogical requirements (optional)"
    And I should not see "Edit the preset before sending it to the AI"
    When I press "Generate questions"
    Then I should see "You must provide a topic to be able to generate questions."
    And I should not see "No valid presets match this selection"
    When I set the field "Pedagogical requirements (optional)" to "Compare concepts for age 14"
    And I set the field "Preset selection" to "Fixed preset"
    Then I should see "Edit the preset before sending it to the AI"
    And I should not see "Pedagogical requirements (optional)"

  @_file_upload
  Scenario: Import a portable preset without overwriting duplicates
    When I open the question preset management page
    Then I should see "Export all presets"
    When I upload "question/bank/questiongen/tests/fixtures/preset-import.json" file to "Preset file" filemanager
    And I press "id_importpresets"
    Then I should see "1 presets imported; 0 identical presets skipped."
    And I should see "Imported regression preset"
    When I upload "question/bank/questiongen/tests/fixtures/preset-import.json" file to "Preset file" filemanager
    And I press "id_importpresets"
    Then I should see "0 presets imported; 1 identical presets skipped."

  Scenario: Ordinary users cannot manage presets
    Given the following "users" exist:
      | username | firstname | lastname |
      | teacher1 | Teacher   | One      |
    And I log out
    And I log in as "teacher1"
    Then question preset administration access is denied
