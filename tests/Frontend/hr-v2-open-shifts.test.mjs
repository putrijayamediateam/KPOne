import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileTemplate, parse } from '@vue/compiler-sfc';

// HR V2 Open shifts: the Version 2 page uses the same composable as Classic, so both send the same requests.
const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const source = read('resources/js/pages/HR/V2/OpenShifts.vue');
const classic = read('resources/js/pages/HR/OpenShifts.vue');
const composable = read('resources/js/composables/hr/useOpenShiftsPage.ts');
const template = parse(source).descriptor.template.content;
const script = parse(source).descriptor.scriptSetup.content;
const flat = template.replace(/\s+/g, ' ');

test('the V2 page compiles, is registered, and shares the composable with Classic', () => {
    assert.deepEqual(
        compileTemplate({
            id: 'hr-v2-open-shifts',
            source: template,
            filename: 'OpenShifts.vue',
        }).errors,
        [],
    );
    assert.match(read('config/v2-pages.php'), /'HR\/OpenShifts',/);
    assert.match(script, /useOpenShiftsPage\(props\)/);
    assert.match(classic, /useOpenShiftsPage\(props\)/);
    // Every request comes from the composable: the V2 page sends none of its own.
    assert.doesNotMatch(script, /router\.(get|post|put|patch|delete)\(/);
    assert.doesNotMatch(script, /fetch\(|axios|useForm|crypto\.randomUUID/);
    assert.doesNotMatch(source, /localStorage|sessionStorage|v-html/);

    for (const url of [
        '`/hr/open-shifts/${shift.id}/ask`',
        '`/hr/open-shifts/asks/${mine.requestId}/withdraw`',
        '`/hr/open-shifts/asks/${ask.id}/confirm`',
        '`/hr/open-shifts/asks/${ask.id}/decline`',
        '`/hr/open-shifts/${shift.id}/withdraw`',
        "'/hr/open-shifts'",
    ]) {
        assert.ok(composable.includes(url), url);
    }

    assert.equal((composable.match(/idempotency_key:/g) ?? []).length, 6);
    assert.equal((composable.match(/expected_lock_version:/g) ?? []).length, 4);
});

test('the Classic template did not change: Classic still renders its own markup', () => {
    assert.match(
        classic,
        /<PageHeader\s+eyebrow="KPOne HR"\s+title="Open shifts"/,
    );
    assert.doesNotMatch(classic, /V2WorkLayout|V2Dialog/);
});

test('Daniel: one filled action: Post an open shift (managers); Ask, Confirm, Decline and Withdraw are outline or red, never filled', () => {
    assert.equal((template.match(/:class="primaryButton"/g) ?? []).length, 1);
    assert.match(
        template,
        /#actions[\s\S]*?:class="primaryButton"[\s\S]*?data-test="open-shift-new"[\s\S]*?Post an open shift/,
    );
    // Ask for this shift, Confirm and Decline have no filled class.
    assert.match(flat, /data-test="open-shift-ask"/);
    assert.match(
        flat,
        /:class="button"[^>]*:disabled="a\.own \|\| s\.started"[^>]*data-test="open-shift-confirm"/,
    );
    assert.match(
        flat,
        /:class="dangerButton"[^>]*:disabled="a\.own"[^>]*data-test="open-shift-decline"/,
    );
    assert.match(
        script,
        /const dangerButton = `\$\{button\} text-destructive`/,
    );
});

test('Daniel: Confirm, Decline and Withdraw are V2 dialogs; Decline and Withdraw are red and the decline reason starts empty and is required', () => {
    assert.doesNotMatch(source, /OperationalConfirmDialog/);
    assert.match(flat, /<V2Dialog :open="declining\.ask !== null"/);
    assert.match(
        flat,
        /description="The locum sees that they were not chosen, with the reason\."/,
    );
    assert.match(flat, /tone="danger"/);
    assert.equal((flat.match(/tone="danger"/g) ?? []).length, 2);
    assert.match(script, /'Pick a reason to continue\.'/);
    assert.match(flat, /:confirm-disabled="declineMissing !== ''"/);
    assert.match(flat, /:model-value="declining\.reason \|\| null"/);
    assert.match(flat, /declining\.reason = '';/);
});

test('a locum sees only their own asks, never who else asked, and an own ask or a started shift cannot be confirmed here', () => {
    const locum = flat.slice(
        flat.indexOf("openShifts.tab === 'available'"),
        flat.indexOf('<!-- Managers: open'),
    );
    assert.doesNotMatch(locum, /\.locum|\.asks|a\.own/);
    assert.match(flat, /This is your own ask\. Someone else decides it\./);
    assert.match(
        flat,
        /This shift has started, so it can no longer be confirmed\./,
    );
    assert.match(flat, /:disabled="a\.own \|\| s\.started"/);
});

test('Daniel: the manager sentence is on the manager tabs only; a locum reads the locum sentence', () => {
    assert.match(
        script,
        /props\.openShifts\.tab === 'open' \|\| props\.openShifts\.tab === 'closed'/,
    );
    assert.match(
        script,
        /'Shifts the clinic needs covered at your branches\. Ask for one; HR chooses who takes it\.'/,
    );
});

test('the Needs posting again wording and tone are kept', () => {
    assert.match(script, /'Needs posting again'/);
    assert.match(flat, /s\.needsPosting \? 'warning'/);
    assert.match(flat, /data-test="open-shift-needs-posting"/);
    assert.match(
        flat,
        /The roster no longer has this shift for the confirmed locum\. Post it again if it is still needed\./,
    );
});

test('the post dialog starts from the composable, and the faded button names what is missing', () => {
    assert.match(flat, /:confirm-disabled="missing !== null"/);
    assert.match(flat, /:confirm-disabled-reason="missing \?\? ''"/);
    assert.match(composable, /'Choose who is needed\.'/);
    assert.match(composable, /'Choose the date\.'/);
    assert.match(composable, /role_needed: '',/);
});
