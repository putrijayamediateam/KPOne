import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// UI-RESTYLE D3 (owner: "start D3 on a branch"; GUIDE section 19): the label preview screen only. The printed label,
// its size, fonts, rows and print CSS stay exactly as they are.
const labels = readFileSync(
    new URL('../../resources/js/pages/Dispensary/Labels.vue', import.meta.url),
    'utf8',
);
const css = labels.slice(labels.indexOf('<style scoped>'));
const print = css.slice(css.indexOf('@media print {'));

test('the screen gets a page title, 13 px notes and calm token cards', () => {
    assert.ok(css.includes('header.print-toolbar h1 {\n    font-size: 28px;'));
    assert.ok(css.includes('color: var(--muted-foreground);'));
    assert.ok(css.includes("section.print-toolbar:not([role='alert']) {"));
    assert.ok(css.includes('border-radius: 14px;'));
    assert.ok(css.includes('background: var(--card);'));
    assert.ok(
        labels.includes(
            'class="print-toolbar grid gap-2 rounded-[14px] border border-destructive/40 bg-destructive/5 p-4"',
        ),
    );
});

test('the print button is 36 px from 1024 px and 44 px below it', () => {
    assert.ok(labels.includes('class="print-button"'));
    assert.ok(
        css.includes(
            '.print-toolbar :deep(.print-button) {\n        height: 36px;',
        ),
    );
    assert.ok(css.includes('min-height: 44px;'));
});

test('a 320 px phone never scrolls sideways; printing stays at 100%', () => {
    assert.ok(css.includes('@media screen and (max-width: 359.98px) {'));
    assert.ok(css.includes('zoom: 0.76;'));
    assert.equal(print.includes('zoom'), false);
});

test('the printed label is untouched: same box, fonts and print rules', () => {
    for (const rule of [
        "9pt/3.8mm 'Courier New'",
        'height: 55mm;',
        'border: 0.4mm solid #000;',
        'break-after: page;',
        '.print-toolbar {\n        display: none;',
    ]) {
        assert.ok(css.includes(rule), rule);
    }
});

test('the REPRINT note keeps normal, stronger text (Hakim)', () => {
    assert.ok(
        css.includes(
            "header.print-toolbar p[data-testid='label-reprint-note'] {\n    font-size: 15px;\n    font-weight: 600;\n    color: var(--foreground);",
        ),
    );
});
