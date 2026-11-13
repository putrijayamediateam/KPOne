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
    assert.match(
        v2,
        /\? 'Save 1 line'\s+: `Save all \$\{unsavedCount\.value\} lines`/,
    );
    assert.equal(
        (template(v2).match(/:class="\[?filledButton/g) ?? []).length,
        2,
        'the summary button and the phone bar',
    );
    // Below the rail (1536 px) the filled action is a sticky bar at every width (Daniel).
    assert.match(template(v2), /rail-from="2xl"/);
    assert.match(
        template(v2),
        /class="sticky bottom-0 z-10 -mx-4 [^"]*2xl:hidden"\s+data-testid="dispensary-bar"/,
    );
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

test('the reason Complete is blocked is tied to both buttons, and on phones sits in the bar under the button (Hakim)', () => {
    assert.match(template(v2), /id="dispensary-complete-blocked-reason"/);
    assert.match(template(v2), /id="dispensary-complete-blocked-reason-phone"/);
    assert.match(template(v2), /:aria-describedby="primaryDescribedBy"/);
    assert.match(template(v2), /:aria-describedby="primaryBarDescribedBy"/);
    const bar = template(v2).slice(
        template(v2).indexOf('data-testid="dispensary-bar"'),
        template(v2).indexOf('<template #rail>'),
    );
    assert.ok(bar.includes('dispensary-complete-blocked-reason-phone'));
});

test('an error is brought into view and focused; the Verify dialog stays open while completing (Hakim)', () => {
    assert.match(
        template(v2),
        /ref="actionErrorRef"\s+role="alert"\s+tabindex="-1"/,
    );
    assert.match(
        v2,
        /scrollIntoView\(\{\s+block: 'center',\s+behavior: reduce \? 'auto' : 'smooth',/,
    );
    assert.match(v2, /\(confirming = open \|\| completing\)/);
});

test('Return to Doctor sits apart behind "Send back to the doctor", in the end card, the rail and the phone menu (Daniel)', () => {
    assert.equal(
        (template(v2).match(/data-testid="dispensary-send-back"/g) ?? [])
            .length,
        2,
    );
    assert.equal(
        (template(v2).match(/Send back to the doctor/g) ?? []).length,
        2,
    );
    assert.match(v2, /label: 'Send back to the doctor'/);
});

test('three allergy states in words; "not recorded" never reads as "no allergies" (Daniel)', () => {
    assert.match(v2, /'Allergies not recorded'/);
    assert.match(v2, /'No known allergies'/);
    assert.match(
        v2,
        /`Allergies \(\$\{props\.dispensary\.allergySafety\.allergies\.length\}\)`/,
    );
    assert.match(
        v2,
        /status === 'no_known_allergies'\s+\? 'none'\s+: 'unrecorded'/,
    );
});

test('the eyebrow names the visit type; the visit number is in the crumb only (Daniel)', () => {
    assert.match(
        template(v2),
        /:eyebrow="isOtc \? 'OTC visit' : 'Consultation visit'"/,
    );
});

test('lines: short labels, the label budget only when focused or near it, and over it in red words (Daniel)', () => {
    assert.ok(!/\(label max/.test(template(v2)));
    assert.equal((template(v2).match(/labelHint\(/g) ?? []).length, 20);
    assert.match(
        v2,
        /`\$\{over\} character\$\{over === 1 \? '' : 's'\} over the label limit`/,
    );
    assert.match(
        v2,
        /focusedField\.value === key \|\| limit - \(value \?\? ''\)\.length <= 10/,
    );
    assert.equal(
        (template(v2).match(/>Not printed on the label</g) ?? []).length,
        2,
    );
    assert.equal(
        (template(v2).match(/<V2DisclosureBar title="Label limits">/g) ?? [])
            .length,
        2,
    );
});

test('lines: a save with an unticked allergy match is aria-disabled with the reason beside it (Daniel)', () => {
    assert.match(template(v2), /:aria-disabled="needsAllergyCheck\(item\)"/);
    assert.match(template(v2), /Tick the allergy check to save this line\./);
    assert.match(
        template(v2),
        /needsAllergyCheck\(item\)\s+\? null\s+: saveItem\(item\)/,
    );
    assert.match(template(v2), />Save this line</);
});

test('the V2 allergy warning: icon and "Allergy match:" words, 44 px row at every width with a 24 px box, announced only after load', () => {
    const warning = read(
        'resources/js/pages/Dispensary/V2/Partials/AllergyMatchWarning.vue',
    );
    assert.match(
        v2,
        /from '@\/pages\/Dispensary\/V2\/Partials\/AllergyMatchWarning\.vue'/,
    );
    assert.match(warning, /Allergy match:/);
    assert.match(warning, /flex min-h-11 w-\[calc\(100%\+1rem\)\]/);
    // Daniel: the whole tick row is the 44 px target from 1024 too.
    assert.ok(!/lg:min-h-9/.test(warning));
    assert.match(warning, /class="size-6 shrink-0 accent-destructive"/);
    assert.match(warning, /if \(props\.announce\) \{/);
    assert.equal(
        (template(v2).match(/:announce="pageReady"/g) ?? []).length,
        3,
    );
    assert.match(warning, /so always read the full allergy list\./);
});

test('scan to add (Classic DSP-12, kept for parity): outline 44 px controls below 1024, a polite region that stays in the page, refusals in words beside the box (Daniel)', () => {
    const add = template(v2).slice(
        template(v2).indexOf('data-action="scan-to-add"') - 900,
        template(v2).indexOf('data-testid="scan-add-status"') + 200,
    );
    assert.equal((add.match(/max-lg:min-h-11/g) ?? []).length, 3);
    assert.match(add, /role="status"\s+aria-live="polite"/);
    assert.ok(!/v-else-if="scanAddMessage"/.test(add));
    // The scan only fills the Add form's pick: scanToAdd is Classic's (checked by the const test) and never saves.
    const scanToAdd = consts(v2).get('scanToAdd');
    assert.ok(!/router\.|saveItem|addItem\(/.test(scanToAdd));
    // An unknown or inactive code is refused in ScanInput's own status line beside the box, in words.
    assert.match(
        read('resources/js/components/inventory/ScanInput.vue'),
        /result\.status !== 'found' \|\| !result\.sku\.active\) \{\s+show\(scanMessage\(result, props\.context\), true\);/,
    );
});

test('the same refusal pressed twice scrolls and focuses again: the error watcher runs sync (Hakim)', async () => {
    assert.match(
        v2,
        /watch\(\s*actionError,[\s\S]*?\{ flush: 'sync' \},\s*\);/,
    );
    assert.match(v2, /actionError\.value = null;/);
    // Vue's semantics, as the page uses them: clear, then set the same text in one tick.
    const { ref, watch, nextTick } = await import('vue');
    const error = ref('Tick the allergy check first.');
    let sync = 0;
    let pre = 0;
    watch(error, (message) => message && sync++, { flush: 'sync' });
    watch(error, (message) => message && pre++);
    error.value = null;
    error.value = 'Tick the allergy check first.';
    await nextTick();
    assert.equal(sync, 1, 'sync runs for the second press');
    assert.equal(pre, 0, 'the default pre flush would miss it');
});

test('no native selects: Batch and FEFO use OperationalSelect, the batch wraps in the field and the list (Daniel)', () => {
    assert.ok(!/<select\b/.test(template(v2)));
    assert.equal(
        (template(v2).match(/:options="fefoOptions"/g) ?? []).length,
        2,
    );
    assert.equal((template(v2).match(/batchOptions\(/g) ?? []).length, 2);
    assert.equal(
        (template(v2).match(/:content-avoid-collisions="false"/g) ?? []).length,
        2,
    );
    assert.match(
        v2,
        /whitespace-normal data-\[size=default\]:h-auto lg:min-h-9 \*:data-\[slot=select-value\]:line-clamp-none/,
    );
    // pointAtBatch (Classic's, unchanged) still finds the batch field by data-batch-select.
    assert.match(template(v2), /:data-batch-select="item\.publicId"/);
    assert.match(template(v2), /data-batch-select="add"/);
    assert.match(v2, /disabled: stock\.held/);
    // Clicking the visible word opens its field (Hakim): a synthetic click only focuses the reka trigger, so the page
    // focuses it and presses Enter (checked in Chromium with a mouse click and a phone tap).
    assert.match(
        v2,
        /trigger\.focus\(\);\s+trigger\.dispatchEvent\(\s+new KeyboardEvent\('keydown', \{ key: 'Enter', bubbles: true \}\),/,
    );
    assert.equal(
        (template(v2).match(/@click="\s*openSelect\(/g) ?? []).length,
        4,
    );
});

test('scan to check sits behind a closed "Check by scan" bar that stays mounted (Daniel)', () => {
    assert.match(v2, /const checkScanOpen = ref\(false\);/);
    assert.match(
        template(v2),
        /v-show="checkScanOpen"\s+id="dispensary-check-scan-panel"/,
    );
    assert.match(template(v2), /aria-controls="dispensary-check-scan-panel"/);
    const panel = template(v2).slice(
        template(v2).indexOf('id="dispensary-check-scan-panel"'),
    );
    assert.ok(panel.indexOf('context="dispensary"') < 400);
});

test('words, not data: "No known allergies" in sentence case and read-only quantities like "10 tablets" (Daniel)', () => {
    const dialog = template(v2).slice(
        template(v2).indexOf('title="Verify before completing"'),
    );
    assert.match(dialog, /'No known allergies'/);
    assert.ok(
        !/formatStatusLabel\(dispensary\.allergySafety\.status\)/.test(dialog),
    );
    assert.equal((template(v2).match(/readQuantity\(/g) ?? []).length, 2);
    assert.ok(!/\{\{ item\.quantityOrdered \}\}/.test(template(v2)));
});

test('a refusal shows in words next to the filled button (bar or rail), and that copy is the one focused (Daniel)', () => {
    for (const ref of ['actionErrorBarRef', 'actionErrorRailRef']) {
        assert.match(
            template(v2),
            new RegExp(`ref="${ref}"\\s+role="alert"\\s+tabindex="-1"`),
        );
    }

    assert.match(
        template(v2),
        /v-if="actionError && !primaryAction"\s+ref="actionErrorRef"/,
    );
    assert.match(v2, /node\.offsetParent !== null/);
    assert.match(v2, /shownActionError\(\)\?\.focus\(/);
    // Hakim: the sticky components force their children to button height; a long refusal must not spill.
    assert.equal(
        (
            template(v2).match(
                /class="mt-2 !h-auto rounded-\[10px\] border border-destructive/g,
            ) ?? []
        ).length,
        2,
    );
});

test('both scan boxes use the ScanInput options: one short help line, no dead camera button, a visible card border in dark (Daniel)', () => {
    assert.equal(
        (template(v2).match(/\bhide-unavailable-camera\b/g) ?? []).length,
        2,
    );
    assert.match(
        template(v2),
        /help="Scanning only checks\. It doesn't save anything or check the quantity or batch\."/,
    );
    assert.match(template(v2), /help="Scanning only finds the medicine\./);
    assert.equal(
        (template(v2).match(/class="dark:border-foreground\/20"/g) ?? [])
            .length,
        2,
    );
});
