import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileTemplate, parse } from '@vue/compiler-sfc';

// HR V2 Job applications: the Version 2 page uses the same composable as Classic, so both send the same requests.
const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const source = read('resources/js/pages/HR/V2/Applications.vue');
const classic = read('resources/js/pages/HR/Applications.vue');
const composable = read('resources/js/composables/hr/useApplicationsPage.ts');
const template = parse(source).descriptor.template.content;
const script = parse(source).descriptor.scriptSetup.content;
const flat = template.replace(/\s+/g, ' ');
// Prettier pads long attribute values with spaces inside the quotes; this form removes that padding.
const tight = flat.replace(/=" /g, '="').replace(/ "/g, '"');

test('the V2 page compiles, is registered, and shares the composable with Classic', () => {
    assert.deepEqual(
        compileTemplate({
            id: 'hr-v2-applications',
            source: template,
            filename: 'Applications.vue',
        }).errors,
        [],
    );
    assert.match(read('config/v2-pages.php'), /'HR\/Applications',/);
    assert.match(script, /useApplicationsPage\(props\)/);
    assert.match(classic, /useApplicationsPage\(props\)/);
    // Every request comes from the composable: the V2 page sends none of its own.
    assert.doesNotMatch(script, /router\.(get|post|put|patch|delete)\(/);
    assert.doesNotMatch(script, /fetch\(|axios|useForm|crypto\.randomUUID/);
    assert.doesNotMatch(source, /localStorage|sessionStorage|v-html/);

    for (const url of [
        '/hr/applications/${application.id}/move',
        '/hr/applications/${application.id}/screening',
        '/hr/applications/${viewing.value.id}/interview/${path}',
    ]) {
        assert.ok(composable.includes(url), url);
    }
});

test('the Classic template did not change: Classic still renders its own markup', () => {
    assert.match(
        classic,
        /<PageHeader\s+eyebrow="KPOne HR"\s+title="Job applications"/,
    );
    assert.doesNotMatch(classic, /V2WorkLayout|V2Dialog/);
});

test('masked contact stays masked: the same audited Show component and URL as Classic, no value in props, nothing prefetched', () => {
    assert.equal((flat.match(/<MaskedValue/g) ?? []).length, 2);
    assert.match(flat, /:mask="viewing\.phoneMask"/);
    assert.match(flat, /:mask="viewing\.emailMask"/);
    assert.equal(
        (
            flat.match(
                /:url="`\/hr\/applications\/\$\{viewing\.id\}\/show-contact`"/g,
            ) ?? []
        ).length,
        2,
    );
    assert.match(flat, /show-field="phone"/);
    assert.match(flat, /show-field="email"/);
    assert.match(flat, /:reset-key="viewing\.id"/);
    // The page never reads a real phone or email value.
    assert.doesNotMatch(template, /viewing\.(phone|email)\b(?!Mask)/);
    assert.doesNotMatch(script, /onMounted|new Image|rel="prefetch"/);
});

test('a CV opens only through its audited link, each open recorded, and no document loads ahead', () => {
    assert.match(flat, /:href="`\/hr\/applications\/\$\{viewing\.id\}\/cv`"/);
    assert.match(flat, /Each open of a CV is recorded\./);
    assert.match(
        flat,
        /:href="`\/hr\/applications\/interview-documents\/\$\{doc\.id\}`"/,
    );
    assert.doesNotMatch(template, /<iframe|<embed|<object|<img|<link\b/);
});

test("Daniel: the stage's own action is the one filled button; Reject and the closing moves are red; the rest are outline", () => {
    // Filled: Add a position (positions tab), Pass (screening), the first forward move, and a Save only while it has changes.
    const used = template.match(/:class="[^"]*primaryButton[^"]*"/g) ?? [];
    assert.ok(used.length >= 4);
    assert.match(
        flat,
        /:class="primaryButton" :disabled="screening\.processing" data-test="screening-pass"/,
    );
    assert.match(
        flat,
        /:class="dangerButton" :disabled="screening\.processing" data-test="screening-reject"/,
    );
    assert.match(
        script,
        /if \(viewing\.value\?\.status === 'phone_screening'\) \{\s*return null;/,
    );
    assert.match(
        tight,
        /closing\.includes\(step\.value\) && step\.value !== 'passed'/,
    );
    assert.match(flat, /:class="retentionDirty \? primaryButton : button"/);
});

test('Daniel: every confirmation is a V2 dialog; red is for reject, cancel and remove; reasons start empty and the red button waits', () => {
    assert.doesNotMatch(source, /OperationalConfirmDialog|<Sheet/);
    assert.match(script, /'Pick a reason to continue\.'/);
    assert.match(flat, /:confirm-disabled="rejectMissing !== ''"/);
    assert.match(flat, /:confirm-disabled="cancelMissing !== ''"/);
    assert.match(flat, /:model-value="rejecting\.reason \|\| null"/);
    assert.match(flat, /:model-value="interviewAction\.reason \|\| null"/);
    assert.match(
        composable,
        /Object\.assign\(rejecting, \{ open: true, reason: '' \}\)/,
    );
    assert.equal((flat.match(/tone="danger"/g) ?? []).length, 3);
    assert.match(
        flat,
        /:tone="moving\.to === 'passed' \? 'default' : 'danger'"/,
    );
    assert.match(flat, /title="Reject after the phone call\?"/);
    assert.match(flat, /title="Cancel this interview\?"/);
});

test('the wording Classic shows is kept for the rules a person acts on', () => {
    const classicFlat = classic
        .slice(classic.indexOf('<template>'))
        .replace(/\s+/g, ' ');

    for (const phrase of [
        'Applications sent from the public form, the positions shown on it, and how long applications are kept. Turning an applicant into staff stays with the account administrator.',
        'This closes the application. A closed application cannot be moved again, and the retention period starts from today.',
        'No offer is made in KPOne. When they accept, ask the account administrator for a staff account.',
        'Each open of a CV is recorded.',
        'The slot is freed, the application goes back to Scheduling and the candidate is asked for three new times.',
        'Only HR sees it. Do not note health details or anything about pay.',
        'The candidate is told the date, time and branch.',
        'Candidates can no longer download it, and its file is deleted. The record that it existed and who downloaded it is kept.',
    ]) {
        const text = phrase.replace(/\s+/g, ' ');
        assert.ok(classicFlat.includes(text), `classic: ${text}`);
        assert.ok((flat + tight).includes(text), `v2: ${text}`);
    }
});

test('the invitation link is shown once with Copy, and the staff-account and pool wording is kept', () => {
    assert.match(flat, /data-test="pool-invitation-link"/);
    assert.match(
        flat,
        /shown once\. Give it only to the candidate, and do not keep it\./,
    );
    assert.match(flat, /:disabled="!invitePick\[member\.id\]"/);
    assert.match(flat, /Choose a position first\./);
    assert.match(flat, /data-test="application-account-ask"/);
});

test('files use the file chooser with a spoken state line, never the bare file control', () => {
    assert.match(template, /<FileChooseButton/);
    assert.match(template, /status-id="v2-interview-doc-file-status"/);
    assert.doesNotMatch(template, /type="file"/);
});

test('the settings Saves are filled only while they have changes, each with "Changes not saved yet."', () => {
    for (const dirty of ['retentionDirty', 'poolDaysDirty', 'checklistDirty']) {
        assert.ok(
            flat.includes(`:class="${dirty} ? primaryButton : button"`),
            dirty,
        );
        assert.ok(flat.includes(`v-if="${dirty}"`), dirty);
    }

    assert.equal((flat.match(/Changes not saved yet\./g) ?? []).length, 3);
});
