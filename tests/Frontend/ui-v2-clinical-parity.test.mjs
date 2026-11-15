import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const classic = read('resources/js/pages/Clinical/Show.vue');
const composable = read(
    'resources/js/composables/clinical/useConsultationV2.ts',
);
const page = read('resources/js/pages/Clinical/V2/Show.vue');
const config = read('config/v2-pages.php');
const norm = (value) => value.replace(/\s+/g, '').replace(/[(),;]/g, '');

const classicBody = classic.slice(
    classic.indexOf('const user = computed'),
    classic.indexOf('</script>'),
);
const composableBody = composable.slice(
    composable.indexOf('const user = computed'),
    composable.indexOf('return {'),
);

test('the consultation page logic is an exact copy of Classic (form, drift, guard, hold, recall, treatment call)', () => {
    assert.equal(norm(composableBody), norm(classicBody));
});

test('the V2 page is listed for the V2 gate and keeps focus mode', () => {
    assert.match(config, /'Clinical\/Show'/);
    assert.match(page, /focusMode: true/);
});

test('the endpoints and payload keys are the ones in the shared logic, and the page adds none', () => {
    for (const fragment of [
        '/queue/recall',
        '/queue/treatment-call',
        '/encounter/${action}',
        'queue_lock_version',
        'encounter_lock_version',
        'visit_lock_version',
        'expected_branch_id',
        'idempotency_key',
    ]) {
        assert.ok(composable.includes(fragment), fragment);
    }

    // The only request the page itself makes is the held branch switch, replayed after "Discard changes".
    const calls = [
        ...page.matchAll(
            /router\.(get|post|put|patch|delete|visit)\(\s*'([^']*)'/g,
        ),
    ].map((m) => `${m[1]} ${m[2]}`);

    assert.deepEqual(calls, ['post /branch-context']);
    assert.doesNotMatch(page, /form\.(patch|post)\(/);
});

test('the page uses V2 blocks and the V2 unsaved-work dialog, not the Classic dialog', () => {
    assert.match(page, /UnsavedWorkDialogV2/);
    assert.doesNotMatch(
        page,
        /UnsavedClinicalWorkDialog|OperationalConfirmDialog/,
    );
    assert.match(page, /<V2Tabs/);
    assert.match(page, /<V2StickyBar>/);
    assert.match(page, /<V2Chip/);
});

test('the four Classic Partials are reused unchanged', () => {
    for (const name of [
        'TreatmentPlanPanel',
        'AllergyProblemPanel',
        'ClinicalHistoryPanel',
        'DispensaryAttentionPanel',
    ]) {
        assert.match(page, new RegExp(`@/pages/Clinical/Partials/${name}.vue`));
    }
});

test('the V2 unsaved-work dialog has the Classic props and events; Save and hold is the filled choice', () => {
    const classicDialog = read(
        'resources/js/components/clinical/UnsavedClinicalWorkDialog.vue',
    );
    const v2Dialog = read(
        'resources/js/components/clinical-v2/UnsavedWorkDialogV2.vue',
    );

    for (const fragment of [
        "'save-and-hold': []",
        "'hold-without-saving': []",
        "processingAction: 'confirm' | 'secondary'",
        'unsavedItems: string[]',
    ]) {
        assert.ok(classicDialog.includes(fragment), fragment);
        assert.ok(v2Dialog.includes(fragment), fragment);
    }

    assert.match(v2Dialog, /Save and hold/);
    assert.match(
        v2Dialog,
        /variant="outline"[\s\S]{0,200}?Discard and hold|Discard and hold/,
    );
});

test('Save clinical record is outline at every width, with "You have unsaved changes" beside it (Complete Consultation is the one filled action)', () => {
    assert.doesNotMatch(page, /form\.isDirty \? 'default'/);
    assert.match(page, /'You have unsaved changes'/);
    assert.equal(page.match(/Save clinical record/g)?.length, 2);
    assert.match(
        page,
        /variant="outline"[\s\S]{0,120}:disabled="saveDisabled\(\)"/,
    );
});

test('the Treatment plan Complete button is the Classic one (Partial untouched), so no second page-level filled button is added', () => {
    assert.doesNotMatch(page, /Complete Consultation/);
});

