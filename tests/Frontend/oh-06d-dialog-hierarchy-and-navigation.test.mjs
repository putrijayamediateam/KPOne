import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// OH-06d, part 1: the unsaved-work dialog's visual hierarchy was backwards -
// "Hold without saving" (a destructive, filled red button) competed with, or
// outweighed, "Save and hold" (the actual recommended primary action). This
// rebalances it: Save and hold is the prominent default-focused primary,
// Hold without saving becomes a quiet secondary, and the copy is tightened
// to one line naming what is unsaved (the three buttons are the choices).
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

test('"Save and hold" stays the confirm (primary, right-most) action, never destructive', () => {
    assert.match(dialog, /confirm-label="Save and hold"/);
    // The confirm button's variant is `destructive ? 'destructive' : 'default'`
    // in OperationalConfirmDialog - UnsavedClinicalWorkDialog never passes
    // `destructive`, so it stays the prominent default/filled button.
    assert.doesNotMatch(
        dialog,
        /:destructive="true"|destructive="true"|\bdestructive\b\s*$/m,
    );
});

test('"Hold without saving" is a quiet secondary, not a large filled destructive button', () => {
    assert.match(dialog, /secondary-variant="ghost"/);
    assert.doesNotMatch(dialog, /secondary-variant="destructive"/);
    // Still explicit that it discards, per the requirement, just not shouted.
    assert.match(dialog, /secondary-label="Discard and hold"/);
});

test('the description is a single short line naming what is unsaved, not restating the choices', () => {
    assert.match(dialog, /not saved yet/);
    assert.doesNotMatch(dialog, /Choose how to proceed/);
    assert.doesNotMatch(dialog, /before placing this patient On Hold/);
});

test('the confirm (primary) button receives focus by default when the dialog opens', () => {
    assert.match(confirmDialog, /ref="confirmButton"/);
    assert.match(confirmDialog, /@open-auto-focus="onOpenAutoFocus"/);
    const fn = confirmDialog.match(
        /const onOpenAutoFocus = \(event: Event\) => \{([\s\S]*?)\n\};/,
    );
    assert.ok(fn, 'onOpenAutoFocus() was not found.');
    assert.match(fn[1], /event\.preventDefault\(\)/);
    assert.match(fn[1], /confirmButtonRef\.value.*\.focus\(\)/);
});

test('Escape and the close (X) button are never intercepted - both fall through to the ordinary Cancel path', () => {
    // Reka UI's Dialog closes on Escape/outside-click by default (emitting
    // update:open(false)); OperationalConfirmDialog must not override that
    // with its own @escape-key-down/@pointer-down-outside/@interact-outside
    // handler that could bypass or block the ordinary cancel path.
    assert.doesNotMatch(confirmDialog, /@escape-key-down/);
    assert.doesNotMatch(confirmDialog, /@pointer-down-outside/);
    assert.doesNotMatch(confirmDialog, /@interact-outside/);
    // Closing (however triggered) only ever calls updateOpen, which emits
    // update:open and never emits confirm/secondary - so nothing is held.
    assert.match(confirmDialog, /:show-close-button="!processing"/);
});

test('button order keeps the same emphasis when the dialog stacks (Reka UI dialog footer uses flex-col-reverse below sm:)', () => {
    // DialogFooter renders `flex flex-col-reverse ... sm:flex-row`, so DOM
    // order Cancel, Secondary, Confirm becomes, when stacked under 640px,
    // visually Confirm (top) -> Secondary -> Cancel (bottom) - the same
    // emphasis order as the desktop row (Confirm right-most).
    const footer = confirmDialog.match(/<DialogFooter[\s\S]*?<\/DialogFooter>/);
    assert.ok(footer, 'DialogFooter markup was not found.');
    const cancelIndex = footer[0].indexOf('@click="updateOpen(false)"');
    const secondaryIndex = footer[0].indexOf('@click="secondaryAction"');
    const confirmIndex = footer[0].indexOf('@click="confirm"');
    assert.ok(cancelIndex < secondaryIndex && secondaryIndex < confirmIndex);
});
