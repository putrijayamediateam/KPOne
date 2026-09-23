import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// OH-06c: pressing On Hold with unsaved clinical work (this form's own
// note/vitals/diagnoses, or the sibling Treatment Plan draft) must not
// silently strand it - clinical save is disabled for as long as the
// consultation stays held, so Reload (OH-06's own drift-recovery action) was
// the only way out, and it discards exactly what the guard is meant to
// protect. This guard is a separate concern from OH-06's drift banner
// (genuine concurrent edits from elsewhere), which stays in place unchanged.
const show = readFileSync(
    new URL('../../resources/js/pages/Clinical/Show.vue', import.meta.url),
    'utf8',
);
const dialog = readFileSync(
    new URL(
        '../../resources/js/components/clinical/UnsavedClinicalWorkDialog.vue',
        import.meta.url,
    ),
    'utf8',
);
const confirmDialog = readFileSync(
    new URL(
        '../../resources/js/components/ui/OperationalConfirmDialog.vue',
        import.meta.url,
    ),
    'utf8',
);
const treatmentPlanPanel = readFileSync(
    new URL(
        '../../resources/js/pages/Clinical/Partials/TreatmentPlanPanel.vue',
        import.meta.url,
    ),
    'utf8',
);

test('reuses the shared confirmation dialog shell, never window.confirm', () => {
    assert.match(show, /import UnsavedClinicalWorkDialog from/);
    assert.match(
        dialog,
        /import OperationalConfirmDialog from '@\/components\/ui\/OperationalConfirmDialog\.vue'/,
    );
    assert.doesNotMatch(show, /window\.confirm/);
    assert.doesNotMatch(dialog, /window\.confirm/);
});

test('(1) a clean form holds directly on click, no dialog', () => {
    const fn = show.match(/const requestHold = \(\) => \{([\s\S]*?)\n\};/);
    assert.ok(fn, 'requestHold() was not found.');
    assert.match(
        fn[1],
        /if\s*\(\s*!hasUnsavedWork\.value\s*\)\s*\{[\s\S]*?changeHoldState\('hold'\)/,
        "A clean form must call changeHoldState('hold') directly, without opening the dialog.",
    );
    assert.match(show, /@click="requestHold"/);
});

test('(2 & 7) the guard is keyed off both this form and the treatment plan draft', () => {
    assert.match(
        show,
        /const hasUnsavedWork = computed\(\s*\(\)\s*=>\s*form\.isDirty \|\| treatmentPlanDirty\.value,?\s*\);/,
    );
    assert.match(
        show,
        /const treatmentPlanDirty = computed\(\s*\(\)\s*=>\s*treatmentPlanPanelRef\.value\?\.isDirty\(\)/,
    );
    const fn = show.match(/const requestHold = \(\) => \{([\s\S]*?)\n\};/);
    assert.match(
        fn[1],
        /unsavedWorkOpen\.value = true/,
        'A dirty form (note/vitals/diagnoses, or the plan) must open the dialog rather than holding.',
    );
});

test('the dialog names what is unsaved', () => {
    assert.match(show, /items\.push\('clinical note'\)/);
    assert.match(show, /items\.push\('vitals'\)/);
    assert.match(show, /items\.push\('diagnoses'\)/);
    assert.match(show, /items\.push\('treatment plan'\)/);
    assert.match(dialog, /unsavedItems/);
    assert.match(dialog, /not saved yet/);
});

test('(3) "Save and hold" saves first and only holds on success, including a dirty treatment plan draft', () => {
    const fn = show.match(/const saveAndHold = \(\) => \{([\s\S]*?)\n\};\n/);
    assert.ok(fn, 'saveAndHold() was not found.');
    const body = fn[1];
    assert.match(body, /form\.patch\(/, 'The clinical record must be saved.');
    assert.match(
        body,
        /onSuccess:\s*\(\)\s*=>\s*\{[\s\S]*?saveTreatmentPlanThenHold\(\)/,
        'Hold must only be attempted after the save succeeds.',
    );
    assert.match(
        body,
        /panel\.saveForHold\(proceedToHold,/,
        'A dirty treatment plan draft must also be saved before holding, or it would become unsavable the moment canSave excludes a held consultation.',
    );
});

test('(4) a failing save never holds, surfaces the error, and never resets the form', () => {
    const fn = show.match(/const saveAndHold = \(\) => \{([\s\S]*?)\n\};\n/);
    const body = fn[1];
    const onError = body.match(/onError:\s*\(\)\s*=>\s*\{([\s\S]*?)\n\s*\},/);
    assert.ok(onError, 'saveAndHold() has no onError handler.');
    assert.match(
        onError[1],
        /unsavedWorkError\.value\s*=/,
        'The error must be surfaced.',
    );
    assert.doesNotMatch(
        onError[1],
        /changeHoldState|proceedToHold/,
        'A failed save must never proceed to hold.',
    );
    assert.doesNotMatch(
        onError[1],
        /form\.reset\(\)/,
        "A failed save must never discard the doctor's edits.",
    );
});

test('(5) "Hold without saving" discards edits (this form and the treatment plan draft) then holds', () => {
    const fn = show.match(
        /const holdWithoutSaving = \(\) => \{([\s\S]*?)\n\};/,
    );
    assert.ok(fn, 'holdWithoutSaving() was not found.');
    assert.match(fn[1], /form\.reset\(\)/);
    assert.match(fn[1], /treatmentPlanPanelRef\.value\?\.discardDraft\(\)/);
    assert.match(fn[1], /changeHoldState\('hold'\)/);
    assert.match(treatmentPlanPanel, /discardDraft:\s*\(\)\s*=>\s*\{/);
});

test('(6) closing the dialog (Cancel) only changes the open flag', () => {
    assert.match(show, /@update:open="unsavedWorkOpen = \$event"/);
});

test("OperationalConfirmDialog's new secondary action stays backward compatible for existing single-choice dialogs", () => {
    assert.match(confirmDialog, /secondaryLabel\?:\s*string/);
    assert.match(confirmDialog, /processingAction:\s*'confirm'/);
    assert.match(confirmDialog, /v-if="secondaryLabel"/);
});
