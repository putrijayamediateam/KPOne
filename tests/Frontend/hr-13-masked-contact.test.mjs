import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const page = readFileSync(
    new URL('../../resources/js/pages/HR/Applications.vue', import.meta.url),
    'utf8',
);

test("an applicant's phone and email are masked, with an audited Show", () => {
    assert.doesNotMatch(
        page,
        /viewing\.phone\b(?!Mask)|viewing\.email\b(?!Mask)/,
    );

    for (const field of ['phone', 'email']) {
        assert.match(
            page,
            new RegExp(
                `<MaskedValue[\\s\\S]{0,600}:mask="viewing\\.${field}Mask"[\\s\\S]{0,600}show-field="${field}"[\\s\\S]{0,300}/hr/applications/\\$\\{viewing\\.id\\}/show-contact`,
            ),
        );
    }

    assert.match(page, /:reset-key="viewing\.id"/);
});
