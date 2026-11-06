import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// CAT-10 (B.E.R.N.I.S.S for the owner, 2026-11-06): review leftovers on the catalogue and settings screens.

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const business = read('resources/js/pages/BusinessDetails/Index.vue');
const medicine = read('resources/js/pages/Medicine/Index.vue');
const flat = (s) => s.replace(/\s+/g, ' ');

const chooser = read('resources/js/components/catalogue/FileChooseButton.vue');

test('the logo is chosen with a real button and its state sits on its own line (Daniel)', () => {
    const c = flat(chooser);
    // The native input stays, hidden from sight and from the tab order; the button opens it.
    assert.match(
        c,
        /type="file" :accept="accept" class="sr-only" tabindex="-1" aria-hidden="true"/,
    );
    assert.match(c, /defineOptions\(\{ inheritAttrs: false \}\)/);
    assert.match(
        c,
        /<Button v-bind="\$attrs" :aria-describedby="describedBy" type="button" variant="outline" class="min-h-11 w-fit sm:min-h-9"/,
    );
    assert.match(c, /@click="input\?\.click\(\)"/);
    // Ravi: the state line is read with the button, before the parent's own description (the error).
    assert.match(c, /:aria-describedby="describedBy"/);
    assert.match(
        c,
        /\[props\.statusId, attrs\['aria-describedby'\]\]\.filter\(Boolean\)\.join\(' '\)/,
    );
    assert.match(
        c,
        /<p :id="statusId" aria-live="polite" class="text-xs \[overflow-wrap:anywhere\] break-words text-muted-foreground"/,
    );

    const b = flat(business);
    assert.match(
        b,
        /<FileChooseButton id="logo" ref="fileInput" accept="image\/jpeg,image\/png"/,
    );
    // Ravi: the wording follows what is on screen, including a pending removal or a new file.
    assert.match(b, /logoUrl \? 'Replace logo' : 'Choose logo'/);
    assert.match(b, /aria-describedby="logo-error"/);
    assert.match(b, /\$\{form\.logo\.name\} · not saved yet/);
    assert.match(b, /'Logo will be removed when you save\.'/);
    assert.match(b, /'Current logo saved\.'/);
    assert.match(b, /'No file chosen'/);
    // The error sits under the state line, inside the chooser.
    assert.match(
        b,
        /<InputError id="logo-error" :message="form\.errors\.logo" \/> <\/FileChooseButton>/,
    );
    assert.equal((business.match(/id="logo-error"/g) ?? []).length, 1);
    assert.match(b, /class="min-h-11 text-xs sm:min-h-9" @click="removeLogo"/);
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
