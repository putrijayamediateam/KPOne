import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (p) =>
    readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');
const classic = read('resources/js/pages/Patient/Show.vue');
const page = read('resources/js/pages/Patient/V2/Show.vue');
const mergeClassic = read(
    'resources/js/components/patient/PatientMergePanel.vue',
);
const merge = read('resources/js/components/patient-v2/PatientMergeCards.vue');
const lib = read('resources/js/lib/patient-v2.ts');

test('the V2 page keeps the Classic props and permission flags', () => {
    for (const piece of [
        'patient: PatientDetail',
        'merge?: PatientMergePanelData',
        'history?: PatientChangeHistoryData | null',
        'mergedGuardian?:',
        'fromPatientNumber: string',
    ]) {
        assert.ok(classic.includes(piece), `Classic lacks ${piece}`);
        assert.ok(page.includes(piece), `V2 lacks ${piece}`);
    }

    for (const flag of [
        'patient.can.update',
        'patient.can.manageIdentifiers',
    ]) {
        assert.ok(classic.includes(flag) && page.includes(flag), flag);
    }

    assert.ok(page.includes('v-if="history"'));
    assert.ok(page.includes('v-if="merge"'));
    assert.ok(/isCurrent/.test(page));
});

test('identifier routes and fields match Classic', () => {
    for (const piece of [
        "'/identifiers'",
        "'/identifiers/' +",
        "'/retire'",
        'identifier_type',
        'issuing_country_code',
        'identifierForm.patch(url, options)',
        'identifierForm.post(url, options)',
    ]) {
        assert.ok(classic.includes(piece), `Classic lacks ${piece}`);
        assert.ok(page.includes(piece), `V2 lacks ${piece}`);
    }
});

test('the typed identifier is cleared on close and the full value is not shown in Correct', () => {
    assert.ok(
        /identifierForm\.reset\(\);\s*identifierForm\.clearErrors\(\)/.test(
            page,
        ),
    );
    assert.ok(page.includes('maskedIdentifier(correcting)'));
    assert.ok(page.includes('autocomplete="off"'));
    assert.ok(lib.includes('******-**-'));
});

test('no native confirm or select and one filled button outside dialogs', () => {
    const code = (page + merge)
        .split('\n')
        .filter((l) => !/^\s*(\*|\/\/|\/\*)/.test(l))
        .join('\n');
    assert.ok(!/(^|[^\w@'.])confirm\(/.test(code));
    assert.ok(!/<select\b/.test(page + merge));
    // The only bg-primary button on the page is Edit patient (and the dialog confirms are inside V2Dialog).
    assert.equal((page.match(/\bbg-primary\b(?!\/)/g) ?? []).length, 1);
    assert.ok(!/\bbg-primary\b(?!\/)/.test(merge));
});

test('the merge cards use the same endpoints and payload keys as Classic', () => {
    for (const piece of [
        '/merge-compare?other_patient_number=',
        "'/merge-requests'",
        '`/patient-merges/${request.publicId}/${action}`',
        "from: 'patient'",
        'patient_number: props.patientNumber',
        'kept_lock_version',
        'other_lock_version',
        'idempotency_key',
        'expected_branch_id',
        'lock_version: request.lockVersion',
    ]) {
        assert.ok(mergeClassic.includes(piece), `Classic lacks ${piece}`);
        assert.ok(merge.includes(piece), `V2 lacks ${piece}`);
    }

    for (const gate of [
        'merge.canPropose',
        'request.canDecide',
        'request.canWithdraw',
        'comparison.blockers.length',
    ]) {
        assert.ok(mergeClassic.includes(gate) && merge.includes(gate), gate);
    }
});

test('every merge action asks in a dialog', () => {
    for (const label of [
        'Send for approval',
        'Approve',
        'Reject',
        'Withdraw',
    ]) {
        assert.ok(merge.includes(label), label);
    }

    assert.ok(merge.includes('<V2Dialog'));
});

test('the page is registered, Classic is untouched and WhatsApp keeps its label by default', () => {
    assert.ok(read('config/v2-pages.php').includes("'Patient/Show'"));
    const diff = spawnSync(
        'git',
        [
            'diff',
            '--quiet',
            'origin/main',
            '--',
            'resources/js/pages/Patient/Show.vue',
            'resources/js/components/patient/PatientMergePanel.vue',
            'resources/js/components/patient/PatientChangeHistory.vue',
        ],
        { cwd: new URL('../../', import.meta.url) },
    );
    assert.notEqual(diff.status, 1, 'a Classic patient file changed');
    assert.ok(
        read('resources/js/components/patient/WhatsAppLink.vue').includes(
            "label ?? 'Open WhatsApp chat'",
        ),
    );
    assert.ok(
        page.includes('`Open WhatsApp chat with ${patient.identity.fullName}`'),
    );
});

test('no console output and missing values are said in words', () => {
    assert.ok(!/console\./.test(page + merge));

    for (const words of [
        'Phone not recorded',
        'No email',
        'No guardian contact',
        'No address recorded',
    ]) {
        assert.ok(page.includes(words), words);
    }
});

test('failures keep their dialog open with a message, and Retire cannot be pressed twice', () => {
    assert.ok(merge.includes('onError'));
    assert.ok(merge.includes('dialogError'));
    assert.ok(/onSuccess: \(\) => \{\s*confirming\.value = null;/.test(merge));
    assert.ok(page.includes(':busy="retireBusy"'));
    assert.ok(/if \(retireBusy\.value\) \{\s*return;/.test(page));
});

test('only the Classic identifier messages are shown, so a server message cannot echo the number', () => {
    const fn = page.slice(
        page.indexOf('const identifierError'),
        page.indexOf('const closeIdentifierDialog'),
    );
    assert.ok(fn.includes('.value') && fn.includes('.identifiers'));
    assert.ok(!fn.includes('Object.values'));
});

test('the pending request line keeps a space between the verb and the KP number', () => {
    assert.ok(/\{\{ ' '\s*\}\}<span class="font-mono[^"]*">/.test(merge));
});

test('no eyebrow repeats the crumb, and the identifier dialog hints the format without echoing', () => {
    assert.ok(!page.includes(':eyebrow='));
    assert.ok(page.includes('For example 900101-01-5555'));
    assert.ok(page.includes("'Passport number'"));
});

test('merge dialogs say what will happen, with unbreakable KP numbers', () => {
    assert.ok(merge.includes("replaceAll('-', '\\u2011')"));
    assert.ok(merge.includes('will be hidden and kept as a merged record'));
    assert.ok(merge.includes('keep their own KP number'));
    assert.ok(merge.includes('You can undo this later with another approval'));
    assert.ok(merge.includes('stay exactly as they are'));
    assert.ok(
        merge.includes('becomes visible again as its own record') ||
            merge.includes('become visible again as its own record'),
    );
    assert.ok(!merge.includes('This takes effect straight away'));
    assert.ok(!/border-warning\/40 bg-warning\/10/.test(merge));
});

test('the rail starts at 1280 px and its content is also the last card below it', () => {
    assert.ok(page.includes('rail-from="xl"'));
    assert.ok(page.includes('<template #rail>'));
    assert.ok(/xl:hidden/.test(page));
    assert.equal((page.match(/Record details/g) ?? []).length >= 2, true);
});
