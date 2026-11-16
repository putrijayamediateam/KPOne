import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileTemplate, parse } from '@vue/compiler-sfc';

// HR V2 Payroll, part (a): Runs, Exports and Settings in the V2 look; Pay profiles and Tables keep their Classic markup
// inside the V2 frame until part (b). The page shares three composables with Classic, so both send the same requests.
const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const source = read('resources/js/pages/HR/V2/Payroll.vue');
const classic = read('resources/js/pages/HR/Payroll.vue');
const page = read('resources/js/composables/hr/usePayrollPage.ts');
const runs = read('resources/js/composables/hr/useRunsTab.ts');
const exportsTab = read('resources/js/composables/hr/useExportsTab.ts');
const template = parse(source).descriptor.template.content;
const script = parse(source).descriptor.scriptSetup.content;
const classicTemplate = parse(classic).descriptor.template.content;
const flat = template.replace(/\s+/g, ' ');
const section = (name) => {
    const start = classicTemplate.indexOf(`<!-- ${name} -->`);
    const end = classicTemplate.indexOf('<!--', start + 5);

    return classicTemplate.slice(start, end);
};
// Prettier may wrap the copied markup differently at the deeper indent (whitespace, a trailing comma in a call).
const squash = (text) => text.replace(/\s+/g, '').replace(/,\)/g, ')');

test('the V2 page compiles, is registered, and shares the composables with Classic', () => {
    assert.deepEqual(
        compileTemplate({
            id: 'hr-v2-payroll',
            source: template,
            filename: 'Payroll.vue',
        }).errors,
        [],
    );
    assert.match(read('config/v2-pages.php'), /'HR\/Payroll',/);
    assert.match(script, /usePayrollPage\(props\)/);
    assert.match(script, /useRunsTab\(runsProps\)/);
    assert.match(script, /useExportsTab\(\)/);
    assert.match(classic, /usePayrollPage\(props\)/);
    assert.match(
        read('resources/js/components/hr/payroll/RunsTab.vue'),
        /useRunsTab\(props\)/,
    );
    assert.match(
        read('resources/js/components/hr/payroll/ExportsTab.vue'),
        /useExportsTab\(\)/,
    );
    // Every request comes from the composables: the V2 page sends none of its own.
    assert.doesNotMatch(script, /router\.(get|post|put|patch|delete)\(/);
    assert.doesNotMatch(
        script,
        /fetch\(|axios|useForm|crypto\.randomUUID|localStorage|sessionStorage/,
    );
    assert.match(runs, /'\/hr\/payroll\/runs'/);
    assert.match(
        exportsTab,
        /\/hr\/payroll\/runs\/\$\{run\.id\}\/export\/\$\{kind\}/,
    );

    for (const url of ['/hr/payroll/groups']) {
        assert.ok(page.includes(url), url);
    }
});

test('Pay profiles and Tables keep their Classic markup inside the V2 frame until part (b)', () => {
    for (const name of ['Pay profiles', 'Tables']) {
        const block = section(name);
        assert.ok(block.length > 500, name);
        assert.ok(squash(flat).includes(squash(block)), name);
    }

    // The Classic sheets and confirm dialogs that serve them come along unchanged.
    assert.match(template, /<ProfileSheet/);
    assert.match(template, /<TableSheet/);
    assert.match(template, /<MtdScheduleSheet/);
    assert.equal(
        (template.match(/<OperationalConfirmDialog/g) ?? []).length,
        2,
    );
});

test('Runs, Exports and Settings are in the V2 look', () => {
    assert.match(template, /<V2WorkLayout title="Payroll"/);
    assert.match(template, /<V2Tabs/);
    // Runs: one filled action (Prepare run), a worded status chip, and the reason a run cannot start yet.
    assert.equal((flat.match(/:class="primaryButton"/g) ?? []).length, 1);
    assert.match(flat, /data-test="run-prepare-send"/);
    assert.match(
        flat,
        /<V2Chip :tone="runTone\(r\.status\)" :label="runWords\[r\.status\]"/,
    );
    assert.match(script, /'Choose a pay group to continue\.'/);
    // Exports: the password dialog is a V2Dialog fed by the shared composable; the password is never kept here.
    assert.match(template, /title="Protect the file with a password"/);
    assert.match(flat, /@confirm="download"/);
    assert.match(flat, /autocomplete="new-password"/);
    assert.match(script, /'Type a password to continue\.'/);
    assert.match(exportsTab, /file_password: secret\.password/);
    // Settings: pay groups and pay item types open V2 dialogs, with the "In use" switch.
    assert.match(flat, /@confirm="saveGroup"/);
    assert.match(flat, /@confirm="saveType"/);
    assert.match(
        flat,
        /<V2SwitchRow v-if="groupForm\.group" v-model="groupForm\.isActive" label="In use"/,
    );
    assert.doesNotMatch(template, /<Sheet\b/);
});

test('the Classic templates did not change', () => {
    assert.doesNotMatch(classic, /V2WorkLayout|V2Dialog/);
    assert.match(classic, /<RunsTab/);
    assert.match(classic, /<ExportsTab/);
});
