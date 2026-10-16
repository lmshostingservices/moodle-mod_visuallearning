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

namespace mod_visuallearning\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\helper;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy provider. Learner data stays in Moodle; only teacher-written content is sent to LMS Labs.
 *
 * Paid LMS Labs requests are kept (so a request is never sent twice and its charge can be traced); deleting a
 * teacher's data removes the link to the teacher, not the request.
 *
 * @package    mod_visuallearning
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {
    /**
     * Metadata.
     *
     * @param collection $collection
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table(
            'visuallearning_progress',
            [
                'userid' => 'privacy:metadata:progress:userid',
                'pageid' => 'privacy:metadata:progress:pageid',
                'views' => 'privacy:metadata:progress:views',
                'listened' => 'privacy:metadata:progress:listened',
                'timespent' => 'privacy:metadata:progress:timespent',
                'timefirstviewed' => 'privacy:metadata:progress:timefirstviewed',
                'timelistened' => 'privacy:metadata:progress:timelistened',
                'tfattempts' => 'privacy:metadata:progress:tfattempts',
                'tfpassed' => 'privacy:metadata:progress:tfpassed',
                'timepassed' => 'privacy:metadata:progress:timepassed',
            ],
            'privacy:metadata:progress',
        );
        $collection->add_database_table(
            'visuallearning_aireq',
            [
                'userid' => 'privacy:metadata:aireq:userid',
                'operation' => 'privacy:metadata:aireq:operation',
                'body' => 'privacy:metadata:aireq:body',
                'status' => 'privacy:metadata:aireq:status',
                'timecreated' => 'privacy:metadata:aireq:timecreated',
                'timemodified' => 'privacy:metadata:aireq:timemodified',
            ],
            'privacy:metadata:aireq',
        );
        $collection->add_external_location_link(
            'lmslabs',
            [
                'content' => 'privacy:metadata:lmslabs:content',
                'prompt' => 'privacy:metadata:lmslabs:prompt',
                'voicetext' => 'privacy:metadata:lmslabs:voicetext',
                'siteid' => 'privacy:metadata:lmslabs:siteid',
            ],
            'privacy:metadata:lmslabs',
        );
        return $collection;
    }

    /**
     * Contexts with data for a user.
     *
     * @param int $userid
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $sql = "SELECT ctx.id
                  FROM {context} ctx
                  JOIN {course_modules} cm ON cm.id = ctx.instanceid AND ctx.contextlevel = :ctxlevel
                  JOIN {modules} m ON m.id = cm.module AND m.name = 'visuallearning'
                  JOIN {visuallearning_progress} pr ON pr.visuallearningid = cm.instance
                 WHERE pr.userid = :userid";
        $contextlist = new contextlist();
        $contextlist->add_from_sql($sql, ['ctxlevel' => CONTEXT_MODULE, 'userid' => $userid]);
        $sql = "SELECT ctx.id
                  FROM {context} ctx
                  JOIN {course_modules} cm ON cm.id = ctx.instanceid AND ctx.contextlevel = :ctxlevel
                  JOIN {modules} m ON m.id = cm.module AND m.name = 'visuallearning'
                  JOIN {visuallearning_aireq} rq ON rq.visuallearningid = cm.instance
                 WHERE rq.userid = :userid";
        $contextlist->add_from_sql($sql, ['ctxlevel' => CONTEXT_MODULE, 'userid' => $userid]);
        return $contextlist;
    }

    /**
     * Users with data in a context.
     *
     * @param userlist $userlist
     */
    public static function get_users_in_context(userlist $userlist) {
        $context = $userlist->get_context();
        if (!$context instanceof \context_module) {
            return;
        }
        $sql = "SELECT pr.userid
                  FROM {course_modules} cm
                  JOIN {modules} m ON m.id = cm.module AND m.name = 'visuallearning'
                  JOIN {visuallearning_progress} pr ON pr.visuallearningid = cm.instance
                 WHERE cm.id = :cmid";
        $userlist->add_from_sql('userid', $sql, ['cmid' => $context->instanceid]);
        $sql = "SELECT rq.userid
                  FROM {course_modules} cm
                  JOIN {modules} m ON m.id = cm.module AND m.name = 'visuallearning'
                  JOIN {visuallearning_aireq} rq ON rq.visuallearningid = cm.instance
                 WHERE cm.id = :cmid AND rq.userid > 0";
        $userlist->add_from_sql('userid', $sql, ['cmid' => $context->instanceid]);
    }

    /**
     * Export.
     *
     * @param approved_contextlist $contextlist
     */
    public static function export_user_data(approved_contextlist $contextlist) {
        global $DB;
        $userid = $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof \context_module) {
                continue;
            }
            $cm = get_coursemodule_from_id('visuallearning', $context->instanceid);
            if (!$cm) {
                continue;
            }
            $sql = "SELECT pr.*, pg.pagenum, pg.title
                      FROM {visuallearning_progress} pr
                      JOIN {visuallearning_pages} pg ON pg.id = pr.pageid
                     WHERE pr.visuallearningid = :bookid AND pr.userid = :userid
                  ORDER BY pg.pagenum";
            $rows = $DB->get_records_sql($sql, ['bookid' => $cm->instance, 'userid' => $userid]);
            $pages = [];
            foreach ($rows as $r) {
                $pages[] = [
                    'page' => (int)$r->pagenum,
                    'title' => format_string($r->title),
                    'views' => (int)$r->views,
                    'listened' => transform::yesno($r->listened),
                    'timespent' => (int)$r->timespent,
                    'timefirstviewed' => $r->timefirstviewed ? transform::datetime($r->timefirstviewed) : null,
                    'timelistened' => $r->timelistened ? transform::datetime($r->timelistened) : null,
                    'truefalseattempts' => (int)$r->tfattempts,
                    'truefalsecorrect' => transform::yesno($r->tfpassed),
                    'timecorrect' => $r->timepassed ? transform::datetime($r->timepassed) : null,
                ];
            }
            $requests = [];
            foreach (
                $DB->get_records(
                    'visuallearning_aireq',
                    ['visuallearningid' => $cm->instance, 'userid' => $userid],
                    'id',
                    'id, operation, status, charged, timecreated, timemodified'
                ) as $r
            ) {
                $requests[] = [
                    'operation' => $r->operation,
                    'status' => $r->status,
                    'creditscharged' => $r->charged === null ? null : (int)$r->charged,
                    'timecreated' => transform::datetime($r->timecreated),
                    'timemodified' => transform::datetime($r->timemodified),
                ];
            }
            $data = helper::get_context_data($context, $contextlist->get_user());
            $data->pages = $pages;
            if ($requests) {
                $data->lmslabsrequests = $requests;
            }
            writer::with_context($context)->export_data([], $data);
            helper::export_context_files($context, $contextlist->get_user());
        }
    }

    /**
     * Delete all users' data in a context.
     *
     * @param \context $context
     */
    public static function delete_data_for_all_users_in_context(\context $context) {
        global $DB;
        if (!$context instanceof \context_module) {
            return;
        }
        $cm = get_coursemodule_from_id('visuallearning', $context->instanceid);
        if ($cm) {
            $DB->delete_records('visuallearning_progress', ['visuallearningid' => $cm->instance]);
            $DB->set_field('visuallearning_aireq', 'userid', 0, ['visuallearningid' => $cm->instance]);
        }
    }

    /**
     * Delete one user's data.
     *
     * @param approved_contextlist $contextlist
     */
    public static function delete_data_for_user(approved_contextlist $contextlist) {
        global $DB;
        $userid = $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof \context_module) {
                continue;
            }
            $cm = get_coursemodule_from_id('visuallearning', $context->instanceid);
            if ($cm) {
                $DB->delete_records('visuallearning_progress', ['visuallearningid' => $cm->instance, 'userid' => $userid]);
                $DB->set_field('visuallearning_aireq', 'userid', 0, ['visuallearningid' => $cm->instance, 'userid' => $userid]);
            }
        }
    }

    /**
     * Delete several users' data in one context.
     *
     * @param approved_userlist $userlist
     */
    public static function delete_data_for_users(approved_userlist $userlist) {
        global $DB;
        $context = $userlist->get_context();
        if (!$context instanceof \context_module) {
            return;
        }
        $cm = get_coursemodule_from_id('visuallearning', $context->instanceid);
        if (!$cm || !$userlist->get_userids()) {
            return;
        }
        [$insql, $params] = $DB->get_in_or_equal($userlist->get_userids(), SQL_PARAMS_NAMED);
        $params['bookid'] = $cm->instance;
        $DB->delete_records_select('visuallearning_progress', "visuallearningid = :bookid AND userid $insql", $params);
        $DB->set_field_select('visuallearning_aireq', 'userid', 0, "visuallearningid = :bookid AND userid $insql", $params);
    }
}
