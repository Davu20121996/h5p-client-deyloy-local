const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

function preview() {
    const handlers = new Map();
    const timers = new Map();
    let nextTimer = 1;
    let response = { completed: false, score: null, maximum: 10, reported_at: null };
    let httpStatus = 200;
    let requests = 0;
    const element = () => ({
        textContent: '', hidden: false, children: [],
        classList: { toggle() {}, contains() { return false; } },
        setAttribute() {}, focus() {}, append(node) { this.children.push(node); },
        replaceChildren(...children) { this.children = children; },
        addEventListener(event, action) { this.events ??= {}; this.events[event] = action; },
    });
    const message = element();
    const score = element();
    const iframe = element(); iframe.hidden = true;
    const placeholder = element();
    const player = element(); player.querySelector = () => iframe;
    const launch = element();
    const label = element();
    const selectors = {
        '[data-learning-message]': message, '[data-learning-score]': score, '[data-learning-player]': player,
        '[data-learning-label]': label, '[data-learning-launch]': launch, '[data-learning-placeholder]': placeholder,
    };
    const panel = { dataset: { learningStatus: '/courses/1/activities/1/status' }, querySelector: key => selectors[key] };
    const context = {
        document: {
            querySelector: () => panel, addEventListener() {}, createTextNode: text => ({ textContent: text }),
            createElement: element, body: element(),
        },
        window: { addEventListener: (event, action) => handlers.set(event, action) },
        setTimeout: (action, delay) => { const id = nextTimer++; timers.set(id, { action, delay }); return id; },
        clearTimeout: id => timers.delete(id),
        fetch: async () => { requests++; return { status: httpStatus, ok: httpStatus === 200, redirected: false, json: async () => response }; },
    };
    vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../../public/learning.js'), 'utf8'), context);
    return {
        message, score, iframe, placeholder, panel, launch, handlers, timers,
        requests: () => requests,
        result: value => { response = value; },
        status: value => { httpStatus = value; },
        tick: async () => {
            const first = timers.entries().next().value;
            assert.ok(first, 'A status refresh should be scheduled');
            timers.delete(first[0]);
            await first[1].action();
        },
    };
}

test('loads an existing grade on entry without launching an iframe', async () => {
    const page = preview();
    page.result({ completed: true, score: 8, maximum: 10 });
    await page.tick();
    assert.equal(page.panel.dataset.state, 'completed');
    assert.equal(page.score.children[0].textContent, '8 ');
    assert.equal(page.iframe.hidden, true);
    assert.match(page.message.textContent, /đã hoàn thành/);
});

test('embedded launch keeps refreshing grades on the lesson page', async () => {
    const page = preview();
    await page.tick();
    page.launch.events.submit();
    assert.equal(page.iframe.hidden, false);
    assert.equal(page.placeholder.hidden, true);
    page.result({ completed: true, score: 9, maximum: 10 });
    await page.tick();
    assert.equal(page.score.children[0].textContent, '9 ');
    assert.equal(page.panel.dataset.state, 'completed');
});

test('the shared iframe control expands and restores its panel without changing the iframe', () => {
    const handlers = new Map();
    const label = { textContent: 'Phóng to' };
    const attributes = new Map();
    const classList = () => {
        const classes = new Set();
        return {
            contains: name => classes.has(name),
            toggle: (name, enabled) => enabled ? classes.add(name) : classes.delete(name),
        };
    };
    const button = {
        querySelector: () => label,
        addEventListener: (event, action) => handlers.set(event, action),
        setAttribute: (name, value) => attributes.set(name, value),
        focus() { this.focused = true; },
    };
    const originalIframe = { src: 'https://hub.example/lesson' };
    const frame = { classList: classList(), iframe: originalIframe, querySelector: () => button };
    const body = { classList: classList() };
    vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../../public/frame-expand.js'), 'utf8'), {
        document: { body, querySelectorAll: () => [frame], addEventListener: (event, action) => handlers.set(event, action) },
    });

    handlers.get('click')();
    assert.equal(label.textContent, 'Thu gọn');
    assert.equal(attributes.get('aria-pressed'), 'true');
    assert.equal(frame.classList.contains('is-expanded'), true);
    assert.equal(body.classList.contains('lesson-expanded'), true);
    handlers.get('keydown')({ key: 'Escape' });
    assert.equal(label.textContent, 'Phóng to');
    assert.equal(attributes.get('aria-pressed'), 'false');
    assert.equal(body.classList.contains('lesson-expanded'), false);
    assert.equal(button.focused, true);
    assert.equal(frame.iframe, originalIframe);
});

test('revoked access stops polling instead of displaying stale success', async () => {
    const page = preview();
    page.status(403);
    await page.tick();
    assert.equal(page.timers.size, 0);
    assert.match(page.message.textContent, /quyền truy cập/);
});

test('embedded launch reveals player and returning from page cache resumes polling', async () => {
    const page = preview();
    page.launch.events.submit();
    assert.equal(page.iframe.hidden, false);
    assert.equal(page.placeholder.hidden, true);
    page.handlers.get('pagehide')();
    assert.equal(page.timers.size, 0);
    page.handlers.get('pageshow')();
    await page.tick();
    assert.equal(page.requests(), 1);
});
