import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// Q-14 (owner decision 21 Nov 2026): Complete Consultation never completes with unsaved clinical work. The Complete button
// only asks; Show.vue decides; either choice still ends in the existing "Complete consultation?" confirm dialog.
const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const show = read('resources/js/pages/Clinical/Show.vue');
const panel = read(
    'resources/js/pages/Clinical/Partials/TreatmentPlanPanel.vue',
);
const dialog = read(
    'resources/js/components/clinical/UnsavedClinicalWorkContinueDialog.vue',
);
const hold = read(
    'resources/js/components/clinical/UnsavedClinicalWorkDialog.vue',
);
const between = (source, from, to) =>
    source.slice(
        source.indexOf(from),
        source.indexOf(to, source.indexOf(from)),
    );

test('the Complete button only emits request-complete: there is no local way to open the dialog', () => {
    assert.match(panel, /defineEmits<\{ 'request-complete': \[\] \}>\(\)/);
    assert.match(
        panel,
        /@click="emit\('request-complete'\)"\s*>Complete Consultation<\/Button/,
    );
    assert.ok(!/@click="sendOpen = true"/.test(panel));
    // the only writers of sendOpen to true are the exposed openComplete
    assert.equal((panel.match(/sendOpen\.value = true/g) ?? []).length, 1);
    assert.match(panel, /openComplete: \(\) => \{\s*sendOpen\.value = true;/);
    assert.match(panel, /saveDraft: \(/);
    assert.match(
        panel,
        /saveForHold: \(/,
        'saveForHold stays: two tests pin its call',
    );
});

test('Show.vue routes the request through the guard and never completes by itself', () => {
    assert.match(show, /@request-complete="requestComplete"/);
    const guard = between(
        show,
        'const requestComplete',
        'const continueWithoutSaving',
    );
    assert.match(guard, /form\.processing \|\| continueProcessing\.value/);
    assert.match(guard, /!hasUnsavedWork\.value/);
    assert.match(guard, /treatmentPlanPanelRef\.value\?\.openComplete\(\)/);
    assert.match(guard, /continueOpen\.value = true/);
    // order, as saveAndHold: patch, adopt, then save the plan draft, which ends in the confirm dialog
    assert.match(
        guard,
        /form\.patch\([\s\S]*?onSuccess: \(\) => \{\s*adoptLatestRecord\(\);\s*saveTreatmentPlanThenContinue\(\);/,
    );
    assert.match(guard, /panel\.saveDraft\(proceed,/);
    assert.match(guard, /const proceed = \(\) => \{[\s\S]*?openComplete\(\)/);
    // a failed save keeps the dialog open and completes nothing
    assert.match(guard, /continueError\.value =/);
    assert.match(guard, /continueProcessing\.value = false;/);
    assert.ok(
        !/complete-consultation|router\./.test(guard),
        'the guard never posts the completion',
    );
    const discard = between(show, 'const continueWithoutSaving', '\n</script>');
    assert.match(discard, /form\.reset\(\)/);
    assert.match(discard, /discardDraft\(\)/);
    assert.match(discard, /openComplete\(\)/);
    // it reuses the Hold guard's own definition of unsaved work
    assert.match(
        show,
        /const hasUnsavedWork = computed\(\(\) => form\.isDirty \|\| treatmentPlanDirty\.value\)/,
    );
});

test('the continue dialog has the agreed wording and events, and the Hold dialog is untouched', () => {
    assert.match(dialog, /title="Unsaved clinical work"/);
    assert.match(dialog, /confirm-label="Save and continue"/);
    assert.match(dialog, /secondary-label="Discard and continue"/);
    assert.match(dialog, /secondary-variant="outline"/);
    assert.match(
        dialog,
        /`Not saved yet: \$\{joinWithAnd\(props\.unsavedItems\)\}\.`/,
    );
    assert.match(dialog, /'save-and-continue': \[\]/);
    assert.match(dialog, /'continue-without-saving': \[\]/);
    assert.match(show, /<UnsavedClinicalWorkContinueDialog/);
    // Hold dialog literals are still there
    assert.match(hold, /confirm-label="Save and hold"/);
    assert.match(hold, /secondary-label="Discard and hold"/);
});

test('the confirm dialog says when the service choices were reset after a save', () => {
    assert.match(
        panel,
        /servicesReset\.value = sendForm\.service_deliveries\.some\(\s*\(row\) => row\.disposition !== '',?\s*\)/,
    );
    assert.match(panel, /Services changed\. Please confirm each one again\./);
    assert.match(panel, /data-testid="services-reconfirm"/);
    assert.match(
        panel,
        /watch\(sendOpen, \(open\) => \{\s*if \(!open\) \{\s*servicesReset\.value = false;/,
    );
});

test('the confirm sheet footer wraps at sm and wider, so three buttons stay inside the card (Daniel)', () => {
    const shell = read(
        'resources/js/components/ui/OperationalConfirmDialog.vue',
    );
    assert.match(shell, /<DialogFooter class="gap-2 sm:flex-wrap sm:gap-2">/);
});
