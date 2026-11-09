# Step 3 gallery verification

This fixture executes the gallery rendering block directly from `manage.php`,
using inert Moodle doubles for files, strings, URLs and `single_button` forms.
It is **not a running Moodle site**. Fixture forms never submit and cannot spend
credits. The PHP fixture is CLI-only and returns 404 if requested through PHP's
web SAPI.

Run from the workspace root:

```sh
php -l moodle-plugin/visuallearning/manage.php
php -l moodle-plugin/visuallearning/lang/en/visuallearning.php
php -l moodle-plugin/visuallearning/tests/fixtures/media_gallery.php
node --test moodle-plugin/visuallearning/tests/media_gallery.test.cjs
```

Requires PHP, Node and Playwright with a working Chromium installation. Where
Playwright's downloaded Chromium lacks system libraries, select the installed
browser:

```sh
CHROMIUM_EXECUTABLE_PATH="$(command -v chromium)" \
  node --test moodle-plugin/visuallearning/tests/media_gallery.test.cjs
```

Set `CAPTURE_GALLERY=1` to save desktop and mobile screenshots alongside this
fixture. These capture the synthetic fixture only, not Moodle or provider output.

Checks cover:

- Four columns at 1440px and 1920px, two at 1024px and 768px, one at 390px.
- Available-content-width responsiveness when a course drawer narrows the lesson.
- Readable card widths, long-title wrapping and no horizontal overflow.
- In-progress, failed, completed, empty and no-AI-permission rendering.
- Native keyboard disclosure controls and mobile expanded remake forms.
- Preserved POST quote actions, page targets, media kinds and session keys.
- No direct credit confirmation submission from the gallery.

Real Moodle authentication, permissions, background generation, delivery, credit
quotes and confirmation remain integration checks for a Moodle environment. The
presentation change does not modify those handlers or pricing.
