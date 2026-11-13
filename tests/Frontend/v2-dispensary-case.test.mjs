import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// Version 2 Dispensary case page (Daniel's department ruling 2026-11-13). The V2 file copies Classic's logic; this test
// keeps the copy in step, so V2 changes the look only: same props, routes, payload keys, lock versions and rules.
const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const classic = read('resources/js/pages/Dispensary/Show.vue');
const v2 = read('resources/js/pages/Dispensary/V2/Show.vue');
const script = (source) => source.slice(0, source.indexOf('</script>'));
const template = (source) => source.slice(source.indexOf('<template>'));

// Every top-level `const` of a script, by name, with its full text (up to the next top-level statement).
const consts = (source) => {
    const lines = script(source).split('\n');
    const found = new Map();
    let name = null;
    let body = [];
    const flush = () => {
        if (name) {
            found.set(name, body.join('\n').trimEnd());
        }
    };

    for (const line of lines) {
        const start = /^const ([A-Za-z_$][\w$]*)/.exec(line);
        const otherTopLevel = /^(?:[a-z]|\/\/|type |\}|<)/.test(line) && !start;

        if (start) {
            flush();
            name = start[1];
            body = [line];
        } else if (otherTopLevel && !line.startsWith('}') && name) {
            flush();
            name = null;
            body = [];
        } else if (name) {
            body.push(line);
        }
    }

    flush();

    return found;
};

test('V2 is registered and Classic does not import anything from V2', () => {
    assert.match(read('config/v2-pages.php'), /'Dispensary\/Show',/);
    assert.ok(!/components\/v2\/|from '\.\/V2\//.test(classic));
});

test('every Classic top-level const is copied into V2 unchanged (logic, routes, payload keys, lock versions)', () => {
    const classicConsts = consts(classic);
    const v2Consts = consts(v2);
    // Classic's completeCase calls caseAction('complete'); V2 replaces it with confirmComplete (same route and payload,
    // plus a guard so a second press is ignored), checked below.
    const replaced = new Set(['completeCase']);

    assert.ok(classicConsts.size > 60, 'the parser found Classic consts');

    for (const [name, body] of classicConsts) {
        if (replaced.has(name)) {
            assert.equal(v2Consts.has(name), false, name);
            continue;
        }

        assert.ok(v2Consts.has(name), `V2 keeps ${name}`);
        assert.equal(v2Consts.get(name), body, `V2's ${name} is Classic's`);
    }
});

test('Complete posts the same route and payload as Classic, once, after the Verify dialog', () => {
    assert.match(
        classic,
        /const completeCase = \(\) => \{\s+caseAction\('complete'\);/,
    );
    assert.match(
        v2,
        /router\.post\(\s+`\/dispensary\/\$\{props\.dispensary\.publicId\}\/complete`,\s+\{\s+expected_branch_id: branchId\(\),\s+case_lock_version: props\.dispensary\.lockVersion,\s+\},/,
    );
    assert.match(
        v2,
        /if \(completing\.value \|\| completeDisabled\.value\) \{\s+return;/,
    );
    assert.match(v2, /title="Verify before completing"/);
    assert.match(v2, /'Completing…' : 'Confirm and complete'/);
    // The same disabled rule as Classic's Complete button.
    assert.match(
        v2,
        /busy\.value \|\|\s+unsaved\.value\.length > 0 \|\|\s+unsavedServices\.value\.length > 0 \|\|\s+\(isOtc\.value && !otcAllergyConfirmed\.value\)/,
    );
});

test("Return to Doctor asks first and then sends Classic's payload (no reason: the server takes none)", () => {
    assert.match(classic, /@click="caseAction\('return-to-doctor'\)"/);
    assert.match(
        v2,
        /returnOpen\.value = false;\s+caseAction\('return-to-doctor'\);/,
    );
    assert.match(v2, /title="Return this case to the doctor\?"/);
    assert.ok(
        !/reason-label/.test(template(v2).split('Return this case')[1] ?? ''),
    );
});

test('one filled button carries the next step; Complete is not offered while anything is unsaved', () => {
    assert.match(
        v2,
        /if \(props\.dispensary\.can\.start\) \{\s+return 'start';\s+\}\s+if \(props\.dispensary\.can\.update && unsavedCount\.value > 0\) \{\s+return 'save';\s+\}\s+return props\.dispensary\.can\.complete \? 'complete' : null;/,
    );
    assert.match(v2, /`Save all lines \(\$\{unsavedCount\.value\}\)`/);
    assert.equal(
        (template(v2).match(/:class="filledButton"/g) ?? []).length,
        2,
        'the summary button and the phone bar',
    );
    assert.match(template(v2), /<V2StickyBar v-if="primaryAction">/);
});

test('the patient and allergy card is the first card of the main column, never in the rail', () => {
    const main = template(v2).slice(template(v2).indexOf('<template #main>'));
    assert.ok(
        main.indexOf('data-testid="dispensary-patient"') <
            main.indexOf('data-testid="dispensary-scan"'),
    );
    const rail = template(v2).slice(
        template(v2).indexOf('<template #rail>'),
        template(v2).indexOf('</V2WorkLayout>'),
    );
    assert.ok(
        !/dispensary-patient|dispensary-allergies|otc-allergy/.test(rail),
    );
});

test('phones: the counts fold into one bar and the other actions sit in an Actions menu', () => {
    assert.match(template(v2), /data-testid="dispensary-counts-bar"/);
    assert.match(template(v2), /<V2DisclosureBar\s+:title="countsTitle"/);
    assert.match(template(v2), /label="Actions"/);

    for (const id of ["id: 'call'", "id: 'print-all'", "id: 'return'"]) {
        assert.ok(v2.includes(id), id);
    }
});

test("the scan box, the allergy warning, the label prints and the lines are Classic's markup (1b restyles the lines)", () => {
    for (const marker of [
        'context="dispensary"',
        'data-testid="scan-batch-announcement"',
        '<AllergyMatchWarning',
        'data-testid="dispensary-services"',
        'context="dispensary-add"',
    ]) {
        assert.equal(
            (template(classic).match(new RegExp(marker, 'g')) ?? []).length,
            (template(v2).match(new RegExp(marker, 'g')) ?? []).length,
            marker,
        );
    }
});

test('no second filled button: every Button is outline or ghost, and both scan boxes use the outline Look up', () => {
    const buttons = template(v2).match(/<Button\b[^>]*>/g) ?? [];
    assert.ok(buttons.length > 0);

    for (const tag of buttons) {
        assert.match(tag, /variant="(outline|ghost|link)"/, tag);
    }

    assert.equal(
        (template(v2).match(/lookup-variant="outline"/g) ?? []).length,
        2,
    );
});
