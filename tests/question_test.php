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

namespace mod_visuallearning;

use mod_visuallearning\local\question;

/**
 * Tests for reading, storing and editing check questions.
 *
 * @package    mod_visuallearning
 * @category   test
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(question::class)]
final class question_test extends \advanced_testcase {
    /**
     * Questions as AIs write them are read, and unusable ones are refused.
     */
    public function test_from_ai(): void {
        $this->resetAfterTest();
        $pick = ['type' => 'pick', 'prompt' => 'Which?', 'correct' => 3, 'feedback' => 'Because.',
            'options' => [['label' => 'A'], ['label' => 'B'], ['label' => 'C'], 'D']];
        $q = question::from_ai(['question' => $pick]);
        $this->assertSame('pick', $q['type']);
        $this->assertSame(3, $q['data']['correct']);
        $this->assertSame('D', $q['data']['options'][3]['label']);
        // Three options, or a right answer out of range, are not usable.
        $this->assertNull(question::from_ai(['question' => ['options' => array_slice($pick['options'], 0, 3)] + $pick]));
        $this->assertNull(question::from_ai(['question' => ['correct' => 4] + $pick]));

        $match = ['type' => 'matching', 'prompt' => 'Match.', 'pairs' => [['left' => 'a', 'right' => '1'],
            ['left' => 'b', 'right' => '2'], ['left' => 'c', 'right' => '3']]];
        $this->assertSame('match', question::from_ai(['question' => $match])['type']);
        $match['pairs'][2]['right'] = '2';
        $this->assertNull(question::from_ai(['question' => $match]), 'Right items must all differ.');

        $sort = ['type' => 'sort', 'prompt' => 'Sort.', 'groups' => ['Before', 'During'], 'items' => [
            ['text' => 'a', 'group' => 0], ['text' => 'b', 'group' => 'During'], ['text' => 'c', 'group' => 0],
            ['text' => 'd', 'group' => 1]]];
        $q = question::from_ai(['question' => $sort]);
        $this->assertSame(1, $q['data']['items'][1]['group'], 'A group may be given by its name.');
        $sort['items'] = array_map(fn($i) => ['group' => 0] + $i, $sort['items']);
        foreach ($sort['items'] as &$item) {
            $item['group'] = 0;
        }
        $this->assertNull(question::from_ai(['question' => $sort]), 'Both groups need a card.');

        $this->assertSame('truefalse', question::from_ai(['tf_statement' => 'Old style.', 'tf_answer' => 'false'])['type']);
        $this->assertNull(question::from_ai(['question' => ['type' => 'essay', 'prompt' => 'Write.']]));
    }

    /**
     * What the edit form shows is what it saves.
     */
    public function test_form_round_trip(): void {
        $this->resetAfterTest();
        $sort = ['type' => 'sort', 'statement' => 'Sort.', 'feedback' => 'F.', 'groups' => ['X', 'Y'], 'items' => [
            ['text' => 'a', 'group' => 0], ['text' => 'b', 'group' => 1], ['text' => 'c', 'group' => 0], ['text' => 'd',
            'group' => 1]]];
        $fields = question::to_fields(question::from_ai(['question' => $sort]));
        $page = (object)($fields + ['id' => 5]);
        $form = question::to_form($page);
        $this->assertSame('sort', $form['qtype']);
        $this->assertSame('Y', $form['group1']);
        $again = question::to_fields(question::from_form((object)$form));
        $this->assertSame($fields, $again);

        // Choosing no question clears it.
        $this->assertSame('', question::to_fields(question::from_form((object)['qtype' => '']))['tfstatement']);
    }
}
