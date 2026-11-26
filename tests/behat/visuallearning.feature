@mod @mod_visuallearning
Feature: Build and view an AI Visual Learning
  In order to teach one concept per page
  As a teacher
  I need to build a visual learning lesson that learners can open

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher1 | Terry     | Teacher  | teacher1@example.com |
      | student1 | Sam       | Student  | student1@example.com |
    And the following "courses" exist:
      | fullname | shortname |
      | Course 1 | C1        |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | student1 | C1     | student        |
    And the following "activities" exist:
      | activity    | course | name                    | sourcecontent                   |
      | visuallearning | C1     | How to promote teamwork | Teams work better when bonded. |

  Scenario: Building a lesson needs the site to be activated with LMS Labs
    Given I am on the "How to promote teamwork" "visuallearning activity" page logged in as teacher1
    Then I should see "AI Visual Learning is not activated on this site yet"
    And I should see "Open the AI Visual Learning settings"
    And I should not see "Step 1 — Create your lesson"

  Scenario: On an activated site without LMS Labs credentials, a teacher sees the steps but nothing paid is offered
    Given the following config values are set as admin:
      | unlockstate | {"status":"unlocked"} | mod_visuallearning |
    And I am on the "How to promote teamwork" "visuallearning activity" page logged in as teacher1
    Then I should see "Step 1 — Create your lesson"
    And I should see "Pictures & voice"
    And I should see "this site's LMS Labs Site ID and API key are not set"
    And "Create my lesson" "button" should not exist

  Scenario: A learner is told the lesson is not ready
    Given I am on the "How to promote teamwork" "visuallearning activity" page logged in as student1
    Then I should see "This activity is not ready yet."

  Scenario: A teacher sees the check-question, picture and sound settings
    Given I am on the "How to promote teamwork" "visuallearning activity editing" page logged in as teacher1
    Then I should see "Question types"
    And I should see "Pictures for picture questions"
    And the field "qt_truefalse" matches value "1"
    And the field "qt_pick" matches value "1"
    And the field "qt_match" matches value "1"
    And the field "qt_sort" matches value "1"
    And the field "sounds" matches value "1"
