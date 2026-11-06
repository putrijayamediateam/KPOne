import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// UX-12 (design debt DD-43): on the staff page a long work email ran into the Staff number
// column, and dates and times showed as "2026-01-01 through No end date" and "9:16 am".
const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const show = read('resources/js/pages/Staff/Show.vue');
const index = read('resources/js/pages/Staff/Index.vue');

test('UX-12: staff page dates and times use the shared KPOne formats', () => {
    assert.match(
        show,
        /import \{ formatDate, formatDateTime \} from '@\/lib\/presentation';/,
    );
    assert.doesNotMatch(show, /Intl\.DateTimeFormat|dateStyle|timeStyle/);
    assert.doesNotMatch(show, /through/);
    assert.match(show, /date\(assignment\.validFrom\)/);
    assert.match(show, /date\(assignment\.validUntil\)/);
    assert.match(show, /no end date/);
});

test('UX-12: long work emails wrap inside their own column', () => {
    assert.match(
        show,
        /\[overflow-wrap:anywhere\][^>]*>\s*\{\{ emailParts\.local/,
    );
    assert.match(show, />@<wbr \/>\{\{ emailParts\.domain \}\}/);
    assert.match(
        index,
        /\[overflow-wrap:anywhere\][^"]*"\s*>\s*\{\{ member\.email \}\}/,
    );
});
