import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');

const page = read('resources/js/pages/HR/Dashboard.vue');
const routes = read('routes/web.php');
const nav = read('app/Domain/Access/ClinicManagementNavigation.php');
const service = read('app/Domain/HR/Dashboard/HrDashboardService.php');
// Comments may name what is left out; only code is checked for forbidden words.
const code = (text) =>
    text
        .replace(/\/\*[\s\S]*?\*\//g, '')
        .replace(/^\s*(\/\/|\*).*$/gm, '')
        .replace(/\s\/\/ .*$/gm, '');

test('the dashboard route is read only, permissioned by any of the three existing HR permissions, not cached and throttled', () => {
    assert.match(
        routes,
        /Route::get\('hr\/dashboard', \[HrDashboardController::class, 'index'\]\)\s+->middleware\(\['permission:hr\.roster\.manage\.organisation,hr\.attendance\.review\.organisation,hr\.leave\.manage\.organisation', 'sensitive\.no-store', 'throttle:60,1'\]\)->name\('hr\.dashboard\.index'\)/,
    );
    assert.doesNotMatch(
        routes,
        /Route::(post|put|patch|delete)\('hr\/dashboard/,
    );
});

test('the hub row shows for holders of any of the three permissions', () => {
    assert.match(
        nav,
        /\$actor->can\('hr\.roster\.manage\.organisation'\) \|\| \$actor->can\('hr\.attendance\.review\.organisation'\) \|\| \$actor->can\('hr\.leave\.manage\.organisation'\), 'KPOne HR dashboard', .*'hr\.dashboard\.index'/,
    );
});

test('the page says it is read only, repeats the synthetic-data line and offers one month picker', () => {
    assert.match(page, /Read only; it changes nothing\./);
    assert.match(page, /<SyntheticNote \/>/);
    assert.match(page, /id="dashboard-month"/);
    assert.match(page, /router\.get\(\s*'\/hr\/dashboard'/);
    assert.doesNotMatch(page, /router\.(post|put|patch|delete)/);
});

test('staffing and the doctor alert are words with a badge, never colour alone, and a quiet branch is not alarmed', () => {
    for (const label of [
        'Enough staff',
        'Short of staff',
        'No shifts today',
        'Minimum staffing not set',
        'No Resident Doctor rostered now',
    ]) {
        assert.ok(page.includes(label), label);
    }

    assert.match(
        service,
        /'noDoctor' => \$onShift->isNotEmpty\(\) && \$doctors === 0/,
    );
});

test('leave shows only "On leave" or "half day" and nothing about type or reason; no pay or score anywhere', () => {
    assert.match(page, /p\.half \? 'half day' : 'on leave'/);

    for (const forbidden of [
        /leaveType/i,
        /reason/i,
        /salary/i,
        /payroll/i,
        /\bscore\b/i,
        /ranking/i,
    ]) {
        assert.doesNotMatch(code(page), forbidden);
        assert.doesNotMatch(code(service), forbidden);
    }
});

test('late and missing-clock figures stay hidden until attendance settings are saved, and each section needs its own permission', () => {
    assert.match(page, /v-if="dashboard\.attendanceSettingsSaved"/);
    assert.match(page, /stay\s+hidden until\s+HR saves attendance settings/);
    assert.match(
        service,
        /'roster' => \$actor->can\(RosterPermissions::MANAGE\)/,
    );
    assert.match(
        service,
        /'attendance' => \$actor->can\(ClockPermissions::REVIEW\)/,
    );
    assert.match(
        service,
        /'leave' => \$actor->can\(LeavePermissions::MANAGE\)/,
    );
});

test('dates use the branch time zone and leave slots are compared in branch-local time', () => {
    assert.match(
        service,
        /\$now->setTimezone\(\$branch->timezone\)->format\('Y-m-d H:i:s'\)/,
    );
    assert.match(
        service,
        /createFromFormat\('!Y-m', \$month, \$branch->timezone\)/,
    );
});
