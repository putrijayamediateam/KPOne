import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// OH-06 (UAT investigation, preview a29a3ad): a doctor reported the clinical
// note "disappearing" after Hold -> (hold another patient) -> Resume, and
// again after Complete Consultation. Backend investigation
// (tests/Feature/Clinical/OH06ClinicalNoteHoldResumeTest.php) proved the note
// is never lost or overwritten in the database or in the Inertia detail
// projection through any of these actions - the defect was frontend-only:
// `changeHoldState('hold' | 'resume')` uses `preserveState: true` (by design,
// so a Hold/Resume click never disturbs a doctor's in-progress edits), which
// means the component is never remounted by those actions, and nothing
// re-seeded the local `form` (a `useForm()` instance seeded once from props
// at setup) from the fresh `clinical` prop that DOES arrive with the
// hold/resume response.
//
// The fix adds a `watch()` on `clinical.encounter.lockVersion` that adopts
// the fresh clinical_note/vitals/diagnoses/lock_version together, as a single
// atomic operation, but ONLY while the form has no unsaved edits. A dirty
// form is never overwritten and its lock_version is never advanced; instead
// a visible "record drifted" banner is shown, and the next Save is left to
// fail safely at the backend (stale lock_version -> rejected), rather than
// silently overwriting whatever changed elsewhere.
const source = readFileSync(
    new URL('../../resources/js/pages/Clinical/Show.vue', import.meta.url),
    'utf8',
);

test('imports a reactivity primitive capable of watching prop changes', () => {
    assert.match(
        source,
        /import\s*\{[^}]*\bwatch\b[^}]*\}\s*from\s*'vue'/,
        'Show.vue does not import `watch` from vue.',
    );
});

test('watches clinical.encounter.lockVersion, the single signal that fires whenever note, vitals or diagnoses change', () => {
    assert.match(
        source,
        /watch\(\s*\(\)\s*=>\s*props\.clinical\.encounter\.lockVersion/,
        'No watcher on clinical.encounter.lockVersion was found.',
    );
});

test('a clean form adopts clinical_note, vitals, diagnoses and lock_version together, in one place', () => {
    // All four must be reassigned from fresh props inside the same function,
    // so lock_version can never move without the content it guards moving
    // with it in the same operation.
    const fn = source.match(
        /const adoptLatestRecord = \(\) => \{([\s\S]*?)\n\};/,
    );
    assert.ok(fn, 'No single adoptLatestRecord()-style resync function was found.');
    const body = fn[1];
    assert.match(
        body,
        /form\.clinical_note\s*=\s*props\.clinical\.encounter\.clinicalNote/,
        'clinical_note is not resynced from the fresh prop.',
    );
    assert.match(
        body,
        /form\.vitals\s*=\s*vitalsFromProps\(\)/,
        'vitals is not resynced from the fresh prop.',
    );
    assert.match(
        body,
        /form\.diagnoses\s*=\s*diagnosesFromProps\(\)/,
        'diagnoses is not resynced from the fresh prop.',
    );
    assert.match(
        body,
        /form\.lock_version\s*=\s*props\.clinical\.encounter\.lockVersion/,
        'lock_version is not resynced in the same operation as the content.',
    );
});

test('a dirty form is never silently overwritten, and its lock_version is never advanced', () => {
    const watcher = source.match(/watch\(\s*\(\)\s*=>\s*props\.clinical\.encounter\.lockVersion,\s*\(\)\s*=>\s*\{([\s\S]*?)\n\s*\},?\n\);/);
    assert.ok(watcher, 'Could not locate the lockVersion watcher body.');
    const body = watcher[1];
    assert.match(
        body,
        /if\s*\(\s*form\.isDirty\s*\)\s*\{/,
        'The watcher does not guard on form.isDirty before resyncing.',
    );
    // The dirty branch must return before reaching any adoptLatestRecord()/
    // form.lock_version assignment - i.e. the guard short-circuits.
    const dirtyBranch = body.slice(body.indexOf('if'), body.indexOf('return') + 'return'.length + 1);
    assert.doesNotMatch(
        dirtyBranch,
        /form\.lock_version\s*=/,
        'lock_version must not be touched while the form is dirty.',
    );
    assert.doesNotMatch(
        dirtyBranch,
        /form\.clinical_note\s*=/,
        'clinical_note must not be overwritten while the form is dirty.',
    );
});

test('shows a visible drift warning with an explicit reload action, not a silent failure', () => {
    assert.match(
        source,
        /recordDrifted/,
        'No drift-detected flag was found for the warning banner to key off.',
    );
    assert.match(
        source,
        /v-if="recordDrifted"/,
        'No banner is conditionally rendered on the drift flag.',
    );
    assert.match(
        source,
        /@click="adoptLatestRecord"/,
        'No explicit reload action is wired to re-sync the form on demand.',
    );
});

test('Hold and Resume keep preserveState: true (the fix must not force a full remount)', () => {
    const changeHoldState = source.match(
        /const changeHoldState = [\s\S]*?\n\};/,
    );
    assert.ok(changeHoldState, 'Could not locate changeHoldState().');
    assert.match(changeHoldState[0], /preserveState:\s*true/);
});

test('read-only historical views display clinicalNote straight from props, never through a local form', () => {
    const historyShow = readFileSync(
        new URL('../../resources/js/pages/Clinical/HistoryShow.vue', import.meta.url),
        'utf8',
    );
    const historyPanel = readFileSync(
        new URL(
            '../../resources/js/pages/Clinical/Partials/ClinicalHistoryPanel.vue',
            import.meta.url,
        ),
        'utf8',
    );
    assert.doesNotMatch(historyShow, /useForm/);
    assert.doesNotMatch(historyPanel, /useForm/);
    assert.match(historyShow, /historical\.encounter\.clinicalNote/);
    assert.match(historyPanel, /selected\.encounter\.clinicalNote/);
});
