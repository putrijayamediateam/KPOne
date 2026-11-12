import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const src = readFileSync(
    new URL('../../resources/js/lib/ui-version.ts', import.meta.url),
    'utf8',
);

test('uiVersionFor: v2 only with the switch on or inside the spaces', () => {
    assert.match(src, /enabled === true/);
    assert.match(src, /url\.startsWith\('\/spaces'\)/);
    assert.match(src, /'classic'/);
    assert.match(src, /dataset\.ui = version/);
});

test('isV2Page: a page component name with a V2 segment is a Version 2 page', () => {
    assert.match(src, /isV2Page = \(name: string\): boolean/);
    assert.match(src, /\(\^\|\\\/\)V2\\\//);
});
