import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const labels = read('resources/js/pages/Dispensary/Labels.vue');

test('the label preview says on screen why batch and expiry are not printed', () => {
    assert.ok(labels.includes('labelBatchNotes?: Array<'));
    assert.ok(labels.includes('data-testid="label-batch-notes"'));
    assert.ok(labels.includes('{{ note.note }}'));
    assert.ok(
        labels.includes('only when exactly one'),
        'the rule is explained',
    );
});

test('the reason sits in the screen-only toolbar, never on the printed label', () => {
    const notes = labels.indexOf('data-testid="label-batch-notes"');
    const section = labels.lastIndexOf('<section', notes);
    assert.ok(labels.slice(section, notes).includes('class="print-toolbar"'));
    assert.ok(labels.includes('.print-toolbar {\n        display: none;'));
});
