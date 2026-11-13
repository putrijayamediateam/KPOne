import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const s = readFileSync('resources/js/components/v2/V2KeyValueList.vue', 'utf8');

test('V2KeyValueList: a real dl, label above on phones and beside from 640, long values wrap', () => {
    assert.match(s, /<dl/);
    assert.match(s, /<dt/);
    assert.match(s, /<dd/);
    assert.match(s, /grid-cols-1/);
    assert.match(s, /sm:grid-cols-\[12rem_minmax\(0,1fr\)\]/);
    assert.match(s, /break-words/);
    assert.match(s, /min-w-0/);
});

test('V2KeyValueList: empty shows "Not set" in muted words, zero is a value, no masking, no Classic import', () => {
    assert.match(s, /emptyText: 'Not set'/);
    assert.match(s, /String\(item\.value\)\.trim\(\) === ''/);
    assert.match(s, /name="value"/);
    assert.doesNotMatch(s, /<script[\s\S]*(reveal|audit)[\s\S]*<\/script>/);
    assert.doesNotMatch(s, /components\/ui/);
});
