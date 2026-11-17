import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileTemplate, parse } from '@vue/compiler-sfc';

// HR V2 Payroll: part (a) put Runs, Exports and Settings in the V2 look; part (b) Pay profiles and Tables, with V2
// dialogs. The page and its dialogs share their composables with Classic, so both send the same requests.
const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const source = read('resources/js/pages/HR/V2/Payroll.vue');
const classic = read('resources/js/pages/HR/Payroll.vue');
const page = read('resources/js/composables/hr/usePayrollPage.ts');
const runs = read('resources/js/composables/hr/useRunsTab.ts');
const exportsTab = read('resources/js/composables/hr/useExportsTab.ts');
const template = parse(source).descriptor.template.content;
const script = parse(source).descriptor.scriptSetup.content;
const flat = template.replace(/\s+/g, ' ');

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

test("part (b): Pay profiles and Tables are in the V2 look, with V2 dialogs that share the Classic sheets' composables", () => {
    // The Classic markup carried in part (a) is gone: the V2 page uses no Classic sheet or confirm dialog.
    assert.doesNotMatch(
        template,
        /<ProfileSheet|<TableSheet|<MtdScheduleSheet|<OperationalConfirmDialog|<StatusBadge|<EmptyState/,
    );
    assert.match(
        template,
        /<V2ProfileDialog\s+v-model:open="profileSheet\.open"/,
    );
    assert.match(template, /<V2TableDialog\s+v-model:open="tableSheet"/);
    assert.match(template, /<V2MtdScheduleDialog\s+v-model:open="mtdSheet"/);

    for (const [name, comp] of [
        ['ProfileSheet', 'useProfileSheet'],
        ['TableSheet', 'useTableSheet'],
        ['MtdScheduleSheet', 'useMtdScheduleSheet'],
    ]) {
        const v2 = read(
            `resources/js/components/hr-v2/payroll/V2${name === 'ProfileSheet' ? 'Profile' : name === 'TableSheet' ? 'Table' : 'MtdSchedule'}Dialog.vue`,
        );
        const v2Script = parse(v2).descriptor.scriptSetup.content;
        assert.match(
            v2Script,
            new RegExp(
                `${comp}\\(props, \\(\\) => emit\\('update:open', false\\)\\)`,
            ),
            name,
        );
        assert.match(
            read(`resources/js/components/hr/payroll/${name}.vue`),
            new RegExp(
                `${comp}\\(props, \\(\\) => emit\\('update:open', false\\)\\)`,
            ),
            name,
        );
        // Each V2 dialog sends nothing of its own and waits, in words, for what is missing.
        assert.doesNotMatch(
            v2Script,
            /router\.|fetch\(|useForm|crypto\.randomUUID/,
            name,
        );
        assert.match(v2, /:confirm-disabled="missing !== ''"/, name);
        assert.match(v2, /@confirm="missing === '' && send\(\)"/, name);
    }

    assert.match(
        read('resources/js/composables/hr/useProfileSheet.ts'),
        /'\/hr\/payroll\/profiles'/,
    );
    assert.match(
        read('resources/js/composables/hr/useMtdScheduleSheet.ts'),
        /'\/hr\/payroll\/mtd-schedules'/,
    );
    // Confirm, check, reject and withdraw use the shared requests; Reject and Withdraw are red, and a reason starts empty.
    assert.match(flat, /@confirm="decisionMissing === '' && decide\(\)"/);
    assert.match(
        flat,
        /:tone="decision\.action === 'reject' \? 'danger' : 'default'"/,
    );
    assert.match(script, /'Pick a reason to continue\.'/);
    assert.match(
        flat,
        /title="Withdraw this\?"[\s\S]*?tone="danger"[\s\S]*?@confirm="withdraw"/,
    );
    assert.match(flat, /:class="dangerButton" data-test="profile-reject"/);
    assert.match(flat, /data-test="managers-outside"/);
});

test('part (b) fixes: the profile form stays per sheet; the rate example and the "(now …)" note follow the figures', () => {
    // Suresh M1: the form (pay values) is created inside the composable, never shared at module level.
    const profile = read('resources/js/composables/hr/useProfileSheet.ts');
    const fn = profile.indexOf('export function useProfileSheet');
    assert.ok(fn > 0);
    assert.ok(profile.indexOf('const form = reactive(') > fn);

    for (const path of [
        'resources/js/composables/hr/useTableSheet.ts',
        'resources/js/composables/hr/useMtdScheduleSheet.ts',
    ]) {
        const text = read(path);
        assert.ok(
            text.indexOf('const form = reactive(') >
                text.indexOf('export function'),
            path,
        );
    }

    // Daniel: an hourly example for an hourly rate, and "(now …)" only when the waiting figure differs.
    assert.match(
        read('resources/js/components/hr-v2/payroll/V2ProfileDialog.vue'),
        /form\.payType === 'hourly'\s+\? 'For example 15\.00'\s+: 'For example 2,500\.00'/,
    );
    assert.match(
        flat,
        /person\.current\.rateSen !== person\.waiting\.rateSen \|\| person\.current\.payType !== person\.waiting\.payType/,
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
    // Each run row is one link (one focus stop) with a visible outline "Open" (Daniel), and the list reads live props.
    assert.match(
        flat,
        /<span aria-hidden="true" class="[^"]*min-h-11[^"]*lg:min-h-9" >Open<\/span >/,
    );
    assert.match(script, /const runsView = computed\(/);
    assert.match(flat, /v-for="r in runsView\.runs"/);
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
