import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// HR-15g locum pay statement (owner, 18 Nov 2026): Classic and Version 2 share one composable and one statement view.
const read = (path) => readFileSync(path, 'utf8');
const composable = read('resources/js/composables/hr/useLocumPayPage.ts');
const view = read('resources/js/components/hr/locum-pay/StatementView.vue');
const pages = {
    classic: read('resources/js/pages/HR/LocumPay.vue'),
    v2: read('resources/js/pages/HR/V2/LocumPay.vue'),
};

test('the V2 page is registered and both pages use the shared composable and statement view', () => {
    assert.match(read('config/v2-pages.php'), /'HR\/LocumPay',/);

    for (const page of Object.values(pages)) {
        assert.match(page, /useLocumPayPage\(props\)/);
        assert.match(page, /<StatementView/);
        // Pages send no requests of their own.
        assert.doesNotMatch(page, /router\.(post|get)\(/);
    }
});

test('every write carries an idempotency key, and decisions carry the lock version', () => {
    for (const url of [
        '/hr/locum-pay/locums/${rateForm.workerId}/rates',
        '/hr/locum-pay/locums/${statement.workerId}/not-worked',
        '/hr/locum-pay/locums/${statement.workerId}/corrections',
        '/hr/locum-pay/locums/${statement.workerId}/paid',
    ]) {
        assert.ok(composable.includes(url), url);
    }

    assert.equal(
        (composable.match(/idempotency_key:/g) ?? []).length,
        9,
        'rate, decide, reject, not worked, count again, old mark, correction, remove correction, paid',
    );
    assert.match(
        composable,
        /rates\/\$\{rate\.id\}\/\$\{action\}`,\s+\{\s+expected_lock_version: rate\.lockVersion,/,
    );
    // Recording payment sends the statement's fingerprint, so a changed statement is refused.
    assert.match(composable, /fingerprint: statement\.fingerprint,/);
    // The paid date starts empty (Daniel).
    assert.match(composable, /paid_on: '',/);
});

test('a reason starts empty, and the statement offers HR controls only when it may be edited', () => {
    assert.match(composable, /reason: '',/);
    assert.match(view, /v-if="canEdit"/);
    assert.match(view, /Not worked/);
    assert.match(view, /Count again/);

    for (const page of Object.values(pages)) {
        assert.match(page, /:can-edit="locumPay\.statement\.canEdit"/);
        // My pay shows the locum's own statement, read only.
        assert.match(
            page,
            /<StatementView\s+:statement="locumPay\.mine\.statement"\s+own\s*\/>/,
        );
    }
});

test('a missing rate reads "Rate missing", never a zero; a paid month says when', () => {
    assert.match(view, /const pending = 'Rate missing';/);
    assert.match(view, /`Paid on \$\{statement\.payment\.paidOnLabel\}`/);

    for (const page of Object.values(pages)) {
        assert.match(page, /'Not paid yet'/);
    }
});

test('confirming a rate and removing a correction ask first; a reason is needed before the action', () => {
    for (const page of Object.values(pages)) {
        assert.match(page, /startConfirm\(\s*row\.waiting,\s*row\.name,?\s*\)/);
        assert.match(page, /the person who enters a rate can never confirm it/);
        assert.match(page, /@remove-correction="startRemoveCorrection"/);
        assert.match(page, /:confirm-disabled="!notWorked\.reason"/);
        assert.equal(
            (page.match(/Pick a reason to continue\./g) ?? []).length,
            2,
        );
        assert.match(page, /Enter an amount and pick a reason to continue\./);
    }
});

test('Record payment is said plainly: KPOne records, never pays, and the month locks', () => {
    for (const page of Object.values(pages)) {
        assert.match(
            page,
            /Record this only after the clinic has paid the locum outside KPOne\. The month is then locked and cannot be undone\./,
        );
        assert.match(page, /:disabled="!locumPay\.statement\.canMarkPaid"/);
        assert.match(page, /Pick the date paid to continue\./);
        assert.match(
            page,
            /A month can be recorded as paid once it has ended\./,
        );
    }
});

test('the statement is a table from 768 px and cards below it; amounts use tabular figures', () => {
    assert.match(view, /class="flex flex-col gap-3 md:hidden"/);
    assert.match(
        view,
        /hidden overflow-x-auto rounded-xl border bg-card md:block/,
    );
    assert.match(view, /<caption class="sr-only">/);
    assert.match(view, /tabular-nums/);
});
