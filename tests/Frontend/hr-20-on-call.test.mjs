import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileTemplate, parse } from '@vue/compiler-sfc';

// HR-20 "On call": the minimums grid lists clinic sessions only, and the HR dashboard shows on-call people on their own
// line, never as the doctor on duty. Classic and Version 2.
const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const compiles = (path) => {
    const template = parse(read(path)).descriptor.template.content;
    assert.deepEqual(
        compileTemplate({ id: 'hr-20', source: template, filename: path })
            .errors,
        [],
        path,
    );

    return template;
};

for (const path of [
    'resources/js/pages/HR/Roster.vue',
    'resources/js/pages/HR/V2/Roster.vue',
]) {
    test(`${path}: the minimum staffing grid lists clinic sessions only`, () => {
        const template = compiles(path);
        assert.match(template, /v-for="session in roster\.staffingSessions"/);
        assert.doesNotMatch(template, /v-for="session in roster\.sessions"/);
    });
}

test('the roster page props carry the clinic sessions separately', () => {
    assert.match(
        read('resources/js/composables/hr/useRosterPage.ts'),
        /staffingSessions: Option\[\];/,
    );
    assert.match(
        read('app/Domain/HR/Roster/Services/RosterPageService.php'),
        /'staffingSessions' => collect\(RosterRules::clinicSessions\(\)\)/,
    );
});

for (const path of [
    'resources/js/pages/HR/Dashboard.vue',
    'resources/js/pages/HR/V2/Dashboard.vue',
]) {
    test(`${path}: on-call people have their own line`, () => {
        const template = compiles(path);
        assert.match(
            template,
            /v-if="\s*branch\.today\.rostered\.onCall\.length\s*"/,
        );
        assert.match(template, /data-test="on-call-now"/);
        assert.match(template, />\s*On call:\s*</);
    });
}

test('the clock result chip knows on call', () => {
    assert.match(
        read('resources/js/composables/useClockPhoto.ts'),
        /'no_shift' \| 'on_call'/,
    );
    assert.match(
        read('resources/js/composables/hr/useDashboardPage.ts'),
        /onCall: Person\[\];/,
    );
});

test('the planner footer shows on call beside "on shift", never inside it', () => {
    const classic = read('resources/js/pages/HR/Roster.vue');
    assert.match(classic, /planner\.onCallTotals\[index\]/);
    assert.match(classic, /on call<\/template/);
    const v2 = read('resources/js/pages/HR/V2/Roster.vue');
    assert.match(v2, /` · \$\{planner\.value\?\.onCallTotals\[i\]\} on call`/);
    assert.match(
        read('app/Domain/HR/Roster/Services/RosterPageService.php'),
        /'totals' => \$this->dayCounts\(\$branch, \$weekStart, \$shifts->reject\(/,
    );
});
