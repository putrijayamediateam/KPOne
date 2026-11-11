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
    assert.match(print, /counter\(page\) ' of ' counter\(pages\)/);
});

test('STK-24: the check sends the newest movement seen and a retry key, and asks for a reason only when the count differs', () => {
    const page = read('ControlledRegisterShow');
    assert.match(page, /seen_movement_id: props\.register\.newestMovementId/);
    assert.match(page, /idempotency_key: form\.key/);
    assert.match(page, /reason: differs\.value \? form\.reason : null/);
    assert.match(page, /<template v-if="differs">/);
    assert.match(page, /v-if="register\.checkBlockedReason"/);
    // No patient or prescriber column on the register.
    assert.doesNotMatch(page, /[Pp]atient|[Pp]rescriber/);
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
