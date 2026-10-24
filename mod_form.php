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
 * Activity settings form.
 *
 * @package    mod_visuallearning
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/course/moodleform_mod.php');

use mod_visuallearning\local\voices;

/**
 * AI Visual Learning settings form.
 */
class mod_visuallearning_mod_form extends moodleform_mod {
    /**
     * Form definition.
     */
    public function definition() {
        global $PAGE;
        $mform = $this->_form;
        $config = get_config('mod_visuallearning');
        $styles = \mod_visuallearning\local\visual_styles::class;
        $modes = \mod_visuallearning\local\picture_modes::class;
        $current = $this->current ?? null;

        // 1. Your lesson: the only things a teacher must decide.
        $mform->addElement('header', 'general', get_string('lessonheader', 'mod_visuallearning'));
        $mform->addElement('text', 'name', get_string('booktitle', 'mod_visuallearning'), ['size' => 64]);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required', null, 'client');
        $mform->addRule('name', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');

        $mform->addElement(
            'textarea',
            'sourcecontent',
            get_string('sourcecontent', 'mod_visuallearning'),
            ['rows' => 11, 'cols' => 80, 'style' => 'width:100%', 'maxlength' => 10000,
                'placeholder' => get_string('sourcecontent_placeholder', 'mod_visuallearning')]
        );
        $mform->setType('sourcecontent', PARAM_TEXT);
        $mform->addRule('sourcecontent', null, 'required', null, 'client');
        $mform->addHelpButton('sourcecontent', 'sourcecontent', 'mod_visuallearning');
        $this->add_ai_assistant_instructions();

        $mform->addElement('textarea', 'aiinstructions', get_string('aiinstructions', 'mod_visuallearning'),
            ['rows' => 10, 'cols' => 80, 'style' => 'width:100%', 'maxlength' => 50000,
                'placeholder' => get_string('aiinstructions_placeholder', 'mod_visuallearning')]);
        $mform->setType('aiinstructions', PARAM_TEXT);
        $mform->addHelpButton('aiinstructions', 'aiinstructions', 'mod_visuallearning');

        $cards = \mod_visuallearning\local\form_cards::class;
        $levels = [];
        foreach (\mod_visuallearning\local\learner_levels::options() as $key => $name) {
            $levels[$key] = ['title' => $name, 'desc' => get_string('learnerlevel_' . $key . '_card', 'mod_visuallearning'),
                'icon' => $key];
        }
        $cards::add($mform, 'learnerlevel', get_string('learnerlevel', 'mod_visuallearning'), $levels,
            \mod_visuallearning\local\learner_levels::clean($config->defaultlearnerlevel ?? null), 'learnerlevel');

        $approaches = [];
        foreach (\mod_visuallearning\local\approaches::options() as $key => $name) {
            $approaches[$key] = ['title' => $name, 'desc' => get_string('approach_' . $key . '_card', 'mod_visuallearning'),
                'icon' => $key];
        }
        $cards::add($mform, 'teachingapproach', get_string('teachingapproach', 'mod_visuallearning'), $approaches,
            \mod_visuallearning\local\approaches::clean($config->defaultapproach ?? \mod_visuallearning\local\approaches::NEW_DEFAULT),
            'teachingapproach');

        // Pages come in groups of four: each group is recapped (four square pictures) and checked together.
        $mform->addElement('select', 'maxpages', get_string('maxpages', 'mod_visuallearning'), visuallearning_page_counts());
        $mform->setDefault('maxpages', visuallearning_page_count($config->defaultmaxpages ?? 8));
        $mform->addHelpButton('maxpages', 'maxpages', 'mod_visuallearning');

        // 2. Pictures: one style, and one tick box for animation.
        $mform->addElement('header', 'picturesheader', get_string('picturesheader', 'mod_visuallearning'));
        $mform->setExpanded('picturesheader');
        $classic = ($current->picturemode ?? '') === $modes::ANIMATED;
        // Picture style: a card for each style with an example of it (all showing the same heart).
        $pictures = [];
        foreach ($styles::form_options($classic) as $key => $name) {
            $pictures[$key] = ['title' => $name, 'image' => $key,
                'desc' => get_string('visualstyle_' . $key . '_card', 'mod_visuallearning')];
        }
        $defaultstyle = (string)($config->defaultvisualstyle ?? '');
        $cards::add($mform, 'visualstyle', get_string('visualstyle', 'mod_visuallearning'), $pictures,
            isset($styles::options()[$defaultstyle]) ? $defaultstyle : $styles::DEFAULT, 'visualstyle');
        $mform->setType('visualstyle', PARAM_ALPHANUMEXT);
        // With animation on, this style is for the still pictures only: the animated diagrams have their own look.
        $stillnote = html_writer::div(get_string('visualstyle_stillnote', 'mod_visuallearning'), 'alert alert-info py-2 mb-0');
        $mform->addElement('static', 'visualstyle_stillnote', '', $stillnote);
        // Shown only while animation is on (a static element cannot use hideIf, so a few lines of script do it).
        $PAGE->requires->js_init_code(<<<'JS'
(function() {
    var note = document.getElementById('fitem_id_visualstyle_stillnote');
    var form = note && note.closest('form');
    if (!form) {
        return;
    }
    var value = function(name) {
        var el = form.querySelector('[name="' + name + '"]:checked');
        return el ? el.value : '';
    };
    var update = function() {
        note.hidden = !(value('animate') === '1' && value('visualstyle') !== 'animated');
        note.style.display = note.hidden ? 'none' : '';
    };
    form.addEventListener('change', update);
    update();
})();
JS
        );

        $cards::add($mform, 'animate', get_string('animate', 'mod_visuallearning'), [
            0 => ['title' => get_string('animate_off', 'mod_visuallearning'), 'image' => 'motion-off',
                'desc' => get_string('animate_off_card', 'mod_visuallearning')],
            1 => ['title' => get_string('animate_on', 'mod_visuallearning'), 'image' => 'motion-on',
                'desc' => get_string('animate_on_card', 'mod_visuallearning')],
        ], ($config->defaultpicturemode ?? '') === $modes::SVG ? 1 : 0, 'animate');
        $mform->setType('animate', PARAM_INT);
        $mform->hideIf('vlcards_animate', 'visualstyle', 'eq', $styles::ANIMATED);

        $cards::add($mform, 'callouts', get_string('callouts', 'mod_visuallearning'), [
            0 => ['title' => get_string('callouts_off', 'mod_visuallearning'), 'image' => 'words-off',
                'desc' => get_string('callouts_off_card', 'mod_visuallearning')],
            1 => ['title' => get_string('callouts_on', 'mod_visuallearning'), 'image' => 'words-on',
                'desc' => get_string('callouts_on_card', 'mod_visuallearning')],
        ], (int)($config->defaultcallouts ?? 0), 'callouts');
        $mform->setType('callouts', PARAM_INT);
        $mform->hideIf('vlcards_callouts', 'visualstyle', 'eq', $styles::ANIMATED);

        // 3. Voice.
        $mform->addElement('header', 'voiceheader', get_string('voiceheader', 'mod_visuallearning'));
        $mform->setExpanded('voiceheader');
        $mform->addElement('select', 'language', get_string('language', 'mod_visuallearning'), voices::language_options());
        $mform->setDefault('language', $config->defaultlanguage ?? 'en-AU');
        $mform->addHelpButton('language', 'language', 'mod_visuallearning');

        // Only voices LMS Labs offers (its published catalogue); the voice already chosen is kept.
        $default = (string)($config->defaultvoice ?? 'Aoede');
        $currentvoice = (string)($current->voice ?? $default);
        $mform->addElement('select', 'voice', get_string('voice', 'mod_visuallearning'), voices::voice_options('', $currentvoice));
        $mform->setDefault('voice', $default);
        $mform->addHelpButton('voice', 'voice', 'mod_visuallearning');

        // 4. More options: everything with a sensible default, closed until wanted.
        $mform->addElement('header', 'moreheader', get_string('moreheader', 'mod_visuallearning'));
        $mform->setExpanded('moreheader', false);
        $this->standard_intro_elements(get_string('lessondescription', 'mod_visuallearning'));

        $mform->addElement('textarea', 'objectives', get_string('objectives', 'mod_visuallearning'),
            ['rows' => 3, 'cols' => 80, 'style' => 'width:100%', 'maxlength' => 600]);
        $mform->setType('objectives', PARAM_TEXT);
        $mform->addHelpButton('objectives', 'objectives', 'mod_visuallearning');

        $mform->addElement('textarea', 'priorknowledge', get_string('priorknowledge', 'mod_visuallearning'),
            ['rows' => 2, 'cols' => 80, 'style' => 'width:100%', 'maxlength' => 300]);
        $mform->setType('priorknowledge', PARAM_TEXT);
        $mform->addHelpButton('priorknowledge', 'priorknowledge', 'mod_visuallearning');

        $mform->addElement('text', 'unitcode', get_string('unitcode', 'mod_visuallearning'), ['size' => 32, 'maxlength' => 100]);
        $mform->setType('unitcode', PARAM_TEXT);
        $mform->addHelpButton('unitcode', 'unitcode', 'mod_visuallearning');

        // Workplace stories happen in an industry; explanations do not need one.
        $mform->addElement('text', 'industry', get_string('industry', 'mod_visuallearning'), ['size' => 48]);
        $mform->setType('industry', PARAM_TEXT);
        $mform->addHelpButton('industry', 'industry', 'mod_visuallearning');
        $mform->hideIf('industry', 'teachingapproach', 'neq', 'story');
        $mform->addElement('text', 'subindustry', get_string('subindustry', 'mod_visuallearning'), ['size' => 48]);
        $mform->setType('subindustry', PARAM_TEXT);
        $mform->addHelpButton('subindustry', 'subindustry', 'mod_visuallearning');
        $mform->hideIf('subindustry', 'teachingapproach', 'neq', 'story');

        $mform->addElement(
            'select',
            'imageculture',
            get_string('imageculture', 'mod_visuallearning'),
            [
                'language' => get_string('imageculture_language', 'mod_visuallearning'),
                'international' => get_string('imageculture_international', 'mod_visuallearning'),
            ],
        );
        $mform->addHelpButton('imageculture', 'imageculture', 'mod_visuallearning');

        $mform->addElement('textarea', 'imagestyle', get_string('imagestyle', 'mod_visuallearning'),
            ['rows' => 3, 'cols' => 80, 'style' => 'width:100%',
                'placeholder' => get_string('imagestyle_placeholder', 'mod_visuallearning')]);
        $mform->setType('imagestyle', PARAM_TEXT);
        $mform->addHelpButton('imagestyle', 'imagestyle', 'mod_visuallearning');

        $mform->addElement('advcheckbox', 'cardpictures', get_string('cardpictures', 'mod_visuallearning'),
            get_string('cardpictures_label', 'mod_visuallearning'));
        $mform->addHelpButton('cardpictures', 'cardpictures', 'mod_visuallearning');
        $mform->hideIf('cardpictures', 'animate', 'eq', 1);
        $mform->hideIf('cardpictures', 'visualstyle', 'eq', $styles::ANIMATED);

        $paces = [];
        foreach (\mod_visuallearning\local\animation_settings::OPTIONS['animationpacing'] as $value) {
            $paces[$value] = get_string('animationpacing_' . $value, 'mod_visuallearning');
        }
        $mform->addElement('select', 'animationpacing', get_string('animationpacing', 'mod_visuallearning'), $paces);
        $mform->setDefault('animationpacing', 'normal');
        $mform->addHelpButton('animationpacing', 'animationpacing', 'mod_visuallearning');
        $mform->hideIf('animationpacing', 'animate', 'neq', 1);

        $rates = ['0.90' => get_string('speed_slow', 'mod_visuallearning'),
            '1.00' => get_string('speed_normal', 'mod_visuallearning'),
            '1.10' => get_string('speed_fast', 'mod_visuallearning')];
        $mform->addElement('select', 'speakingrate', get_string('speakingrate', 'mod_visuallearning'), $rates);
        $mform->setDefault('speakingrate', '1.00');

        $mform->addElement(
            'select',
            'navmode',
            get_string('navmode', 'mod_visuallearning'),
            [
                VISUALLEARNING_NAV_LINEAR => get_string('navmode_linear', 'mod_visuallearning'),
                VISUALLEARNING_NAV_FREE => get_string('navmode_free', 'mod_visuallearning'),
            ],
        );
        $mform->addHelpButton('navmode', 'navmode', 'mod_visuallearning');

        $mform->addElement(
            'advcheckbox',
            'autoplay',
            get_string('autoplay', 'mod_visuallearning'),
            get_string('autoplay_label', 'mod_visuallearning')
        );
        $mform->setDefault('autoplay', 0);
        $mform->addHelpButton('autoplay', 'autoplay', 'mod_visuallearning');

        // Recap and checks.
        $mform->addElement('header', 'reviewheader', get_string('reviewheader', 'mod_visuallearning'));
        $mform->setExpanded('reviewheader', false);
        $mform->addElement(
            'advcheckbox',
            'enablerecap',
            get_string('enablerecap', 'mod_visuallearning'),
            get_string('enablerecap_label', 'mod_visuallearning')
        );
        $mform->setDefault('enablerecap', 1);
        $mform->addHelpButton('enablerecap', 'enablerecap', 'mod_visuallearning');
        $mform->addElement(
            'advcheckbox',
            'enablequiz',
            get_string('enablequiz', 'mod_visuallearning'),
            get_string('enablequiz_label', 'mod_visuallearning')
        );
        $mform->setDefault('enablequiz', 1);
        $mform->addHelpButton('enablequiz', 'enablequiz', 'mod_visuallearning');

        // Which kinds of check question the AI may write (it rotates between them).
        $types = [];
        foreach (\mod_visuallearning\local\question::TYPES as $type) {
            $types[] = $mform->createElement('advcheckbox', 'qt_' . $type, '', get_string('qtype_' . $type, 'mod_visuallearning'));
        }
        $mform->addGroup($types, 'questiontypesgroup', get_string('questiontypes', 'mod_visuallearning'), '<br>', false);
        $mform->addHelpButton('questiontypesgroup', 'questiontypes', 'mod_visuallearning');
        foreach (\mod_visuallearning\local\question::TYPES as $type) {
            $mform->setDefault('qt_' . $type, 1);
        }
        $mform->hideIf('questiontypesgroup', 'enablequiz', 'notchecked');

        $mform->addElement(
            'advcheckbox',
            'pickimages',
            get_string('pickimages', 'mod_visuallearning'),
            get_string('pickimages_label', 'mod_visuallearning')
        );
        $mform->setDefault('pickimages', 1);
        $mform->addHelpButton('pickimages', 'pickimages', 'mod_visuallearning');
        $mform->hideIf('pickimages', 'enablequiz', 'notchecked');
        $mform->hideIf('pickimages', 'qt_pick', 'notchecked');

        // After a wrong answer: back to the page to listen again (Next locked until then), or simply try again.
        $mform->addElement(
            'advcheckbox',
            'relistenlock',
            get_string('relistenlock', 'mod_visuallearning'),
            get_string('relistenlock_label', 'mod_visuallearning')
        );
        $mform->setDefault('relistenlock', 1);
        $mform->addHelpButton('relistenlock', 'relistenlock', 'mod_visuallearning');
        $mform->hideIf('relistenlock', 'enablequiz', 'notchecked');

        $mform->addElement(
            'advcheckbox',
            'sounds',
            get_string('sounds', 'mod_visuallearning'),
            get_string('sounds_label', 'mod_visuallearning')
        );
        $mform->setDefault('sounds', 1);
        $mform->addHelpButton('sounds', 'sounds', 'mod_visuallearning');

        // Grade: the share of check questions answered correctly in the end (all right = 100%).
        $this->standard_grading_coursemodule_elements();
        $mform->setDefault('grade', 100);
        if (empty($this->_instance) && $mform->elementExists('gradepass')) {
            $mform->setDefault('gradepass', 100);
        }

        $this->standard_coursemodule_elements();
        $this->add_price_estimate();
        $this->add_action_buttons();
    }

    /**
     * A button under "What to teach" that opens instructions for an AI assistant (ChatGPT, Claude, Copilot...), with a
     * copy button: the teacher pastes them into the assistant with their topic and notes, and pastes its answer back.
     */
    protected function add_ai_assistant_instructions(): void {
        global $PAGE;
        $mform = $this->_form;
        $c = 'mod_visuallearning';
        $dialog = \html_writer::tag('dialog',
            \html_writer::div(
                \html_writer::tag('h3', get_string('aiassistant_title', $c), ['id' => 'vl-aiassist-title']) .
                \html_writer::div(
                    \html_writer::tag('button', get_string('aiassistant_copy', $c),
                        ['type' => 'button', 'class' => 'btn btn-primary vl-aiassist-copy',
                            'data-copied' => get_string('aiassistant_copied', $c)]) .
                    \html_writer::tag('button', '&times;', ['type' => 'button', 'class' => 'vl-aiassist-close',
                        'aria-label' => get_string('aiassistant_close', $c)]),
                    'vl-aiassist-actions'),
                'vl-aiassist-head') .
            \html_writer::tag('p', get_string('aiassistant_intro', $c), ['class' => 'vl-aiassist-intro']) .
            \html_writer::tag('pre', '', ['class' => 'vl-aiassist-text', 'tabindex' => '0']),
            ['id' => 'vl-aiassist', 'class' => 'vl-aiassist', 'aria-labelledby' => 'vl-aiassist-title',
                'data-prompt' => get_string('aiassistant_prompt', $c)]);
        $button = \html_writer::tag('button', get_string('aiassistant_button', $c),
            ['type' => 'button', 'class' => 'btn btn-outline-primary vl-aiassist-open']);
        $mform->addElement('static', 'vl_aiassistant', '', $button . $dialog);
        $PAGE->requires->js_init_code(<<<'JS'
(function() {
    var dialog = document.getElementById('vl-aiassist');
    var form = dialog && dialog.closest('form');
    if (!dialog || !form || typeof dialog.showModal !== 'function') {
        return;
    }
    var open = form.querySelector('.vl-aiassist-open');
    var copy = dialog.querySelector('.vl-aiassist-copy');
    var text = dialog.querySelector('.vl-aiassist-text');
    var label = copy.textContent;
    var timer = null;
    var fill = function() {
        // The number of slides follows the "Number of pages" choice on the form.
        var select = form.querySelector('select[name="maxpages"]');
        var pages = select && select.value ? select.value : '4, 8 or 12';
        text.textContent = dialog.dataset.prompt.split('{pages}').join(pages);
    };
    var done = function() {
        copy.textContent = copy.dataset.copied;
        window.clearTimeout(timer);
        timer = window.setTimeout(function() {
            copy.textContent = label;
        }, 2000);
    };
    var fallback = function() {
        var range = document.createRange();
        range.selectNodeContents(text);
        var sel = window.getSelection();
        sel.removeAllRanges();
        sel.addRange(range);
        try {
            document.execCommand('copy');
            done();
        } catch (e) {
            // The text stays selected, ready for Ctrl+C.
        }
    };
    open.addEventListener('click', function() {
        fill();
        copy.textContent = label;
        dialog.showModal();
    });
    copy.addEventListener('click', function() {
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(text.textContent).then(done, fallback);
        } else {
            fallback();
        }
    });
    dialog.querySelector('.vl-aiassist-close').addEventListener('click', function() {
        dialog.close();
    });
    // A click on the backdrop closes it too.
    dialog.addEventListener('click', function(e) {
        if (e.target === dialog) {
            dialog.close();
        }
    });
})();
JS
        );
    }

