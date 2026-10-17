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
 * Library functions for the AI Visual Learning activity.
 *
 * @package    mod_visuallearning
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/** Media status: nothing generated yet. */
define('VISUALLEARNING_MEDIA_PENDING', 0);
/** Media status: generation task queued. */
define('VISUALLEARNING_MEDIA_QUEUED', 1);
/** Media status: generated and stored. */
define('VISUALLEARNING_MEDIA_READY', 2);
/** Media status: generation failed. */
define('VISUALLEARNING_MEDIA_FAILED', 3);

/** Navigation: students must go page by page. */
define('VISUALLEARNING_NAV_LINEAR', 0);
/** Navigation: students can jump to any page. */
define('VISUALLEARNING_NAV_FREE', 1);

/** The three narrated cards on every page. */
define('VISUALLEARNING_CARDS', ['concept', 'action', 'outcome']);

/** Every narrated clip of a page => the page field it reads. */
define(
    'VISUALLEARNING_CLIPS',
    [
        'concept' => 'concepttext',
        'action' => 'actiontext',
        'outcome' => 'outcometext',
        'summary' => 'summarytext',
        'question' => 'tfstatement',
        'feedback' => 'feedback',
    ],
);

/** Concepts per recap / true-false group. */
define('VISUALLEARNING_GROUP_SIZE', 4);

/**
 * Supported features.
 *
 * @param string $feature
 * @return mixed
 */
function visuallearning_supports($feature) {
    switch ($feature) {
        case FEATURE_MOD_INTRO:
        case FEATURE_SHOW_DESCRIPTION:
        case FEATURE_COMPLETION_TRACKS_VIEWS:
        case FEATURE_COMPLETION_HAS_RULES:
        case FEATURE_BACKUP_MOODLE2:
            return true;
        case FEATURE_GRADE_HAS_GRADE:
        case FEATURE_GROUPS:
        case FEATURE_GROUPINGS:
            return false;
        case FEATURE_MOD_PURPOSE:
            return MOD_PURPOSE_CONTENT;
        default:
            return null;
    }
}

/**
 * Add an instance.
 *
 * @param stdClass $data
 * @param mod_visuallearning_mod_form|null $mform
 * @return int
 */
function visuallearning_add_instance($data, $mform = null) {
    global $DB;
    $data->timecreated = time();
    $data->timemodified = $data->timecreated;
    visuallearning_clean_instance_data($data);
    $data->id = $DB->insert_record('visuallearning', $data);

    if (!empty($data->completionexpected)) {
        \core_completion\api::update_completion_date_event(
            $data->coursemodule,
            'visuallearning',
            $data->id,
            $data->completionexpected
        );
    }
    return $data->id;
}

/**
 * Update an instance.
 *
 * @param stdClass $data
 * @param mod_visuallearning_mod_form|null $mform
 * @return bool
 */
function visuallearning_update_instance($data, $mform = null) {
    global $DB;
    $data->id = $data->instance;
    $data->timemodified = time();
    visuallearning_clean_instance_data($data);

    $DB->update_record('visuallearning', $data);

    // Media files are bound to what made them (text, voice, prompt), so the statuses follow from the files.
    $cm = get_coursemodule_from_instance('visuallearning', $data->id, 0, false, MUST_EXIST);
    \mod_visuallearning\local\media::refresh(
        $DB->get_record('visuallearning', ['id' => $data->id], '*', MUST_EXIST),
        context_module::instance($cm->id)
    );

    \core_completion\api::update_completion_date_event(
        $data->coursemodule,
        'visuallearning',
        $data->id,
        $data->completionexpected ?? null
    );
    return true;
}

/**
 * Normalise form data before saving.
 *
 * @param stdClass $data
 */