test('save, hold and resume send the Classic payloads (from the shared logic, equal to Classic)', () => {
    for (const source of [classic, composable]) {
        const flat = source.replace(/\s+/g, ' ');

        assert.match(
            flat,
            /form\.patch\( `\/visits\/\$\{encodeURIComponent\(props\.clinical\.visit\.visitNumber\)\}\/encounter`/,
        );
        assert.match(
            flat,
            /router\.post\( `\/visits\/\$\{encodeURIComponent\(props\.clinical\.visit\.visitNumber\)\}\/encounter\/\$\{action\}`/,
        );
        assert.match(
            flat,
            /expected_branch_id: props\.clinical\.branch\.id, visit_lock_version: props\.clinical\.visit\.lockVersion, queue_lock_version: props\.clinical\.queue\.lockVersion, encounter_lock_version: props\.clinical\.encounter\.lockVersion, idempotency_key: action === 'hold' \? props\.clinical\.hold\.holdIdempotencyKey : props\.clinical\.hold\.resumeIdempotencyKey/,
        );
        assert.match(
            flat,
            /expected_branch_id: props\.clinical\.branch\.id, lock_version: props\.clinical\.encounter\.lockVersion, clinical_note: props\.clinical\.encounter\.clinicalNote \?\? '', vitals: vitalsFromProps\(\), diagnoses: diagnosesFromProps\(\)/,
        );
    }
});

test('save-before-hold, the drift rule and the allergy gate stay in the shared logic and the Partials', () => {
    const flat = composable.replace(/\s+/g, ' ');

    assert.match(flat, /const saveAndHold = \(\) => \{/);
    assert.match(flat, /panel\.saveForHold\(proceedToHold/);
    assert.match(
        flat,
        /if \(form\.isDirty\) \{ recordDrifted\.value = true; return; \}/,
    );
    assert.match(
        flat,
        /const requestHold = \(\) => \{ if \(!hasUnsavedWork\.value\)/,
    );
});

test('every way out of the page with unsaved work is guarded: leave guard, branch switch, hold; the shared rule covers browser Back', () => {
    const flatPage = page.replace(/\s+/g, ' ');

    assert.match(
        page,
        /<V2LeaveGuard[\s\S]{0,160}hasUnsavedWork && !form\.processing/,
    );
    assert.match(flatPage, /\.pathname !== '\/branch-context'/);
    assert.match(page, /leaveGuard\.value\?\.ask\(/);
    assert.match(page, /@click="requestHold"/);

    const guard = read('resources/js/composables/useLeaveGuard.ts');

    assert.match(guard, /registerPopStateGuard/);
    assert.match(guard, /beforeunload/);
});

test('the phone toolbar keeps Hold, Resume, Call again and the status; documents and room calls go behind one More menu', () => {
    const menu = read(
        'resources/js/components/clinical-v2/ConsultationMoreMenu.vue',
    );

    assert.match(page, /<ConsultationMoreMenu/);
    assert.match(page, /class="sm:hidden"/);
    assert.equal((page.match(/max-sm:hidden/g) ?? []).length >= 4, true);
    assert.match(menu, /target="_blank"/);
    assert.match(menu, /opens in a new tab/);
    assert.match(menu, /min-h-11/);
    assert.match(page, /@room="callRoom"/);
});

test('the Treatment plan / Patient history tabs name their panels', () => {
    assert.match(page, /id-prefix="consultation"/);
    assert.match(page, /role="tabpanel"/);
    assert.match(page, /aria-labelledby="consultation-tab-treatment"/);
    assert.match(page, /aria-labelledby="consultation-tab-history"/);
});

test('the unsaved-work sentence says what each choice does, and Discard and hold is a destructive outline, not a filled red button', () => {
    const dialog = read(
        'resources/js/components/clinical-v2/UnsavedWorkDialogV2.vue',
    );

    assert.match(
        dialog,
        /Save and hold keeps your text\. Discard and hold deletes it\./,
    );
    assert.match(dialog, /variant="outline"[\s\S]{0,200}text-destructive/);
    assert.doesNotMatch(dialog, /variant="destructive"/);
});

test('vitals keep numbers or empty text, as the Classic native number input does', () => {
    assert.match(
        page.replace(/\s+/g, ' '),
        /value === '' \? '' : Number\(value\)/,
    );
});

test('a failed room call shows its error on phones too (the inline group is hidden there)', () => {
    const flat = page.replace(/\s+/g, ' ');

    assert.match(
        flat,
        /v-if="recallError && treatmentError" class="w-full max-w-prose sm:hidden"/,
    );
});

test('the held branch-switch replay is one-shot, so a cancelled visit cannot leave the guard off, and nothing is reset before the POST', () => {
    const flat = page.replace(/\s+/g, ' ');

    assert.match(flat, /if \(replaying\) \{ replaying = false; return; \}/);
    assert.doesNotMatch(flat, /onFinish: \(\) => \(replaying = false\)/);
    assert.doesNotMatch(flat, /@discard=/);
});

test('while the visit is held the clinical record and the treatment plan are read-only (disabled fieldsets), so no unsaved text can exist then', () => {
    const flat = page.replace(/\s+/g, ' ');

    assert.match(
        flat,
        /<fieldset :disabled="clinical\.hold\.isHeld" class="[^"]*" data-testid="consultation-record-fields"/,
    );
    assert.match(
        flat,
        /<fieldset :disabled="clinical\.hold\.isHeld" class="[^"]*" data-testid="consultation-treatment-fields"/,
    );
    // The Resume button and the held notice are outside both fieldsets.
    const record = flat.slice(
        flat.indexOf('consultation-record-fields'),
        flat.indexOf('</fieldset>', flat.indexOf('consultation-record-fields')),
    );

    assert.doesNotMatch(record, /changeHoldState\('resume'\)/);
    assert.doesNotMatch(record, /HeldCallNext/);
});

test('the two-column workspace starts at 1280 px, so the right column stacks at 1024', () => {
    assert.match(page, /xl:grid-cols-\[minmax\(0,55fr\)_minmax\(0,45fr\)\]/);
    assert.doesNotMatch(page, /lg:grid-cols-\[minmax\(0,55fr\)/);
});