    /**
     * The one price the teacher sees on this form: what creating the whole lesson can cost with the choices made,
     * kept up to date as they change the number of pages, animation or card pictures.
     */
    protected function add_price_estimate(): void {
        global $PAGE, $DB;
        $mform = $this->_form;
        if ($this->_instance && $DB->record_exists('visuallearning_pages', ['visuallearningid' => $this->_instance])) {
            // A built lesson: saving its settings costs nothing, so no price is shown here.
            return;
        }
        $lmslabs = \mod_visuallearning\local\ai\lmslabs::class;
        $prices = ['page' => $lmslabs::approved_price('page'), 'animation' => $lmslabs::approved_price('animation'),
            'card' => $lmslabs::approved_price('card'), 'cards' => \mod_visuallearning\local\picture_modes::CARDS,
            'usd' => $lmslabs::CREDIT_USD];
        $text = get_string('estimate_text', 'mod_visuallearning', (object)['credits' => '{credits}', 'usd' => '{usd}',
            'pages' => '{pages}']);
        $mform->addElement('html', \html_writer::div(
            \html_writer::tag('div', get_string('estimate_heading', 'mod_visuallearning'), ['class' => 'vl-estimate-heading']) .
            \html_writer::tag('div', '', ['class' => 'vl-estimate-text', 'aria-live' => 'polite']) .
            \html_writer::tag('div', get_string('estimate_note', 'mod_visuallearning'), ['class' => 'vl-estimate-note']),
            'vl-estimate', ['id' => 'vl-estimate', 'data-prices' => json_encode($prices), 'data-text' => $text]
        ));
        $PAGE->requires->js_init_code(<<<'JS'
(function() {
    var box = document.getElementById('vl-estimate');
    var form = box && box.closest('form');
    if (!form) {
        return;
    }
    // Shown just above the save buttons, outside the collapsible sections, so it is always in sight.
    var buttons = form.querySelector('[id^="fgroup_id_buttonar"]');
    if (buttons) {
        buttons.parentNode.insertBefore(box, buttons);
    }
    var prices = JSON.parse(box.dataset.prices);
    var value = function(name) {
        var el = form.querySelector('[name="' + name + '"]:checked') || form.querySelector('select[name="' + name + '"]');
        return el ? el.value : '';
    };
    var update = function() {
        var pages = parseInt(value('maxpages'), 10) || 0;
        var cards = form.querySelector('input[type="checkbox"][name="cardpictures"]');
        var animated = value('animate') === '1' && value('visualstyle') !== 'animated';
        var each = prices.page + (animated ? prices.animation
            : (cards && cards.checked && value('visualstyle') !== 'animated' ? prices.cards * prices.card : 0));
        var credits = pages * each;
        box.querySelector('.vl-estimate-text').textContent = box.dataset.text
            .replace('{credits}', credits)
            .replace('{usd}', 'US$' + (credits * prices.usd).toFixed(2))
            .replace('{pages}', pages);
    };
    form.addEventListener('change', update);
    update();
})();
JS
        );
    }

