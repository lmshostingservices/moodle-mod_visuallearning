@mod @mod_visuallearning
Feature: Build and view an AI Visual Learning
  In order to teach one concept per page
  As a teacher
  I need to build a visual learning book that learners can open

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

  Scenario: Building a book needs the site to be activated with LMS Labs
    Given I am on the "How to promote teamwork" "visuallearning activity" page logged in as teacher1
    When I click on "Build the book" "button"
    Then I should see "AI Visual Learning is not activated on this site yet"
    And I should not see "Write the pages"

  Scenario: On an activated site without LMS Labs credentials, a teacher sees the steps but nothing paid is offered
    Given the following config values are set as admin:
      | unlockstate | {"status":"unlocked"} | mod_visuallearning |
    And I am on the "How to promote teamwork" "visuallearning activity" page logged in as teacher1
    Then I should see "This book has no pages yet."
    And I click on "Build the book" "button"
    And I should see "Write the pages"
    And I should see "Paste your AI assistant's reply"
    And I should see "Create the illustrations and voiceover"
    And I should see "this site's LMS Labs Site ID and API key are not set"
    And I should see "Download the prompt file (free)"
    And "Write the pages with LMS Labs AI…" "button" should not exist

  Scenario: A learner is told the book is not ready
    Given I am on the "How to promote teamwork" "visuallearning activity" page logged in as student1
    Then I should see "This activity is not ready yet."
