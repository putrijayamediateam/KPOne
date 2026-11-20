// Classic only, CO-7: the Classic half of hr-v2-payroll.test.mjs, kept when its V2 page and dialogs were removed. The
// V2 dialog checks went with them; the Classic sheets' checks are carried over unchanged.
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const classic = read('resources/js/pages/HR/Payroll.vue');
const page = read('resources/js/composables/hr/usePayrollPage.ts');
const runs = read('resources/js/composables/hr/useRunsTab.ts');
const exportsTab = read('resources/js/composables/hr/useExportsTab.ts');

test('Classic Payroll and its tabs take their requests from the shared composables', () => {
    assert.match(classic, /usePayrollPage\(props\)/);
    assert.match(
        read('resources/js/components/hr/payroll/RunsTab.vue'),
        /useRunsTab\(props\)/,
    );
    assert.match(
        read('resources/js/components/hr/payroll/ExportsTab.vue'),
        /useExportsTab\(\)/,
    );
    assert.match(runs, /'\/hr\/payroll\/runs'/);
    assert.match(
        exportsTab,
        /\/hr\/payroll\/runs\/\$\{run\.id\}\/export\/\$\{kind\}/,
    );
    assert.ok(page.includes('/hr/payroll/groups'));
});

test('the Classic profile, table and MTD sheets take their requests from their composables', () => {
    for (const [name, comp] of [
        ['ProfileSheet', 'useProfileSheet'],
        ['TableSheet', 'useTableSheet'],
        ['MtdScheduleSheet', 'useMtdScheduleSheet'],
    ]) {
        assert.match(
            read(`resources/js/components/hr/payroll/${name}.vue`),
            new RegExp(
                `${comp}\\(props, \\(\\) => emit\\('update:open', false\\)\\)`,
            ),
            name,
        );
    }

    assert.match(
        read('resources/js/composables/hr/useProfileSheet.ts'),
        /'\/hr\/payroll\/profiles'/,
    );
    assert.match(
        read('resources/js/composables/hr/useMtdScheduleSheet.ts'),
        /'\/hr\/payroll\/mtd-schedules'/,
    );
});

test('part (b) fixes: the profile form stays per sheet; the rate example and the "(now …)" note follow the figures', () => {
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
});

test('a protected export sends its file password', () => {
    assert.match(exportsTab, /file_password: secret\.password/);
});

test('Classic Payroll renders its own tabs and no V2 part', () => {
    assert.doesNotMatch(classic, /V2WorkLayout|V2Dialog/);
    assert.match(classic, /<RunsTab/);
    assert.match(classic, /<ExportsTab/);
});