function visuallearning_clean_instance_data(stdClass $data): void {
    $data->maxpages = max(1, min(20, (int)($data->maxpages ?? 20)));
    if (!\mod_visuallearning\local\voices::is_valid_voice($data->voice ?? '')) {
        $data->voice = 'Aoede';
    }
    if (!isset(\mod_visuallearning\local\voices::LANGUAGES[$data->language ?? ''])) {
        $data->language = 'en-AU';
    }
    $data->speakingrate = max(0.5, min(1.5, (float)($data->speakingrate ?? 1)));
    $data->completionlistened = empty($data->completionlistened) ? 0 : 1;
    $data->completionquiz = empty($data->completionquiz) ? 0 : 1;
    $data->autoplay = empty($data->autoplay) ? 0 : 1;
    // Recap and checks are on unless explicitly switched off (e.g. by generators or web services).
    $data->enablerecap = isset($data->enablerecap) && empty($data->enablerecap) ? 0 : 1;
    $data->enablequiz = isset($data->enablequiz) && empty($data->enablequiz) ? 0 : 1;
    if (!$data->enablequiz) {
        $data->completionquiz = 0;
    }
    $data->imageculture = ($data->imageculture ?? 'language') === 'international' ? 'international' : 'language';
    // Question types arrive as one checkbox each from the form, or as a list from generators and web services.
    $types = [];
    foreach (\mod_visuallearning\local\question::TYPES as $type) {
        if (!empty($data->{'qt_' . $type})) {
            $types[] = $type;
        }
    }
    if (!$types) {
        $types = array_intersect(\mod_visuallearning\local\question::TYPES, explode(',', (string)($data->questiontypes ?? '')));
    }
    $data->questiontypes = implode(',', $types ?: \mod_visuallearning\local\question::TYPES);
    $data->pickimages = isset($data->pickimages) && empty($data->pickimages) ? 0 : 1;
    $data->sounds = isset($data->sounds) && empty($data->sounds) ? 0 : 1;
}

/**
 * Delete an instance.
 *
 * @param int $id
 * @return bool
 */
function visuallearning_delete_instance($id) {
    global $DB;
    if (!$book = $DB->get_record('visuallearning', ['id' => $id])) {
        return false;
    }
    $cm = get_coursemodule_from_instance('visuallearning', $id);
    if ($cm) {
        \core_completion\api::update_completion_date_event($cm->id, 'visuallearning', $id, null);
        $context = context_module::instance($cm->id);
        get_file_storage()->delete_area_files($context->id, 'mod_visuallearning');
    }
    $DB->delete_records('visuallearning_progress', ['visuallearningid' => $id]);
    $DB->delete_records('visuallearning_aireq', ['visuallearningid' => $id]);
    $DB->delete_records('visuallearning_pages', ['visuallearningid' => $id]);
    $DB->delete_records('visuallearning', ['id' => $id]);
    return true;
}

/**
 * Course module info, including custom completion rules.
 *
 * @param stdClass $coursemodule
 * @return cached_cm_info|false
 */
function visuallearning_get_coursemodule_info($coursemodule) {
    global $DB;
    $book = $DB->get_record(
        'visuallearning',
        ['id' => $coursemodule->instance],
        'id, name, intro, introformat, completionlistened, completionquiz'
    );
    if (!$book) {
        return false;
    }
    $info = new cached_cm_info();
    $info->name = $book->name;
    if ($coursemodule->showdescription) {
        $info->content = format_module_intro('visuallearning', $book, $coursemodule->id, false);
    }
    if ($coursemodule->completion == COMPLETION_TRACKING_AUTOMATIC) {
        $info->customdata['customcompletionrules']['completionlistened'] = $book->completionlistened;
        $info->customdata['customcompletionrules']['completionquiz'] = $book->completionquiz;
    }
    return $info;
}

/**
 * Serve plugin files.
 *
 * @param stdClass $course
 * @param stdClass $cm
 * @param context $context
 * @param string $filearea
 * @param array $args
 * @param bool $forcedownload
 * @param array $options
 * @return bool
 */
