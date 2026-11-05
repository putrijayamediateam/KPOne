import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const labels = readFileSync(
    new URL('../../resources/js/pages/Dispensary/Labels.vue', import.meta.url),
    'utf8',
);

test('labels printed before carry a REPRINT mark on the last row, outside the line budget', () => {
    assert.ok(labels.includes('labelReprints?: string[];'));
    assert.ok(labels.includes('n === item.lines.length - 1 &&'));
    assert.ok(labels.includes('reprinted.includes(item.previewUrl)'));
    assert.ok(labels.includes('data-testid="label-reprint-mark"'));
    assert.ok(labels.includes('>REPRINT</span'));
    assert.ok(labels.includes('.reprint-mark {'));
    assert.ok(labels.includes('position: absolute;'));
});

test('the print check adds the reprint list before the print dialog opens', () => {
    const body = labels.slice(labels.indexOf('const printLabels = async'));
    const merge = body.indexOf('result.reprints ?? []');
    const tick = body.indexOf('await nextTick();');
    const print = body.indexOf('window.print();');
    assert.ok(merge > 0 && merge < tick && tick < print);
});

test('the screen-only toolbar says which labels were printed before', () => {
    assert.ok(labels.includes('data-testid="label-reprint-note"'));
    assert.ok(labels.includes('of these labels were printed before.'));
    assert.ok(labels.includes('This label was printed before.'));
});
