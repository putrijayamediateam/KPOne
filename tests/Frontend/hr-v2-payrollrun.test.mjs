import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileTemplate, parse } from '@vue/compiler-sfc';

// HR V2 payroll run: the Version 2 page shares one composable with Classic, so both send the same requests.
const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const source = read('resources/js/pages/HR/V2/PayrollRun.vue');
const classic = read('resources/js/pages/HR/PayrollRun.vue');
const composable = read('resources/js/composables/hr/usePayrollRunPage.ts');
const template = parse(source).descriptor.template.content;
const script = parse(source).descriptor.scriptSetup.content;
const flat = template.replace(/\s+/g, ' ');

test('the V2 run page compiles, is registered, and shares the composable with Classic', () => {
    assert.deepEqual(
        compileTemplate({
            id: 'hr-v2-payrollrun',
            source: template,
            filename: 'PayrollRun.vue',
        }).errors,
        [],
    );
    assert.match(read('config/v2-pages.php'), /'HR\/PayrollRun',/);
    assert.match(script, /usePayrollRunPage\(props\)/);
    assert.match(classic, /usePayrollRunPage\(props\)/);
    // Every request comes from the composable: the V2 page sends none of its own.
    assert.doesNotMatch(script, /router\.(get|post|put|patch|delete)\(/);
    assert.doesNotMatch(
        script,
        /fetch\(|axios|useForm|crypto\.randomUUID|localStorage|sessionStorage/,
    );
    assert.match(
        composable,
        /`\/hr\/payroll\/runs\/\$\{props\.run\.id\}\/\$\{step\}`/,
    );
    assert.match(composable, /expected_lock_version: props\.run\.lockVersion/);
});

test('one filled step at a time; Return and the reason; Take off and Remove are red', () => {
    // Filled: Send (draft), Approve (sent) or Lock (approved); each behind a confirm dialog.
    assert.equal((flat.match(/:class="primaryButton"/g) ?? []).length, 3);
    assert.match(flat, /data-test="run-send" @click="asking = 'send'"/);
    assert.match(flat, /data-test="run-approve" @click="asking = 'approve'"/);
    assert.match(flat, /data-test="run-lock" @click="asking = 'lock'"/);
    assert.match(flat, /@confirm="returnMissing === '' && confirm\(\)"/);
    assert.match(script, /'Pick a reason to continue\.'/);
    assert.match(script, /returnReason\.value = '';/);
    assert.match(
        flat,
        /:class="dangerButton" :disabled="busy" data-test="run-late-remove"/,
    );
    assert.match(
        flat,
        /:class="dangerButton" :aria-label="`Remove manual line \$\{i \+ 1\}`"/,
    );
    assert.doesNotMatch(
        template,
        /OperationalConfirmDialog|<Sheet\b|<StatusBadge|<EmptyState|<Button\b/,
    );
});

test('late items show kinds and changes in words, never money', () => {
    const late = template.slice(
        template.indexOf('data-test="run-late"'),
        template.indexOf('Nobody is in this run.'),
    );
    assert.match(late, /:label="item\.kindLabel"/);
    assert.match(late, /\{\{ item\.change \}\}/);
    assert.match(late, /decided on/);
    assert.doesNotMatch(late, /myr\(|amountSen|rmText/);
});

test('manual lines wait, in words, for every missing item, and Add line looks off meanwhile', () => {
    // One sentence names each missing item (Daniel): type, amount and why.
    assert.match(script, /'choose a pay item type'/);
    assert.match(script, /'enter the amount'/);
    assert.match(script, /'choose why'/);
    assert.match(script, /to add a line\.`/);
    assert.match(
        flat,
        /@submit\.prevent="manualMissing === '' && addManual\(\)"/,
    );
    assert.match(
        flat,
        /class="self-start aria-disabled:cursor-not-allowed aria-disabled:opacity-50"/,
    );
    assert.match(flat, /:aria-disabled="manualMissing \? 'true' : undefined"/);
});

test('a late item with no decision date says only what it is for', () => {
    assert.match(flat, /<template v-if="item\.approvedAt"> · decided on/);
    assert.doesNotMatch(template, /: '—'/);
});

test('the Classic template did not change shape', () => {
    assert.doesNotMatch(classic, /V2WorkLayout|V2Dialog/);
    assert.match(classic, /<OperationalConfirmDialog/);
});
