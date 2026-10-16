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

namespace mod_visuallearning\local;

/**
 * Content languages, their countries (for culturally matched illustrations) and voice labels.
 *
 * Which voices can read a language comes only from the LMS Labs voice catalogue.
 *
 * @package    mod_visuallearning
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class voices {
    /** @var string[] Chirp 3 HD voices with a female voice, used only to label voices LMS Labs offers. */
    public const FEMALE = ['Achernar', 'Aoede', 'Autonoe', 'Callirrhoe', 'Despina', 'Erinome', 'Gacrux', 'Kore',
        'Laomedeia', 'Leda', 'Pulcherrima', 'Sulafat', 'Vindemiatrix', 'Zephyr'];

    /** @var string[] Chirp 3 HD voices with a male voice, used only to label voices LMS Labs offers. */
    public const MALE = ['Achird', 'Algenib', 'Algieba', 'Alnilam', 'Charon', 'Enceladus', 'Fenrir', 'Iapetus', 'Orus',
        'Puck', 'Rasalgethi', 'Sadachbia', 'Sadaltager', 'Schedar', 'Umbriel', 'Zubenelgenubi'];

    /** @var array BCP-47 code => English name of the content languages a book can be written in. */
    public const LANGUAGES = [
        'ar-XA' => 'Arabic',
        'bn-IN' => 'Bengali (India)',
        'bg-BG' => 'Bulgarian',
        'yue-HK' => 'Chinese, Cantonese (Hong Kong)',
        'cmn-CN' => 'Chinese, Mandarin (China)',
        'hr-HR' => 'Croatian',
        'cs-CZ' => 'Czech',
        'da-DK' => 'Danish',
        'nl-BE' => 'Dutch (Belgium)',
        'nl-NL' => 'Dutch (Netherlands)',
        'en-AU' => 'English (Australia)',
        'en-IN' => 'English (India)',
        'en-GB' => 'English (United Kingdom)',
        'en-US' => 'English (United States)',
        'et-EE' => 'Estonian',
        'fi-FI' => 'Finnish',
        'fr-CA' => 'French (Canada)',
        'fr-FR' => 'French (France)',
        'de-DE' => 'German',
        'el-GR' => 'Greek',
        'gu-IN' => 'Gujarati',
        'he-IL' => 'Hebrew',
        'hi-IN' => 'Hindi',
        'hu-HU' => 'Hungarian',
        'id-ID' => 'Indonesian',
        'it-IT' => 'Italian',
        'ja-JP' => 'Japanese',
        'kn-IN' => 'Kannada',
        'ko-KR' => 'Korean',
        'lv-LV' => 'Latvian',
        'lt-LT' => 'Lithuanian',
        'ml-IN' => 'Malayalam',
        'mr-IN' => 'Marathi',
        'nb-NO' => 'Norwegian (Bokmål)',
        'pl-PL' => 'Polish',
        'pt-BR' => 'Portuguese (Brazil)',
        'pa-IN' => 'Punjabi (India)',
        'ro-RO' => 'Romanian',
        'ru-RU' => 'Russian',
        'sr-RS' => 'Serbian (Cyrillic)',
        'sk-SK' => 'Slovak',
        'sl-SI' => 'Slovenian',
        'es-ES' => 'Spanish (Spain)',
        'es-US' => 'Spanish (United States)',
        'sw-KE' => 'Swahili',
        'sv-SE' => 'Swedish',
        'ta-IN' => 'Tamil',
        'te-IN' => 'Telugu',
        'th-TH' => 'Thai',
        'tr-TR' => 'Turkish',
        'uk-UA' => 'Ukrainian',
        'ur-IN' => 'Urdu',
        'vi-VN' => 'Vietnamese',
    ];

    /** @var array BCP-47 code => the country or region whose culture the illustrations reflect. */
    public const CULTURES = [
        'ar-XA' => 'the Arabic-speaking Middle East and North Africa',
        'bn-IN' => 'West Bengal and Bengali-speaking India',
        'bg-BG' => 'Bulgaria',
        'yue-HK' => 'Hong Kong',
        'cmn-CN' => 'mainland China',
        'hr-HR' => 'Croatia',
        'cs-CZ' => 'the Czech Republic',
        'da-DK' => 'Denmark',
        'nl-BE' => 'Flanders, Belgium',
        'nl-NL' => 'the Netherlands',
        'en-AU' => 'Australia',
        'en-IN' => 'India',
        'en-GB' => 'the United Kingdom',
        'en-US' => 'the United States',
        'et-EE' => 'Estonia',
        'fi-FI' => 'Finland',
        'fr-CA' => 'Quebec, Canada',
        'fr-FR' => 'France',
        'de-DE' => 'Germany',
        'el-GR' => 'Greece',
        'gu-IN' => 'Gujarat, India',
        'he-IL' => 'Israel',
        'hi-IN' => 'Hindi-speaking northern India',
        'hu-HU' => 'Hungary',
        'id-ID' => 'Indonesia',
        'it-IT' => 'Italy',
        'ja-JP' => 'Japan',
        'kn-IN' => 'Karnataka, India',
        'ko-KR' => 'South Korea',
        'lv-LV' => 'Latvia',
        'lt-LT' => 'Lithuania',
        'ml-IN' => 'Kerala, India',
        'mr-IN' => 'Maharashtra, India',
        'nb-NO' => 'Norway',
        'pl-PL' => 'Poland',
        'pt-BR' => 'Brazil',
        'pa-IN' => 'Punjab, India',
        'ro-RO' => 'Romania',
        'ru-RU' => 'Russia',
        'sr-RS' => 'Serbia',
        'sk-SK' => 'Slovakia',
        'sl-SI' => 'Slovenia',
        'es-ES' => 'Spain',
        'es-US' => 'Spanish-speaking (Hispanic and Latino) communities in the United States',
        'sw-KE' => 'Kenya',
        'sv-SE' => 'Sweden',
        'ta-IN' => 'Tamil Nadu, India',
        'te-IN' => 'Andhra Pradesh and Telangana, India',
        'th-TH' => 'Thailand',
        'tr-TR' => 'Türkiye',
        'uk-UA' => 'Ukraine',
        'ur-IN' => 'Urdu-speaking communities in India',
        'vi-VN' => 'Vietnam',
    ];

    /**
     * The country or region illustrations should reflect for a language.
     *
     * @param string $code
     * @return string
     */
    public static function culture(string $code): string {
        return self::CULTURES[$code] ?? self::language_name($code);
    }

    /**
     * Language options for a select.
     *
     * @return array
     */
    public static function language_options(): array {
        $options = self::LANGUAGES;
        asort($options);
        return $options;
    }

    /**
     * Voice options from the LMS Labs voice catalogue (never a list made up here).
     *
     * The catalogue is read from what LMS Labs last published (no request is made while building a form). The
     * voice already chosen is always kept as an option, so editing settings never changes it silently.
     *
     * @param string $locale only voices of this locale ('' for every voice LMS Labs offers)
     * @param string $current the voice chosen now
     * @return array short name => label
     */
    public static function voice_options(string $locale = '', string $current = ''): array {
        $before = \mod_visuallearning\local\ai\lmslabs::$fetch;
        \mod_visuallearning\local\ai\lmslabs::$fetch = false;
        try {
            $catalogue = \mod_visuallearning\local\ai\lmslabs::voice_catalogue();
        } finally {
            \mod_visuallearning\local\ai\lmslabs::$fetch = $before;
        }
        $names = [];
        foreach ($catalogue as $code => $voices) {
            if ($locale === '' || $code === $locale) {
                $names += array_fill_keys(array_keys($voices), true);
            }
        }
        if ($current !== '' && self::is_valid_voice($current)) {
            $names[$current] = true;
        }
        ksort($names);
        $out = [];
        foreach (array_keys($names) as $name) {
            $gender = in_array($name, self::FEMALE, true) ? 'female' : (in_array($name, self::MALE, true) ? 'male' : '');
            $out[$name] = $gender !== '' ? $name . ' (' . get_string($gender, 'mod_visuallearning') . ')' : $name;
        }
        return $out;
    }

    /**
     * Whether a value has the form of a Chirp 3 HD voice short name. Whether LMS Labs offers it for a language is
     * checked against its catalogue before anything is made.
     *
     * @param string $voice
     * @return bool
     */
    public static function is_valid_voice(string $voice): bool {
        return (bool)preg_match('/^[A-Z][a-z]{2,20}$/', $voice);
    }

    /**
     * Language display name.
     *
     * @param string $code
     * @return string
     */
    public static function language_name(string $code): string {
        return self::LANGUAGES[$code] ?? $code;
    }
}
