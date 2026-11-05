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
    assert.ok(
        labels.indexOf('label-reprint-note') <
            labels.indexOf('Print current labels'),
        'the note is read before pressing Print',
    );
    assert.ok(labels.includes('This label was printed before.'));
});

test('the REPRINT list is updated from the check before the print dialog opens (behaviour)', async () => {
    const { parse } = await import('@vue/compiler-sfc');
    const { descriptor } = parse(labels);
    const body = descriptor.scriptSetup.content.slice(
        descriptor.scriptSetup.content.indexOf('const printLabels = async'),
    );
    const url = 'http://127.0.0.1/dispensary/synthetic/items/one/label';
    const reprinted = { value: [] };
    const seenAtPrint = [];
    const fn = new Function(
        'props',
        'busy',
        'changed',
        'retired',
        'error',
        'fetch',
        'window',
        'URL',
        'reprinted',
        'nextTick',
        body + ';return printLabels;',
    )(
        { labels: { blocked: [], token: 'a'.repeat(64), branchId: 1 } },
        { value: false },
        { value: false },
        { value: false },
        { value: '' },
        async () => ({
            ok: true,
            json: async () => ({ current: true, reprints: [url] }),
        }),
        {
            location: { href: 'http://127.0.0.1/dispensary/synthetic/labels' },
            print: () => seenAtPrint.push([...reprinted.value]),
        },
        URL,
        reprinted,
        async () => {},
    );
    await fn();
    assert.deepEqual(seenAtPrint, [[url]]);
});