function visuallearning_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, array $options = []) {
    if ($context->contextlevel != CONTEXT_MODULE) {
        return false;
    }
    if (!in_array($filearea, ['image', 'audio', 'cover', 'option'])) {
        return false;
    }
    require_course_login($course, true, $cm);
    require_capability('mod/visuallearning:view', $context);

    $itemid = (int)array_shift($args);
    $filename = array_pop($args);
    $filepath = $args ? '/' . implode('/', $args) . '/' : '/';
    $file = get_file_storage()->get_file($context->id, 'mod_visuallearning', $filearea, $itemid, $filepath, $filename);
    if (!$file || $file->is_directory()) {
        return false;
    }
    // File names carry a hash of what made the file, so they can be cached for a long time.
    send_stored_file($file, YEARSECS, 0, $forcedownload, $options);
}

/**
 * Add Manage and Report nodes to the activity settings navigation.
 *
 * @param settings_navigation $settingsnav
 * @param navigation_node $node
 */
function visuallearning_extend_settings_navigation(settings_navigation $settingsnav, navigation_node $node) {
    $cm = $settingsnav->get_page()->cm;
    if (!$cm) {
        return;
    }
    $context = context_module::instance($cm->id);
    if (has_capability('mod/visuallearning:manage', $context)) {
        $node->add(
            get_string('managepages', 'mod_visuallearning'),
            new moodle_url('/mod/visuallearning/manage.php', ['id' => $cm->id]),
            navigation_node::TYPE_SETTING,
            null,
            'visuallearning_manage'
        );
    }
    if (has_capability('mod/visuallearning:viewreports', $context)) {
        $node->add(
            get_string('report', 'mod_visuallearning'),
            new moodle_url('/mod/visuallearning/report.php', ['id' => $cm->id]),
            navigation_node::TYPE_SETTING,
            null,
            'visuallearning_report'
        );
    }
}

/**
 * Mark the activity viewed and trigger the viewed event.
 *
 * @param stdClass $book
 * @param stdClass $course
 * @param stdClass|cm_info $cm
 * @param context_module $context
 */
function visuallearning_view($book, $course, $cm, $context) {
    $event = \mod_visuallearning\event\course_module_viewed::create(
        [
            'objectid' => $book->id,
            'context' => $context,
        ],
    );
    $event->add_record_snapshot('course', $course);
    $event->add_record_snapshot('visuallearning', $book);
    $event->trigger();

    $completion = new completion_info($course);
    $completion->set_module_viewed($cm);
}

/**
 * Course reset form elements.
 *
 * @param MoodleQuickForm $mform
 */
function visuallearning_reset_course_form_definition(&$mform) {
    $mform->addElement('header', 'visuallearningheader', get_string('modulenameplural', 'mod_visuallearning'));
    $mform->addElement('advcheckbox', 'reset_visuallearning_progress', get_string('resetprogress', 'mod_visuallearning'));
}

/**
 * Course reset defaults.
 *
 * @param stdClass $course
 * @return array
 */
function visuallearning_reset_course_form_defaults($course) {
    return ['reset_visuallearning_progress' => 1];
}

/**
 * Reset user data.
 *
 * @param stdClass $data
 * @return array
 */
function visuallearning_reset_userdata($data) {
    global $DB;
    $status = [];
    if (!empty($data->reset_visuallearning_progress)) {
        $books = $DB->get_fieldset_select('visuallearning', 'id', 'course = ?', [$data->courseid]);
        if ($books) {
            [$insql, $params] = $DB->get_in_or_equal($books);
            $DB->delete_records_select('visuallearning_progress', "visuallearningid $insql", $params);
        }
        $status[] = [
            'component' => get_string('modulenameplural', 'mod_visuallearning'),
            'item' => get_string('resetprogress', 'mod_visuallearning'),
            'error' => false,
        ];
    }
    return $status;
}

/**
 * Pages of a lesson in order.
 *
 * @param int $bookid
 * @return array
 */
function visuallearning_get_pages(int $bookid): array {
    global $DB;
    return $DB->get_records('visuallearning_pages', ['visuallearningid' => $bookid], 'pagenum ASC, id ASC');
}

