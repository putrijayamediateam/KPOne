import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// One Clock in, step 3 (owner, 18 Nov 2026): Attendance and My roster link to Me, where every press happens.
const read = (path) => readFileSync(path, 'utf8');
const pages = {
    classic: read('resources/js/pages/HR/Attendance.vue'),
};

for (const [name, page] of Object.entries(pages)) {
    test(`${name}: Clock out on Me shows only with a branch; without one, the page points to a correction`, () => {
        assert.match(
            page,
            /attendance\.my\.state === 'in' &&\s+attendance\.my\.branch/,
        );
        assert.match(page, /data-test="clock-out-no-branch"/);
        assert.match(
            page.replace(/\s+/g, ' '),
            /You are clocked in, but you have no branch to work at now, so Me cannot clock you out\. Ask HR to add your clock-out time as a correction\./,
        );
    });

    test(`${name}: the arrow is hidden from screen readers, so the name is the visible text`, () => {
        assert.equal(
            page.match(/<ArrowRight class="size-4" aria-hidden="true"/g)
                ?.length,
            2,
        );
    });
}
