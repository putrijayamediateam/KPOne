import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// CAT-10 (B.E.R.N.I.S.S for the owner, 2026-11-06): review leftovers on the catalogue and settings screens.

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const business = read('resources/js/pages/BusinessDetails/Index.vue');
const medicine = read('resources/js/pages/Medicine/Index.vue');
const flat = (s) => s.replace(/\s+/g, ' ');

test('the logo is chosen with a real button and the file name sits on its own line (Daniel)', () => {
    const b = flat(business);
    // The native input stays, hidden from sight and from the tab order; the button opens it.
    assert.match(
        b,
        /<input ref="fileInput" name="logo" type="file" accept="image\/jpeg,image\/png" class="sr-only" tabindex="-1" aria-hidden="true" @change="chooseLogo" \/>/,
    );
    assert.match(
        b,
        /<Button id="logo" type="button" variant="outline" class="min-h-11 sm:min-h-9" aria-describedby="logo-error"/,
    );
    assert.match(b, /@click="fileInput\?\.click\(\)"/);
    assert.match(b, /letterhead\.logo \? 'Replace logo' : 'Choose logo'/);
    assert.match(
        b,
        /id="logo-file-name" aria-live="polite" class="text-xs \[overflow-wrap:anywhere\] break-words text-muted-foreground"/,
    );
    assert.match(b, /form\.logo\?\.name \?\? 'No file chosen'/);
    // The button wraps under the preview on a narrow phone instead of widening the page.
    assert.match(b, /v-if="canEdit" class="min-w-0 flex-1 basis-40 space-y-2"/);
    // The readiness card's "Fix below" still lands on a focusable control.
    assert.ok(business.includes("'#logo'"));
});

test('the Medicine Catalogue publication branch select is named by its visible label (Ravi)', () => {
    const m = flat(medicine);
    assert.match(
        m,
        /<span id="medicine-publication-branch-label" >Branch used for price publication<\/span > <OperationalSelect id="medicine-publication-branch" v-model="form\.expected_branch_id" label="Branch used for price publication" labelledby="medicine-publication-branch-label"/,
    );
    assert.doesNotMatch(medicine, /label="Price publication branch"/);
});