    /**
     * Normalise the speaking rate for the select, and spread the question types over their checkboxes.
     *
     * @param array $defaultvalues
     */
    public function data_preprocessing(&$defaultvalues) {
        // The stored picture mode becomes the form's simple choices.
        $mode = (string)($defaultvalues['picturemode'] ?? '');
        $defaultvalues['animate'] = $mode === \mod_visuallearning\local\picture_modes::SVG ? 1 : 0;
        $defaultvalues['cardpictures'] = $mode === \mod_visuallearning\local\picture_modes::SEQUENCE ? 1 : 0;
        if ($mode === \mod_visuallearning\local\picture_modes::ANIMATED) {
            $defaultvalues['visualstyle'] = \mod_visuallearning\local\visual_styles::ANIMATED;
        }
        // A default art direction is shown as empty (the placeholder explains it); only the teacher's own words show.
        if (isset($defaultvalues['imagestyle'])
                && \mod_visuallearning\local\visual_styles::is_default_text((string)$defaultvalues['imagestyle'])) {
            $defaultvalues['imagestyle'] = '';
        }
        if (isset($defaultvalues['questiontypes'])) {
            $chosen = explode(',', (string)$defaultvalues['questiontypes']);
            foreach (\mod_visuallearning\local\question::TYPES as $type) {
                $defaultvalues['qt_' . $type] = in_array($type, $chosen, true) ? 1 : 0;
            }
        }
        if (isset($defaultvalues['speakingrate'])) {
            $rate = (float)$defaultvalues['speakingrate'];
            $defaultvalues['speakingrate'] = $rate < 0.95 ? '0.90' : ($rate > 1.05 ? '1.10' : '1.00');
        }
    }

