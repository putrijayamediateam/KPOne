import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// Classic only, CO-5: direct Classic pins for logic that was pinned only through equality with a deleted V2 copy
// (Nadiah's review). Every string below is read from the Classic file today.
const read = (p) =>
    readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');

// From bv2-2-billing-v2-dialogs ('Classic does not use the Version 2 copy'), plus the Classic post, labels and rules.
test('Classic billing dialogs import no V2 code and keep their post, labels and disabled rules', () => {
    const adjustment = read(
        'resources/js/components/billing/InvoiceAdjustmentDialog.vue',
    );
    const completion = read(
        'resources/js/components/billing/BillingCompletionDialog.vue',
    );

    for (const classic of [adjustment, completion]) {
        assert.doesNotMatch(classic, /V2Dialog|\/v2\//);
    }

    const flat = completion.replace(/\s+/g, ' ');
    assert.ok(
        flat.includes(
            'router.post( `/visits/${encodeURIComponent(props.visitNumber)}/billing/complete`',
        ),
    );
    assert.ok(flat.includes('expected_branch_id: props.branchId,'));
    assert.ok(flat.includes('lock_version: props.invoiceLockVersion,'));
    assert.ok(flat.includes('visit_lock_version: props.visitLockVersion,'));
    assert.match(completion, /confirm-label="Complete Visitation"/);
    assert.match(completion, /processing-label="Completing…"/);

    assert.match(
        adjustment.replace(/\s+/g, ' '),
        /:disabled=" busy \|\| \(above && \(!controls\?\.override \|\| !form\.confirmed\)\) "/,
    );
    assert.match(adjustment, />Reason \(required\)<textarea/);
    assert.match(adjustment, /An authorised override holder is required\./);
});

// From v2-dispensary-otc-allergy-form ('same props, emits, fields and request as Classic').
test('Classic OTC allergy form keeps its request, lock versions and preserveState', () => {
    const form = read(
        'resources/js/pages/Dispensary/Partials/OtcAllergyRecordForm.vue',
    );

    for (const piece of [
        '.post(`/dispensary/${props.casePublicId}/otc-allergy-records`',
        'case_lock_version: props.caseLockVersion,',
        'profile_lock_version: props.profileVersion,',
        'preserveState: true,',
    ]) {
        assert.ok(form.includes(piece), piece);
    }
});

// From v2-dispensary-labels (script, markup and label CSS were pinned byte for byte to the V2 copy).
test('Classic drug labels page keeps its print, label markup and DSP-10 label CSS', () => {
    const labels = read('resources/js/pages/Dispensary/Labels.vue');

    assert.match(labels, /window\.print\(\);/);
    assert.match(
        labels,
        /<section v-if="!labels\.blocked\.length" aria-label="Medicine labels">/,
    );
    assert.ok(
        labels.includes(
            '`@page { size: ${labels.pageSize.width}mm ${labels.pageSize.height}mm; margin: 0; }`',
        ),
    );
    assert.ok(labels.includes("/* DSP-10: the clinic's landscape label"));
});
