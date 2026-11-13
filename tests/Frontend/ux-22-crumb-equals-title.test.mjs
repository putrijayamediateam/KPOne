import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const api = readFileSync(
    new URL('../../resources/js/composables/usePageCrumbs.ts', import.meta.url),
    'utf8',
);
const squash = (s) => s.replace(/\s+/g, ' ');

// The rule itself, run on real inputs: the function body is copied out of the source so the test checks the shipped code.
const body = squash(api).match(
    /export function repeatsTitle\(trail: PageCrumb\[\], pageTitle: string\): boolean \{ (return [^}]*;) \}/,
)?.[1];
const normalise = (text) => text.replace(/\s+/g, ' ').trim().toLowerCase();
const repeatsTitle = new Function('trail', 'pageTitle', 'normalise', body);

test('UX-22: a one-item crumb that repeats the page title is dropped; longer trails stay', () => {
    assert.ok(body, 'repeatsTitle is in usePageCrumbs');
    assert.equal(
        repeatsTitle(
            [{ title: 'Stock', href: '/spaces/stock' }],
            'Stock',
            normalise,
        ),
        true,
    );
    assert.equal(
        repeatsTitle([{ title: 'Front desk' }], '  front   DESK ', normalise),
        true,
    );
    assert.equal(
        repeatsTitle(
            [{ title: 'Stock', href: '/spaces/stock' }],
            'Inventory',
            normalise,
        ),
        false,
    );
    assert.equal(
        repeatsTitle(
            [
                { title: 'Registration', href: '/registration' },
                { title: 'KPV-00000008' },
            ],
            'KPV-00000008',
            normalise,
        ),
        false,
    );
    assert.equal(repeatsTitle([{ title: 'Stock' }], '', normalise), false);
});

test('UX-22: the shell reads the page h1 after each page change, and the rule gates drawing', () => {
    assert.match(api, /watch\(\(\) => page\.url, readTitle\);/);
    assert.match(api, /onMounted\(readTitle\);/);
    assert.match(
        api,
        /document\s+\.querySelector\('\[data-test="v2-shell"\] h1'\)/,
    );
    assert.match(
        squash(api),
        /!repeatsTitle\(context\.trail\.value, context\.pageTitle\.value\) && !context\.hidden\.value/,
    );
});
