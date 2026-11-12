import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

// TEAM.md section 7 hardening (an HR-3a known limit): a successful Clock in or Clock out was read twice, once by the
// toast and once by the page's own polite live region. The region now stays quiet when the same response carries a
// toast, and still reads a state change that arrives with no toast.
const source = readFileSync(
    new URL('../../resources/js/pages/HR/Attendance.vue', import.meta.url),
    'utf8',
);
const watcher = source.slice(
    source.indexOf('const announcement'),
    source.indexOf('// ---- Clock in and out'),
);

test('the live region stays quiet when the response carries a toast', () => {
    const guard = watcher.search(/\.flash\s*\?\.toast/);
    assert.ok(guard > 0, 'the watcher checks for a flashed toast');
    assert.ok(
        guard < watcher.indexOf("'Clocked in.'"),
        'before it writes the announcement',
    );
    assert.match(
        watcher.slice(guard, guard + 160),
        /announcement\.value = '';\s+return;/,
    );
});

test('a state change with no toast is still announced, and the page keeps one live region', () => {
    assert.match(
        watcher,
        /my\.state === 'in'\s+\? String\(label \?\? 'Clocked in\.'\)/,
    );
    assert.equal((source.match(/aria-live=/g) ?? []).length, 1);
});
