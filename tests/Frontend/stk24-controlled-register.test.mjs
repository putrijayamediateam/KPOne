import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (name) =>
    readFileSync(
        new URL(
            `../../resources/js/pages/Inventory/${name}.vue`,
            import.meta.url,
        ),
        'utf8',
    );

// STK-24: the controlled-drug register is a DRAFT pending pharmacist review, on every screen and printed page.
test('STK-24: every register screen and every printed page carries the draft banner', () => {
    for (const name of ['ControlledRegister', 'ControlledRegisterShow']) {
        assert.match(
            read(name),
            /Draft register — pending pharmacist review\. Not a statutory register\s+until a pharmacist approves its format\./,
            name,
        );
    }

    const print = read('ControlledRegisterPrint');
    assert.match(print, /DRAFT REGISTER — PENDING PHARMACIST REVIEW/);
    assert.match(
        print,
        /@page cd-register \{[^}]*@top-center \{\s*content: 'DRAFT REGISTER — PENDING PHARMACIST REVIEW';/,
    );
    assert.match(print, /page: cd-register;/);
    // The banner also sits in the table head, which repeats on every printed page in every browser.
    assert.match(
        print,
        /<thead>\s*<!--[^>]*-->\s*<tr class="cd-repeat-banner">\s*<th colspan="9">\s*DRAFT REGISTER — PENDING PHARMACIST REVIEW/,
    );
    // Pressing Print records it with a POST before printing; opening the page records nothing.
    assert.match(print, /method: 'POST'/);
    assert.match(
        print,
        /if \(!response\.ok\) \{\s*throw new Error\('refused'\);\s*\}\s*window\.print\(\);/,
    );
    // Printed without the app shell, so the register starts on page 1.
    assert.match(
        readFileSync(
            new URL('../../resources/js/app.ts', import.meta.url),
            'utf8',
        ),
        /case name === 'Inventory\/ControlledRegisterPrint':/,
    );
    assert.match(print, /counter\(page\) ' of ' counter\(pages\)/);
});

test('STK-24: the check sends the newest movement seen and a retry key, and asks for a reason only when the count differs', () => {
    const page = read('ControlledRegisterShow');
    assert.match(page, /seen_movement_id: props\.register\.newestMovementId/);
    assert.match(page, /idempotency_key: form\.key/);
    assert.match(
        page,
        /seen_check_sequence: props\.register\.newestCheckSequence/,
    );
    assert.match(page, /Do not enter patient or prescriber details\./);
    // Daniel: full-width 44 px Record check on phones, 36 px from 1024 px; the amber note stays apart from errors.
    assert.match(page, /class="min-h-11 w-full lg:min-h-9 lg:w-auto"/);
    assert.match(page, /data-testid="cd-differs-note"/);
    assert.match(page, /Showing \{\{ register\.lines\.rows\.length \}\} of/);
    assert.match(page, /reason: differs\.value \? form\.reason : null/);
    assert.match(page, /<template v-if="differs">/);
    assert.match(page, /v-if="register\.checkBlockedReason"/);
    // No patient or prescriber column on the register.
    // (The note's hint tells staff not to type them; no field or column carries them.)
    assert.doesNotMatch(
        page,
        /patient\w*:|prescriber\w*:|>\s*Patient|>\s*Prescriber/i,
    );
    assert.doesNotMatch(
        read('ControlledRegisterPrint'),
        />\s*Patient|>\s*Prescriber/,
    );
});

test('STK-24: the Inventory task link shows only for register viewers', () => {
    const page = read('Index');
    assert.match(
        page,
        /href="\/inventory\/controlled-register"\s+variant="outline"\s+v-if="canViewControlledRegister"/,
    );
    assert.match(
        page,
        /includes\(\s*'inventory\.controlled_register\.view\.branch',\s*\)/,
    );
});
