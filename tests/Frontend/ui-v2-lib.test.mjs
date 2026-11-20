import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const src = readFileSync(
    new URL('../../resources/js/lib/ui-version.ts', import.meta.url),
    'utf8',
);

test('uiVersionFor (CO-1, Classic only): always classic, the spaces included', () => {
    assert.match(src, /\): UiVersion => 'classic';/);
    assert.doesNotMatch(src, /enabled === true|url\.startsWith\('\/spaces'\)/);
    assert.match(src, /dataset\.ui = version/);
});

test('isV2Page: a page component name with a V2 segment is a Version 2 page', () => {
    assert.match(src, /isV2Page = \(name: string\): boolean/);
    assert.match(src, /\(\^\|\\\/\)V2\\\//);
});
