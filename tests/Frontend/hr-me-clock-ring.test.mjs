import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// Owner report, 17 Nov 2026: Clock in on /spaces/me did nothing. The progress ring's SVG is absolutely positioned over
// the button, so a real click or tap landed on the SVG. The ring is decoration and lets every pointer through.
const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const css = read('resources/css/space-shell-layout.css');
const me = read('resources/js/components/spaces/MeHome.vue');

const rule = (selector) => {
    const start = css.indexOf(`${selector} {`);
    assert.ok(start >= 0, selector);

    return css.slice(start, css.indexOf('}', start));
};

test('the ring over the Clock in button lets clicks and taps through', () => {
    const ring = rule('.space-shell .me-ring-svg');
    assert.match(ring, /position: absolute;/);
    assert.match(ring, /pointer-events: none;/);
});

test('the ring is decoration, and the Clock in button inside it still calls the shared press', () => {
    assert.match(
        me,
        /<svg[^>]*aria-hidden="true"[^>]*class="me-ring-svg"|<svg[^>]*class="me-ring-svg"[^>]*aria-hidden="true"/,
    );
    assert.match(
        me,
        /class="me-clock-button"\s+data-test="me-clock-in"\s+@click="press\(\)"/,
    );
});
