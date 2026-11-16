import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileTemplate, parse } from '@vue/compiler-sfc';

// HR V2 dashboard: read only, and the Version 2 page shares its composable with Classic.
const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const source = read('resources/js/pages/HR/V2/Dashboard.vue');
const classic = read('resources/js/pages/HR/Dashboard.vue');
const composable = read('resources/js/composables/hr/useDashboardPage.ts');
const template = parse(source).descriptor.template.content;
const script = parse(source).descriptor.scriptSetup.content;
const flat = template.replace(/\s+/g, ' ');

test('the V2 page compiles, is registered, and shares the composable with Classic', () => {
    assert.deepEqual(
        compileTemplate({
            id: 'hr-v2-dashboard',
            source: template,
            filename: 'Dashboard.vue',
        }).errors,
        [],
    );
    assert.match(read('config/v2-pages.php'), /'HR\/Dashboard',/);
    assert.match(script, /useDashboardPage\(props\)/);
    assert.match(classic, /useDashboardPage\(props\)/);
    assert.match(composable, /router\.get\(\s*'\/hr\/dashboard'/);
});

test('read only: no write, no fetch, no storage, and no button that changes anything', () => {
    assert.doesNotMatch(
        script,
        /router\.|fetch\(|axios|useForm|localStorage|sessionStorage|crypto\.randomUUID/,
    );
    assert.doesNotMatch(composable, /router\.(post|put|patch|delete)/);
    assert.doesNotMatch(flat, /<form|<V2Dialog|@click/);
    // The only links go to the roster's minimum-staffing tab, as in Classic.
    assert.match(script, /\/hr\/roster\?tab=minimums&branch=/);
});

test('privacy: no salary, leave type or reason, or per-person score; names only in the three lists for today', () => {
    assert.doesNotMatch(flat, /\.(salary|pay|leaveType|reason|score)\b/i);
    assert.match(flat, /data-test="rostered"/);
    assert.match(flat, /data-test="clocked-in"/);
    assert.match(flat, /data-test="on-leave"/);
    // Leave shows only "on leave" or "half day".
    assert.match(flat, /p\.half \? 'half day' : 'on leave'/);
    // Staff cost stays one clinic-wide estimate.
    assert.match(flat, /Not split by person or branch/);
});

test('V2 rules: no filled button on a read-only page, 44 px outline links, and the Classic template did not change', () => {
    assert.doesNotMatch(flat, /primaryButton|bg-primary/);
    assert.match(flat, /min-h-11/);
    assert.doesNotMatch(classic, /V2WorkLayout|V2Stat/);
});