/**
 * Renumber pages 1..n after edits.
 *
 * @param int $bookid
 */
function visuallearning_renumber_pages(int $bookid): void {
    global $DB;
    $n = 1;
    foreach (visuallearning_get_pages($bookid) as $page) {
        if ((int)$page->pagenum !== $n) {
            $DB->set_field('visuallearning_pages', 'pagenum', $n, ['id' => $page->id]);
        }
        $n++;
    }
}

/**
 * Latest stored file in an area/item, or null.
 *
 * @param int $contextid
 * @param string $area
 * @param int $itemid
 * @param string $prefix Filename prefix to match (e.g. 'concept_').
 * @return stored_file|null
 */
function visuallearning_get_file(int $contextid, string $area, int $itemid, string $prefix = ''): ?stored_file {
    $files = get_file_storage()->get_area_files($contextid, 'mod_visuallearning', $area, $itemid, 'timemodified DESC', false);
    foreach ($files as $file) {
        if ($prefix === '' || strpos($file->get_filename(), $prefix) === 0) {
            return $file;
        }
    }
    return null;
}

/**
 * URL for a stored file.
 *
 * @param stored_file|null $file
 * @return string
 */
function visuallearning_file_url(?stored_file $file): string {
    if (!$file) {
        return '';
    }
    return moodle_url::make_pluginfile_url(
        $file->get_contextid(),
        'mod_visuallearning',
        $file->get_filearea(),
        $file->get_itemid(),
        $file->get_filepath(),
        $file->get_filename()
    )->out(false);
}

/**
 * Re-evaluate completion for a user after progress changes.
 *
 * @param stdClass $course
 * @param cm_info|stdClass $cm
 * @param int $userid
 */
function visuallearning_update_completion($course, $cm, int $userid): void {
    $completion = new completion_info($course);
    if ($completion->is_enabled($cm) == COMPLETION_TRACKING_AUTOMATIC) {
        $completion->update_state($cm, COMPLETION_UNKNOWN, $userid);
    }
}

/**
 * Status badge for the manage screen.
 *
 * @param int $status
 * @return string
 */
function visuallearning_status_label(int $status): string {
    $map = [
        VISUALLEARNING_MEDIA_PENDING => ['status_pending', 'secondary'],
        VISUALLEARNING_MEDIA_QUEUED => ['status_queued', 'info'],
        VISUALLEARNING_MEDIA_READY => ['status_ready', 'success'],
        VISUALLEARNING_MEDIA_FAILED => ['status_failed', 'danger'],
    ];
    [$key, $class] = $map[$status] ?? $map[VISUALLEARNING_MEDIA_PENDING];
    return html_writer::span(get_string($key, 'mod_visuallearning'), "badge bg-{$class} badge-{$class}");
}

/**
 * A single button, optionally with a confirmation dialogue.
 *
 * @param moodle_url $url
 * @param string $label
 * @param string $method
 * @param bool $primary
 * @param string $confirm Confirmation message, or ''.
 * @return string
 */
function visuallearning_button(
    moodle_url $url,
    string $label,
    string $method = 'post',
    bool $primary = false,
    string $confirm = ''
): string {
    global $OUTPUT;
    $button = new single_button($url, $label, $method, $primary ? single_button::BUTTON_PRIMARY : single_button::BUTTON_SECONDARY);
    if ($confirm !== '') {
        $button->add_confirm_action($confirm);
    }
    return $OUTPUT->render($button);
}

/**
 * Format seconds as h:mm:ss / m:ss.
 *
 * @param int $secs
 * @return string
 */
function visuallearning_format_duration(int $secs): string {
    $h = intdiv($secs, 3600);
    $m = intdiv($secs % 3600, 60);
    $s = $secs % 60;
    return $h ? sprintf('%d:%02d:%02d', $h, $m, $s) : sprintf('%d:%02d', $m, $s);
}
