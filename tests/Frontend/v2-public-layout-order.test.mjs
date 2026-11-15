import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const app = readFileSync(
    new URL('../../resources/js/app.ts', import.meta.url),
    'utf8',
);

test('a public page keeps the public layout even when its name has /V2/ in it', () => {
    const publicCase = app.indexOf("case name.startsWith('PublicCheckIn/'):");
    const careersCase = app.indexOf("case name.startsWith('Careers/'):");
    const v2Case = app.indexOf('case isV2Page(name):');

    assert.ok(publicCase > 0 && careersCase > 0 && v2Case > 0);
    assert.ok(
        publicCase < v2Case && careersCase < v2Case,
        'the public layout case must come before the staff V2 shell case',
    );
});

test('the public layout case still returns PublicCheckInLayout and the V2 case the staff shell', () => {
    const publicBlock = app.slice(
        app.indexOf("case name.startsWith('PublicCheckIn/'):"),
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
