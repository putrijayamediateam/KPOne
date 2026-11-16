import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// V2 OTC allergy record form (owner, 2026-11-16; Daniel's OK). Classic form untouched.
const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const classic = read(
    'resources/js/pages/Dispensary/Partials/OtcAllergyRecordForm.vue',
);
const v2 = read(
    'resources/js/pages/Dispensary/V2/Partials/OtcAllergyRecordForm.vue',
);
const page = read('resources/js/pages/Dispensary/V2/Show.vue');
const classicPage = read('resources/js/pages/Dispensary/Show.vue');
const template = (source) => source.slice(source.indexOf('<template>'));
const block = (source, start, end) =>
    source.slice(
        source.indexOf(start),
        source.indexOf(end, source.indexOf(start)),
    );

test('the V2 case page uses the V2 form; Classic keeps its own', () => {
    assert.match(
        page,
        /import OtcAllergyRecordForm from '@\/pages\/Dispensary\/V2\/Partials\/OtcAllergyRecordForm\.vue';/,
    );
    assert.match(
        classicPage,
        /import OtcAllergyRecordForm from '@\/pages\/Dispensary\/Partials\/OtcAllergyRecordForm\.vue';/,
    );
    assert.ok(!/\/V2\//.test(classic));
});

test('same props, emits, fields and request as Classic', () => {
    assert.equal(
        block(v2, 'const props = defineProps<{', 'const form = useForm'),
        block(classic, 'const props = defineProps<{', 'const form = useForm'),
    );
    assert.equal(
        block(v2, 'const form = useForm({', '});'),
        block(classic, 'const form = useForm({', '});'),
    );
    // The transform, URL, preserveScroll and preserveState, word for word.
    assert.equal(
        block(v2, 'form.clearErrors();', 'onSuccess:'),
        block(classic, 'form.clearErrors();', 'onSuccess:'),
    );

    for (const fragment of [
        "form.setError('allergen_text', 'Enter the allergen.');",
        'form.reset();',
        "emit('saved');",
        'allergenInput.value?.focus();',
        'Medicine, food or substance, e.g. penicillin.',
        'What happened, e.g. rash, swelling.',
        "Saved to the patient's allergy profile. Only a doctor can change",
        'allergyCategoryOptions',
        'allergySeverityOptions',
        'maxlength="500"',
    ]) {
        assert.ok(classic.includes(fragment), `Classic ${fragment}`);
        assert.ok(v2.includes(fragment), `V2 ${fragment}`);
    }
});

test('no filled button: Add allergy and Cancel are outline; Cancel only when another allergy exists', () => {
    assert.ok(!/bg-primary/.test(v2));
    assert.match(template(v2), /v-if="optional"/);
    assert.match(v2, /const outlineButton =\s*'inline-flex h-11 /);
    assert.match(v2, /max-sm:w-full lg:h-9/);
});

test('Add allergy is faded with its reason until the allergen is typed; busy never adds twice', () => {
    assert.match(
        v2,
        /const addBlocked = computed\(\(\) => form\.allergen_text\.trim\(\) === ''\);/,
    );
    assert.match(
        template(v2),
        /:aria-disabled="addBlocked \? 'true' : undefined"/,
    );
    assert.match(template(v2), /Enter the allergen to continue\./);
    assert.match(v2, /if \(form\.processing\) \{\s*return;\s*\}/);
    assert.match(template(v2), /:aria-busy="form\.processing"/);
});

test('fields: required allergen with its hint, optional selects with Classic options, reaction with a counter', () => {
    assert.match(template(v2), /label="Allergen"\s+required/);
    assert.match(template(v2), /label="Category \(optional\)"/);
    assert.match(template(v2), /label="Severity \(optional\)"/);
    // Hakim: the selects' names match their visible labels, for voice control.
    assert.equal(
        (template(v2).match(/label="Category \(optional\)"/g) ?? []).length,
        2,
    );
    assert.equal(
        (template(v2).match(/label="Severity \(optional\)"/g) ?? []).length,
        2,
    );
    assert.match(template(v2), /label="Reaction \(optional\)"/);
    assert.match(v2, /const reactionMax = 1000;/);
    assert.match(
        template(v2),
        /\{\{ form\.reaction_text\.length \}\} \/ \{\{ reactionMax \}\}/,
    );
});

test('a refusal shows once above the buttons and focus returns to Allergen', () => {
    assert.equal((template(v2).match(/role="alert"/g) ?? []).length, 1);
    assert.match(
        v2,
        /onFinish: async \(\) => \{\s*if \(!form\.hasErrors\) \{\s*return;\s*\}\s*await nextTick\(\);\s*allergenInput\.value\?\.focus\(\);/,
    );
    assert.ok(
        template(v2).indexOf('data-testid="otc-allergy-refusal"') <
            template(v2).indexOf('data-action="add-otc-allergy-record"'),
    );
});

test('the note sits beside Add allergy and says it cannot be edited afterwards', () => {
    assert.match(template(v2), /You can't edit it afterwards\./);
    assert.match(template(v2), /'otc-allergy-note'/);
});

test('a leave guard asks only when something is typed; Cancel asks before discarding', () => {
    assert.match(
        template(v2),
        /<V2LeaveGuard ref="guard" :dirty="typed" @discard="form\.reset\(\)" \/>/,
    );
    assert.match(v2, /guard\.value\?\.ask\(\(\) => emit\('cancel'\)\);/);
});

test("Daniel (#860): the focused control clears the case page's sticky bar below 1536 px", () => {
    assert.match(template(v2), /@focusin="clearOfStickyBar"/);
    assert.match(v2, /window\.matchMedia\('\(max-width: 1535\.98px\)'\)/);
    assert.match(v2, /target\.scrollIntoView\(\{ block: 'nearest' \}\);/);
    assert.match(template(v2), /\[&_textarea\]:scroll-mb-48/);
    assert.match(page, /2xl:hidden"\s+data-testid="dispensary-bar"/);
});

test('Daniel (#860): an off Complete button reads as off on the V2 case page (dashed and muted, not faded blue)', () => {
    assert.match(
        page,
        /aria-disabled:border-dashed aria-disabled:border-border aria-disabled:bg-muted aria-disabled:text-muted-foreground/,
    );
    assert.ok(!/aria-disabled:opacity-60 lg:h-9';/.test(page));
    assert.match(
        page,
        /'Confirm what the patient reports about allergies, then complete\.'/,
    );
    assert.match(page, /:aria-disabled="primaryDisabled"/);
});
