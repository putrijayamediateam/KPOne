import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileTemplate, parse } from '@vue/compiler-sfc';

// HR-7d MTD (PCB) worked out by KPOne (HR-PLAN section 18; the owner's "start the HR-7d build", 2026-11-07).
const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const files = {
    sheet: 'resources/js/components/hr/payroll/MtdScheduleSheet.vue',
    profile: 'resources/js/components/hr/payroll/ProfileSheet.vue',
    payroll: 'resources/js/pages/HR/Payroll.vue',
    run: 'resources/js/pages/HR/PayrollRun.vue',
};
const source = Object.fromEntries(
    Object.entries(files).map(([k, p]) => [k, read(p)]),
);
const template = (key) => parse(source[key]).descriptor.template.content;

test('the MTD schedule sheet, the profile sheet and the pages using them compile', () => {
    for (const [key, path] of Object.entries(files)) {
        assert.deepEqual(
            compileTemplate({ id: key, source: template(key), filename: path })
                .errors,
            [],
            path,
        );
    }
});

test('the MTD schedule sheet ships no tax number and posts every band and relief', () => {
    assert.match(source.sheet, /'\/hr\/payroll\/mtd-schedules'/);
    assert.match(source.sheet, /\.\.\.form\.reliefs/);
    // Every figure starts empty; only the first band's start is filled.
    assert.match(source.sheet, /rows: \[band\('0\.00'\)\]/);
    assert.doesNotMatch(source.sheet, /9,?000|4,?000|2,?000\.00/);
    assert.match(template('sheet'), /Tax on M, categories 1 and 3 \(RM\)/);
    assert.match(template('sheet'), /Tax on M, category 2 \(RM\)/);
    assert.match(template('sheet'), /Smallest MTD deducted \(RM\)/);
});

test('the Tables tab lists MTD schedules with check, reject and withdraw by someone else', () => {
    assert.match(template('payroll'), /data-test="mtd-schedules"/);
    assert.match(template('payroll'), /MTD \(PCB\) schedules/);
    assert.match(template('payroll'), /askDecide\(mtdTarget\(m\), 'check'\)/);
    assert.match(template('payroll'), /askWithdraw\(mtdTarget\(m\)\)/);
    assert.match(source.payroll, /'mtd-schedules'/);
    assert.match(template('payroll'), /KPOne\s+ships\s+no\s+tax\s+number\./);
});

test('the pay profile holds optional MTD details, and none keeps MTD typed each run', () => {
    assert.match(template('profile'), /data-test="profile-mtd-details"/);
    assert.match(template('profile'), /None: type MTD each run/);
    assert.match(template('profile'), /v-if="form\.mtdCategory === '2'"/);
    assert.match(source.profile, /mtd_category: worksOutMtd\.value/);
});

test('the run MTD box says a saved figure replaces the worked-out one', () => {
    assert.match(
        template('run'),
        /A\s+figure saved here replaces it for this run; leave it\s+empty to use the worked-out figure\./,
    );
    assert.doesNotMatch(template('run'), /does not work out MTD yet/);
});
