/* Run: node --test moodle-plugin/visuallearning/tests/media_gallery.test.cjs
 * Real manage.php rendering block + inert Moodle doubles; NOT real-Moodle verification.
 */
const { test, before, after } = require('node:test');
const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const { readFileSync } = require('node:fs');
const { join } = require('node:path');
const { createServer } = require('node:http');
const { chromium } = require('playwright');

const root = join(__dirname, '..');
const render = (...args) => execFileSync('php', [join(__dirname, 'fixtures/media_gallery.php'), ...args], { encoding: 'utf8' });
let server, browser, base;

before(async () => {
    server = createServer((req, res) => {
        if (req.url === '/styles.css') {
            res.setHeader('Content-Type', 'text/css');
            res.end(readFileSync(join(root, 'styles.css')));
        } else {
            res.setHeader('Content-Type', 'text/html');
            res.end(render());
        }
    });
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    base = `http://127.0.0.1:${server.address().port}`;
    browser = await chromium.launch({
        headless: true,
        executablePath: process.env.CHROMIUM_EXECUTABLE_PATH || undefined,
        args: ['--no-sandbox'],
    });
});

after(async () => {
    if (browser) await browser.close();
    if (server) await new Promise(resolve => server.close(resolve));
});

test('actual gallery rendering retains quote forms, session keys and capability gates', () => {
    const html = render();
    assert.equal((html.match(/class="vl-media-card/g) || []).length, 9);
    assert.match(html, /aria-label="Media progress"/);
    assert.match(html, /aria-valuenow="59"/);
    assert.match(html, /59% ready/);
    for (const kind of ['image', 'option', 'card', 'voice', 'cover']) {
        assert.match(html, new RegExp(`name="kinds" value="${kind}"`));
    }
    assert.match(html, /name="action" value="quote"/);
    assert.match(html, /name="what" value="missing"/);
    assert.match(html, /name="sesskey" value="fixture-session-key"/);
    assert.doesNotMatch(html, /name="action" value="confirm"/);
    const readonly = render('--no-ai');
    assert.doesNotMatch(readonly, /method="post"|class="vl-remake"|name="action" value="quote"/);
    assert.match(readonly, /Preview lesson and animations/);
    assert.match(html, /Static picture — no animated scene/);
    assert.match(html, /Edit page \/ animation/);
    assert.match(readonly, /class="vl-media-grid"/);
    assert.match(render('--complete'), /100% ready/);
    assert.doesNotMatch(render('--complete'), /name="what" value="missing"/);
    assert.doesNotMatch(render('--empty'), /class="vl-media-grid"|class="vl-media-panel"/);
});

test('four desktop columns, two tablet columns, single mobile column, with no overflow', async () => {
    const page = await browser.newPage();
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    for (const [width, columns] of [[1440, 4], [1920, 4], [1024, 2], [768, 2], [390, 1]]) {
        await page.setViewportSize({ width, height: 1000 });
        await page.goto(base);
        const geometry = await page.locator('.vl-media-grid').evaluate(el => ({
            columns: getComputedStyle(el).gridTemplateColumns.split(' ').length,
            overflow: document.documentElement.scrollWidth > window.innerWidth,
            width: el.firstElementChild.getBoundingClientRect().width,
        }));
        assert.equal(geometry.columns, columns, `${width}px column count`);
        assert.equal(geometry.overflow, false, `${width}px horizontal overflow`);
        assert.ok(geometry.width > 200, `${width}px card width must remain readable`);
        if (process.env.CAPTURE_GALLERY === '1' && [1440, 390].includes(width)) {
            await page.screenshot({
                path: join(__dirname, 'fixtures', `media-gallery-${width}.jpg`),
                type: 'jpeg',
                quality: 85,
            });
        }
    }
    // A desktop Moodle course drawer can reduce available content independently of viewport.
    await page.setViewportSize({ width: 1440, height: 1000 });
    await page.locator('main').evaluate(el => { el.style.maxWidth = '710px'; });
    assert.equal(await page.locator('.vl-media-grid').evaluate(el =>
        getComputedStyle(el).gridTemplateColumns.split(' ').length), 2);
    assert.deepEqual(errors, []);
    await page.close();
});

test('remake disclosure works by keyboard and retains the correct POST target', async () => {
    const page = await browser.newPage({ viewport: { width: 1440, height: 1000 } });
    await page.goto(base);
    const card = page.locator('.vl-media-card').nth(2);
    const details = card.locator('details');
    assert.equal(await details.getAttribute('open'), null);
    await card.locator('summary').focus();
    await page.keyboard.press('Enter');
    assert.notEqual(await details.getAttribute('open'), null);
    assert.ok(await card.locator('.vl-remake-note').isVisible());
    const form = card.locator('form').first();
    assert.equal(await form.getAttribute('method'), 'post');
    assert.equal(await form.locator('[name="pageid"]').inputValue(), '2');
    assert.equal(await form.locator('[name="kinds"]').inputValue(), 'image');
    assert.equal(await form.locator('[name="what"]').inputValue(), 'all');
    for (const target of await page.locator('form').all()) {
        assert.equal(await target.getAttribute('method'), 'post');
        assert.equal(await target.locator('[name="sesskey"]').inputValue(), 'fixture-session-key');
        assert.equal(await target.locator('[name="action"]').inputValue(), 'quote');
    }
    await form.locator('button').click();
    assert.equal(await page.locator('body').getAttribute('data-submitted'), 'true');
    assert.ok(await page.locator('.vl-chip.is-failed').count() > 0);
    assert.ok(await page.locator('.vl-chip.is-making').count() > 0);
    await page.keyboard.press('Tab');
    await card.locator('summary').focus();
    await page.keyboard.press('Enter');
    assert.equal(await details.getAttribute('open'), null);
    await page.setViewportSize({ width: 390, height: 874 });
    await card.locator('summary').click();
    assert.ok(await form.locator('button').isVisible());
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth), false);
    await page.close();
});
