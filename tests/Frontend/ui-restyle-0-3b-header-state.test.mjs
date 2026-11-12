import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (p) =>
    readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');
const header = read('resources/js/components/ui/page-header/PageHeader.vue');
const css = read('resources/css/app.css');
const empty = read('resources/js/components/ui/state/EmptyState.vue');
const error = read('resources/js/components/ui/state/ErrorState.vue');
const notice = read('resources/js/components/ui/state/NoticePanel.vue');

test('0.3b: the page title is one shared class with the GUIDE section 19 sizes', () => {
    assert.match(
        css,
        /\.kp-page-title \{\s*font-size: 28px;\s*line-height: 34px;\s*font-weight: 700;\s*letter-spacing: -0\.01em;/,
    );
    assert.match(
        css,
        /min-width: 64rem\) \{\s*\.kp-page-title \{\s*font-size: 30px;\s*line-height: 36px;/,
    );
    assert.equal((header.match(/kp-page-title/g) ?? []).length, 2);
});

test('0.3b: below 640 px only the primary action is full width, first; the rest share one row', () => {
    assert.match(header, /max-sm:w-full max-sm:\[&>\*\]:flex-1/);
    assert.match(header, /:has\(>:nth-child\(3\)\)/);
    assert.match(header, /\[data-variant=primary\]\)\]:order-first/);
    assert.doesNotMatch(header, /uppercase/);
    assert.match(header, /\[data-variant=primary\]\)\]:basis-full/);
});

test('0.3b: EmptyState has a solid hairline at 14 px and an optional icon slot', () => {
    assert.match(empty, /rounded-\[14px\] border bg-card/);
    assert.doesNotMatch(empty, /border-dashed/);
    assert.match(empty, /<slot name="icon">/);
});

test('0.3b: ErrorState uses the destructive soft token and stays an alert', () => {
    assert.match(error, /bg-destructive-soft/);
    assert.match(error, /role="alert"/);
});

test('0.3b: NoticePanel has four tones on the soft tokens and the right live role', () => {
    for (const token of [
        'bg-tint-1',
        'bg-success-soft',
        'bg-warning-soft',
        'bg-destructive-soft',
    ]) {
        assert.ok(notice.includes(token), token);
    }

    assert.match(notice, /tone === 'danger' \? 'alert' : 'status'/);
});
