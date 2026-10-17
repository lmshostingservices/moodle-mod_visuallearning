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
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle. If not, see <https://www.gnu.org/licenses/>.

namespace mod_visuallearning\local\ai;

defined('MOODLE_INTERNAL') || die();

/**
 * Durable bundle identities for slide text and its included media.
 *
 * @package mod_visuallearning
 * @copyright 2026 LMS Hosting Services
 * @license https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class bundles {
    /** @var string Contract route prefix. */
    public const PREFIX = '/api/moodle/ai-visuallearning/v2';

    /**
     * A deterministic child key keeps an uncertain bundle opening replayable.
     *
     * @param string $key Parent request key
     * @param string $purpose Child operation
     * @return string
     */
    public static function key(string $key, string $purpose): string {
        $hash = hash('sha256', $key . ':' . $purpose);
        return substr($hash, 0, 8) . '-' . substr($hash, 8, 4) . '-4' . substr($hash, 13, 3) .
            '-8' . substr($hash, 17, 3) . '-' . substr($hash, 20, 12);
    }

    /**
     * Find a page's purchased bundle. Missing identities mean a legacy page.
     *
     * @param int $bookid Activity
     * @param int $pageid Page; zero selects the cover owner
     * @return \stdClass|null
     */
    public static function page(int $bookid, int $pageid): ?\stdClass {
        global $DB;
        if ($pageid) {
            return $DB->get_record('visuallearning_pages', ['id' => $pageid, 'visuallearningid' => $bookid]) ?: null;
        }
        $pages = $DB->get_records('visuallearning_pages', ['visuallearningid' => $bookid], 'pagenum ASC', '*', 0, 1);
        return $pages ? reset($pages) : null;
    }

    /**
     * Bind a confirmed media quote to its purchased slot, or retain legacy billing.
     *
     * @param int $bookid Activity
     * @param string $operation Operation
     * @param int $targetid Page
     * @param string $part Voice part or option
     * @param array $body Legacy media body
     * @param bool $recovering Explicit recovery of this activity's missing delivery
     * @return array New body and price
     */
    public static function media_quote(
        int $bookid, string $operation, int $targetid, string $part, array $body, bool $recovering = false
    ): array {
        $page = self::page($bookid, $targetid);
        if (!$page || empty($page->bundleid)) {
            return [$body, $body['maxCredits']];
        }
        if ($page->bundlesite !== requests::sitehash()) {
            throw new \moodle_exception('lmslabscredentialsmissing', 'mod_visuallearning');
        }
        $slot = $operation === requests::VOICE ? 'clip:' . $part :
            ($operation === requests::OPTION ? 'option' . $part : ($operation === requests::COVER ? 'cover' : 'picture'));
        [$status, $state] = lmslabs::get(self::PREFIX . '/bundles/' . $page->bundleid);
        if ($status !== 200) {
            throw new \moodle_exception('bundleunavailable', 'mod_visuallearning');
        }
        $remote = null;
        foreach ($state['slides'] ?? [] as $slide) {
            if ($slide['slideId'] === $page->slideid) {
                $remote = $slide['slots'][$slot] ?? null;
            }
        }
        if (!$remote || in_array($remote['state'], ['support-required', 'in-progress'], true)) {
            throw new \moodle_exception('bundlesupport', 'mod_visuallearning');
        }
        $delivered = $remote['state'] === 'delivered';
        $receipts = json_decode((string)$page->bundledeliveries, true) ?: [];
        $cm = get_coursemodule_from_instance('visuallearning', $bookid, 0, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);
        $area = $operation === requests::VOICE ? 'audio' :
            ($operation === requests::OPTION ? 'option' : ($operation === requests::COVER ? 'cover' : 'image'));
        $files = get_file_storage()->get_area_files($context->id, 'mod_visuallearning', $area, $targetid, 'id', false);
        $hasfile = false;
        foreach ($files as $file) {
            if ($operation === requests::VOICE && !str_starts_with($file->get_filename(), $part . '-')) {
                continue;
            }
            if ($operation === requests::OPTION && !str_starts_with($file->get_filename(), 'option' . $part . '-')) {
                continue;
            }
            $hasfile = true;
        }
        // Delivery without a matching saved receipt is recovery, never a paid optional remake.
        if ($delivered && (!$hasfile || empty($receipts[$slot]) ||
                ($recovering && $receipts[$slot] !== ($remote['deliveryId'] ?? '')))) {
            $delivery = $remote['deliveryId'] ?? '';
            [$code, $lostheaders, $lostbody] = lmslabs::post(
                self::PREFIX . '/bundles/' . $page->bundleid . '/slides/' . $page->slideid .
                    '/slots/' . $slot . '/lost',
                json_encode(['deliveryId' => $delivery, 'maxCredits' => 0]),
                self::key($delivery, 'lost'), 'application/json'
            );
            if ($code !== 200 || !empty(json_decode($lostbody, true)['supportRequired'])) {
                throw new \moodle_exception('bundlesupport', 'mod_visuallearning');
            }
            $delivered = false;
        }
        $bound = ['bundleId' => $page->bundleid, 'slideId' => $page->slideid, 'slot' => $slot,
            'remake' => $delivered, 'maxCredits' => $delivered ? 2 : 0];
        if ($operation === requests::VOICE) {
            [$code, , $raw] = lmslabs::post(
                self::PREFIX . '/speech/quote', json_encode(array_replace($bound, ['maxCredits' => 0])),
                \core\uuid::generate(), 'application/json'
            );
            $quote = json_decode($raw, true);
            if ($code !== 200 || !isset($quote['credits']) || !in_array($quote['credits'], [0, 2], true)) {
                throw new \moodle_exception('bundleunavailable', 'mod_visuallearning');
            }
            $bound['maxCredits'] = $quote['credits'];
        }
        unset($body['clipRef'], $body['purpose'], $body['maxCredits'], $body['bundleId'],
            $body['slideId'], $body['slot'], $body['remake']);
        return [$body + $bound, $bound['maxCredits']];
    }

    /**
     * Open exactly one unpaid bundle, then send the confirmed text request.
     *
     * @param \stdClass $row Durable local request
     * @param \stdClass $book Activity
     * @return array HTTP status, headers and response
     */
    public static function text(\stdClass $row, \stdClass $book): array {
        global $DB;
        $body = json_decode($row->body, true);
        $draft = $row->operation === requests::WRITE;
        $source = $draft ? 'draft' : ($body['_manual'] ?? false ? 'manual' : 'import');
        $count = $draft ? $body['maxPages'] : $body['pageCount'];
        $open = ['lessonRef' => hash('sha256', requests::sitehash() . ':' . $book->id . ':' . $row->idemkey),
            'source' => $source, 'slides' => $count, 'maxCredits' => (int)$row->maxcredits];
        if (empty($body['_bundle'])) {
            [$status, $headers, $raw] = lmslabs::post(
                self::PREFIX . '/bundles', json_encode($open), self::key($row->idemkey, 'open'), 'application/json'
            );
            if (!in_array($status, [200, 201], true)) {
                return [$status, $headers, $raw];
            }
            $bundle = json_decode($raw, true);
            if (empty($bundle['bundleId']) || count($bundle['slides'] ?? []) !== $count) {
                throw new \moodle_exception('bundleunavailable', 'mod_visuallearning');
            }
            $body['_bundle'] = $bundle;
            $row->body = json_encode($body);
            $DB->update_record('visuallearning_aireq', $row);
        }
        $bundle = $body['_bundle'];
        if ($draft) {
            unset($body['_bundle'], $body['_contract']);
        } else {
            $pages = json_decode($row->result, true)['pages'];
            $body = ['slides' => [], 'maxCredits' => (int)$row->maxcredits];
            foreach ($pages as $i => $page) {
                $body['slides'][] = ['slideId' => $bundle['slides'][$i]['slideId'], 'title' => $page['title'],
                    'questionType' => $page['question']['type'] ?? 'none'];
            }
        }
        return lmslabs::post(
            self::PREFIX . '/bundles/' . $bundle['bundleId'] . '/' . $source,
            json_encode($body), $row->idemkey, 'application/json', lmslabs::DRAFT_TIMEOUT
        );
    }

    /**
     * Record successful local media persistence; absence of this receipt requires recovery.
     *
     * @param \stdClass $row Request
     * @param array $headers Response headers
     */
    public static function media_saved(\stdClass $row, array $headers): void {
        global $DB;
        $body = json_decode($row->body, true);
        if (empty($body['bundleId'])) {
            return;
        }
        if (empty($headers['x-delivery-id'])) {
            throw new \moodle_exception('bundlesupport', 'mod_visuallearning');
        }
        $page = self::page((int)$row->visuallearningid, (int)$row->targetid);
        if (!$page || $page->slideid !== $body['slideId']) {
            throw new \moodle_exception('bundlesupport', 'mod_visuallearning');
        }
        $lock = \core\lock\lock_config::get_lock_factory('mod_visuallearning_aireq')
            ->get_lock('receipts' . $page->id, 5);
        if (!$lock) {
            throw new \moodle_exception('bundlesupport', 'mod_visuallearning');
        }
        try {
            $page = $DB->get_record('visuallearning_pages', ['id' => $page->id], '*', MUST_EXIST);
            $receipts = json_decode((string)$page->bundledeliveries, true) ?: [];
            $receipts[$body['slot']] = $headers['x-delivery-id'];
            $DB->set_field('visuallearning_pages', 'bundledeliveries', json_encode($receipts), ['id' => $page->id]);
        } finally {
            $lock->release();
        }
    }
}