    /**
     * Checks the voice against the LMS Labs voice catalogue, when LMS Labs has published one.
     *
     * @param array $data
     * @param array $files
     * @return array
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        \mod_visuallearning\local\ai\lmslabs::$fetch = false;
        try {
            $catalogue = \mod_visuallearning\local\ai\lmslabs::voice_catalogue();
        } finally {
            \mod_visuallearning\local\ai\lmslabs::$fetch = true;
        }
        // Everything the AI reads travels in one request, so the two boxes share LMS Labs' limit.
        $total = 300; // The labels of the lines added to the content.
        foreach (['sourcecontent', 'aiinstructions', 'objectives', 'priorknowledge', 'unitcode'] as $field) {
            $total += \core_text::strlen(trim((string)($data[$field] ?? '')));
        }
        if ($total > \mod_visuallearning\local\ai\lmslabs::MAX_CONTENT) {
            $errors['aiinstructions'] = get_string('aiinstructions_toolong', 'mod_visuallearning',
                \mod_visuallearning\local\ai\lmslabs::MAX_CONTENT);
        }
        if (!empty($data['enablequiz'])) {
            $any = false;
            foreach (\mod_visuallearning\local\question::TYPES as $type) {
                $any = $any || !empty($data['qt_' . $type]);
            }
            if (!$any) {
                $errors['questiontypesgroup'] = get_string('questiontypes_none', 'mod_visuallearning');
            }
        }
        $language = (string)($data['language'] ?? '');
        $voice = (string)($data['voice'] ?? '');
        if ($catalogue && empty($catalogue[$language])) {
            $errors['language'] = get_string('novoicesforlanguage', 'mod_visuallearning');
        } else if ($catalogue && empty($catalogue[$language][$voice])) {
            $errors['voice'] = get_string(
                'voicenotoffered',
                'mod_visuallearning',
                implode(', ', array_keys($catalogue[$language]))
            );
        }
        return $errors;
    }

    /**
     * Custom completion rules.
     *
     * @return array
     */
    public function add_completion_rules() {
        $mform = $this->_form;
        $suffix = $this->get_suffix();
        $element = 'completionlistened' . $suffix;
        $mform->addElement('advcheckbox', $element, '', get_string('completionlistened_label', 'mod_visuallearning'));
        $mform->setDefault($element, 1);
        $mform->addHelpButton($element, 'completionlistened', 'mod_visuallearning');

        $quizelement = 'completionquiz' . $suffix;
        $mform->addElement('advcheckbox', $quizelement, '', get_string('completionquiz_label', 'mod_visuallearning'));
        $mform->setDefault($quizelement, 0);
        $mform->addHelpButton($quizelement, 'completionquiz', 'mod_visuallearning');
        if ($mform->elementExists('enablequiz')) {
            $mform->disabledIf($quizelement, 'enablequiz', 'notchecked');
        }
        return [$element, $quizelement];
    }

    /**
     * Is the custom rule enabled?
     *
     * @param array $data
     * @return bool
     */
    public function completion_rule_enabled($data) {
        $suffix = $this->get_suffix();
        return !empty($data['completionlistened' . $suffix]) || !empty($data['completionquiz' . $suffix]);
    }

    /**
     * Turn off the rule when automatic completion is off.
     *
     * @param stdClass $data
     */
    public function data_postprocessing($data) {
        parent::data_postprocessing($data);
        if (!empty($data->completionunlocked)) {
            $suffix = $this->get_suffix();
            $auto = !empty($data->{'completion' . $suffix})
                && $data->{'completion' . $suffix} == COMPLETION_TRACKING_AUTOMATIC;
            if (!$auto) {
                $data->{'completionlistened' . $suffix} = 0;
                $data->{'completionquiz' . $suffix} = 0;
            }
        }
    }
}
