import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const app = readFileSync(
    new URL('../../resources/js/app.ts', import.meta.url),
    'utf8',
);

test('a public page keeps the public layout even when its name has /V2/ in it', () => {
    const publicV2Case = app.indexOf(
        "case name.startsWith('PublicCheckIn/V2/'):",
    );
    const v2Case = app.indexOf('case isV2Page(name):');

    assert.ok(publicV2Case > 0 && v2Case > 0);
    assert.ok(
        publicV2Case < v2Case,
        'the public V2 layout case must come before the staff V2 shell case',
    );
});

test('the public layout case still returns PublicCheckInLayout and the V2 case the staff shell', () => {
    const publicBlock = app.slice(
        app.indexOf("case name.startsWith('PublicCheckIn/V2/'):"),
        app.indexOf('case isV2Page(name):'),
    );

    assert.ok(publicBlock.includes('return PublicCheckInLayout;'));
    assert.ok(
        app
            .slice(app.indexOf('case isV2Page(name):'))
            .startsWith(
                'case isV2Page(name):\n                    return V2Layout;',
            ),
    );
});
